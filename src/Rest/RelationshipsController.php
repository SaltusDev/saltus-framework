<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Features\Relationships\RelationshipDefinition;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\MCP\MCPConfig;
use Saltus\WP\Framework\Modeler;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST surface for reading and writing post relationships.
 *
 * Reads require the post type's read-level edit capability; writes are checked
 * against the specific post being changed, so a user able to edit one post
 * cannot rewrite relationships on another. On top of that, a relationship
 * declaring `capabilities` is gated per relationship: a denied read is refused
 * here rather than answered with an empty set, while the definition listing
 * filters, because one denial there would hide every other relationship.
 *
 * @api
 */
class RelationshipsController extends WP_REST_Controller {

	protected Modeler $modeler;
	private ?ModelRestPolicy $policy;
	private RelationshipManager $relationships;

	public function __construct( Modeler $modeler, ?ModelRestPolicy $policy, RelationshipManager $relationships ) {
		$this->modeler       = $modeler;
		$this->policy        = $policy;
		$this->relationships = $relationships;
		$this->namespace     = MCPConfig::get_namespace();
		$this->rest_base     = 'relationships';
	}

	/** Register discovery, read, and write routes. */
	public function register_routes(): void {
		if ( $this->namespace === '' ) {
			return;
		}

		/** @var non-falsy-string $namespace */
		$namespace = $this->namespace;

		register_rest_route(
			$namespace,
			'/' . $this->rest_base . '/(?P<post_type>[a-z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_definitions' ],
				'permission_callback' => [ $this, 'get_definitions_permissions_check' ],
				'args'                => [
					'post_type' => [
						'type'        => 'string',
						'required'    => true,
						'description' => 'Post type slug to list relationship definitions for',
					],
				],
			]
		);

		$item = '/posts/(?P<post_id>\d+)/' . $this->rest_base . '/(?P<relationship>[a-z0-9_-]+)';

		register_rest_route(
			$namespace,
			$item,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => $this->item_args(),
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'attach_item' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
					'args'                => array_merge(
						$this->item_args(),
						[
							'related_id' => [
								'type'        => 'integer',
								'required'    => true,
								'description' => 'Post ID to attach',
							],
							'pivot'      => [
								'type'        => 'object',
								'description' => 'Pivot field values declared by the relationship',
							],
							'order'      => [
								'type'        => 'integer',
								'description' => 'Explicit position within the related set',
							],
						]
					),
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'sync_items' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
					'args'                => array_merge(
						$this->item_args(),
						[
							'related_ids' => [
								'type'        => 'array',
								'required'    => true,
								'items'       => [ 'type' => 'integer' ],
								'description' => 'Complete ordered set of related post IDs',
							],
						]
					),
				],
			]
		);

		register_rest_route(
			$namespace,
			$item . '/(?P<related_id>\d+)',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'detach_item' ],
				'permission_callback' => [ $this, 'update_item_permissions_check' ],
				'args'                => array_merge(
					$this->item_args(),
					[
						'related_id' => [
							'type'        => 'integer',
							'required'    => true,
							'description' => 'Post ID to detach',
						],
					]
				),
			]
		);
	}

	/**
	 * Shared route arguments for the per-post routes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function item_args(): array {
		return [
			'post_id'      => [
				'type'        => 'integer',
				'required'    => true,
				'description' => 'Post ID that owns the relationship',
			],
			'relationship' => [
				'type'        => 'string',
				'required'    => true,
				'description' => 'Relationship name as declared in the model config',
			],
		];
	}

	/**
	 * List relationship definitions for a post type.
	 *
	 * @param mixed $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_definitions( $request ) {
		$post_type = (string) $request->get_param( 'post_type' );
		$gate      = $this->assert_model_enabled( $post_type );
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		return rest_ensure_response(
			[
				'post_type'     => $post_type,
				'relationships' => $this->relationships->describe( $post_type ),
			]
		);
	}

	/**
	 * List related posts for one post.
	 *
	 * @param mixed $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$post_id      = (int) $request->get_param( 'post_id' );
		$relationship = (string) $request->get_param( 'relationship' );

		$post_type = $this->post_type_of( $post_id );
		if ( $post_type instanceof WP_Error ) {
			return $post_type;
		}

		$definition = $this->resolve_relationship( $post_type, $relationship );
		if ( $definition instanceof WP_Error ) {
			return $definition;
		}

		// Refused rather than filtered to an empty set. A 200 with `related: []` cannot
		// be told apart from a relationship that is genuinely empty, so a caller has no
		// way to learn it was denied. The list route above still filters, because there
		// one denial would hide every other relationship on the post type.
		$denied = $this->relationships->permissions()->reject_denied_read( $definition );
		if ( $denied instanceof WP_Error ) {
			return $denied;
		}

		return rest_ensure_response(
			[
				'post_id'      => $post_id,
				'relationship' => $relationship,
				'related'      => $this->relationships->get_related( $post_id, $post_type, $relationship ),
			]
		);
	}

	/**
	 * Attach one related post.
	 *
	 * @param mixed $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function attach_item( $request ) {
		$post_id      = (int) $request->get_param( 'post_id' );
		$relationship = (string) $request->get_param( 'relationship' );

		$post_type = $this->post_type_of( $post_id );
		if ( $post_type instanceof WP_Error ) {
			return $post_type;
		}

		$gate = $this->resolve_relationship( $post_type, $relationship );
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$pivot = $request->get_param( 'pivot' );
		$order = $request->get_param( 'order' );

		return $this->respond(
			$this->relationships->attach(
				$post_id,
				$post_type,
				$relationship,
				(int) $request->get_param( 'related_id' ),
				is_array( $pivot ) ? $pivot : [],
				$order === null ? null : (int) $order
			)
		);
	}

	/**
	 * Replace the related set for one post.
	 *
	 * @param mixed $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync_items( $request ) {
		$post_id      = (int) $request->get_param( 'post_id' );
		$relationship = (string) $request->get_param( 'relationship' );

		$post_type = $this->post_type_of( $post_id );
		if ( $post_type instanceof WP_Error ) {
			return $post_type;
		}

		$gate = $this->resolve_relationship( $post_type, $relationship );
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$related_ids = $request->get_param( 'related_ids' );
		if ( ! is_array( $related_ids ) ) {
			return new WP_Error(
				'saltus_relationship_invalid_payload',
				__( 'A list of related post ids is required.', 'saltus-framework' ),
				[
					'status' => 400,
					'hint'   => 'Send related_ids as an array of post ids.',
				]
			);
		}

		return $this->respond(
			$this->relationships->sync( $post_id, $post_type, $relationship, array_map( 'intval', array_values( $related_ids ) ) )
		);
	}

	/**
	 * Detach one related post.
	 *
	 * @param mixed $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function detach_item( $request ) {
		$post_id      = (int) $request->get_param( 'post_id' );
		$relationship = (string) $request->get_param( 'relationship' );

		$post_type = $this->post_type_of( $post_id );
		if ( $post_type instanceof WP_Error ) {
			return $post_type;
		}

		$gate = $this->resolve_relationship( $post_type, $relationship );
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		return $this->respond(
			$this->relationships->detach( $post_id, $post_type, $relationship, (int) $request->get_param( 'related_id' ) )
		);
	}

	/**
	 * Whether the caller may read relationship definitions.
	 *
	 * @param mixed $request The REST request.
	 * @return WP_Error|bool
	 */
	public function get_definitions_permissions_check( $request ) {
		$post_type = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'post_type' ) : null;

		return $this->authorize( $this->read_capability( is_string( $post_type ) ? $post_type : '' ) );
	}

	/**
	 * Whether the caller may read a post's relationships.
	 *
	 * @param mixed $request The REST request.
	 * @return WP_Error|bool
	 */
	public function get_items_permissions_check( $request ) {
		$post_id = is_object( $request ) && method_exists( $request, 'get_param' ) ? (int) $request->get_param( 'post_id' ) : 0;
		$post    = $post_id > 0 && function_exists( 'get_post' ) ? get_post( $post_id ) : null;

		if ( $post instanceof \WP_Post ) {
			return $this->authorize( $this->read_capability( (string) $post->post_type ), $post_id );
		}

		return $this->authorize( 'edit_posts' );
	}

	/**
	 * Whether the caller may change this specific post's relationships.
	 *
	 * @param mixed $request The REST request.
	 * @return WP_Error|bool
	 */
	public function update_item_permissions_check( $request ) {
		$post_id = is_object( $request ) && method_exists( $request, 'get_param' ) ? (int) $request->get_param( 'post_id' ) : 0;
		if ( $post_id <= 0 ) {
			return $this->forbidden( 'edit_post' );
		}

		$post       = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		$capability = $post instanceof \WP_Post
			? $this->post_type_capability( (string) $post->post_type, 'edit_post', 'edit_post' )
			: 'edit_post';

		return $this->authorize( $capability, $post_id );
	}

	/**
	 * Approve a capability check, or explain the refusal.
	 *
	 * @return WP_Error|bool
	 */
	private function authorize( string $capability, ?int $post_id = null ) {
		if ( ! function_exists( 'current_user_can' ) ) {
			return $this->forbidden( $capability );
		}

		$allowed = $post_id === null ? current_user_can( $capability ) : current_user_can( $capability, $post_id );

		return $allowed ? true : $this->forbidden( $capability, $post_id );
	}

	private function forbidden( string $capability, ?int $post_id = null ): WP_Error {
		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to manage relationships for this post.', 'saltus-framework' ),
			[
				'status'     => 403,
				'capability' => $capability,
				'post_id'    => $post_id,
				'hint'       => "Assign the '" . $capability . "' capability to your user role, or use an account that can edit this post.",
			]
		);
	}

	/** Capability needed to read relationships of a post type. */
	private function read_capability( string $post_type ): string {
		return $post_type === '' ? 'edit_posts' : $this->post_type_capability( $post_type, 'edit_posts', 'edit_posts' );
	}

	/**
	 * Resolve a registered post type capability, falling back when unavailable.
	 */
	private function post_type_capability( string $post_type, string $capability, string $fallback ): string {
		if ( ! function_exists( 'get_post_type_object' ) ) {
			return $fallback;
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( is_object( $post_type_object ) && isset( $post_type_object->cap->{$capability} ) && is_string( $post_type_object->cap->{$capability} ) ) {
			return $post_type_object->cap->{$capability};
		}

		return $fallback;
	}

	/**
	 * Resolve the post type of a post, or explain why it cannot be used.
	 *
	 * @return string|WP_Error
	 */
	private function post_type_of( int $post_id ) {
		if ( $post_id <= 0 || ! function_exists( 'get_post' ) ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'A valid post id is required.', 'saltus-framework' ),
				[
					'status' => 404,
					'hint'   => 'Pass the id of an existing post.',
				]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'The post does not exist.', 'saltus-framework' ),
				[
					'status' => 404,
					'hint'   => 'Pass the id of an existing post.',
				]
			);
		}

		return (string) $post->post_type;
	}

	/**
	 * Refuse when the model has relationships disabled for REST.
	 *
	 * @return WP_Error|null
	 */
	private function assert_model_enabled( string $post_type ): ?WP_Error {
		if ( $this->policy === null || $this->policy->is_post_type_enabled( $post_type, ModelRestPolicy::CAPABILITY_RELATIONSHIPS ) ) {
			return null;
		}

		return new WP_Error(
			'model_not_found',
			__( 'Model not found.', 'saltus-framework' ),
			[
				'status' => 404,
				'hint'   => sprintf(
					/* translators: %s: post type slug */
					__( "Add 'show_in_rest' => true under the 'relationships' section in the model config for '%s' in src/models/.", 'saltus-framework' ),
					$post_type
				),
			]
		);
	}

	/**
	 * Resolve one relationship, or explain why the request cannot proceed.
	 *
	 * Returns the definition rather than just a verdict so the read route can hand it
	 * to the permission policy without resolving it a second time.
	 *
	 * @return RelationshipDefinition|WP_Error
	 */
	private function resolve_relationship( string $post_type, string $relationship ) {
		$gate = $this->assert_model_enabled( $post_type );
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$definition = $this->relationships->get_definition( $post_type, $relationship );
		if ( $definition instanceof RelationshipDefinition ) {
			return $definition;
		}

		return new WP_Error(
			'saltus_relationship_not_found',
			__( 'The relationship is not defined for this post type.', 'saltus-framework' ),
			[
				'status' => 404,
				'hint'   => 'Call GET /' . $this->rest_base . '/' . $post_type . ' to list defined relationships.',
			]
		);
	}

	/**
	 * Pass a manager result through as a REST response.
	 *
	 * @param array<string, mixed>|WP_Error $result Manager result.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( $result ) {
		return $result instanceof WP_Error ? $result : rest_ensure_response( $result );
	}
}
