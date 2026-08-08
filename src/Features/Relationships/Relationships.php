<?php

namespace Saltus\WP\Framework\Features\Relationships;

use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\MCP\Tools\AttachRelated;
use Saltus\WP\Framework\MCP\Tools\DetachRelated;
use Saltus\WP\Framework\MCP\Tools\GetRelated;
use Saltus\WP\Framework\MCP\Tools\ListRelationships;
use Saltus\WP\Framework\MCP\Tools\SyncRelated;
use Saltus\WP\Framework\MCP\Tools\ToolContributor;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RelationshipsController;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;

/**
 * Registers post relationships: storage, REST routes, and MCP tools.
 *
 * Deleting a post clears its relationship rows on `before_delete_post` rather
 * than `deleted_post`, because resolving which rows to cascade needs the post
 * type, which is gone once the post row has been removed.
 *
 * @api
 */
final class Relationships implements Service, Registerable, RestRouteProvider, ToolContributor {

	/** @var callable */
	private $modeler_resolver;

	private RelationshipStore $store;
	private ?RelationshipManager $manager = null;

	/**
	 * @param array<string, mixed>     $dependencies Framework dependencies.
	 * @param RelationshipStore|null   $store        Optional shared store.
	 * @param RelationshipManager|null $manager      Optional preconfigured manager.
	 */
	public function __construct( array $dependencies = [], ?RelationshipStore $store = null, ?RelationshipManager $manager = null ) {
		$resolver               = $dependencies['modeler_resolver'] ?? null;
		$this->modeler_resolver = is_callable( $resolver ) ? $resolver : static function () {
			return null;
		};
		$this->store            = $store ?? new RelationshipStore();
		$this->manager          = $manager;
	}

	public function register(): void {
		add_action( 'before_delete_post', [ $this, 'clean_up_post' ], 10, 1 );
	}

	/**
	 * Remove a deleted post's relationships, cascading where declared.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public function clean_up_post( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$manager = $this->manager();
		if ( ! $manager instanceof RelationshipManager ) {
			return;
		}

		$post      = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		$post_type = $post instanceof \WP_Post ? (string) $post->post_type : '';

		$manager->delete_all_for_post( $post_id, $post_type );
	}

	/**
	 * Shared manager, built from the resolved model registry.
	 *
	 * Returns null before models are loaded so a hook firing early cannot
	 * register a registry over an empty model list and cache it.
	 */
	public function manager(): ?RelationshipManager {
		if ( $this->manager instanceof RelationshipManager ) {
			return $this->manager;
		}

		$modeler = ( $this->modeler_resolver )();
		if ( ! $modeler instanceof Modeler ) {
			return null;
		}

		$this->manager = new RelationshipManager( new RelationshipRegistry( $modeler ), $this->store );

		return $this->manager;
	}

	/** Manager built against an explicitly supplied model registry. */
	private function manager_for( Modeler $modeler ): RelationshipManager {
		if ( ! $this->manager instanceof RelationshipManager ) {
			$this->manager = new RelationshipManager( new RelationshipRegistry( $modeler ), $this->store );
		}

		return $this->manager;
	}

	/** @return list<RestRouteDefinition> */
	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array {
		return [
			new RestRouteDefinition(
				ModelRestPolicy::CAPABILITY_RELATIONSHIPS,
				new RelationshipsController( $modeler, $policy, $this->manager_for( $modeler ) ),
				'post_type'
			),
		];
	}

	/** @return list<ToolInterface> */
	public function get_mcp_tools( Modeler $modeler, ?ModelRestPolicy $policy = null ): array {
		return [
			new ListRelationships(),
			new GetRelated(),
			new AttachRelated(),
			new DetachRelated(),
			new SyncRelated(),
		];
	}
}
