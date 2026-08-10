---
title: Runtime & Operations
---

# Runtime & Operations

Saltus wraps every ability execution with audit logging, rate limiting, and transient caching. This
page covers how execution is dispatched and how those controls are configured.

## Execution Model

Saltus abilities are REST-backed. A client calls a `saltus/*` ability, Saltus validates the input schema, checks rate limits, builds a `WP_REST_Request`, and dispatches it through `rest_do_request()`.

The REST controller remains the authoritative execution layer. This keeps behavior consistent between direct REST requests and MCP/Abilities calls.

### Two-Pass Service Registration & the `is_needed()` Gate

To decouple REST and MCP tool availability from the current page loading context, the framework separates tool registration from core runtime initialization:

1. **Unconditional REST/MCP Discovery (Pass 1):** Services implementing `RestRouteProvider` or `ToolContributor` are instantiated unconditionally during boot. This ensures that their REST routes and MCP tools are registered and accessible to AI clients on any request, bypassing the `is_needed()` check. No hooks, actions, or assets are registered in this pass.
2. **Gated Core Activation (Pass 2):** Core features (admin hooks, action hooks, asset enqueuing) are registered inside the service container, which strictly enforces the `is_needed()` gate:
   ```php
   if ( is_a( $service_class, Conditional::class, true ) &&
        ! $service_class::is_needed() ) {
       return;
   }
   ```
   If a service is not needed in the current request context (e.g. an admin-only service requested on a frontend page), it is skipped entirely. This prevents unneeded scripts, styles, and action hooks from running amok.

## Runtime Controls

These controls are configured with WordPress filters.

| Filter | Purpose | Default |
|--------|---------|---------|
| `saltus/framework/mcp/audit/enabled` | Enable or disable audit writes | `true` |
| `saltus/framework/mcp/audit/retention_days` | Days to keep audit rows | `30` |
| `saltus/framework/mcp/audit/table_check_ttl` | Seconds a table-existence check is trusted before the DDL runs again; `0` restores a check on every request | `3600` |
| `saltus/framework/mcp/rate_limit/enabled` | Enable or disable rate limiting | `true` |
| `saltus/framework/mcp/rate_limit/max_requests` | Max calls per window | `60` |
| `saltus/framework/mcp/rate_limit/window_seconds` | Rate-limit window size | `60` |
| `saltus/framework/mcp/rate_limit/identifier` | Override the user/request rate-limit key | current user or request IP hash |
| `saltus/framework/mcp/cache/enabled` | Enable or disable transient caching | `true` |
| `saltus/framework/mcp/cache/ttl` | Override cache TTL per tool | tool-defined TTL |
| `saltus/framework/mcp/cache/cacheable` | Override whether a tool is cacheable | tool-defined cacheability |
| `saltus/framework/health/audit_sample_size` | Audit rows sampled by health endpoint | `100` |

Example:

```php
add_filter(
	'saltus/framework/mcp/rate_limit/max_requests',
	static function (): int {
		return 120;
	}
);
```

## Caching

Read-only tools can be cached in WordPress transients. Mutating tools clear the Saltus MCP cache after successful execution.

Current cacheable tools include:

- `get_health`
- `list_models`
- `get_model`
- `list_posts`
- `get_post`
- `list_terms`
- `get_settings`
- `list_meta_fields`
- `get_meta_fields`

Other tools may still be made cacheable through the `saltus/framework/mcp/cache/cacheable` filter, but write operations should remain uncached.

## Audit Trail

Ability executions are recorded in the Saltus MCP audit table when audit logging is enabled. Audit rows include:

- timestamp
- user ID
- rate-limit identifier
- ability/tool name
- arguments
- status
- duration in milliseconds
- error code and message when applicable

The health endpoint uses recent audit rows to calculate error-rate and latency metrics.
Health degradation is based on server-side audit failures: `error` and `exception`. Client-side outcomes such as `validation_error` and `rate_limited` remain visible in the status breakdown but do not count toward the framework health error rate.

Audit retention cleanup runs through the daily `saltus_framework_mcp_audit_cleanup` WP-Cron event. The cleanup query only deletes expired rows from the internal Saltus MCP audit table and never deletes posts, terms, settings, or other site content. Set `saltus/framework/mcp/audit/retention_days` to `0` or a negative value to disable retention cleanup.

## Health Monitoring

The `get_health` ability calls `GET /saltus-framework/v1/health`. It reports:

- framework version
- whether the native Abilities API is available
- audit sample size
- recent error count and error rate
- status counts
- latency average, p95, and max in milliseconds
- cache enabled state
- rate limit enabled state

The health route requires `edit_posts` by default. It is not tied to a specific CPT model and does not require model opt-in.

## Metadata Discovery

Use `list_meta_fields` when a client needs to understand available custom fields across the site. Use `get_meta_fields` when the client already knows the post type.

Metadata responses include:

- `meta`: raw Saltus/Codestar metabox configuration
- `normalized.fields`: flattened client-facing field paths
- `normalized.rest_meta_keys`: writable REST meta roots with serialization and type information

Nested Codestar fields are flattened into explicit paths, for example:

```text
points_info.coordinates.latitude
points_info.coordinates.longitude
points_info.tooltipContent
```

This lets clients reason about nested meta while still preserving the original Saltus/Codestar shape for advanced integrations.
