<?php
namespace Saltus\WP\Framework\MCP\Middleware;

use Saltus\WP\Framework\MCP\Audit\AuditEntry;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;

/**
 * Middleware that logs every request and response through the audit trail.
 *
 * Creates an audit entry before dispatch and records it with the result
 * status after the response is available.
 */
class AuditMiddleware implements MiddlewareInterface {

	private AuditLogger $logger;

	public function __construct( AuditLogger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @param RequestContext $context
	 * @param callable $next
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( RequestContext $context, callable $next ) {
		$tool_name = $this->resolve_tool_name( $context );
		$args      = $this->resolve_args( $context );

		$entry = new AuditEntry( $tool_name, $args, $this->identifier() );

		$result = $next( $context );

		if ( is_wp_error( $result ) ) {
			$entry->complete(
				'error',
				(string) $result->get_error_code(),
				(string) $result->get_error_message()
			);
		} else {
			$entry->complete( 'success' );
		}

		$this->logger->record( $entry );

		return $result;
	}

	/**
	 * Resolve the tool or route name for the audit entry.
	 *
	 * @param RequestContext $context
	 * @return string
	 */
	private function resolve_tool_name( RequestContext $context ): string {
		$meta = $context->get_tool_metadata();
		if ( ! empty( $meta['name'] ) && is_string( $meta['name'] ) ) {
			return $meta['name'];
		}

		$route = $context->get_route();
		if ( $route !== '' ) {
			return 'rest:' . $route;
		}

		return 'unknown';
	}

	/**
	 * Resolve the arguments for the audit entry.
	 *
	 * @param RequestContext $context
	 * @return array<string, mixed>
	 */
	private function resolve_args( RequestContext $context ): array {
		$args = $context->get_args();
		if ( ! empty( $args ) ) {
			return $args;
		}

		$request = $context->get_rest_request();
		if ( $request !== null ) {
			return $request->get_params();
		}

		return [];
	}

	/**
	 * Resolve a unique identifier for the current user for audit.
	 *
	 * @return string
	 */
	private function identifier(): string {
		$identifier = \function_exists( 'get_current_user_id' ) ? 'user:' . (int) \get_current_user_id() : 'user:0';
		if ( $identifier === 'user:0' && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$identifier = 'ip:' . \hash( 'sha256', (string) $_SERVER['REMOTE_ADDR'] );
		}

		return $identifier;
	}
}
