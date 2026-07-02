<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller exposing framework health and MCP runtime metrics.
 */
class HealthController extends WP_REST_Controller {

	private const ROUTE_NAMESPACE = 'saltus-framework/v1';

	private string $version;
	private AuditLogger $audit_logger;

	public function __construct( string $version, ?AuditLogger $audit_logger = null ) {
		$this->version      = $version;
		$this->audit_logger = $audit_logger ?? new AuditLogger();
		$this->namespace    = self::ROUTE_NAMESPACE;
		$this->rest_base    = 'health';
	}

	/**
	 * Register the health route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
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
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ): true|WP_Error {
		if ( function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to view framework health.', 'saltus-framework' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Return framework health and recent runtime metrics.
	 *
	 * @param mixed $request  The REST request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ): WP_REST_Response {
		$limit   = max( 1, (int) $this->filter( 'saltus/framework/health/audit_sample_size', 100 ) );
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
				'audit'        => $audit,
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
			if ( in_array( $status, [ 'error', 'validation_error', 'rate_limited', 'exception' ], true ) ) {
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

	/**
	 * Apply a WordPress filter, falling back to the default outside WordPress.
	 *
	 * @param non-empty-string $hook  Filter hook.
	 * @param mixed $value  Default value.
	 * @return mixed
	 */
	private function filter( string $hook, mixed $value ): mixed {
		if ( function_exists( 'apply_filters' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Health filter names are internal.
			return apply_filters( $hook, $value );
		}

		return $value;
	}
}
