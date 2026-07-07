<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool to update meta fields for a specific post.
 */
class UpdateMetaFields extends RestTool {

	private MetaFieldProvider $meta_field_provider;

	/**
	 * @param MetaFieldProvider|null $meta_field_provider Shared meta field provider.
	 */
	public function __construct( ?MetaFieldProvider $meta_field_provider = null ) {
		$this->meta_field_provider = $meta_field_provider ?? new MetaFieldProvider();
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'update_meta_fields';
	}

	/**
	 * Get the tool description for the AI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'Update meta fields for a specific post of a registered Saltus post type';
	}

	/**
	 * Get the JSON Schema for tool parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_parameters(): array {
		return [
			'post_id'   => [
				'type'        => 'number',
				'description' => 'The post ID to update meta fields for',
				'required'    => true,
			],
			'post_type' => [
				'type'        => 'string',
				'description' => 'The post type slug',
				'required'    => true,
			],
			'meta'      => [
				'type'                 => 'object',
				'description'          => 'Meta fields to update as key-value pairs',
				'required'             => true,
				'additionalProperties' => true,
			],
		];
	}

	/**
	 * Get the capability requirement for this tool.
	 *
	 * @return RestCapabilityRequirement|null
	 */
	public function get_rest_capability(): ?RestCapabilityRequirement {
		return new RestCapabilityRequirement( ModelRestPolicy::CAPABILITY_META, 'post_type' );
	}

	/**
	 * Build the WP_REST_Request for updating meta fields.
	 *
	 * @param array<string, mixed> $args
	 * @return \WP_REST_Request|null
	 */
	public function build_rest_request( array $args ): ?\WP_REST_Request {
		$body = [
			'meta' => is_array( $args['meta'] ?? null ) ? $args['meta'] : [],
		];

		return $this->request(
			'PUT',
			'/saltus-framework/v1/meta/' . rawurlencode( (string) ( $args['post_type'] ?? '' ) ) . '/' . (int) ( $args['post_id'] ?? 0 ),
			[],
			$body
		);
	}

	/**
	 * Update meta fields directly through the shared feature provider.
	 *
	 * @param Modeler $modeler The model registry.
	 * @param ModelRestPolicy|null $policy Optional REST policy.
	 * @param string $post_type Post type slug.
	 * @param int $post_id Post ID.
	 * @param array<string, mixed> $meta Meta fields to update.
	 * @return array{post_id: int, post_type: string, meta: array<string, mixed>}|\WP_Error
	 */
	public function update_meta_fields( Modeler $modeler, ?ModelRestPolicy $policy, string $post_type, int $post_id, array $meta ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== $post_type ) {
			return new \WP_Error(
				'rest_post_invalid_id',
				__( 'Invalid post ID or post type mismatch.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		$meta_fields_info = $this->meta_field_provider->post_type_meta( $modeler, $policy, $post_type );
		if ( is_wp_error( $meta_fields_info ) ) {
			return $meta_fields_info;
		}

		$meta_key_lookup = $this->buildMetaKeyLookup( $meta_fields_info );
		$updated         = $this->applyMetaUpdates( $post_id, $meta, $meta_key_lookup );

		return [
			'post_id'   => $post_id,
			'post_type' => $post_type,
			'meta'      => $updated,
		];
	}

	/**
	 * @param array<string, mixed> $meta_fields_info
	 * @return array{0: string[], 1: array<string, bool>}
	 */
	private function buildMetaKeyLookup( array $meta_fields_info ): array {
		$rest_meta_keys = [];
		if ( isset( $meta_fields_info['normalized']['rest_meta_keys'] ) && is_array( $meta_fields_info['normalized']['rest_meta_keys'] ) ) {
			$rest_meta_keys = $meta_fields_info['normalized']['rest_meta_keys'];
		}

		$valid_keys     = [];
		$serialized_map = [];
		foreach ( $rest_meta_keys as $meta_key_info ) {
			if ( ! isset( $meta_key_info['meta_key'] ) ) {
				continue;
			}
			$key          = (string) $meta_key_info['meta_key'];
			$valid_keys[] = $key;
			if ( ! empty( $meta_key_info['serialized'] ) ) {
				$serialized_map[ $key ] = true;
			}
		}

		return [ $valid_keys, $serialized_map ];
	}

	/**
	 * @param array<string, mixed> $meta_data
	 * @param array{0: string[], 1: array<string, bool>} $meta_key_lookup
	 * @return array<string, mixed>
	 */
	private function applyMetaUpdates( int $post_id, array $meta_data, array $meta_key_lookup ): array {
		[ $valid_keys, $serialized_map ] = $meta_key_lookup;

		$updated = [];
		foreach ( $meta_data as $key => $value ) {
			if ( ! in_array( (string) $key, $valid_keys, true ) ) {
				continue;
			}

			if ( isset( $serialized_map[ $key ] ) ) {
				$existing = get_post_meta( $post_id, $key, true );
				if ( ! is_array( $existing ) ) {
					$existing = [];
				}
				$new_value    = is_array( $value ) ? $value : [];
				$merged_value = array_replace_recursive( $existing, $new_value );
				update_post_meta( $post_id, $key, $merged_value );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
			$updated[ $key ] = get_post_meta( $post_id, $key, true );
		}

		return $updated;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return bool
	 */
	public function has_permission( array $args ): bool {
		return $this->can_post( 'edit_post', $args );
	}
}
