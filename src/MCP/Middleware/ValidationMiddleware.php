<?php
namespace Saltus\WP\Framework\MCP\Middleware;

use Saltus\WP\Framework\MCP\Validation\Validator;

/**
 * Middleware that applies JSON Schema-like validation to incoming request arguments.
 *
 * Uses the existing Validator class. For REST requests, it maps route arg
 * definitions to the Validator schema format.
 */
class ValidationMiddleware implements MiddlewareInterface {

	/**
	 * @param RequestContext $context
	 * @param callable $next
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( RequestContext $context, callable $next ) {
		$tool_meta = $context->get_tool_metadata();
		$schema    = [];

		if ( ! empty( $tool_meta ) && isset( $tool_meta['parameters'] ) ) {
			$schema = $tool_meta['parameters'];
		} elseif ( $context->get_rest_request() !== null ) {
			$schema = $this->build_schema_from_route( $context->get_rest_request() );
		}

		if ( empty( $schema ) ) {
			return $next( $context );
		}

		$args  = ! empty( $tool_meta ) ? $context->get_args() : $this->extract_args( $context );
		$valid = Validator::validate( $args, $schema );

		if ( ! $valid['valid'] ) {
			return \Saltus\WP\Framework\MCP\Error\ErrorResponse::invalid(
				'',
				\implode( '; ', $valid['errors'] ),
				\__( 'Check the request parameters and ensure all required fields are present with the correct types.', 'saltus-framework' )
			);
		}

		return $next( $context );
	}

	/**
	 * Build a Validator-compatible schema from a REST request's registered args.
	 *
	 * @param \WP_REST_Request $request
	 * @return array<string, mixed>
	 */
	private function build_schema_from_route( \WP_REST_Request $request ): array {
		$schema   = [];
		$raw_args = $request->get_attributes();
		$args     = $raw_args['args'] ?? [];

		foreach ( $args as $field => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}

			$rules = [];

			if ( ! empty( $config['required'] ) ) {
				$rules['required'] = true;
			}

			if ( ! empty( $config['type'] ) && is_string( $config['type'] ) ) {
				$rules['type'] = $this->map_wp_type( $config['type'] );
			}

			if ( ! empty( $config['enum'] ) && is_array( $config['enum'] ) ) {
				$rules['enum'] = $config['enum'];
			}

			$schema[ $field ] = $rules;
		}

		return $schema;
	}

	/**
	 * Map WordPress REST API type names to Validator types.
	 *
	 * @param string $wp_type
	 * @return string
	 */
	private function map_wp_type( string $wp_type ): string {
		$map = [
			'string'  => 'string',
			'integer' => 'integer',
			'number'  => 'number',
			'boolean' => 'boolean',
			'object'  => 'object',
			'array'   => 'array',
		];

		return $map[ $wp_type ] ?? 'string';
	}

	/**
	 * Extract request arguments from the context.
	 *
	 * @param RequestContext $context
	 * @return array<string, mixed>
	 */
	private function extract_args( RequestContext $context ): array {
		$request = $context->get_rest_request();
		if ( $request === null ) {
			return [];
		}

		return $request->get_params();
	}
}
