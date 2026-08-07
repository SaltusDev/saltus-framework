<?php

namespace Saltus\WP\Framework\WebMcp\Tools;

use Saltus\WP\Framework\Features\WebMcp\PublicFieldFilter;
use Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\WebMcp\WebMcpAnnotated;

/**
 * Base class for public, read-only WebMCP tools.
 *
 * These tools are callable by an unauthenticated visitor's in-browser agent,
 * so every one of them:
 *
 * - reads only `publish`-status posts of publicly queryable post types,
 * - restricts post types to models that opted into frontend WebMCP,
 * - returns only meta fields `PublicFieldFilter` deems public,
 * - never mutates anything.
 *
 * The permission check deliberately returns true: these tools expose data a
 * visitor could already read by browsing the site. Scoping is enforced by the
 * policy and by the query guards below, not by a capability.
 * @api
 */
abstract class PublicTool implements ToolInterface, WebMcpAnnotated {

	/** Maximum posts returned by any single call. */
	protected const MAX_RESULTS = 20;

	/** Default posts returned when the caller does not specify. */
	protected const DEFAULT_RESULTS = 10;

	protected Modeler $modeler;
	protected WebMcpPolicy $policy;
	protected PublicFieldFilter $fields;

	public function __construct( Modeler $modeler, WebMcpPolicy $policy, ?PublicFieldFilter $fields = null ) {
		$this->modeler = $modeler;
		$this->policy  = $policy;
		$this->fields  = $fields ?? new PublicFieldFilter();
	}

	/**
	 * Public read tools are read-only and return user-generated content.
	 *
	 * @return array<string, bool>
	 */
	public function get_annotations(): array {
		return [
			'readOnlyHint'         => true,
			'untrustedContentHint' => true,
		];
	}

	/**
	 * Execute the tool.
	 *
	 * @param array<string, mixed> $args Validated arguments.
	 * @return array<string, mixed> Result payload.
	 */
	abstract public function execute( array $args ): array;

	/**
	 * Public tools require no capability.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	public function has_permission( array $args ): bool {
		return true;
	}

	/**
	 * Resolve the post types a call may touch.
	 *
	 * When the caller names a post type, it is honored only if that model
	 * enabled frontend WebMCP. Otherwise every enabled model is searched.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return list<string> Post type slugs.
	 */
	protected function resolve_post_types( array $args ): array {
		$enabled = $this->policy->frontend_models();
		if ( $enabled === [] ) {
			return [];
		}

		$requested = isset( $args['post_type'] ) && is_string( $args['post_type'] ) ? $args['post_type'] : '';
		if ( $requested === '' ) {
			return $enabled;
		}

		return in_array( $requested, $enabled, true ) ? [ $requested ] : [];
	}

	/**
	 * Clamp a caller-supplied limit into the allowed range.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	protected function resolve_limit( array $args ): int {
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_RESULTS;
		if ( $limit < 1 ) {
			return self::DEFAULT_RESULTS;
		}

		return min( $limit, self::MAX_RESULTS );
	}

	/**
	 * Build the immutable portion of every public query.
	 *
	 * `post_status` is pinned to `publish` and password-protected posts are
	 * excluded, so no draft, pending, private, or gated content can surface.
	 *
	 * @param list<string> $post_types Post types to query.
	 * @param int          $limit      Result count.
	 * @return array<string, mixed> WP_Query arguments.
	 */
	protected function base_query( array $post_types, int $limit ): array {
		return [
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => $limit,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];
	}

	/**
	 * Summarize a post for a list response.
	 *
	 * @param \WP_Post $post Post to summarize.
	 * @return array<string, mixed>
	 */
	protected function summarize( \WP_Post $post ): array {
		return [
			'id'        => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'title'     => (string) $post->post_title,
			'excerpt'   => $this->excerpt( $post ),
			'permalink' => (string) get_permalink( $post ),
			'date'      => (string) $post->post_date,
		];
	}

	/**
	 * Resolve a plain-text excerpt, falling back to trimmed content.
	 *
	 * @param \WP_Post $post Post to read.
	 */
	protected function excerpt( \WP_Post $post ): string {
		$excerpt = trim( (string) $post->post_excerpt );
		if ( $excerpt !== '' ) {
			return $excerpt;
		}

		$content = trim( wp_strip_all_tags( (string) $post->post_content ) );
		if ( strlen( $content ) <= 200 ) {
			return $content;
		}

		$clipped = substr( $content, 0, 200 );
		$break   = strrpos( $clipped, ' ' );

		return ( $break === false ? $clipped : substr( $clipped, 0, $break ) ) . '…';
	}

	/**
	 * Whether a post is publicly readable and in an enabled model.
	 *
	 * @param \WP_Post|null $post Post to check.
	 */
	protected function is_public_post( ?\WP_Post $post ): bool {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( (string) $post->post_status !== 'publish' ) {
			return false;
		}

		if ( trim( (string) $post->post_password ) !== '' ) {
			return false;
		}

		return in_array( (string) $post->post_type, $this->policy->frontend_models(), true );
	}

	/**
	 * Run a query and return its posts.
	 *
	 * @param array<string, mixed> $query WP_Query arguments.
	 * @return list<\WP_Post>
	 */
	protected function query_posts( array $query ): array {
		$results = ( new \WP_Query( $query ) )->posts;
		if ( ! is_array( $results ) ) {
			return [];
		}

		$posts = [];
		foreach ( $results as $post ) {
			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * Get the public taxonomies registered for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return list<string> Taxonomy slugs.
	 */
	protected function public_taxonomies( string $post_type ): array {
		if ( ! function_exists( 'get_object_taxonomies' ) ) {
			return [];
		}

		$public = [];
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $slug => $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}

			$name     = $taxonomy->name !== '' ? $taxonomy->name : (string) $slug;
			$public[] = $name;
		}

		return $public;
	}
}
