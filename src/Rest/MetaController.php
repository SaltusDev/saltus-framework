<?php

namespace Saltus\WP\Framework\Rest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Saltus\WP\Framework\Modeler;

class MetaController extends WP_REST_Controller {

	private const ROUTE_NAMESPACE = 'saltus-framework/v1';

	protected Modeler $modeler;

	public function __construct( Modeler $modeler ) {
		$this->modeler   = $modeler;
		$this->namespace = self::ROUTE_NAMESPACE;
		$this->rest_base = 'meta';
	}

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

	public function get_items_permissions_check( $request ): WP_Error|true {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view meta fields.', 'saltus-framework' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function get_all_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_types = [];

		foreach ( $this->modeler->get_models() as $post_type => $model ) {
			if ( $model->get_type() !== 'post_type' ) {
				continue;
			}

			$post_types[] = [
				'post_type'      => (string) $post_type,
				'label_singular' => $model->args['label_singular'] ?? '',
				'label_plural'   => $model->args['label_plural'] ?? '',
				'meta'           => $model->args['meta'] ?? [],
				'normalized'     => $this->normalize_meta_fields( $model->args['meta'] ?? [] ),
			];
		}

		return rest_ensure_response(
			[
				'post_types' => $post_types,
			]
		);
	}

	public function get_items( $request ): WP_REST_Response|WP_Error {
		$post_type = $request->get_param( 'post_type' );
		$models    = $this->modeler->get_models();

		if ( ! isset( $models[ $post_type ] ) ) {
			return new WP_Error(
				'model_not_found',
				__( 'Model not found.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		$model = $models[ $post_type ];

		if ( $model->get_type() !== 'post_type' ) {
			return new WP_Error(
				'invalid_model_type',
				__( 'Meta fields are only available for post type models.', 'saltus-framework' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! isset( $model->args['meta'] ) || empty( $model->args['meta'] ) ) {
			return rest_ensure_response(
				[
					'post_type' => $post_type,
					'meta'      => [],
					'normalized' => $this->normalize_meta_fields( [] ),
				]
			);
		}

		return rest_ensure_response(
			[
				'post_type'  => $post_type,
				'meta'       => $model->args['meta'],
				'normalized' => $this->normalize_meta_fields( $model->args['meta'] ),
			]
		);
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
			if ( ! is_array( $box ) ) {
				continue;
			}

			$data_type        = $box['data_type'] ?? 'unserialize';
			$is_serialized    = $data_type === 'serialize';
			$is_rest_writable = ! empty( $box['register_rest_api'] ) && $box['register_rest_api'] === true;
			$field_groups     = $this->get_field_groups( $box );

			if ( $is_serialized && ! empty( $field_groups ) ) {
				$serialized_fields = [];
				foreach ( $field_groups as $group ) {
					$serialized_fields = array_merge( $serialized_fields, $group['fields'] );
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
	 * @param list<array<string, mixed>> $normalized
	 * @param array<string|int, mixed>   $fields
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
	 * @param array<string|int, mixed> $field
	 * @param string|int $field_key
	 */
	private function is_data_field( array $field, $field_key ): bool {
		if ( empty( $field['type'] ) ) {
			return false;
		}

		if ( isset( $field['id'] ) && $field['id'] !== '' ) {
			return true;
		}

		return is_string( $field_key ) && $field_key !== '';
	}

	/**
	 * @param array<string|int, mixed> $field
	 * @param string|int $field_key
	 */
	private function get_field_id( array $field, $field_key ): string {
		return (string) ( $field['id'] ?? $field_key );
	}

	/**
	 * @param array<string|int, mixed> $box
	 * @param array<string|int, mixed> $fields
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
	 * @param array<string|int, mixed> $field
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

	private function get_schema_type( string $codestar_type ): string {
		$field_type_map = [
			'number'     => 'number',
			'background' => 'object',
			'color_group' => 'object',
			'fieldset'   => 'object',
			'group'      => 'object',
			'map'        => 'object',
			'media'      => 'array',
			'select'     => 'array',
			'repeater'   => 'array',
		];

		return $field_type_map[ $codestar_type ] ?? 'string';
	}

	/**
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
