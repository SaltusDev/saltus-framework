<?php

namespace Saltus\WP\Framework\Features\WebMcp;

/**
 * Maps an admin screen to the tools that make sense on it.
 *
 * The discovery notes record Shopify registering a uniform tool set on every
 * page and some of those tools breaking where they did not apply. An agent
 * cannot tell a tool that is wrong for the current screen from one that is
 * right, so the wrong ones are simply not offered.
 *
 * Names refer to abilities already registered for MCP/Abilities. A name that no
 * longer resolves is skipped rather than erroring, so removing an ability cannot
 * break admin registration.
 * @api
 */
final class AdminToolSet {

	/**
	 * Tool names permitted on each screen.
	 *
	 * Reads that orient the agent come first, then the writes that screen can
	 * reasonably queue. The post editor gets entry-level operations; settings
	 * gets settings; the review queue gets reads only, because an agent
	 * proposing changes from the screen where a human reviews proposals would
	 * be arguing with itself.
	 *
	 * @var array<string, list<string>>
	 */
	private const SCREENS = [
		AdminScreen::POST_EDITOR  => [
			'get_post',
			'get_model',
			'get_context',
			'get_meta_fields',
			'list_terms',
			'update_post',
			'update_meta_fields',
			'create_term',
		],
		AdminScreen::POST_LIST    => [
			'list_posts',
			'list_models',
			'get_model',
			'get_context',
			'list_terms',
			'create_post',
			'duplicate_post',
			'reorder_posts',
		],
		AdminScreen::SETTINGS     => [
			'get_settings',
			'get_model',
			'list_models',
			'update_settings',
		],
		AdminScreen::REVIEW_QUEUE => [
			'get_post',
			'get_model',
			'get_health',
		],
	];

	/**
	 * Tool names available on a screen.
	 *
	 * @param string|null $screen Screen identifier from AdminScreen.
	 * @return list<string> Tool names, empty when the screen exposes none.
	 */
	public function for_screen( ?string $screen ): array {
		if ( $screen === null ) {
			return [];
		}

		$tools = self::SCREENS[ $screen ] ?? [];

		/**
		 * Filter the tool names offered on one admin screen.
		 *
		 * @param list<string> $tools  Tool names.
		 * @param string       $screen Screen identifier.
		 */
		return $this->accept( apply_filters( 'saltus/framework/webmcp/admin_tools', $tools, $screen ), $tools );
	}

	/**
	 * Every screen that exposes tools.
	 *
	 * @return list<string>
	 */
	public function screens(): array {
		return array_keys( self::SCREENS );
	}

	/**
	 * Narrow a filtered value back to a list of tool names.
	 *
	 * @param mixed        $filtered Filter return value.
	 * @param list<string> $fallback Names to use when unusable.
	 * @return list<string>
	 */
	private function accept( $filtered, array $fallback ): array {
		if ( ! is_array( $filtered ) ) {
			return $fallback;
		}

		$names = [];
		foreach ( $filtered as $name ) {
			if ( is_string( $name ) && trim( $name ) !== '' ) {
				$names[] = trim( $name );
			}
		}

		return $names;
	}
}
