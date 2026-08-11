<?php
namespace Saltus\WP\Framework\MCP\Tools;

use Saltus\WP\Framework\Features\Meta\FieldEncryptionPolicy;
use Saltus\WP\Framework\Features\Meta\FieldPermissionPolicy;
use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * MCP tool to update meta fields for a specific post.
 */
class UpdateMetaFields extends RestTool {

	private MetaFieldProvider $meta_field_provider;
	private FieldPermissionPolicy $field_permissions;
	private FieldEncryptionPolicy $field_encryption;

	/**
	 * @param MetaFieldProvider|null $meta_field_provider Shared meta field provider.
	 * @param FieldPermissionPolicy|null $field_permissions Shared per-field access policy.
	 * @param FieldEncryptionPolicy|null $field_encryption Shared per-field encryption policy.
	 */
	public function __construct(
		?MetaFieldProvider $meta_field_provider = null,
		?FieldPermissionPolicy $field_permissions = null,
		?FieldEncryptionPolicy $field_encryption = null
	) {
		$this->meta_field_provider = $meta_field_provider ?? new MetaFieldProvider();
		$this->field_permissions   = $field_permissions ?? new FieldPermissionPolicy( $this->meta_field_provider );
		$this->field_encryption    = $field_encryption ?? new FieldEncryptionPolicy( $this->meta_field_provider );
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
			$this->mcp_route( '/meta/' . rawurlencode( (string) ( $args['post_type'] ?? '' ) ) . '/' . (int) ( $args['post_id'] ?? 0 ) ),
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

		// Same policy, same rejection as REST. This method is also what
		// `wp saltus meta update` calls, so MCP and WP-CLI enforce here together
		// rather than each reimplementing the check.
		$denied = $this->field_permissions->reject_denied_write( $modeler, $post_type, $meta );
		if ( $denied instanceof \WP_Error ) {
			return $denied;
		}

		// Encryption runs after the permission check and before storage, so a
		// denied write never reaches the cipher and a permitted one is never
		// stored in plaintext.
		$encrypted = $this->field_encryption->encrypt_payload( $modeler, $post_type, $meta );
		if ( $encrypted instanceof \WP_Error ) {
			return $encrypted;
		}

		$meta_key_lookup = $this->build_meta_key_lookup( $meta_fields_info );
		$updated         = $this->apply_meta_updates( $post_id, $encrypted, $meta_key_lookup );

		// The response reports what the caller set, not the envelope. Returning
		// ciphertext would be useless to a client and would put the stored form in
		// logs and caches.
		foreach ( array_keys( $updated ) as $key ) {
			$updated[ $key ] = $this->field_encryption->decrypt_stored( $modeler, $post_type, (string) $key, $updated[ $key ] );
		}

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
	private function build_meta_key_lookup( array $meta_fields_info ): array {
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
	private function apply_meta_updates( int $post_id, array $meta_data, array $meta_key_lookup ): array {
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
