<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Saltus\WP\Framework\Modeler;

/**
 * REST controller exposing meta field configuration per post type.
 */
class MetaController extends WP_REST_Controller {

	private const ROUTE_NAMESPACE = 'saltus-framework/v1';

	protected Modeler $modeler;
	private ?ModelRestPolicy $policy;

	/**
	 * @param Modeler $modeler  The model registry.
	 * @param ModelRestPolicy|null $policy  Optional REST policy for capability gating.
	 */
	public function __construct( Modeler $modeler, ?ModelRestPolicy $policy = null ) {
		$this->modeler   = $modeler;
		$this->policy    = $policy;
		$this->namespace = self::ROUTE_NAMESPACE;
		$this->rest_base = 'meta';
	}

	/**
	 * Register the REST routes for listing and reading meta fields.
	 */
	public function register_routes(): void {
		if ( $this->namespace === '' ) {
			return;
		}

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_all_items' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
			]
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . $this->rest_base . '/(?P<post_type>[a-z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
				'args'                => [
					'post_type' => [
						'type'        => 'string',
						'required'    => true,
						'description' => 'Post type slug to get meta fields for',
					],
				],
			]
		);
	}

	/**
	 * Check whether the current user can view meta fields.
	 *
	 * @param mixed $request  The REST request.
	 * @return WP_Error|bool
	 */
	public function get_items_permissions_check( $request ): WP_Error|bool {
		$post_type = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'post_type' ) : null;
		$allowed   = is_string( $post_type ) && $post_type !== ''
			? $this->can_view_post_type_meta( $post_type )
			: $this->can_view_any_post_type_meta();

		if ( ! $allowed ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view meta fields.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Get meta field definitions for all post types.
	 *
	 * @param WP_REST_Request $request  The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_all_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_types = [];

		$models = $this->policy
			? $this->policy->get_enabled_models( ModelRestPolicy::CAPABILITY_META, 'post_type' )
			: $this->modeler->get_models();

		foreach ( $models as $post_type => $model ) {
			if ( $model->get_type() !== 'post_type' ) {
				continue;
			}

			if ( ! $this->can_view_post_type_meta( (string) $post_type ) ) {
				continue;
			}

			$args = $this->get_model_args( $model );

			$post_types[] = [
				'post_type'      => (string) $post_type,
				'label_singular' => $args['label_singular'] ?? '',
				'label_plural'   => $args['label_plural'] ?? '',
				'meta'           => $args['meta'] ?? [],
				'normalized'     => $this->normalize_meta_fields( $args['meta'] ?? [] ),
			];
		}

		return rest_ensure_response(
			[
				'post_types' => $post_types,
			]
		);
	}

	/**
	 * Get meta field definitions for a specific post type.
	 *
	 * @param mixed $request  The REST request containing the post_type parameter.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$post_type = $request->get_param( 'post_type' );
		$models    = $this->policy
			? $this->policy->get_enabled_models( ModelRestPolicy::CAPABILITY_META, 'post_type' )
			: $this->modeler->get_models();

		if ( ! isset( $models[ $post_type ] ) ) {
			return new WP_Error(
				'model_not_found',
				__( 'Model not found.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		$model = $models[ $post_type ];
		$args  = $this->get_model_args( $model );

		if ( $model->get_type() !== 'post_type' ) {
			return new WP_Error(
				'invalid_model_type',
				__( 'Meta fields are only available for post type models.', 'saltus-framework' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! isset( $args['meta'] ) || empty( $args['meta'] ) ) {
			return rest_ensure_response(
				[
					'post_type'  => $post_type,
					'meta'       => [],
					'normalized' => $this->normalize_meta_fields( [] ),
				]
			);
		}

		return rest_ensure_response(
			[
				'post_type'  => $post_type,
				'meta'       => $args['meta'],
				'normalized' => $this->normalize_meta_fields( $args['meta'] ),
			]
		);
	}

	/**
	 * Check whether the current user can view meta for any enabled post type.
	 *
	 * @return bool
	 */
	private function can_view_any_post_type_meta(): bool {
		if ( function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' ) ) {
			return true;
		}

		$models = $this->policy
			? $this->policy->get_enabled_models( ModelRestPolicy::CAPABILITY_META, 'post_type' )
			: $this->modeler->get_models();

		foreach ( $models as $post_type => $model ) {
			if ( $model->get_type() !== 'post_type' ) {
				continue;
			}

			if ( $this->can_view_post_type_meta( (string) $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether the current user can view meta for a post type.
	 *
	 * @param string $post_type  Post type slug.
	 * @return bool
	 */
	private function can_view_post_type_meta( string $post_type ): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		return current_user_can( $this->post_type_edit_capability( $post_type ) );
	}

	/**
	 * Resolve the edit capability for a post type.
	 *
	 * @param string $post_type  Post type slug.
	 * @return string
	 */
	private function post_type_edit_capability( string $post_type ): string {
		if ( ! function_exists( 'get_post_type_object' ) ) {
			return 'edit_posts';
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( is_object( $post_type_object ) && isset( $post_type_object->cap->edit_posts ) && is_string( $post_type_object->cap->edit_posts ) ) {
			return $post_type_object->cap->edit_posts;
		}

		return 'edit_posts';
	}

	/**
	 * Get the args array for a given model.
	 *
	 * @param \Saltus\WP\Framework\Models\Model $model  The model to retrieve args for.
	 * @return array<string, mixed>
	 */
	private function get_model_args( $model ): array {
		if ( $this->policy ) {
			return $this->policy->get_model_args( $model );
		}

		if ( method_exists( $model, 'get_args' ) ) {
			$args = $model->get_args();
			return is_array( $args ) ? $args : [];
		}

		return property_exists( $model, 'args' ) && is_array( $model->args ) ? $model->args : [];
	}

	/**
	 * Normalize raw Saltus/Codestar metabox configuration into depth-aware paths.
	 *
	 * @param array<string|int, mixed> $meta
	 * @return array{fields: list<array<string, mixed>>, rest_meta_keys: list<array<string, mixed>>}
	 */
	private function normalize_meta_fields( array $meta ): array {
		$fields         = [];
		$rest_meta_keys = [];

		foreach ( $meta as $box_id => $box ) {
			if ( ! \is_array( $box ) ) {
				continue;
			}

			$data_type        = $box['data_type'] ?? 'unserialize';
			$is_serialized    = $data_type === 'serialize';
			$is_rest_writable = ! empty( $box['register_rest_api'] ) && $box['register_rest_api'] === true;
			$field_groups     = $this->get_field_groups( $box );

			if ( $is_serialized && ! empty( $field_groups ) ) {
				$serialized_fields = [];
				foreach ( $field_groups as $group ) {
					$serialized_fields = \array_merge( $serialized_fields, $group['fields'] );
				}

				$rest_meta_keys[] = $this->build_rest_meta_key(
					(string) $box_id,
					(string) $box_id,
					true,
					$is_rest_writable,
					$box,
					$serialized_fields
				);
			}

			foreach ( $field_groups as $group ) {
				$group_fields = $group['fields'];
				$section_id   = $group['section_id'];
				$section_name = $group['section_title'];

				if ( $is_serialized ) {
					$this->append_normalized_fields(
						$fields,
						$group_fields,
						(string) $box_id,
						(string) $box_id,
						true,
						$is_rest_writable,
						(string) $box_id,
						$section_id,
						$section_name,
						0
					);
					continue;
				}

				foreach ( $group_fields as $field_key => $field ) {
					if ( ! is_array( $field ) || ! $this->is_data_field( $field, $field_key ) ) {
						continue;
					}

					$field_id = $this->get_field_id( $field, $field_key );

					$rest_meta_keys[] = $this->build_rest_meta_key(
						$field_id,
						$field_id,
						false,
						$is_rest_writable,
						$box,
						[ $field ]
					);

					$this->append_normalized_fields(
						$fields,
						[ $field_id => $field ],
						$field_id,
						$field_id,
						false,
						$is_rest_writable,
						(string) $box_id,
						$section_id,
						$section_name,
						0
					);
				}
			}
		}

		return [
			'fields'         => $fields,
			'rest_meta_keys' => $this->dedupe_rest_meta_keys( $rest_meta_keys ),
		];
	}

	/**
	 * Extract field groups from a metabox configuration array.
	 *
	 * @param array<string|int, mixed> $box
	 * @return list<array{fields: array<string|int, mixed>, section_id: string, section_title: string}>
	 */
	private function get_field_groups( array $box ): array {
		$groups = [];

		if ( ! empty( $box['sections'] ) && is_array( $box['sections'] ) ) {
			foreach ( $box['sections'] as $section_key => $section ) {
				if ( ! is_array( $section ) || empty( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
					continue;
				}

				$groups[] = [
					'fields'        => $section['fields'],
					'section_id'    => (string) ( $section['id'] ?? $section_key ),
					'section_title' => (string) ( $section['title'] ?? '' ),
				];
			}
			return $groups;
		}

		if ( ! empty( $box['fields'] ) && is_array( $box['fields'] ) ) {
			$groups[] = [
				'fields'        => $box['fields'],
				'section_id'    => '',
				'section_title' => '',
			];
		}

		return $groups;
	}

	/**
	 * Append normalized field definitions to the list.
	 *
	 * @param list<array<string, mixed>> $normalized  Accumulated normalized fields (passed by reference).
	 * @param array<string|int, mixed>   $fields  Fields to normalize.
	 * @param string $path_prefix  Dot-separated path prefix for nested fields.
	 * @param string $meta_key  The meta key these fields belong to.
	 * @param bool $serialized  Whether the fields are serialized under a single meta key.
	 * @param bool $rest_writable  Whether the fields are writable via REST API.
	 * @param string $metabox_id  The metabox identifier.
	 * @param string $section_id  The section identifier.
	 * @param string $section_title  The section title.
	 * @param int $depth  Current nesting depth.
	 */
	private function append_normalized_fields(
		array &$normalized,
		array $fields,
		string $path_prefix,
		string $meta_key,
		bool $serialized,
		bool $rest_writable,
		string $metabox_id,
		string $section_id,
		string $section_title,
		int $depth
	): void {
		foreach ( $fields as $field_key => $field ) {
			if ( ! is_array( $field ) || ! $this->is_data_field( $field, $field_key ) ) {
				continue;
			}

			$field_id      = $this->get_field_id( $field, $field_key );
			$current_path  = $serialized || $depth > 0 ? $path_prefix . '.' . $field_id : $field_id;
			$codestar_type = (string) ( $field['type'] ?? 'object' );
			$schema_type   = $this->get_schema_type( $codestar_type );
			$schema        = $this->build_field_schema( $field, $schema_type );

			$normalized[] = [
				'path'          => $current_path,
				'field_id'      => $field_id,
				'meta_key'      => $meta_key,
				'label'         => (string) ( $field['title'] ?? $field['label'] ?? '' ),
				'codestar_type' => $codestar_type,
				'type'          => $schema_type,
				'schema'        => $schema,
				'serialized'    => $serialized,
				'writable_rest' => $rest_writable,
				'depth'         => $depth,
				'metabox_id'    => $metabox_id,
				'section_id'    => $section_id,
				'section_title' => $section_title,
				'raw'           => $field,
			];

			if ( ! empty( $field['fields'] ) && is_array( $field['fields'] ) ) {
				$this->append_normalized_fields(
					$normalized,
					$field['fields'],
					$current_path,
					$meta_key,
					$serialized,
					$rest_writable,
					$metabox_id,
					$section_id,
					$section_title,
					$depth + 1
				);
			}
		}
	}

	/**
	 * Check whether a field entry is a data field (has a type and identifier).
	 *
	 * @param array<string|int, mixed> $field  The field configuration.
	 * @param string|int $field_key  The field key.
	 * @return bool
	 */
	private function is_data_field( array $field, $field_key ): bool {
		if ( empty( $field['type'] ) ) {
			return false;
		}

		if ( isset( $field['id'] ) && $field['id'] !== '' ) {
			return true;
		}

		return \is_string( $field_key ) && $field_key !== '';
	}

	/**
	 * Resolve a field identifier from a field config or its key.
	 *
	 * @param array<string|int, mixed> $field  The field configuration.
	 * @param string|int $field_key  The field key.
	 * @return string
	 */
	private function get_field_id( array $field, $field_key ): string {
		return (string) ( $field['id'] ?? $field_key );
	}

	/**
	 * Build a REST meta key definition from a metabox and fields.
	 *
	 * @param string $path  The field path.
	 * @param string $meta_key  The meta key name.
	 * @param bool $serialized  Whether the field is serialized.
	 * @param bool $rest_writable  Whether the field is writable via REST API.
	 * @param array<string|int, mixed> $box  The metabox configuration.
	 * @param array<string|int, mixed> $fields  The field definitions.
	 * @return array<string, mixed>
	 */
	private function build_rest_meta_key(
		string $path,
		string $meta_key,
		bool $serialized,
		bool $rest_writable,
		array $box,
		array $fields
	): array {
		$schema = $serialized
			? [
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => $this->build_schema_properties( $fields ),
			]
			: $this->build_field_schema( is_array( $fields[0] ?? null ) ? $fields[0] : [], $this->get_schema_type( (string) ( $fields[0]['type'] ?? 'object' ) ) );

		return [
			'path'          => $path,
			'meta_key'      => $meta_key,
			'serialized'    => $serialized,
			'writable_rest' => $rest_writable,
			'schema'        => $schema,
			'raw'           => $box,
		];
	}

	/**
	 * Build JSON Schema properties from an array of field definitions.
	 *
	 * @param array<string|int, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function build_schema_properties( array $fields ): array {
		$properties = [];

		foreach ( $fields as $field_key => $field ) {
			if ( ! is_array( $field ) || ! $this->is_data_field( $field, $field_key ) ) {
				continue;
			}

			$field_id    = $this->get_field_id( $field, $field_key );
			$schema_type = $this->get_schema_type( (string) $field['type'] );
			$schema      = $this->build_field_schema( $field, $schema_type );

			if ( ! empty( $field['fields'] ) && is_array( $field['fields'] ) && $schema_type === 'object' ) {
				$schema['properties'] = $this->build_schema_properties( $field['fields'] );
			}

			$properties[ $field_id ] = $schema;
		}

		return $properties;
	}

	/**
	 * Build a JSON Schema snippet from a Codestar field and a schema type string.
	 *
	 * @param array<string|int, mixed> $field  The field configuration.
	 * @param string $schema_type  The resolved JSON Schema type.
	 * @return array<string, mixed>
	 */
	private function build_field_schema( array $field, string $schema_type ): array {
		if ( ! empty( $field['schema'] ) && is_array( $field['schema'] ) ) {
			return $field['schema'];
		}

		$schema = [
			'type' => $schema_type,
		];

		if ( $schema_type === 'array' ) {
			$schema['items'] = [
				'type' => ( ( $field['type'] ?? '' ) === 'repeater' ) ? 'object' : 'string',
			];
		}

		return $schema;
	}

	/**
	 * Map a Codestar field type to a JSON Schema type.
	 *
	 * @param string $codestar_type  The Codestar field type identifier.
	 * @return string
	 */
	private function get_schema_type( string $codestar_type ): string {
		$field_type_map = [
			'number'      => 'number',
			'background'  => 'object',
			'color_group' => 'object',
			'fieldset'    => 'object',
			'group'       => 'object',
			'map'         => 'object',
			'media'       => 'array',
			'select'      => 'array',
			'repeater'    => 'array',
		];

		return $field_type_map[ $codestar_type ] ?? 'string';
	}

	/**
	 * Deduplicate REST meta key definitions by meta_key.
	 *
	 * @param list<array<string, mixed>> $rest_meta_keys
	 * @return list<array<string, mixed>>
	 */
	private function dedupe_rest_meta_keys( array $rest_meta_keys ): array {
		$seen   = [];
		$result = [];

		foreach ( $rest_meta_keys as $rest_meta_key ) {
			$key = (string) $rest_meta_key['meta_key'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$result[]     = $rest_meta_key;
		}

		return $result;
	}
}
