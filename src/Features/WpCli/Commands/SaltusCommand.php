<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

use Saltus\WP\Framework\Core;
use Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy;
use Saltus\WP\Framework\Features\WpCli\CliGateway;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\Rest\HealthController;

/**
 * Inspects the framework and its models.
 *
 * Deliberately defines no __invoke(): WP_CLI's CommandFactory reflects on that
 * method to decide a command's kind, and a class that has one becomes a
 * Subcommand, which cannot accept children. Since every other command nests
 * beneath this one, adding __invoke() here throws "'wp saltus' can't have
 * subcommands." from inside cli_init, which aborts WordPress bootstrap and
 * breaks every wp command on the site, not just this one. The default action
 * lives at `wp saltus health` instead.
 */
final class SaltusCommand extends AbstractCommand {
	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function health( array $args, array $assoc_args ): void {
		$response = ( new HealthController( Core::VERSION, new AuditLogger(), new WebMcpPolicy( $this->modeler() ) ) )->get_item( null );
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
