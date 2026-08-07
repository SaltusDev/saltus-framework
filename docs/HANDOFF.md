# Handoff: MCP Error Hints

Delivered 2026-07-07. Kept as a record of the convention it established.

Every `WP_Error` returned from a REST controller carries an actionable `hint` key in its `$data` array, so MCP clients receive config guidance alongside `code`/`message`/`status`.

## Where hints live

| File | Hints |
|---|---|
| `src/Rest/SettingsController.php` | 6 |
| `src/Rest/DuplicateController.php` | 3 |
| `src/Rest/MetaController.php` | 3 |
| `src/Rest/ModelsController.php` | 3 |
| `src/Rest/ExportController.php` | 2 |
| `src/Rest/HealthController.php` | 1 |
| `src/Rest/ReorderController.php` | 1 |
| `src/Features/Meta/MetaFieldProvider.php` | 1 |

## Convention for new errors

A hint tells the caller what to change, naming the capability or the config key and where it lives:

```php
return new WP_Error(
	'rest_forbidden',
	__( 'You do not have permission to view framework health.', 'saltus-framework' ),
	[
		'status' => 403,
		'hint'   => __( 'Assign the edit_posts capability to your user role, or use an administrator account.', 'saltus-framework' ),
	]
);
```

Rules:

- Wrap hints in `__()` / `sprintf()` so they stay translatable.
- Return the hint in `$data['hint']` alongside `$data['status']` — standard `WP_Error` shape, no new response structure.
- For capability failures, name the capability and the post ID or post type it applies to.
- For gating failures, name the config section and the file it belongs in.

Capability gating moved to the per-feature `show_in_rest` / `show_in_mcp` config model after this pass, so hint text should reference that shape rather than the older `saltus_rest` capabilities array. See [Permissions](MCP.md#permissions).

## Downstream sync

Consuming plugins that vendor the framework through Strauss need to rebuild `vendor-prefixed/` to pick up hint changes.
