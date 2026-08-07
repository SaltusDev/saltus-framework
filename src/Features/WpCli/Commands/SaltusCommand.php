<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

use Saltus\WP\Framework\Core;
use Saltus\WP\Framework\Features\WpCli\CliGateway;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\Rest\HealthController;

final class SaltusCommand extends AbstractCommand {
	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$this->health( $args, $assoc_args );
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function health( array $args, array $assoc_args ): void {
		$response = ( new HealthController( Core::VERSION, new AuditLogger() ) )->get_item( null );
		$data     = $response->get_data();
		$row      = is_array( $data ) ? $data : [ 'result' => $data ];
		$this->cli->format_items( $this->format( $assoc_args ), [ $this->flatten( $row ) ], array_keys( $this->flatten( $row ) ) );
	}

	/**
	 * @param array<string, mixed> $data Health data.
	 * @return array<string, mixed>
	 */
	private function flatten( array $data ): array {
		return [
			'status'       => $data['status'] ?? '',
			'version'      => $data['version'] ?? '',
			'generated_at' => $data['generated_at'] ?? '',
			'error_rate'   => $data['audit']['error_rate'] ?? 0,
			'latency_p95'  => $data['audit']['latency_ms']['p95'] ?? null,
			'cache'        => ! empty( $data['cache']['enabled'] ) ? 'enabled' : 'disabled',
			'rate_limit'   => ! empty( $data['rate_limit']['enabled'] ) ? 'enabled' : 'disabled',
			'ai'           => ! empty( $data['ai']['client_available'] ) ? 'available' : 'unavailable',
		];
	}
}
