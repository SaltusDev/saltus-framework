# Saltus Framework
Saltus Framework helps you develop WordPress plugins that are based on Custom Post Types.

We built it to make things easier and faster for developers with different skills. Add metaboxes, settings pages and other enhancements with just a few lines of code.

Visit saltus.dev for more information.

## Version

### Current version [2.0.0] - 2026-06-30

See [change log file](CHANGELOG.md) for full details.

### Features
	* Create Custom Post Types easily
	* Control all labels and messages
	* Add administration columns
	* Add administration lists
	* Add settings pages
	* Add metaboxes
	* Enable cloning
	* Enable single entry export
	* Enable drag&drop reordering
	* Create Taxonomies easily
	* Control labels
	* Associate with any existing post type
	* Add quick edit fields

### Requirements

Saltus Framework requires PHP 7.4+

### Installation

Install the framework in your plugin with Composer:

```bash
composer require saltus/framework
```

## Getting Started

### Demo

Refer to the [Framework Demo](https://github.com/SaltusDev/framework-demo) for a complete plugin example and to the [Wiki](https://github.com/SaltusDev/saltus-framework/wiki) for complete documentation.


Once the framework is installed and Composer's autoloader is loaded by your plugin, you can initialize it the following way:

```php
    $autoload = __DIR__ . '/vendor/autoload.php';
    if ( is_readable( $autoload ) ) {
      require_once $autoload;
    }

    if ( class_exists( \Saltus\WP\Framework\Core::class ) ) {

      /*
      * The path to the plugin root directory is mandatory,
      * so it loads the models from a subdirectory.
      */
      $framework = new \Saltus\WP\Framework\Core( dirname( __FILE__ ), __FILE__ );
      $framework->register();

      /**
       * Initialize plugin after this
       */

    }
```

The framework will search for a model file, by default in the folder `src/models`. This can be changed using a filter.

A model file should return one array or a multidimensional array of models to build the Custom Post Types or Taxonomies. Simple example:

```php
    return [
        'type'     => 'cpt',
        'name'     => 'movie',
    ];
```

The above example will create a Custom Post Type 'movie'.

Several models in same file:

```php
    return [
    [
        'type'     => 'cpt',
        'name'     => 'movie',
    ],
    [
            'type'         => 'category',
            'name'         => 'genre',
            'associations' => [
                ‘movie’,
            ],
        ],
    ];
```

The above example will create a Custom Post Type 'movie' and a hierarchical Taxonomy 'genre' associated with the Custom Post Type 'movie'.

## Models

Currently there are 2 types of Model, one for **Custom Post Types** and another for **Taxonomies**. Depending what you define, you’ll have different parameters available.

### Model type = ‘cpt’

| Parameter | Description |
| --- | --- |
| active | `boolean` - sets this model to active or inactive |
type | `string` - type of model |
name | `string` - identifier of the custom post type |
features | `array` - Features that this CPT will support (More Info Soon)  |
supports | `array` - refer to the supports argument of the [register_post_type](https://developer.wordpress.org/reference/functions/register_post_type/) function from WordPress |
labels | `array` - Everything related with labels for this custom post type (More Info Soon)  |
options | `array` - Refer to the second argument in the [register_post_type](https://developer.wordpress.org/reference/functions/register_post_type/) function from WordPress |
block_editor | `boolean` - if the Block Editor should be enabled or not |
meta | `array` - Information for the Metaboxes for this CPT (More Info Soon)  |
settings | `array` - Information for the Settings page of this CPT (More Info Soon)  |

Example File for a CPT Model (Soon)


### Model type = ‘category’ or ‘tag’

| Parameter | Description |
| --- | --- |
type | `string` - ‘category’ // or 'tag' to set it to non-hierarchical automatically |
name | `string` - identifier of this taxonomy |
associations | `string` - to what CPT it should be associated |
labels | `array` - Everything related with labels for this custom post type (More Info Soon)  |
options | `array` - Refer to the third parameter for register_taxonomy function from WordPress |

Example File for a Taxonomy Model (Soon)

## Filters/Hooks

Saltus Framework provides several hooks to customize its behavior.

### Actions

| Hook | Description | Parameters |
| :--- | :--- | :--- |
| `saltus/framework/duplicate_post/after` | Triggered after a post is successfully duplicated. | `string $post_type`, `int $original_post_id`, `int $new_post_id` |
| `saltus/framework/admin_filters/filter_output/{$filter_id}` | Allows overriding the HTML output of a specific admin filter. | `SaltusAdminFilters $instance`, `array $filter_args`, `string $element_id` |
| `saltus/framework/drag_and_drop/update_menu_order` | Triggered after the menu order is updated via drag and drop. | None |

### Filters

| Hook | Description | Parameters | Default |
| :--- | :--- | :--- | :--- |
| `saltus/framework/modeler/priority` | Filters the priority of the `init` hook used to register models. | `int $priority` | `1` |
| `saltus/framework/services` | Filters the list of service classes to be registered. | `array $services` | Core services array |
| `saltus/framework/models/path` | Filters the directory path where model files are located. | `string $path` | `{project_path}/src/models/` |
| `saltus/framework/models/extra_models` | Allows adding extra model configurations programmatically. | `array $extra_models` | `[]` |
| `saltus/framework/admin_filters/category_list` | Filters the term name shown in taxonomy dropdown filters. | `string $name`, `WP_Term $term` | `$term->name` |
| `saltus/framework/meta/matched_fields` | Filters the mapping between Saltus field types and Codestar field types. | `array $field_map` | Built-in map |
| `saltus/framework/duplicate_post/args` | Filters the data used to create a new duplicated post. | `array $args`, `int $original_post_id` | Copied post data |
| `saltus/framework/duplicate_post/excluded_meta_keys` | Filters meta keys that should not be copied during duplication. | `array $keys` | `['_wp_old_slug', '_edit_lock', '_edit_last']` |
| `saltus/framework/admin_filters/{$post_type}/filter_query/{$filter_id}` | Filters the query arguments for a specific admin filter. | `array $vars`, `array $query`, `array $filter` | `[]` |



## Credits and Licenses:

Includes a simplified version of SoberWP/Models. Their license is in lib/sobwewp/models/LICENSE.md. Is used to load php/json/yaml models of CPT.

Includes the [Codestart Framework](https://codestarframework.com/) which is [licensed under GPL](https://codestarframework.com/license/).

### Patching Codestar Framework

Every time the Codestar Framework is updated, our custom fixes may be overwritten. To re-apply the patches located in `lib/codestar-framework/patches`, run the following command from the repository root:

```bash
for f in lib/codestar-framework/patches/*; do git apply "$f"; done
```

Includes support for [github-updater](https://github.com/afragen/github-updater) to keep track on updates through the WordPress backend.
* Download [github-updater](https://github.com/afragen/github-updater)
* Clone [github-updater](https://github.com/afragen/github-updater) to your sites plugins/ folder
* Activate via WordPress

## WordPress-Native MCP/Abilities

Saltus Framework exposes its AI-facing tool surface through the WordPress-native MCP/Abilities API. Native WordPress MCP clients can discover and call the `saltus/*` abilities directly from the active plugin.

For full documentation, see [docs/MCP.md](docs/MCP.md). For client integration guidance, see [docs/MCP-CLIENTS.md](docs/MCP-CLIENTS.md). These pages are source material for the future Saltus MCP documentation site.

### Quick Start

Install and activate the plugin that uses Saltus Framework on a WordPress version with the Abilities API. Saltus registers its abilities during `wp_abilities_api_init`; clients that understand WordPress-native MCP/Abilities can discover the `saltus/*` tools from WordPress.

The standalone local stdio MCP server has been removed. Saltus MCP development targets WordPress-native abilities instead.

### Capability Dispatch

Abilities dispatch through WordPress REST requests, so existing REST permission callbacks and `current_user_can()` checks remain authoritative. WordPress versions without the Abilities API simply skip Saltus ability registration.

Saltus wraps ability execution with WordPress-native audit logging, rate limiting, and transient caching. Read-only abilities can be cached in transients, mutating abilities clear the MCP cache, rate limits are keyed by the current user or request identifier, and audit records are written to the Saltus MCP audit table.

### Available Tools

| Tool | Description |
|------|-------------|
| `get_health` | Get Saltus Framework health, version, error rate, latency, cache, and rate-limit status |
| `list_models` | List all registered CPTs and taxonomies |
| `get_model` | Get details of a specific post type or taxonomy |
| `list_posts` | Query posts with filters (status, search, pagination) |
| `get_post` | Get a single post with all fields and meta |
| `create_post` | Create a new post in any CPT |
| `update_post` | Update an existing post's fields and meta |
| `delete_post` | Trash or force delete a post |
| `list_terms` | List terms from a taxonomy |
| `create_term` | Create a new term in a taxonomy |
| `duplicate_post` | Duplicate a WordPress post |
| `export_post` | Export a post as WXR |
| `get_settings` | Get Saltus settings for a post type |
| `update_settings` | Update Saltus settings for a post type |
| `reorder_posts` | Batch update post menu order |
| `list_meta_fields` | Discover Saltus meta field definitions across all registered CPTs |
| `get_meta_fields` | Get Saltus meta field definitions for a post type |

Meta field discovery preserves the raw Saltus/Codestar configuration in `meta` and includes normalized MCP-friendly metadata in `normalized.fields` and `normalized.rest_meta_keys`. Nested fields are exposed as paths such as `points_info.coordinates.latitude`, with JSON-schema-like types and REST writability information.

### Requirements

- WordPress 7.0+ or a WordPress build that provides the Abilities API
- A WordPress-native MCP/Abilities client
- The plugin using Saltus Framework must be active

### Configuration

No local MCP server configuration is required for the WordPress-native path. Runtime behavior can be tuned with WordPress filters:

| Filter | Purpose |
|--------|---------|
| `saltus/framework/mcp/audit/enabled` | Enable or disable audit writes |
| `saltus/framework/mcp/audit/retention_days` | Set audit retention before cleanup |
| `saltus/framework/mcp/rate_limit/enabled` | Enable or disable rate limiting |
| `saltus/framework/mcp/rate_limit/max_requests` | Set max ability calls per window |
| `saltus/framework/mcp/rate_limit/window_seconds` | Set the rate-limit window |
| `saltus/framework/mcp/rate_limit/identifier` | Override the rate-limit identifier |
| `saltus/framework/mcp/cache/enabled` | Enable or disable transient caching |
| `saltus/framework/mcp/cache/ttl` | Set cache TTL per tool |
| `saltus/framework/mcp/cache/cacheable` | Override whether a tool is cacheable |

### Generated MCP documentation

The detailed MCP ability reference is generated from the `src/MCP/Tools` source classes:

```bash
composer docs:mcp
```

This refreshes `docs/MCP-ABILITIES.md` and the generated ability table in `docs/MCP.md`.

## Building

### Quality checks

Run the static analysis and coding-standard checks from the repository root:

```bash
composer phpstan
composer phpcs
composer validate --strict
```

### Disadvantages of classmap
As we move from 'files' to 'classmap', heed this:
> Manual Updates: If you add new classes, you must regenerate the classmap by running composer dump-autoload.
