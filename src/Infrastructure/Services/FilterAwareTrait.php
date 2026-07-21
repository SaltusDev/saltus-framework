<?php

namespace Saltus\WP\Framework\Infrastructure\Services;

/**
 * Shared helper for applying WordPress filters with a safe fallback outside WordPress.
 * @api
 */
trait FilterAwareTrait {

	/**
	 * Apply a WordPress filter, falling back to the default value outside WordPress.
	 *
	 * @param non-empty-string $hook  The filter hook name.
	 * @param mixed $value  The value to filter.
	 * @param mixed ...$args  Additional arguments passed to the filter.
	 * @return mixed
	 */
	private function filter( string $hook, $value, ...$args ) {
		if ( function_exists( 'apply_filters' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook names are internal constants passed through this helper.
			return apply_filters( $hook, $value, ...$args );
		}

		return $value;
	}
}
