# Handoff: MCP Error Hints

Add an actionable `hint` key to every `WP_Error` `$data` array in the REST controllers so MCP clients receive config guidance alongside `code`/`message`/`status`.

## Changes needed

### 1. `src/Rest/HealthController.php` — `get_item_permissions_check`
```php
return new WP_Error(
    'rest_forbidden',
    __('You do not have permission to view framework health.', 'saltus-framework'),
    [
        'status' => 403,
        'hint'   => __('Assign the edit_posts capability to your user role, or use an administrator account.', 'saltus-framework'),
    ]
);
```

### 2. `src/Rest/ExportController.php` — `get_item_permissions_check` + `get_item`
Permission check:
```php
'hint' => __('Assign the export capability to your user role via Users → Edit User, or use an administrator account.', 'saltus-framework'),
```

`model_rest_capability_disabled` in `get_item`:
```php
'hint' => sprintf(
    __("Add 'saltus_rest' => [ 'capabilities' => [ 'export' => true ] ] to the model config for '%s' in src/models/.", 'saltus-framework'),
    $post->post_type
),
```

### 3. `src/Rest/DuplicateController.php` — 3 locations
- `create_item_permissions_check` (`rest_forbidden`): `"Assign the edit_posts capability to your user role, or use an administrator account."`
- `create_item` (`model_rest_capability_disabled`): `sprintf("Add 'saltus_rest' => [ 'capabilities' => [ 'duplicate' => true ] ] to the model config for '%s' in src/models/.", $post->post_type)`
- `create_item` (second `rest_forbidden`): `sprintf("You need the edit_post capability for post ID %d. Assign a role with this capability or use an administrator account.", $post_id)`

### 4. `src/Rest/ModelsController.php` — 3 locations
- `get_items_permissions_check` (`rest_forbidden`): `"Assign edit_posts to your user, or ensure at least one model has 'saltus_rest' => true in its config."`
- `get_item_permissions_check` (`rest_forbidden`): `sprintf("Assign edit_posts to your user, or ensure model '%s' has 'saltus_rest' => true in its config.", $model_name ?? '(unknown)')`
- `get_item` (`model_not_found`): `sprintf("Model '%s' is not registered in the Saltus modeler. Check the model slug and ensure it is registered in src/models/.", $name)`

### 5. `src/Rest/MetaController.php` — 3 locations
- `get_items_permissions_check` (`rest_forbidden`): `"Assign edit_posts to your user, or ensure the model has 'saltus_rest' => [ 'capabilities' => [ 'meta' => true ] ] in its config."`
- `update_item_permissions_check` (`model_not_found`): `sprintf("Add 'saltus_rest' => [ 'capabilities' => [ 'meta' => true ] ] to the model config for '%s' in src/models/.", $post_type)`
- `update_item_permissions_check` (`rest_forbidden`): `sprintf("Assign the '%s' capability to your user role for post ID %d, or use an administrator account.", $this->post_type_edit_capability((string) $post_type), $post_id)`

### 6. `src/Rest/SettingsController.php` — 6 locations
All `model_not_found` returns (4x: `get_item_permissions_check`, `update_item_permissions_check`, `get_item`, `update_item`):
```php
'hint' => sprintf(
    __("Add 'saltus_rest' => [ 'capabilities' => [ 'settings' => true ] ] to the model config for '%s' in src/models/.", 'saltus-framework'),
    $post_type
),
```

`rest_forbidden` in `get_item_permissions_check`:
```php
'hint' => sprintf(
    __("Assign the '%s' capability to your user role, or use an administrator account.", 'saltus-framework'),
    $capability
),
```

`rest_forbidden` in `update_item_permissions_check`:
```php
'hint' => __("Assign the 'manage_options' capability to your user role. Only administrators can update settings.", 'saltus-framework'),
```

### 7. `src/Rest/ReorderController.php` — `create_item_permissions_check`
```php
'hint' => __("Assign edit_posts to your user, or ensure all requested posts are editable by the current user. Check that each post's post type has 'saltus_rest' configured.", 'saltus-framework'),
```

### 8. `src/Features/Meta/MetaFieldProvider.php` — `post_type_meta`
```php
'hint' => sprintf(
    __("Model '%s' is not registered or the post type is not enabled. Check the model slug and ensure it has 'saltus_rest' => [ 'capabilities' => [ 'meta' => true ] ] in src/models/.", 'saltus-framework'),
    $post_type
),
```

---

## Notes
- All hints use WP i18n `__()` / `sprintf()` so they're translatable
- Hints are returned in `$data['hint']` alongside existing `$data['status']` — standard WP_Error format, no new response structure
- After committing these to `saltus-framework-git`, run `strauss` in `framework-demo` to rebuild `vendor-prefixed/`
- The `vendor-prefixed/MetaController.php` is also missing the `update_item_permissions_check` / `update_item` methods (PUT route) — this should be synced
