<?php
namespace Saltus\WP\Framework\Features\Settings;

/**
 * Shared feature-layer access to Saltus per-post-type settings.
 * @api
 */
class SettingsManager {

	/**
	 * Build the option name for a given post type.
	 *
	 * @param string $post_type The post type slug.
	 * @return string
	 */
	public function option_name( string $post_type ): string {
		return sprintf( 'saltus_framework_settings_%s', $post_type );
	}

	/**
	 * Retrieve settings for a post type.
	 *
	 * @param string $post_type The post type slug.
	 * @return array{post_type: string, settings: mixed}
	 */
	public function get_settings( string $post_type ): array {
		return [
			'post_type' => $post_type,
			'settings'  => get_option( $this->option_name( $post_type ), [] ),
		];
	}

	/**
	 * Update settings for a post type.
	 *
	 * @param string $post_type The post type slug.
	 * @param array<string, mixed> $settings Raw settings payload.
	 * @return array{post_type: string, settings: array<string, mixed>, status: string}|\WP_Error
	 */
	public function update_settings( string $post_type, array $settings ) {
		if ( empty( $settings ) ) {
			return new \WP_Error(
				'rest_empty_data',
				__( 'No settings data provided.', 'saltus-framework' ),
				[ 'status' => 400 ]
			);
		}

		$sanitized = [];
		foreach ( $settings as $key => $value ) {
			$sanitized[ preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $key ) ] = $this->sanitize_setting_value( $value );
		}

		$option_name = $this->option_name( $post_type );
		$current     = get_option( $option_name );

		if ( $current === $sanitized ) {
			return [
				'post_type' => $post_type,
				'settings'  => $sanitized,
				'status'    => 'unchanged',
			];
		}

		$updated = update_option( $option_name, $sanitized );

		if ( ! $updated ) {
			$recheck = get_option( $option_name );
			if ( $recheck !== $sanitized ) {
				return new \WP_Error(
					'rest_update_failed',
					__( 'Failed to update settings.', 'saltus-framework' ),
					[ 'status' => 500 ]
				);
			}
		}

		return [
			'post_type' => $post_type,
			'settings'  => $sanitized,
			'status'    => 'updated',
		];
	}

	/**
	 * Sanitize a setting value while preserving structured data.
	 *
	 * @param mixed $value Raw setting value.
	 * @return mixed
	 */
	public function sanitize_setting_value( $value ) {
		$value = wp_unslash( $value );

		if ( is_array( $value ) ) {
			$sanitized = [];
			foreach ( $value as $key => $child ) {
				$sanitized_key               = is_int( $key ) ? $key : preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $key );
				$sanitized[ $sanitized_key ] = $this->sanitize_setting_value( $child );
			}
			return $sanitized;
		}

		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || $value === null ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}
}
