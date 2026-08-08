<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Features\AiAssistant\AiClient;
use Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\MCPConfig;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller exposing framework health and MCP runtime metrics.
 * @api
 */
class HealthController extends WP_REST_Controller {
	use \Saltus\WP\Framework\Infrastructure\Services\FilterAwareTrait;

	private string $version;
	private AuditLogger $audit_logger;
	private ?WebMcpPolicy $webmcp;

	public function __construct( string $version, ?AuditLogger $audit_logger = null, ?WebMcpPolicy $webmcp = null ) {
		$this->version      = $version;
		$this->audit_logger = $audit_logger ?? new AuditLogger();
		$this->webmcp       = $webmcp;
		$this->namespace    = MCPConfig::get_namespace();
		$this->rest_base    = 'health';
	}

	/**
	 * Register the health route.
	 */
	public function register_routes(): void {
		/** @var non-falsy-string $namespace */
		$namespace = $this->namespace;
		register_rest_route(
			$namespace,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => [ $this, 'get_item_permissions_check' ],
			]
		);
	}

	/**
	 * Check whether the current user can view framework health.
	 *
	 * @param mixed $request  The REST request.
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to view framework health.', 'saltus-framework' ),
			[
				'status' => 403,
				'hint'   => __( 'Assign the edit_posts capability to your user role, or use an administrator account.', 'saltus-framework' ),
			]
		);
	}

	/**
	 * Return framework health and recent runtime metrics.
	 *
	 * @param mixed $request  The REST request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ): WP_REST_Response {
		$limit   = max( 1, min( 1000, (int) $this->filter( 'saltus/framework/health/audit_sample_size', 100 ) ) );
		$entries = $this->audit_logger->get_recent_entries( $limit );
		$audit   = $this->audit_stats( $entries );

		return rest_ensure_response(
			[
				'status'       => $audit['error_rate'] > 0.1 ? 'degraded' : 'ok',
				'version'      => $this->version,
				'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'abilities'    => [
					'native_api_available' => function_exists( 'wp_register_ability' ),
				],
				'ai'           => [
					'client_available'     => AiClient::is_available(),
					'connectors_available' => function_exists( 'wp_get_connectors' ),
				],
				'audit'        => $audit,
				'webmcp'       => $this->webmcp_stats(),
				'rate_limit'   => [
					'enabled' => (bool) $this->filter( 'saltus/framework/mcp/rate_limit/enabled', true ),
				],
				'cache'        => [
					'enabled' => (bool) $this->filter( 'saltus/framework/mcp/cache/enabled', true ),
				],
			]
		);
	}

	/**
	 * Report the WebMCP browser surface state.
	 *
	 * Registration is opt-in per model and silent when unsupported, which makes
	 * "is it on?" genuinely hard to answer from the outside — the absence of tools
	 * in a browser looks identical whether no model opted in or the browser has no
	 * WebMCP API. This reports the server's half of that so an operator can tell
	 * the two apart.
	 *
	 * @return array<string, mixed>
	 */
	private function webmcp_stats(): array {
		if ( ! $this->webmcp instanceof WebMcpPolicy ) {
			return [
				'available'     => false,
				'frontend'      => false,
				'admin'         => false,
				'enabled_count' => 0,
				'models'        => [],
			];
		}

		$frontend = $this->webmcp->frontend_models();
		$admin    = $this->webmcp->admin_models();
		$enabled  = $this->webmcp->enabled_models();

		return [
			'available'     => $enabled !== [],
			'frontend'      => $frontend !== [],
			'admin'         => $admin !== [],
			'enabled_count' => count( $enabled ),
			'models'        => [
				'frontend' => $frontend,
				'admin'    => $admin,
			],
		];
	}

	/**
	 * Build audit-derived health metrics.
	 *
	 * @param list<array<string, mixed>> $entries  Recent audit rows.
	 * @return array<string, mixed>
	 */
	private function audit_stats( array $entries ): array {
		$total       = count( $entries );
		$error_count = 0;
		$durations   = [];

		foreach ( $entries as $entry ) {
			$status = isset( $entry['status'] ) ? (string) $entry['status'] : '';
			if ( in_array( $status, [ 'error', 'exception' ], true ) ) {
				++$error_count;
			}

			if ( isset( $entry['duration_ms'] ) && is_numeric( $entry['duration_ms'] ) ) {
				$durations[] = (float) $entry['duration_ms'];
			}
		}

		sort( $durations );

		return [
			'enabled'            => (bool) $this->filter( 'saltus/framework/mcp/audit/enabled', true ),
			'sample_size'        => $total,
			'error_count'        => $error_count,
			'error_rate'         => $total > 0 ? $error_count / $total : 0.0,
			'latency_ms'         => [
				'average' => $this->average( $durations ),
				'p95'     => $this->percentile( $durations, 95 ),
				'max'     => $durations === [] ? null : max( $durations ),
			],
			'statuses'           => $this->status_counts( $entries ),
			'recent_entry_limit' => $total,
		];
	}

	/**
	 * Count recent audit statuses.
	 *
	 * @param list<array<string, mixed>> $entries  Recent audit rows.
	 * @return array<string, int>
	 */
	private function status_counts( array $entries ): array {
		$counts = [];

		foreach ( $entries as $entry ) {
			$status = isset( $entry['status'] ) ? (string) $entry['status'] : 'unknown';
			if ( $status === '' ) {
				$status = 'unknown';
			}

			$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;
		}

		ksort( $counts );

		return $counts;
	}

	/**
	 * Calculate an average duration.
	 *
	 * @param list<float> $values  Numeric values.
	 * @return float|null
	 */
	private function average( array $values ): ?float {
		if ( $values === [] ) {
			return null;
		}

		return array_sum( $values ) / count( $values );
	}

	/**
	 * Calculate a nearest-rank percentile.
	 *
	 * @param list<float> $values  Sorted numeric values.
	 * @param int $percentile  Percentile to calculate.
	 * @return float|null
	 */
	private function percentile( array $values, int $percentile ): ?float {
		if ( $values === [] ) {
			return null;
		}

		$rank = (int) ceil( ( $percentile / 100 ) * count( $values ) );
		$rank = max( 1, min( $rank, count( $values ) ) );

		return $values[ $rank - 1 ];
	}
}
