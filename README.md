# Saltus Framework
Saltus Framework helps you develop WordPress plugins that are based on Custom Post Types.

We built it to make things easier and faster for developers with different skills. Add metaboxes, settings pages and other enhancements with just a few lines of code.

Visit saltus.dev for more information.

## Version

### Current version [1.3.5] - 2026-06-20

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
      $framework = new \Saltus\WP\Framework\Core( dirname( __FILE__ ) );
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
