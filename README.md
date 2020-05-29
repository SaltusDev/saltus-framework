You can use this framework in your plugin to easily create Custom Post Types, Taxonomies, Metaboxes and Settings pages.

Refer to the [Framework Demo](https://github.com/SaltusDev/framework-demo) for an example.

How to initialize the framework once it’s included in your plugin:

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

When the framework is included in a plugin, it will search for a model file, by default in the folder `src/models`. This can be changed using a filter.

A model file should return one array or a multidimensional array of models. Simple example:

    return [
        'type'     => 'cpt',
        'name'     => 'movie',
        ];

Several models in same file:

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

### Available Filters/Hooks

(More Info Soon)

### How to include it in your plugin

(More Info Soon)

#### Credits and Licenses:

Includes a simplified version of SoberWP/Models. Their license is in lib/sobwewp/models/LICENSE.md. Is used to load php/json/yaml models of CPT.

Includes support for [github-updater](https://github.com/afragen/github-updater) to keep track on updates through the WordPress backend.
* Download [github-updater](https://github.com/afragen/github-updater)
* Clone [github-updater](https://github.com/afragen/github-updater) to your sites plugins/ folder
* Activate via WordPress
