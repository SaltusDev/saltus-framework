<?php

namespace Saltus\WP\Framework\Features\WebMcp;

/**
 * Resolves which admin screen the current request is rendering.
 *
 * Tools are scoped per screen rather than registered as one uniform set. The
 * discovery notes record Shopify shipping the same tools on every page type and
 * nekuda reporting that some of them break where they do not apply — an agent
 * offered `update_settings` while the user edits a post has been handed a
 * plausible-looking wrong move.
 *
 * `get_current_screen()` is the authority when WordPress has built it. It is
 * unavailable before `current_screen` fires and in unit tests, so the request
 * globals are read as a fallback and an explicit context can be injected.
 * @api
 */
final class AdminScreen {

	/** Editing or creating a single entry. */
	public const POST_EDITOR = 'post_editor';

	/** The list table for a post type. */
	public const POST_LIST = 'post_list';

	/** A Saltus settings page. */
	public const SETTINGS = 'settings';

	/** The editorial review queue. */
	public const REVIEW_QUEUE = 'review_queue';

	/** Page slug of the review queue screen registered by EditorialReview. */
	private const REVIEW_QUEUE_PAGE = 'saltus-ai-review';

	/** @var array<string, mixed>|null */
	private ?array $context;

	/**
	 * @param array<string, mixed>|null $context Explicit screen context: `base`,
	 *                                           `id`, `post_type`, `page`.
	 *                                           Null resolves from WordPress.
	 */
	public function __construct( ?array $context = null ) {
		$this->context = $context;
	}

	/**
	 * Identify the current screen.
	 *
	 * @return string|null One of the screen constants, or null when the screen
	 *                     exposes no WebMCP tools.
	 */
	public function resolve(): ?string {
		$context = $this->context();
		$page    = $this->value( $context, 'page' );
		$id      = $this->value( $context, 'id' );
		$base    = $this->value( $context, 'base' );

		if ( $page === self::REVIEW_QUEUE_PAGE || strpos( $id, self::REVIEW_QUEUE_PAGE ) !== false ) {
			return self::REVIEW_QUEUE;
		}

		if ( $base === 'post' || $base === 'post-new' ) {
			return self::POST_EDITOR;
		}

		if ( $base === 'edit' ) {
			return self::POST_LIST;
		}

		// Saltus settings screens are registered as submenu pages, so the base
		// is theme-dependent; the page slug is the stable signal.
		if ( $page !== '' && strpos( $page, 'saltus' ) === 0 ) {
			return self::SETTINGS;
		}

		return null;
	}

	/**
	 * Post type context for the current screen, when it has one.
	 */
	public function post_type(): ?string {
		$post_type = $this->value( $this->context(), 'post_type' );

		return $post_type !== '' ? $post_type : null;
	}

	/**
	 * Resolve the screen context from WordPress, or the injected override.
	 *
	 * @return array<string, mixed>
	 */
	private function context(): array {
		if ( is_array( $this->context ) ) {
			return $this->context;
		}

		$screen = $this->wp_screen();

		return [
			'base'      => $this->screen_property( $screen, 'base' ),
			'id'        => $this->screen_property( $screen, 'id' ),
			'page'      => $this->request_value( 'page' ),
			'post_type' => $this->resolve_post_type( $screen ),
		];
	}

	/**
	 * Read one context value as a string.
	 *
	 * @param array<string, mixed> $context Screen context.
	 * @param string               $key     Key to read.
	 */
	private function value( array $context, string $key ): string {
		$value = $context[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * The current WP_Screen, when WordPress has built one.
	 *
	 * @return object|null
	 */
	private function wp_screen(): ?object {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return null;
		}

		$screen = get_current_screen();

		return is_object( $screen ) ? $screen : null;
	}

	/**
	 * Read a string property from the screen object.
	 *
	 * Uses `get_object_vars()` rather than `property_exists()`, matching how
	 * `ModelRestPolicy` reads model objects: a declared-but-inaccessible property
	 * would satisfy `property_exists()` and then fatal on access.
	 *
	 * @param object|null $screen Screen object.
	 * @param string      $name   Property name.
	 */
	private function screen_property( ?object $screen, string $name ): string {
		if ( $screen === null ) {
			return '';
		}

		$value = get_object_vars( $screen )[ $name ] ?? '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Read a sanitized value from the current admin request.
	 *
	 * Read-only inspection to identify the screen: nothing is persisted or
	 * echoed, so there is no nonce to verify and no unslashing to do.
	 *
	 * @param string $key Query key to read.
	 */
	private function request_value( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen identification only.
		$value = $_GET[ $key ] ?? '';
		if ( ! is_string( $value ) || $value === '' ) {
			return '';
		}

		return function_exists( 'sanitize_key' ) ? sanitize_key( $value ) : $value;
	}

	/**
	 * Resolve the post type in context, from the screen, request, or edited post.
	 *
	 * @param object|null $screen Screen object.
	 */
	private function resolve_post_type( ?object $screen ): string {
		$from_screen = $this->screen_property( $screen, 'post_type' );
		if ( $from_screen !== '' ) {
			return $from_screen;
		}

		$requested = $this->request_value( 'post_type' );
		if ( $requested !== '' ) {
			return $requested;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen identification only.
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( $post_id <= 0 || ! function_exists( 'get_post_type' ) ) {
			return '';
		}

		$post_type = get_post_type( $post_id );

		return is_string( $post_type ) ? $post_type : '';
	}
}
