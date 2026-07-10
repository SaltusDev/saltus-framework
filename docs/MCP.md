# WordPress-Native MCP/Abilities

Saltus Framework exposes its AI-facing tool surface through the WordPress-native MCP/Abilities API. Plugins built with Saltus do not run a separate MCP server. When the host WordPress version provides the Abilities API, Saltus registers `saltus/*` abilities from inside WordPress and dispatches calls through the existing REST layer.

This document is written as the source page for the future Saltus documentation site.

For client implementation guidance, see [MCP-CLIENTS.md](MCP-CLIENTS.md). For the generated ability reference, see [MCP-ABILITIES.md](MCP-ABILITIES.md).

## Status

- Supported path: WordPress-native MCP/Abilities
- Standalone stdio server: removed
- SSE transport: out of scope
- Current ability count: 18
- REST namespace: `saltus-framework/v1`
- Ability namespace: `saltus/*`

## Requirements

- WordPress 7.0+ or a WordPress build that includes the Abilities API
- An active plugin that loads and registers Saltus Framework
- A WordPress-native MCP/Abilities client
- A WordPress user with the capabilities required by the requested operation

Older WordPress versions simply skip ability registration. The framework continues to work for CPT, taxonomy, settings, meta, and admin features; only the native MCP/Abilities surface is unavailable.

## Setup

Install Saltus Framework in your plugin and register it normally:

```php
$autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require_once $autoload;
}

if ( class_exists( \Saltus\WP\Framework\Core::class ) ) {
	$framework = new \Saltus\WP\Framework\Core( dirname( __FILE__ ), __FILE__ );
	$framework->register();
}
```

No MCP-specific server process, local config file, port, token, or shell command is required. Saltus registers abilities during WordPress' Abilities API lifecycle when the native API is present.

## Discovery

Native MCP/Abilities clients discover Saltus tools from the active WordPress site. Saltus registers ability names in the `saltus/*` namespace, using kebab-case ability names derived from snake_case tool names.

Examples:

| Tool | Ability |
|------|---------|
| `get_health` | `saltus/get-health` |
| `list_models` | `saltus/list-models` |
| `get_meta_fields` | `saltus/get-meta-fields` |
| `update_settings` | `saltus/update-settings` |

Each ability definition includes:

- `name`
- `label`
- `description`
- `category`
- `input_schema`
- `inputSchema`
- `execute_callback`
- `permission_callback`
- `meta`

The ability `meta` identifies the MCP tool name, REST namespace, transport, and REST visibility.

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

## Permissions

Permissions are enforced in two layers:

1. Native ability permission callbacks check broad WordPress capabilities before execution.
2. REST permission callbacks and controller policies enforce the final operation-specific rules.

Saltus reuses WordPress capability checks such as:

| Operation | Typical capability check |
|-----------|--------------------------|
| Read/list tools | `read` or model-specific read/edit access |
| Create posts | post type `create_posts` capability, falling back to `edit_posts` |
| Update posts | `edit_post` for the target post |
| Delete posts | `delete_post` for the target post |
| Duplicate posts | `edit_post` for the source post |
| Export posts | `export` |
| Settings updates | `manage_options` |
| Term creation | taxonomy edit/manage capability |

REST routes and MCP tools are gated by model configuration.

### Master Options (Model Level)

At the model level, two master options in the `options` array control access:
- **`show_in_rest`**: Controls whether model-scoped REST routes are registered. If explicitly set to `false`, all model-scoped REST capabilities for the model are disabled. It does not control whether the model's MCP tools are generated/shown (which is managed by `mcp_tools` and `show_in_mcp`), although calling those MCP tools will fail if the underlying REST route is disabled. Defaults to `true` (if omitted or not `false`).
- **`mcp_tools`**: Must be set and truthy (e.g., `true`) in model options to enable any MCP tools for that model.

The framework-scoped health capability (`health` ability / REST route) is independent of per-model opt-in and is always available. The `models` capability is always enabled for a model as long as its `show_in_rest` is not `false` (or always, for MCP, if `mcp_tools` is enabled).

### Feature-Level Gating

Each individual framework capability can be gated in the model's `config` array. They map to specific configuration sections:
- **Meta (`meta`):** `'meta'` key (root level of `config`)
- **Settings (`settings`):** `'settings'` key (root level of `config`)
- **Duplicate (`duplicate`):** `'duplicate'` key (nested under `config.features.duplicate`)
- **Export (`export`):** `'single_export'` key (nested under `config.features.single_export`)
- **Reorder (`reorder`):** `'drag_and_drop'` key (nested under `config.features.drag_and_drop`)

### Resolution Rules

For each feature/capability configuration section:
1. **Omitted (Null):** If a capability config section is omitted from the model configuration, the feature's availability defaults to the master model option:
   - For **REST API**: falls back to `show_in_rest` (which itself defaults to `true`).
   - For **MCP Tools**: falls back to `mcp_tools` (which itself defaults to `false` if omitted).
   - If the respective master option is omitted/false, the feature is **disabled**. If the master option is `true`, the feature is **enabled**.
2. **Boolean Value:** If defined as a simple boolean (e.g., `'meta' => false` or `'features' => ['duplicate' => false]`), it acts as a joint gate. A value of `false` disables both REST and MCP for that capability; a value of `true` enables both.
3. **Array Value:** If defined as an array, REST and MCP gating can be configured independently:
   - **REST Route Gating:** Governed by the `show_in_rest` key in the section array. If present, it resolves to its boolean value. If omitted, it falls back to matching the master model `show_in_rest` option.
   - **MCP Tool Gating:** Governed by the `show_in_mcp` key in the section array. If present, it resolves to its boolean value. If omitted, it falls back to matching the master model `mcp_tools` option.

Enable all Saltus REST-backed and MCP capabilities for a model:

```php
return [
	'type'    => 'cpt',
	'name'    => 'book',
	'options' => [
		'show_in_rest' => true,
		'mcp_tools'    => true,
	],
];
```

Example showing various feature-level configurations:

```php
return [
	'type'    => 'cpt',
	'name'    => 'book',
	'options' => [
		'show_in_rest' => true,
		'mcp_tools'    => true,
	],
	'config'  => [
		// 1. Array style: enabled for REST but disabled for MCP
		'meta'     => [
			'show_in_rest' => true,
			'show_in_mcp'  => false,
		],
		// 2. Boolean style: disabled for both REST and MCP
		'settings' => false,

		'features' => [
			// 3. Array style: enabled for REST, and defaults to matching master options (enabled) for MCP
			'duplicate'     => [
				'show_in_rest' => true,
			],
			// 4. Omitted config for single_export and drag_and_drop:
			// both default to matching the master options (enabled here because show_in_rest & mcp_tools are true)
		],
	],
];
```

If `show_in_rest` is explicitly `false`, Saltus does not register the model-scoped REST routes. The `mcp_tools` option controls whether MCP tools are exposed. The health ability is framework-scoped and remains independent of per-model opt-in.

## Available Abilities

<!-- BEGIN AUTO-GENERATED MCP ABILITIES -->
<!-- This section is auto-generated by `composer docs:mcp`. Do not edit by hand. -->

| Tool | Ability | REST request | Description |
|------|---------|--------------|-------------|
| `create_post` | `saltus/create-post` | `POST /wp/v2/posts` | Create a new post in any registered Custom Post Type |
| `create_term` | `saltus/create-term` | `POST /wp/v2/{taxonomy_rest_base}` | Create a new term in a taxonomy |
| `delete_post` | `saltus/delete-post` | `DELETE /wp/v2/posts/123` | Delete (trash or force delete) a post by ID |
| `duplicate_post` | `saltus/duplicate-post` | `POST /saltus-framework/v1/duplicate/123` | Duplicate a WordPress post, creating a copy with "(Copy)" appended to the title |
| `export_post` | `saltus/export-post` | `GET /saltus-framework/v1/export/123` | Export a WordPress post as WXR (WordPress eXtended RSS) for import into another site |
| `get_health` | `saltus/get-health` | `GET /saltus-framework/v1/health` | Get Saltus Framework health, version, audit error rate, latency, cache, and rate limit status |
| `get_meta_fields` | `saltus/get-meta-fields` | `GET /saltus-framework/v1/meta/{post_type}` | Get the meta field definitions for a post type as configured in the Saltus Framework model |
| `get_model` | `saltus/get-model` | `GET /saltus-framework/v1/models/{slug}` | Get details of a specific Custom Post Type or Taxonomy by slug |
| `get_post` | `saltus/get-post` | `GET /wp/v2/posts/123` | Get a single post by ID with all fields and meta data |
| `get_settings` | `saltus/get-settings` | `GET /saltus-framework/v1/settings/{post_type}` | Get the Saltus Framework settings for a specific post type |
| `list_meta_fields` | `saltus/list-meta-fields` | `GET /saltus-framework/v1/meta` | List model-defined meta field definitions for all registered Saltus post types |
| `list_models` | `saltus/list-models` | `GET /saltus-framework/v1/models` | List all registered Custom Post Types and Taxonomies on the WordPress site |
| `list_posts` | `saltus/list-posts` | `GET /wp/v2/posts` | Query posts from a Custom Post Type with optional filters |
| `list_terms` | `saltus/list-terms` | `GET /wp/v2/{taxonomy_rest_base}` | List terms from a taxonomy (categories, tags, or custom taxonomies) |
| `reorder_posts` | `saltus/reorder-posts` | `POST /saltus-framework/v1/reorder` | Reorder multiple posts by updating their menu_order values in a single batch operation |
| `update_meta_fields` | `saltus/update-meta-fields` | `PUT /saltus-framework/v1/meta/{post_type}/123` | Update meta fields for a specific post of a registered Saltus post type |
| `update_post` | `saltus/update-post` | `PUT /wp/v2/posts/123` | Update an existing post's fields and meta data |
| `update_settings` | `saltus/update-settings` | `PUT /saltus-framework/v1/settings/{post_type}` | Update the Saltus Framework settings for a specific post type |

For full generated parameter details, see [MCP-ABILITIES.md](MCP-ABILITIES.md).
<!-- END AUTO-GENERATED MCP ABILITIES -->

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

## Runtime Controls

Saltus wraps ability execution with audit logging, rate limiting, and transient caching. These controls are configured with WordPress filters.

| Filter | Purpose | Default |
|--------|---------|---------|
| `saltus/framework/mcp/audit/enabled` | Enable or disable audit writes | `true` |
| `saltus/framework/mcp/audit/retention_days` | Days to keep audit rows | `30` |
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

## Compatibility Matrix

| Environment | Behavior |
|-------------|----------|
| WordPress with Abilities API | Saltus registers `saltus/*` abilities |
| WordPress without Abilities API | Saltus skips native ability registration |
| `mcp_tools` not set or `false` | No MCP tools are generated for that model |
| `show_in_rest` set to `false` | Model-scoped Saltus REST routes are disabled (calling any corresponding MCP tools will fail) |
| No WordPress-native MCP client | Saltus abilities are registered, but no client consumes them |

## Troubleshooting

| Symptom | Check |
|---------|-------|
| No `saltus/*` abilities appear | Confirm the WordPress build provides the Abilities API and the plugin is active |
| A model is missing from MCP results | Confirm the model has `mcp_tools` enabled, and required feature-level `show_in_mcp` flags |
| A write operation fails | Confirm the current WordPress user has the needed post, taxonomy, or settings capability |
| Calls are throttled | Check `saltus/framework/mcp/rate_limit/*` filters |
| Results look stale | Clear transients or disable MCP cache while testing |
| Health reports degraded | Inspect recent audit entries for repeated errors or high latency |

## Removed Paths

Earlier Saltus MCP planning included a standalone local stdio server and remote transports. Those paths were removed when the WordPress-native Abilities direction became the supported integration model.

Saltus does not currently ship:

- a standalone MCP server binary
- a PHAR for MCP
- a Docker image for MCP
- an SSE transport
- a separate MCP configuration profile system
