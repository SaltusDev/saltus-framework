<?php
namespace Saltus\WP\Framework\Features\Meta;

use CSF;
use Saltus\WP\Framework\Infrastructure\Service\{
	Processable
};


final class CodestarMeta implements Processable {

	private $name;
	private $meta;

	/**
	 * Instantiate the Codestar Framework Fields object.
	 */
	public function __construct( string $name, array $project = null, array $meta = array() ) {
		$this->name = $name;
		$this->meta = $meta;
	}

	public function process() {

		/**
		 * Create Metaboxes
		 */
		foreach ( $this->meta as $box_id => $box ) {
			if ( empty( $box['fields'] ) && empty( $box['sections'] ) ) {
				continue;
			}

			// else add just the fields
			$this->create_metabox( $box_id, $box );
		}

	}

	/**
	 * Create metabox
	 *
	 * @param int   $box_id identifier of the metabox
	 * @param array $box_settings paramaters for the page
	 * @return void
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

		if ( isset( $box_settings['sections'] ) ) {
			foreach ( $box_settings['sections'] as $section ) {
				if ( empty( $section['fields'] ) ) {
					continue;
				}
				$section['fields'] = $this->prepare_fields( $section['fields'] );
				$this->create_section( $box_id, $section );
			}
		}

		if ( ! empty( $box_settings['register_rest_api'] ) && $box_settings['register_rest_api'] === true ) {
			if ( ! empty( $box_settings['data_type'] ) && $box_settings['data_type'] === 'serialize' ) {
				$post_type = $this->name;
				if ( ! empty( $box_settings['sections']['details']['fields'] ) ) {
					$this->create_meta_fields_serialized( $box_settings['sections']['details']['fields'], $box_id, $post_type );
				}
			}
			if ( empty( $box_settings['data_type'] ) ||
				( ! empty( $box_settings['data_type'] ) && $box_settings['data_type'] === 'unserialize' ) ) {
				$post_type = $this->name;
				if ( ! empty( $box_settings['sections']['details']['fields'] ) ) {
					foreach ( $box_settings['sections']['details']['fields'] as $meta_name => $want_to_register_fields ) {
						$meta_type = 'object';
						if ( ! empty( $want_to_register_fields['type'] ) ) {
							$meta_type = $want_to_register_fields['type'];
						}
						$this->create_meta_fields_not_serialized( $meta_name, $meta_type, $post_type );
					}
				}
			}
		}

		// add filter to properly save line breaks in this meta box
		add_filter( sprintf( 'csf_%s_save', $box_id ), array( $this, 'sanitize_meta_save' ), 1, 3 );
	}
	private function setup_restapi_fields( $fields ) {
		$rest_fields = [];
		$rest_types = $this->match_fields( $this->list_fields() );
		foreach ( $fields as $name => $attributes ) {
			if ( empty( $attributes['type'] ) ) {
				continue;
			}
			$rest_type = $this->get_field_type( $attributes['type'], $rest_types );
			$rest_fields[ $name ] = [
				'type' => $rest_type
			];
			if ( $rest_type === 'object' && ! empty( $attributes['fields'] ) ) {
				$rest_properties = $this->setup_restapi_fields( $attributes['fields'] );
				$rest_fields[ $name ]['properties'] = $rest_properties;
			}
		}
		return $rest_fields;
	}
	private function create_meta_fields_not_serialized( $meta_name, $meta_type, $post_type ) {

		$rest_types = $this->match_fields( $this->list_fields() );
		$rest_type = $this->get_field_type( $meta_type, $rest_types );

		add_action( 'rest_api_init', function() use ($post_type, $meta_name, $rest_type ) {
			register_meta( 'post', $meta_name, array(
				'object_subtype' => $post_type,
				'type'           => $rest_type,
				'single'         => true,
				'show_in_rest'  => [
					'prepare_callback' => function( $value ) {
						return wp_json_encode( $value );
					}
				],
			));
		});
	}

	private function create_meta_fields_serialized( $meta_fields, $meta_name, $post_type ) {

		$meta_type = 'object';

		$meta_rest_fields = $this->setup_restapi_fields( $meta_fields );

		add_action( 'rest_api_init', function() use ( $post_type, $meta_name, $meta_type, $meta_rest_fields ) {
			register_meta( 'post', $meta_name, array(
				'object_subtype' => $post_type,
				'type'           => $meta_type,
				'single'         => true,
				'show_in_rest'   => [
					'schema' => [
						'type'       => 'object',
						'additionalProperties' => true, // so it handles old meta
						'properties' => $meta_rest_fields,
					],
				],
			));
		});
	}

	private function list_fields() {

		// Include all framework fields
		return apply_filters( 'saltus/cfs/fields', array(
			'accordion',
			'background',
			'backup',
			'border',
			'button_set',
			'callback',
			'checkbox',
			'code_editor',
			'color',
			'color_group',
			'content',
			'date',
			'datetime',
			'dimensions',
			'fieldset',
			'gallery',
			'group',
			'heading',
			'icon',
			'image_select',
			'link',
			'link_color',
			'map',
			'media',
			'notice',
			'number',
			'palette',
			'radio',
			'repeater',
			'select',
			'slider',
			'sortable',
			'sorter',
			'spacing',
			'spinner',
			'subheading',
			'submessage',
			'switcher',
			'tabbed',
			'text',
			'textarea',
			'typography',
			'upload',
			'wp_editor',
		) );
	}
	private function match_fields( $allowed_fields ) {

		$assigned_field_type = [];
		foreach( $allowed_fields as $field ) {
			switch( $field) {
				case 'accordion':
				case 'backup':
				case 'border':
				case 'button_set':
				case 'callback':
				case 'checkbox':
				case 'code_editor':
				case 'color':
				case 'content':
				case 'date':
				case 'datetime':
				case 'dimensions':
				case 'gallery':
				case 'heading':
				case 'icon':
				case 'image_select':
				case 'link':
				case 'link_color':
				case 'media':
				case 'notice':
				case 'palette':
				case 'radio':
				case 'slider':
				case 'sortable':
				case 'sorter':
				case 'spacing':
				case 'spinner':
				case 'subheading':
				case 'submessage':
				case 'switcher':
				case 'tabbed':
				case 'text':
				case 'textarea':
				case 'typography':
				case 'upload':
				case 'wp_editor':
					$assigned_field_type[ $field ] = 'string';
					break;
				case 'number':
					$assigned_field_type[ $field ] = 'number';
					break;
				case 'background':
				case 'color_group':
				case 'fieldset':
				case 'group':
				case 'map':
					$assigned_field_type[ $field ] = 'object';
					break;
				case 'select':
				case 'repeater':
					$assigned_field_type[ $field ] = 'array';
					break;
				default:
					$assigned_field_type[ $field ] = 'string';
					break;
			}
		}
		return $assigned_field_type;
	}

	private function get_field_type( $field, $fields = null ) {
		if ( $fields === null ) {
			$fields = $this->match_fields( $this->list_fields() );
		}
		if ( ! is_array( $fields ) ) {
			return '';
		}
		if ( ! empty( $fields[ $field ] ) ) {
			return $fields[ $field ];
		}
		return null;
	}

	/**
	 * Create section using builtin Codestart method
	 *
	 * @param string $id - identifier of the section
	 * @param array  $section - parameters for the section
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
	 * @param array $fields
	 * @return array $fields array of fields prepared to be rendered by CodestarFields
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
	 * @param array $request with meta info
	 * @param int $post_id
	 * @param obj $csf class
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
