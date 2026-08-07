# WordPress-Native MCP/Abilities

Saltus Framework exposes its AI-facing tool surface through the WordPress-native MCP/Abilities API. Plugins built with Saltus do not run a separate MCP server. When the host WordPress version provides the Abilities API, Saltus registers `saltus/*` abilities from inside WordPress and dispatches calls through the existing REST layer.

This document is written as the source page for the future Saltus documentation site.

For client implementation guidance, see [Client Integration](/mcp/clients). For the generated ability reference, see [Abilities Reference](/mcp/abilities).

## Status

- Supported path: WordPress-native MCP/Abilities
- Standalone stdio server: removed
- SSE transport: out of scope
- Current ability count: 20
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

At the model level, two master options in the `options` array set the default for capabilities that do not configure themselves:

- **`show_in_rest`**: the fallback for model-scoped REST routes. Defaults to `true` when omitted.
- **`mcp_tools`**: the fallback for MCP tools. Defaults to `false` when omitted, so a model exposes no MCP tools until this is truthy.

These are defaults, not overrides. A capability whose config section is present as an array without a gate key resolves to enabled regardless of the master option — see [Resolution Rules](#resolution-rules). The `models` capability is the exception: it reads only the master option and cannot be configured per-feature.

The framework-scoped health capability (`health` ability / REST route) is independent of per-model opt-in and is always available.

### Feature-Level Gating

Each individual framework capability is gated from its own section of the model configuration. These sections sit at the top level of the model array — the same level as `name`, `options`, and `features`. There is no wrapping `config` key.

| Capability | Configuration section |
|---|---|
| `meta` | `meta` (top level) |
| `settings` | `settings` (top level) |
| `blocks` | `blocks` (top level) |
| `duplicate` | `features.duplicate` |
| `export` | `features.single_export` |
| `reorder` | `features.drag_and_drop` |

The `reorder` gate reads `features.drag_and_drop`, while the feature itself is enabled under `features.draganddrop`. The two keys are not interchangeable: `draganddrop` turns the admin reordering UI on, and `drag_and_drop` is the section the REST/MCP policy inspects.

### Resolution Rules

Each capability resolves in this order:

1. **Section omitted.** The capability falls back to the master option — `show_in_rest` for REST (defaults to `true`), `mcp_tools` for MCP (defaults to `false`). This is the only case that consults the master option.
2. **Section is a boolean.** It acts as a joint gate for both surfaces: `'meta' => false` disables REST and MCP for meta, `true` enables both.
3. **Section is an array with the gate key.** `show_in_rest` in the section governs REST; `show_in_mcp` governs MCP. The two are independent.
4. **Section is an array without the gate key.** The capability is **enabled**, regardless of the master option.

Rule 4 is the case that surprises people. A `meta` section holding metabox definitions, a `settings` section holding a settings page, or a `features.duplicate` section holding a label are all arrays without a gate key, so they resolve to enabled even when the model sets `show_in_rest => false` and `mcp_tools => false`:

```php
return [
	'type'    => 'cpt',
	'name'    => 'book',
	'options' => [
		'show_in_rest' => false,  // intent: keep this model off the Saltus REST surface
		'mcp_tools'    => false,  // intent: keep this model out of MCP
	],
	'meta'    => [
		'book_details' => [ 'title' => 'Book Details', 'fields' => [] ],
	],
];
```

Here `meta` is still reachable over REST **and** MCP, because the `meta` array carries no `show_in_rest` / `show_in_mcp` key. The `models` capability does honor the master options, so the model itself is not listed — only its meta capability is exposed.

To actually close a capability, gate it explicitly rather than relying on the master option:

```php
'meta' => [
	'show_in_rest' => false,
	'show_in_mcp'  => false,
	'book_details' => [ 'title' => 'Book Details', 'fields' => [] ],
],
```

The `models` capability behaves differently from the rest: it reads only the master option (`show_in_rest !== false` for REST, `mcp_tools === true` for MCP) and has no per-feature section.

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

Example showing each gating style. Note that the capability sections sit at the top level of the model array, not inside a `config` key:

```php
return [
	'type'     => 'cpt',
	'name'     => 'book',
	'options'  => [
		'show_in_rest' => true,
		'mcp_tools'    => true,
	],

	// 1. Array with both gate keys: enabled for REST, disabled for MCP.
	'meta'     => [
		'show_in_rest' => true,
		'show_in_mcp'  => false,
		'book_details' => [ 'title' => 'Book Details', 'fields' => [] ],
	],

	// 2. Boolean: disabled for both REST and MCP.
	'settings' => false,

	'features' => [
		// 3. Array with one gate key: REST explicit, MCP enabled by rule 4.
		'duplicate'     => [
			'show_in_rest' => true,
			'label'        => 'Duplicate book',
		],

		// 4. Array without gate keys: enabled on both surfaces by rule 4.
		'single_export' => [ 'label' => 'Export book' ],

		// The admin reordering UI. The reorder capability is gated from
		// 'drag_and_drop', not from this key.
		'draganddrop'   => true,
	],
];
```

If `show_in_rest` is explicitly `false`, Saltus does not register the model-scoped REST routes for capabilities that fall back to it — but see rule 4 above, since a present config section overrides that fallback. The health ability is framework-scoped and remains independent of per-model opt-in.

## Available Abilities

<!-- BEGIN AUTO-GENERATED MCP ABILITIES -->
<!-- This section is auto-generated by `composer docs:mcp`. Do not edit by hand. -->

Saltus Framework exposes 20 WordPress-native MCP/Abilities tools.

For full details including parameters, see [Abilities Reference](/mcp/abilities).
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
| `mcp_tools` not set or `false` | No MCP tools for that model, except capabilities whose config section is an array without a `show_in_mcp` key |
| `show_in_rest` set to `false` | Model-scoped Saltus REST routes are disabled, except capabilities whose config section is an array without a `show_in_rest` key |
| No WordPress-native MCP client | Saltus abilities are registered, but no client consumes them |

## Troubleshooting

| Symptom | Check |
|---------|-------|
| No `saltus/*` abilities appear | Confirm the WordPress build provides the Abilities API and the plugin is active |
| A model is missing from MCP results | Confirm the model has `options.mcp_tools` enabled, and required feature-level `show_in_mcp` flags |
| A capability is exposed after opting out | A config section present as an array without a gate key resolves to enabled; add an explicit `show_in_rest`/`show_in_mcp` to close it |
| Reorder is not exposed | The REST/MCP gate reads `features.drag_and_drop`, not `features.draganddrop` |
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
