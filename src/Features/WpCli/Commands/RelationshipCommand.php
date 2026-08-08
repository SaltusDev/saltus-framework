<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;
use Saltus\WP\Framework\Features\Relationships\RelationshipStore;
use Saltus\WP\Framework\Features\WpCli\CliGateway;

/** Reads and writes post relationships from the command line. */
final class RelationshipCommand extends AbstractCommand {

	private ?RelationshipManager $relationships;

	public function __construct( CliGateway $cli, callable $modeler_resolver, ?RelationshipManager $relationships = null ) {
		parent::__construct( $cli, $modeler_resolver );
		$this->relationships = $relationships;
	}

	/**
	 * List the relationships a post type declares.
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$post_type = sanitize_key( $this->argument( $args, 0, 'post-type' ) );
		$rows      = [];
		foreach ( $this->manager()->describe( $post_type ) as $definition ) {
			$rows[] = [
				'name'     => $definition['name'],
				'type'     => $definition['type'],
				'to'       => $definition['to'],
				'multiple' => $definition['multiple'] ? 'yes' : 'no',
				'inverse'  => $definition['inverse'] ? 'yes' : 'no',
			];
		}

		$this->cli->format_items( $this->format( $assoc_args ), $rows, [ 'name', 'type', 'to', 'multiple', 'inverse' ] );
	}

	/**
	 * Show the posts related to one post.
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function get( array $args, array $assoc_args ): void {
		$post_id      = (int) $this->argument( $args, 0, 'post-id' );
		$relationship = sanitize_key( $this->argument( $args, 1, 'relationship' ) );
		$rows         = [];
		foreach ( $this->manager()->get_related( $post_id, $this->post_type_of( $post_id ), $relationship ) as $related ) {
			$rows[] = [
				'post_id'   => $related['post_id'],
				'post_type' => $related['post_type'],
				'title'     => $related['title'],
				'order'     => $related['order_index'],
				'pivot'     => (string) wp_json_encode( $related['pivot'] ),
			];
		}

		$this->cli->format_items( $this->format( $assoc_args ), $rows, [ 'post_id', 'post_type', 'title', 'order', 'pivot' ] );
	}

	/**
	 * Attach one related post.
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function attach( array $args, array $assoc_args ): void {
		$post_id      = (int) $this->argument( $args, 0, 'post-id' );
		$relationship = sanitize_key( $this->argument( $args, 1, 'relationship' ) );
		$related_id   = (int) $this->argument( $args, 2, 'related-id' );
		$pivot        = isset( $assoc_args['pivot'] ) ? $this->json_object( (string) $assoc_args['pivot'] ) : [];

		$this->ensure_result(
			$this->manager()->attach( $post_id, $this->post_type_of( $post_id ), $relationship, $related_id, $pivot )
		);
		$this->cli->success( 'Attached post ' . $related_id . ' to ' . $relationship . ' on post ' . $post_id . '.' );
	}

	/**
	 * Detach one related post.
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function detach( array $args, array $assoc_args ): void {
		$post_id      = (int) $this->argument( $args, 0, 'post-id' );
		$relationship = sanitize_key( $this->argument( $args, 1, 'relationship' ) );
		$related_id   = (int) $this->argument( $args, 2, 'related-id' );

		$result = $this->ensure_result(
			$this->manager()->detach( $post_id, $this->post_type_of( $post_id ), $relationship, $related_id )
		);

		$this->cli->success(
			is_array( $result ) && ! empty( $result['detached'] )
				? 'Detached post ' . $related_id . ' from ' . $relationship . ' on post ' . $post_id . '.'
				: 'Post ' . $related_id . ' was not attached to ' . $relationship . ' on post ' . $post_id . '.'
		);
	}

	/**
	 * Replace the related set for one post.
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function sync( array $args, array $assoc_args ): void {
		$post_id      = (int) $this->argument( $args, 0, 'post-id' );
		$relationship = sanitize_key( $this->argument( $args, 1, 'relationship' ) );
		$payload      = $this->json_list( $this->argument( $args, 2, 'json|@file' ) );
		$related_ids  = array_map( 'intval', $payload );

		$this->ensure_result(
			$this->manager()->sync( $post_id, $this->post_type_of( $post_id ), $relationship, array_values( $related_ids ) )
		);
		$this->cli->success( 'Synced ' . count( $related_ids ) . ' related posts for ' . $relationship . ' on post ' . $post_id . '.' );
	}

	/** Resolve the post type of a post, failing when it does not exist. */
	private function post_type_of( int $post_id ): string {
		$post = $post_id > 0 && function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			$this->fail( 'Post not found: ' . $post_id );
		}

		/** @var \WP_Post $post Guarded above; fail() throws. */
		return (string) $post->post_type;
	}

	/** Manager built over the resolved model registry. */
	private function manager(): RelationshipManager {
		if ( ! $this->relationships instanceof RelationshipManager ) {
			$this->relationships = new RelationshipManager( new RelationshipRegistry( $this->modeler() ), new RelationshipStore() );
		}

		return $this->relationships;
	}
}
