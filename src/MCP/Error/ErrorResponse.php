<?php
namespace Saltus\WP\Framework\MCP\Error;

/**
 * Factory for standardized WP_Error responses across REST and MCP paths.
 * @api
 */
class ErrorResponse {

	/**
	 * Insufficient permissions.
	 *
	 * @param string $capability  The required capability.
	 * @param string $hint        User-facing resolution hint.
	 * @return \WP_Error
	 */
	public static function forbidden( string $capability = '', string $hint = '' ): \WP_Error {
		$data = [ 'status' => 403 ];
		if ( $hint !== '' ) {
			$data['hint'] = $hint;
		}

		return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to perform this action.', 'saltus-framework' ), $data );
	}

	/**
	 * Resource not found.
	 *
	 * @param string $resource_name  The resource identifier.
	 * @param string $hint           User-facing resolution hint.
	 * @return \WP_Error
	 */
	public static function not_found( string $resource_name = '', string $hint = '' ): \WP_Error {
		$data = [ 'status' => 404 ];
		if ( $hint !== '' ) {
			$data['hint'] = $hint;
		}

		return new \WP_Error(
			$resource_name !== '' ? $resource_name . '_not_found' : 'not_found',
			__( 'Resource not found.', 'saltus-framework' ),
			$data
		);
	}

	/**
	 * Invalid request parameters.
	 *
	 * @param string $field   The invalid field name.
	 * @param string $reason  Why the field is invalid.
	 * @param string $hint    User-facing resolution hint.
	 * @return \WP_Error
	 */
	public static function invalid( string $field = '', string $reason = '', string $hint = '' ): \WP_Error {
		$data = [ 'status' => 400 ];
		if ( $field !== '' ) {
			$data['field'] = $field;
		}
		if ( $reason !== '' ) {
			$data['reason'] = $reason;
		}
		if ( $hint !== '' ) {
			$data['hint'] = $hint;
		}

		return new \WP_Error( 'rest_invalid_param', __( 'Invalid parameter.', 'saltus-framework' ), $data );
	}

	/**
	 * Rate limited.
	 *
	 * @param int $retry_after  Seconds to wait before retrying.
	 * @return \WP_Error
	 */
	public static function rate_limited( int $retry_after = 60 ): \WP_Error {
		return new \WP_Error(
			'rate_limited',
			__( 'Rate limit exceeded.', 'saltus-framework' ),
			[
				'status'      => 429,
				'retry_after' => $retry_after,
			]
		);
	}

	/**
	 * Internal server error.
	 *
	 * @param string $message  Error message.
	 * @param string $hint     User-facing resolution hint.
	 * @return \WP_Error
	 */
	public static function internal_error( string $message = '', string $hint = '' ): \WP_Error {
		$data = [ 'status' => 500 ];
		if ( $hint !== '' ) {
			$data['hint'] = $hint;
		}

		return new \WP_Error( 'rest_internal_error', $message ?: __( 'Internal server error.', 'saltus-framework' ), $data );
	}

	/**
	 * REST dispatch error wrapping an upstream WP_Error.
	 *
	 * @param \WP_Error $error  The upstream error.
	 * @return \WP_Error
	 */
	public static function dispatch_error( \WP_Error $error ): \WP_Error {
		$data               = $error->get_error_data();
		$status             = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		$error_data         = is_array( $data ) ? $data : [ 'status' => $status ];
		$error_data['hint'] = $error_data['hint'] ?? __( 'The upstream REST endpoint returned an error. Check the error code and message for details.', 'saltus-framework' );

		return new \WP_Error(
			$error->get_error_code() ?: 'rest_dispatch_error',
			$error->get_error_message() ?: __( 'REST dispatch error.', 'saltus-framework' ),
			$error_data
		);
	}
}
