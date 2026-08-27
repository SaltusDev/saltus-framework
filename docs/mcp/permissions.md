---
title: Permissions
---

# Permissions

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

## Master Options (Model Level)

At the model level, two master options in the `options` array set the default for capabilities that do not configure themselves:

- **`show_in_rest`**: the fallback for model-scoped REST routes. Defaults to `true` when omitted.
- **`mcp_tools`**: the fallback for MCP tools. Defaults to `false` when omitted, so a model exposes no MCP tools until this is truthy.

These are defaults, not overrides. A capability whose config section is present as an array without a gate key resolves to enabled regardless of the master option — see [Resolution Rules](#resolution-rules). The `models` capability is the exception: it reads only the master option and cannot be configured per-feature.

The framework-scoped health capability (`health` ability / REST route) is independent of per-model opt-in and is always available.

## Feature-Level Gating

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

## Resolution Rules

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

## Compatibility Matrix

Every `show_in_rest` on this page is the Saltus model option, which governs the
`saltus-framework/v1` routes only. It is not the ability `meta.show_in_rest` that
core reads to decide whether an ability is listed and runnable on
`wp-abilities/v1`; abilities always declare that one true. A model with
`show_in_rest => false` still has its abilities discoverable and runnable through
core, provided `mcp_tools` enabled the tool. See
[Clients](/mcp/clients) for the ability meta keys.

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
