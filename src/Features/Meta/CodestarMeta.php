<?php
namespace Saltus\WP\Framework\Features\Meta;

use CSF;
use Saltus\WP\Framework\Infrastructure\Service\{
	Processable
};


final class CodestarMeta implements Processable {

	/**
	 * @var string $name The name of the custom post type (CPT)
	 */
	private $name;

	/**
	 * @var array $meta The meta fields
	 */
	private $meta;

	/**
	 * Instantiate the Codestar Framework Fields object.
	 *
	 * @param string  $name The name of the custom post type (CPT)
	 * @param array   $meta Meta fields.
	 */
	public function __construct( string $name, array $meta ) {
		$this->name = $name;
		$this->meta = $meta;
	}

	/**
	 * Process the functionality
	 */
	public function process() {

		/**
		 * Create Metaboxes
		 */
		foreach ( $this->meta as $box_id => $box ) {
			if ( empty( $box['fields'] ) && empty( $box['sections'] ) ) {
				continue;
			}

			// add just the fields and register rest api
			$this->create_metabox( $box_id, $box );
			$this->register_rest_api( $box_id, $box );
		}
	}

	/**
	 * Create metabox
	 *
	 * @param mixed $box_id       Identifier of the metabox
	 * @param array $box_settings Paramaters for the box
	 *
	 */
	private function create_metabox( $box_id, $box_settings ) {

		$default_args = array(
			'post_type' => $this->name,
			'priority'  => 'high',
			'context'   => 'normal',
			'theme'     => 'light',
			'data_type' => 'unserialize',
		);

		$args = array_merge( $default_args, $box_settings );

		if ( ! class_exists( '\CSF' ) ) {
			return;
		}

		// Create options
		\CSF::createMetabox( $box_id, $args );

		if ( isset( $box_settings['fields'] ) ) {
			$box_settings['fields'] = $this->prepare_fields( $box_settings['fields'] );
			$this->create_section( $box_id, $box_settings );
		}

		if ( isset( $box_settings['sections'] ) && is_array( $box_settings['sections'] ) ) {
			foreach ( $box_settings['sections'] as $section ) {
				if ( empty( $section['fields'] ) ) {
					continue;
				}
				$section['fields'] = $this->prepare_fields( $section['fields'] );
				$this->create_section( $box_id, $section );
			}
		}

		// add filter to properly save line breaks in this meta box
		add_filter( sprintf( 'csf_%s_save', $box_id ), array( $this, 'sanitize_meta_save' ), 1, 3 );
	}


	/**
	 * Register REST API
	 *
	 * @param mixed $box_id       Identifier of the metabox
	 * @param array $box_settings Paramaters for the box
	 *
	 */
	private function register_rest_api( $box_id, $box_settings ) {

		if ( empty( $box_settings['register_rest_api'] ) || $box_settings['register_rest_api'] !== true ) {
			return;
		}

		$post_type = $this->name;
		$data_type = $box_settings['data_type'] ?? 'unserialize'; // default

		$process_fields = function ( array $fields, bool $serialized ) use ( $box_id, $post_type ) {
			if ( $serialized ) {
				$this->create_meta_fields_serialized( $fields, $box_id, $post_type );
			} else {
				foreach ( $fields as $meta_name => $meta_fields ) {
					$this->create_meta_fields_not_serialized( $meta_name, $meta_fields, $post_type );
				}
			}
		};

		$serialized = $data_type === 'serialize';
		if ( ! empty( $box_settings['sections'] ) && is_array( $box_settings['sections'] ) ) {
			foreach ( $box_settings['sections'] as $section ) {
				if ( ! empty( $section['fields'] ) ) {
					$process_fields( $section['fields'], $serialized );
				}
			}
		} elseif ( ! empty( $box_settings['fields'] ) ) {
			$process_fields( $box_settings['fields'], $serialized );
		}
	}


	/**
	 * Setup REST API fields
	 *
	 * @param array $fields Fields to be registered
	 *
	 * @return array $rest_fields Fields to be registered in REST API
	 */
	private function setup_restapi_fields( $fields ) {
		$rest_fields = [];
		$rest_types  = $this->match_fields();
		foreach ( $fields as $name => $attributes ) {
			if ( empty( $attributes['type'] ) ) {
				continue;
			}
			$rest_type            = $this->get_field_type( $attributes['type'], $rest_types );
			$rest_fields[ $name ] = [
				'type' => $rest_type,
			];
			if ( $rest_type === 'object' && ! empty( $attributes['fields'] ) ) {
				$rest_properties                    = $this->setup_restapi_fields( $attributes['fields'] );
				$rest_fields[ $name ]['properties'] = $rest_properties;
			}
		}
		return $rest_fields;
	}

	/**
	 * Create meta fields that are not serialized
	 * Hooks into REST API
	 *
	 * @param string $meta_name   Name of the meta field
	 * @param array  $field_args  All the field arguments
	 * @param string $post_type   Post type to register the meta field for
	 */
	private function create_meta_fields_not_serialized( $meta_name, $field_args, $post_type ) {

		$meta_type = is_array( $field_args ) ? ( $field_args['type'] ?? 'object' ) : $field_args;

		$rest_types = $this->match_fields();
		$rest_type  = $this->get_field_type( $meta_type, $rest_types );

		add_action(
			'rest_api_init',
			function () use ( $post_type, $meta_name, $meta_type, $rest_type, $field_args ) {

				$show_in_rest = true;

				if ( ! empty( $field_args['schema'] ) ) {
					$show_in_rest = [
						'schema' => $field_args['schema'],
					];
				} elseif ( $rest_type === 'array' ) {
					$show_in_rest = [
						'schema' => [
							'items' => [
								'type' => ( $meta_type === 'repeater' ) ? 'object' : 'string',
							],
						],
					];
				}

				register_meta(
					'post',
					$meta_name,
					array(
						'object_subtype' => $post_type,
						'type'           => $rest_type,
						'single'         => true,
						'show_in_rest'   => $show_in_rest,
					)
				);
			}
		);
	}

	/**
	 * Create meta fields that are serialized
	 * Hooks into REST API
	 *
	 * @param array  $meta_fields Meta fields to be registered
	 * @param string $meta_name   Name of the meta field
	 * @param string $post_type   Post type to register the meta field for
	 */
	private function create_meta_fields_serialized( $meta_fields, $meta_name, $post_type ) {

		$meta_type = 'object';

		$meta_rest_fields = $this->setup_restapi_fields( $meta_fields );

		add_action(
			'rest_api_init',
			function () use ( $post_type, $meta_name, $meta_type, $meta_rest_fields ) {
				register_meta(
					'post',
					$meta_name,
					array(
						'object_subtype' => $post_type,
						'type'           => $meta_type,
						'single'         => true,
						'show_in_rest'   => [
							'schema' => [
								'type'                 => 'object',
								'additionalProperties' => true, // so it handles old meta
								'properties'           => $meta_rest_fields,
							],
						],
					)
				);
			}
		);
	}

	/**
	 * Match fields to their types
	 *
	 * @return array Array of field types
	 */
	private function match_fields() {

		$field_type_map = [
			'accordion'    => 'string',
			'backup'       => 'string',
			'border'       => 'string',
			'button_set'   => 'string',
			'callback'     => 'string',
			'checkbox'     => 'string',
			'code_editor'  => 'string',
			'color'        => 'string',
			'content'      => 'string',
			'date'         => 'string',
			'datetime'     => 'string',
			'dimensions'   => 'string',
			'gallery'      => 'string',
			'heading'      => 'string',
			'icon'         => 'string',
			'image_select' => 'string',
			'link'         => 'string',
			'link_color'   => 'string',
			'notice'       => 'string',
			'palette'      => 'string',
			'radio'        => 'string',
			'slider'       => 'string',
			'sortable'     => 'string',
			'sorter'       => 'string',
			'spacing'      => 'string',
			'spinner'      => 'string',
			'subheading'   => 'string',
			'submessage'   => 'string',
			'switcher'     => 'string',
			'tabbed'       => 'string',
			'text'         => 'string',
			'textarea'     => 'string',
			'typography'   => 'string',
			'upload'       => 'string',
			'wp_editor'    => 'string',
			'number'       => 'number',
			'background'   => 'object',
			'color_group'  => 'object',
			'fieldset'     => 'object',
			'group'        => 'object',
			'map'          => 'object',
			'media'        => 'array',
			'select'       => 'array',
			'repeater'     => 'array',
		];

		// Include all framework fields

		/** @deprecated 1.2.0 */
		$filtered = apply_filters( 'saltus/cfs/fields', $field_type_map );
		$filtered = apply_filters( 'saltus/framework/meta/matched_fields', $field_type_map );
		if ( ! is_array( $filtered ) ) {
			return [];
		}
		return $filtered;
	}

	/**
	 * Get field type
	 *
	 * @param string     $field  Field name
	 * @param array|null $fields Optional. Fields to match against
	 *
	 * @return string|null Field type or null if not found
	 */
	private function get_field_type( $field, ?array $fields = null ) {
		if ( $fields === null ) {
			$fields = $this->match_fields();
		}

		if ( empty( $fields[ $field ] ) ) {
			return null;
		}
		return $fields[ $field ];
	}

	/**
	 * Create section using builtin Codestart method
	 *
	 * @param string $id      Identifier of the section
	 * @param array  $section Parameters for the section
	 * @return void
	 */
	private function create_section( $id, $section ) {

		if ( ! class_exists( '\CSF' ) ) {
			return;
		}
		\CSF::createSection( $id, $section );
	}

	/**
	 * Prepare fields to make sure they have all necessary parameters
	 *
	 * @param array  $fields  Fields to be prepared
	 *
	 * @return array Array of fields prepared to be rendered by CodestarFields
	 */
	private function prepare_fields( $fields ) {

		foreach ( $fields as $key => &$field ) {

			// Each field array needs to have an id,
			// but only if it wasn't provided
			$field_id = $field['id'] ?? $key;

			$fields[ $key ]['id'] = $field_id;

			if ( isset( $field['fields'] ) ) {
				$field['fields'] = $this->prepare_fields( $field['fields'] );
			}
		}

		// codestar framework 'prefers' keys to be a numeric index, so we return the array_values
		return array_values( $fields );
	}

	/**
	 * Function to sanitize meta on save
	 *
	 * @param $request with meta info
	 * @param $post_id
	 * @param $csf class
	 * @return array
	 */
	public function sanitize_meta_save( $request, $post_id, $csf ) {

		if ( empty( $request ) || ! is_array( $request ) ) {
			return $request;
		}

		//replace line breaks on meta info to make it compatible with export
		array_walk_recursive(
			$request,
			function ( &$value ) {
				$value = str_replace( "\r\n", "\n", $value );
			}
		);

		return $request;
	}
}
