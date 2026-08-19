<?php
namespace Saltus\WP\Framework\Features\Observability;

use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\MCP\Audit\RollupStore;

/**
 * REST API endpoints for observability metrics.
 * @api
 */
final class MetricsApi implements Service, Registerable {
	use \Saltus\WP\Framework\Infrastructure\Services\FilterAwareTrait;

	private RollupStore $rollup_store;

	/**
	 * @param RollupStore|null $rollup_store  Optional store, constructed when omitted.
	 */
	public function __construct( ?RollupStore $rollup_store = null ) {
		$this->rollup_store = $rollup_store ?? new RollupStore();
	}

	/**
	 * Register AJAX handlers.
	 */
	public function register(): void {
		add_action( 'wp_ajax_saltus_get_metrics', [ $this, 'handle_get_metrics' ] );
	}

	/**
	 * Handle AJAX request for metrics data.
	 */
	public function handle_get_metrics(): void {
		check_ajax_referer( 'saltus_metrics', 'nonce' );

		// The read sits in the else branch rather than after an early return.
		// `wp_send_json_error()` is declared `never` in the WordPress stubs, so a
		// `return` after it is unreachable code — but relying on that to stop the
		// work means any context where the send does return (a test double, a
		// filtered `wp_die` handler) falls straight through into the query with
		// the permission check already failed. Branching states the denial
		// structurally instead of inheriting it from the stub's exit.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'saltus-framework' ) ] );
		} else {
			$this->send_metrics();
		}
	}

	/**
	 * Read the requested window and send the aggregate payload.
	 *
	 * Only reached from `handle_get_metrics()`, which verifies the nonce and the
	 * `manage_options` capability first. PHPCS reads one method at a time and so
	 * cannot see that check across the call boundary; the request arguments below
	 * are read-only filters that select a date window, never written anywhere.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the only caller.
	 */
	private function send_metrics(): void {
		$range   = isset( $_GET['range'] ) ? (int) $_GET['range'] : 7;
		$ability = isset( $_GET['ability'] ) && is_string( $_GET['ability'] ) ? sanitize_text_field( $_GET['ability'] ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $range <= 0 || $range > 365 ) {
			$range = 7;
		}

		$end_date   = gmdate( 'Y-m-d' );
		$start_time = strtotime( "-{$range} days" );
		$start_date = $start_time !== false ? gmdate( 'Y-m-d', $start_time ) : gmdate( 'Y-m-d', time() - ( $range * 86400 ) );

		$rollups        = $this->rollup_store->get_rollups( $start_date, $end_date, $ability );
		$client_rollups  = $this->rollup_store->get_client_rollups( $start_date, $end_date, $ability );
		$metrics         = $this->compute_aggregates( $rollups );

		wp_send_json_success(
			[
				'metrics'   => array_merge( $metrics, [ 'per_client' => $this->compute_client_aggregates( $client_rollups ) ] ),
				'abilities' => $this->get_distinct_abilities( $rollups ),
			]
		);
	}

	/**
	 * Compute aggregate metrics from rollups.
	 *
	 * @param list<\Saltus\WP\Framework\MCP\Audit\DailyRollup> $rollups
	 * @return array<string, mixed>
	 */
	private function compute_aggregates( array $rollups ): array {
		$total_calls    = 0;
		$total_errors   = 0;
		$total_duration = 0;
		$duration_count = 0;
		$per_ability    = [];
		$daily_calls    = [];
		$sample_rates   = [];

		foreach ( $rollups as $rollup ) {
			$total_calls    += $rollup->call_count();
			$total_errors   += $rollup->error_count() + $rollup->exception_count();
			$total_duration += $rollup->avg_duration_ms() * $rollup->call_count();
			$duration_count += $rollup->call_count();
			$sample_rates[]  = $rollup->sample_rate();

			// Daily aggregates
			$date = $rollup->date();
			if ( ! isset( $daily_calls[ $date ] ) ) {
				$daily_calls[ $date ] = 0;
			}
			$daily_calls[ $date ] += $rollup->call_count();

			// Per-ability aggregates
			$ability = $rollup->ability();
			if ( ! isset( $per_ability[ $ability ] ) ) {
				$per_ability[ $ability ] = [
					'ability'        => $ability,
					'call_count'     => 0,
					'error_count'    => 0,
					'total_duration' => 0.0,
					'max_p95'        => 0.0,
				];
			}

			$per_ability[ $ability ]['call_count']     += $rollup->call_count();
			$per_ability[ $ability ]['error_count']    += $rollup->error_count() + $rollup->exception_count();
			$per_ability[ $ability ]['total_duration'] += $rollup->avg_duration_ms() * $rollup->call_count();
			$per_ability[ $ability ]['max_p95']         = max( $per_ability[ $ability ]['max_p95'], $rollup->p95_duration_ms() );
		}

		// Compute averages per ability
		$per_ability_final = [];
		foreach ( $per_ability as $ability => $data ) {
			$per_ability_final[] = [
				'ability'         => $ability,
				'call_count'      => $data['call_count'],
				'error_count'     => $data['error_count'],
				'avg_duration_ms' => $data['call_count'] > 0 ? $data['total_duration'] / $data['call_count'] : 0.0,
				'p95_duration_ms' => $data['max_p95'],
			];
		}

		// Determine sampling metadata
		$unique_rates = array_unique( $sample_rates );
		$is_sampled   = count( $unique_rates ) === 1 && $unique_rates[0] < 1.0;
		$sample_rate  = count( $unique_rates ) === 1 ? $unique_rates[0] : null;

		$sampling = [
			'is_sampled'  => $is_sampled,
			'sample_rate' => $sample_rate,
		];

		if ( $is_sampled && $sample_rate !== null && $sample_rate > 0.0 ) {
			$sampling['estimated_total_calls'] = (int) round( $total_calls / $sample_rate );
		}

		return [
			'total_calls'    => $total_calls,
			'error_rate'     => $total_calls > 0 ? $total_errors / $total_calls : 0.0,
			'avg_latency_ms' => $duration_count > 0 ? $total_duration / $duration_count : 0.0,
			'daily_calls'    => $daily_calls,
			'per_ability'    => $per_ability_final,
			'sampling'       => $sampling,
		];
	}

	/**
	 * Compute client-scoped metrics without mixing aggregate rows.
	 *
	 * @param list<\Saltus\WP\Framework\MCP\Audit\DailyRollup> $rollups
	 * @return list<array<string, mixed>>
	 */
	private function compute_client_aggregates( array $rollups ): array {
		$grouped = [];
		foreach ( $rollups as $rollup ) {
			$client = $rollup->client_identifier();
			if ( $client === null ) {
				continue;
			}
			if ( ! isset( $grouped[ $client ] ) ) {
				$grouped[ $client ] = [ 'client_identifier' => $client, 'call_count' => 0, 'error_count' => 0, 'total_duration' => 0.0, 'max_p95' => 0.0 ];
			}
			$grouped[ $client ]['call_count']     += $rollup->call_count();
			$grouped[ $client ]['error_count']    += $rollup->error_count() + $rollup->exception_count();
			$grouped[ $client ]['total_duration'] += $rollup->avg_duration_ms() * $rollup->call_count();
			$grouped[ $client ]['max_p95']         = max( $grouped[ $client ]['max_p95'], $rollup->p95_duration_ms() );
		}

		$output = [];
		foreach ( $grouped as $data ) {
			$output[] = [
				'client_identifier' => $data['client_identifier'],
				'call_count'       => $data['call_count'],
				'error_count'      => $data['error_count'],
				'avg_duration_ms'  => $data['call_count'] > 0 ? $data['total_duration'] / $data['call_count'] : 0.0,
				'p95_duration_ms'  => $data['max_p95'],
			];
		}
		usort( $output, static fn( array $a, array $b ): int => $b['call_count'] <=> $a['call_count'] );
		return $output;
	}

	/**
	 * Get distinct abilities from rollups.
	 *
	 * @param list<\Saltus\WP\Framework\MCP\Audit\DailyRollup> $rollups
	 * @return list<string>
	 */
	private function get_distinct_abilities( array $rollups ): array {
		$abilities = [];
		foreach ( $rollups as $rollup ) {
			$ability = $rollup->ability();
			if ( ! in_array( $ability, $abilities, true ) ) {
				$abilities[] = $ability;
			}
		}
		sort( $abilities );
		return $abilities;
	}
}
