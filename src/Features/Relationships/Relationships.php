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

	/** @var array<string, mixed> */
	private array $project;

	private ?RelationshipMetabox $metabox = null;

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
		$this->project          = is_array( $dependencies['project'] ?? null ) ? $dependencies['project'] : [];
	}

	public function register(): void {
		add_action( 'before_delete_post', [ $this, 'clean_up_post' ], 10, 1 );
		add_action( 'add_meta_boxes', [ $this, 'register_metabox' ], 10, 1 );
		add_action( 'save_post', [ $this, 'save_metabox' ], 10, 1 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_picker' ], 10, 1 );
	}

	/**
	 * Add the relationship picker to a post type that declares relationships.
	 *
	 * @param string $post_type Post type of the screen being rendered.
	 */
	public function register_metabox( string $post_type ): void {
		$metabox = $this->metabox();
		if ( ! $metabox instanceof RelationshipMetabox ) {
			return;
		}

		$metabox->add_meta_boxes( $post_type );
	}

	/**
	 * Persist submitted relationship sets.
	 *
	 * Reading `$_POST` here rather than in `RelationshipMetabox` keeps the
	 * superglobal at the WordPress boundary, so the metabox itself stays a plain
	 * unit-testable object.
	 *
	 * @param int $post_id Post being saved.
	 */
	public function save_metabox( int $post_id ): void {
		$metabox = $this->metabox();
		if ( ! $metabox instanceof RelationshipMetabox ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- RelationshipMetabox::save() verifies the nonce before it writes.
		$metabox->save( $post_id, wp_unslash( $_POST ) );
	}

	/**
	 * Load the picker's assets on a post editor screen that has relationships.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_picker( $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$manager = $this->manager();
		if ( ! $manager instanceof RelationshipManager ) {
			return;
		}

		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type = $screen instanceof \WP_Screen ? (string) $screen->post_type : '';
		if ( $post_type === '' || ! $manager->has_relationships( $post_type ) ) {
			return;
		}

		$root_url = rtrim( (string) ( $this->project['root_url'] ?? '' ), '/' );

		wp_enqueue_style(
			'saltus-relationship-picker',
			$root_url . '/Feature/Relationships/picker.css',
			[],
			\Saltus\WP\Framework\Core::VERSION
		);

		wp_enqueue_script(
			'saltus-relationship-picker',
			$root_url . '/Feature/Relationships/picker.js',
			[],
			\Saltus\WP\Framework\Core::VERSION,
			true
		);

		wp_localize_script(
			'saltus-relationship-picker',
			'saltusRelationships',
			[
				'restRoot'  => rest_url(),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				// A target post type may set its own rest_base, in which case the
				// slug is not the collection path. Resolve it server-side rather
				// than letting the browser guess.
				'restBases' => $this->rest_bases_for( $manager, $post_type ),
				'strings'   => [
					'searching'    => __( 'Searching…', 'saltus-framework' ),
					'noResults'    => __( 'No matches found.', 'saltus-framework' ),
					'oneResult'    => __( '1 match found.', 'saltus-framework' ),
					'manyResults'  => __( 'matches found.', 'saltus-framework' ),
					'searchFailed' => __( 'Search failed.', 'saltus-framework' ),
					'added'        => __( 'Added.', 'saltus-framework' ),
					'removed'      => __( 'Removed.', 'saltus-framework' ),
					'remove'       => __( 'Remove', 'saltus-framework' ),
					'movedUp'      => __( 'Moved up.', 'saltus-framework' ),
					'movedDown'    => __( 'Moved down.', 'saltus-framework' ),
				],
			]
		);
	}

	/**
	 * Map each target post type to its REST collection base.
	 *
	 * @return array<string, string>
	 */
	private function rest_bases_for( RelationshipManager $manager, string $post_type ): array {
		$bases = [];

		foreach ( $manager->describe( $post_type ) as $definition ) {
			$target = isset( $definition['to'] ) ? (string) $definition['to'] : '';
			if ( $target === '' || isset( $bases[ $target ] ) ) {
				continue;
			}

			$object = function_exists( 'get_post_type_object' ) ? get_post_type_object( $target ) : null;
			if ( $object === null ) {
				continue;
			}

			// `rest_base` is `bool|string`: false when the type did not set one.
			$base = is_string( $object->rest_base ) && $object->rest_base !== ''
				? $object->rest_base
				: $target;

			$bases[ $target ] = $base;
		}

		return $bases;
	}

	/** The picker, once a manager can be built. */
	private function metabox(): ?RelationshipMetabox {
		if ( $this->metabox instanceof RelationshipMetabox ) {
			return $this->metabox;
		}

		$manager = $this->manager();
		if ( ! $manager instanceof RelationshipManager ) {
			return null;
		}

		$this->metabox = new RelationshipMetabox( $manager );

		return $this->metabox;
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
