<?php
namespace Saltus\WP\Framework\MCP\Support;

class Json {

	/**
	 * @param mixed $value
	 */
	public static function encode( mixed $value, int $flags = 0 ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $value, $flags );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone MCP CLI can run outside WordPress.
			$encoded = json_encode( $value, $flags );
		}

		return is_string( $encoded ) ? $encoded : '';
	}
}
