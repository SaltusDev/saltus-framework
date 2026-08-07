<?php

namespace Saltus\WP\Framework\Features\EditorialReview;

use Saltus\WP\Framework\MCP\Audit\AuditEntry;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\Tools\CreateTerm;
use Saltus\WP\Framework\MCP\Tools\DuplicatePost;
use Saltus\WP\Framework\MCP\Tools\ReorderPosts;
use Saltus\WP\Framework\MCP\Tools\RestTool;
use Saltus\WP\Framework\MCP\Tools\UpdateMetaFields;
use Saltus\WP\Framework\MCP\Tools\UpdateSettings;

/** Creates and applies human-reviewed AI changes. */
final class ProposalService {

	private ProposalStore $store;
	private AuditLogger $audit;

	public function __construct( ?ProposalStore $store = null, ?AuditLogger $audit = null ) {
		$this->store = $store ?? new ProposalStore();
		$this->audit = $audit ?? new AuditLogger();
	}

	public function should_queue( string $tool ): bool {
		if ( ! in_array(
			$tool,
			[
				'create_post',
				'update_post',
				'delete_post',
				'create_term',
				'duplicate_post',
				'update_meta_fields',
				'update_settings',
				'reorder_posts',
			],
			true
		) ) {
			return false;
		}
		if ( function_exists( 'apply_filters' ) ) {
			return (bool) apply_filters( 'saltus/framework/editorial_review/require_human_review', true, $tool );
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function propose( string $tool, array $args ): array {
		$actions = [
			'create_post'        => 'create',
			'update_post'        => 'update',
			'delete_post'        => 'delete',
			'create_term'        => 'create_term',
			'duplicate_post'     => 'duplicate',
			'update_meta_fields' => 'update_meta',
			'update_settings'    => 'update_settings',
			'reorder_posts'      => 'reorder',
		];
		$action  = $actions[ $tool ] ?? '';
		$model   = (string) ( $args['post_type'] ?? 'posts' );
		$post_id = (int) ( $args['post_id'] ?? 0 );

		if ( $post_id > 0 && function_exists( 'get_post' ) ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$model = (string) $post->post_type;
			}
		}

		$id = $this->store->insert(
			[
				'tool'      => $tool,
				'model'     => $model,
				'post_id'   => $post_id,
				'action'    => $action,
				'status'    => 'pending',
				'arguments' => $args,
				'changeset' => $this->changeset( $action, $args ),
			]
		);
		$this->record_audit(
			'proposal_' . $action,
			[
				'proposal_id' => $id,
				'tool'        => $tool,
			],
			$id
		);

		return [
			'proposal_id'     => $id,
			'status'          => 'pending',
			'requires_review' => true,
			'message'         => __( 'The change was queued for editorial review.', 'saltus-framework' ),
		];
	}

	/** @return array<string, mixed>|null */
	public function get( int $id ): ?array {
		return $this->store->get( $id );
	}

	/** @return list<array<string, mixed>> */
	public function list( string $status = '', int $limit = 50 ): array {
		return $this->store->list( $status, $limit );
	}

	/** @return array<string, mixed>|\WP_Error */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Approval validates state, applies the mutation, and records its audit outcome.
	public function approve( int $id, string $note = '' ) {
		$proposal = $this->store->get( $id );
		if ( ! $proposal ) {
			return new \WP_Error( 'proposal_not_found', __( 'Proposal not found.', 'saltus-framework' ), [ 'status' => 404 ] );
		}
		if ( ( $proposal['status'] ?? '' ) !== 'pending' ) {
			return new \WP_Error( 'proposal_not_pending', __( 'Only pending proposals can be approved.', 'saltus-framework' ), [ 'status' => 409 ] );
		}

		$args   = is_array( $proposal['arguments'] ?? null ) ? $proposal['arguments'] : [];
		$result = $this->apply( (string) $proposal['action'], $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result_id = is_int( $result )
			? $result
			: ( is_array( $result ) ? (int) ( $result['post_id'] ?? $result['id'] ?? $proposal['post_id'] ?? 0 ) : (int) ( $proposal['post_id'] ?? 0 ) );
		$this->store->update(
			$id,
			[
				'status'         => 'approved',
				'reviewed_at'    => gmdate( 'Y-m-d H:i:s' ),
				'reviewer_id'    => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				'review_note'    => $note,
				'result_post_id' => $result_id,
			]
		);
		$this->record_audit(
			'proposal_approved',
			[
				'proposal_id'    => $id,
				'result_post_id' => $result_id,
			],
			$id
		);

		$proposal['status']         = 'approved';
		$proposal['result_post_id'] = $result_id;
		return $proposal;
	}

	/** @return array<string, mixed>|\WP_Error */
	public function reject( int $id, string $note = '' ) {
		$proposal = $this->store->get( $id );
		if ( ! $proposal ) {
			return new \WP_Error( 'proposal_not_found', __( 'Proposal not found.', 'saltus-framework' ), [ 'status' => 404 ] );
		}
		if ( ( $proposal['status'] ?? '' ) !== 'pending' ) {
			return new \WP_Error( 'proposal_not_pending', __( 'Only pending proposals can be rejected.', 'saltus-framework' ), [ 'status' => 409 ] );
		}

		$this->store->update(
			$id,
			[
				'status'      => 'rejected',
				'reviewed_at' => gmdate( 'Y-m-d H:i:s' ),
				'reviewer_id' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				'review_note' => $note,
			]
		);
		$this->record_audit( 'proposal_rejected', [ 'proposal_id' => $id ], $id );
		$proposal['status'] = 'rejected';
		return $proposal;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function changeset( string $action, array $args ): array {
		$fields    = [ 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status' ];
		$out       = [];
		$post_args = $this->post_args( $args );
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $post_args ) ) {
				$out[ $field ] = $post_args[ $field ];
			}
		}
		if ( in_array( $action, [ 'create', 'update' ], true ) ) {
			$out['post_status'] = 'publish';
		}
		if ( ! in_array( $action, [ 'create', 'update', 'delete' ], true ) ) {
			$out = $args;
		}
		return [
			'action' => $action,
			'before' => $this->current_values( (int) ( $args['post_id'] ?? 0 ), $fields ),
			'after'  => $out,
		];
	}

	/**
	 * @param list<string> $fields
	 * @return array<string, mixed>
	 */
	private function current_values( int $post_id, array $fields ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_post' ) ) {
			return [];
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}
		$out = [];
		foreach ( $fields as $field ) {
			if ( isset( $post->$field ) ) {
				$out[ $field ] = $post->$field;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return int|true|array<string, mixed>|\WP_Error
	 */
	private function apply( string $action, array $args ) {
		if ( $action === 'create' ) {
			$post_args                = $this->post_args( $args );
			$post_args['post_status'] = 'publish';
			return wp_insert_post( $post_args, true );
		}
		if ( $action === 'update' ) {
			$post_id             = (int) ( $args['post_id'] ?? 0 );
			$args                = $this->post_args( $args );
			$args['ID']          = $post_id;
			$args['post_status'] = 'publish';
			unset( $args['post_id'] );
			return wp_update_post( $args, true );
		}
		if ( $action === 'delete' ) {
			$deleted = wp_delete_post( (int) ( $args['post_id'] ?? 0 ), false );
			if ( $deleted ) {
				return true;
			}
			return new \WP_Error( 'proposal_apply_failed', __( 'The proposed deletion could not be applied.', 'saltus-framework' ), [ 'status' => 500 ] );
		}
		if ( in_array( $action, [ 'create_term', 'duplicate', 'update_meta', 'update_settings', 'reorder' ], true ) ) {
			return $this->apply_rest_mutation( $action, $args );
		}
		return new \WP_Error( 'proposal_action_invalid', __( 'The proposal action is invalid.', 'saltus-framework' ), [ 'status' => 400 ] );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|true|\WP_Error
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- REST application validates dispatch availability, response shape, and status.
	private function apply_rest_mutation( string $action, array $args ) {
		if ( ! function_exists( 'rest_do_request' ) ) {
			return new \WP_Error( 'rest_unavailable', __( 'WordPress REST dispatch is not available.', 'saltus-framework' ), [ 'status' => 503 ] );
		}
		$tool = $this->rest_tool( $action );
		if ( $tool === null ) {
			return new \WP_Error( 'proposal_action_invalid', __( 'The proposal action is invalid.', 'saltus-framework' ), [ 'status' => 400 ] );
		}
		$request = $tool->build_rest_request( $args );
		if ( $request === null ) {
			return new \WP_Error( 'proposal_request_invalid', __( 'The proposal REST request could not be built.', 'saltus-framework' ), [ 'status' => 400 ] );
		}
		$response = $this->dispatch_rest_request( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response instanceof \WP_REST_Response ) {
			return new \WP_Error( 'proposal_apply_failed', __( 'The proposal returned an invalid REST response.', 'saltus-framework' ), [ 'status' => 500 ] );
		}
		if ( (int) $response->get_status() >= 400 ) {
			$data = $response->get_data();
			return new \WP_Error(
				is_array( $data ) ? (string) ( $data['code'] ?? 'proposal_apply_failed' ) : 'proposal_apply_failed',
				is_array( $data ) ? (string) ( $data['message'] ?? __( 'The proposal could not be applied.', 'saltus-framework' ) ) : __( 'The proposal could not be applied.', 'saltus-framework' ),
				[ 'status' => (int) $response->get_status() ]
			);
		}
		$data = $response->get_data();
		return is_array( $data ) ? $data : true;
	}

	private function rest_tool( string $action ): ?RestTool {
		return [
			'create_term'     => new CreateTerm(),
			'duplicate'       => new DuplicatePost(),
			'update_meta'     => new UpdateMetaFields(),
			'update_settings' => new UpdateSettings(),
			'reorder'         => new ReorderPosts(),
		][ $action ] ?? null;
	}

	/** @return mixed */
	private function dispatch_rest_request( \WP_REST_Request $request ) {
		return rest_do_request( $request );
	}

	/** @param array<string, mixed> $args */
	private function record_audit( string $tool, array $args, int $id ): void {
		$entry = new AuditEntry( $tool, $args, 'proposal:' . $id );
		$entry->complete( 'success' );
		$this->audit->record( $entry );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function post_args( array $args ): array {
		$map = [
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'slug'    => 'post_name',
			'status'  => 'post_status',
		];
		$out = [];
		foreach ( $args as $key => $value ) {
			$out[ $map[ $key ] ?? $key ] = $value;
		}
		return $out;
	}
}
