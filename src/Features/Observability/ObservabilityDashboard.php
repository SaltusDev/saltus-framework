<?php
namespace Saltus\WP\Framework\Features\Observability;

use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Service;

/**
 * Admin dashboard for MCP audit metrics.
 * @api
 */
final class ObservabilityDashboard implements Service, Registerable {
	use \Saltus\WP\Framework\Infrastructure\Services\FilterAwareTrait;

	/** @var array<string, mixed> */
	private array $project;

	/** @param array<string, mixed> $dependencies */
	public function __construct( array $dependencies = [] ) {
		$this->project = is_array( $dependencies['project'] ?? null ) ? $dependencies['project'] : [];
	}

	/**
	 * Register the dashboard page and assets.
	 */
	public function register(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add the Tools → Saltus Metrics menu page.
	 */
	public function add_menu_page(): void {
		add_management_page(
			__( 'Saltus Metrics', 'saltus-framework' ),
			__( 'Saltus Metrics', 'saltus-framework' ),
			'manage_options',
			'saltus-metrics',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueue dashboard assets on the metrics page.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'tools_page_saltus-metrics' ) {
			return;
		}

		$root_path  = rtrim( (string) ( $this->project['root_path'] ?? '' ), '/' );
		$root_url   = rtrim( (string) ( $this->project['root_url'] ?? '' ), '/' );
		$asset_file = $root_path . '/assets/Feature/Observability/dashboard.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : [
			'dependencies' => [ 'wp-element', 'wp-i18n' ],
			'version'      => '1.0.0',
		];

		wp_enqueue_script(
			'saltus-observability-dashboard',
			$root_url . '/Feature/Observability/dashboard.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'saltus-observability-dashboard',
			$root_url . '/Feature/Observability/dashboard.css',
			[],
			$asset['version']
		);

		// WordPress core Chart.js
		wp_enqueue_script( 'chart' );
		wp_enqueue_style( 'wp-components' );

		wp_localize_script(
			'saltus-observability-dashboard',
			'saltusMetrics',
			[
				'nonce'   => wp_create_nonce( 'saltus_metrics' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			]
		);
	}

	/**
	 * Render the dashboard page container.
	 */
	public function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Saltus Metrics', 'saltus-framework' ); ?></h1>
			<div id="saltus-metrics-dashboard"></div>
		</div>
		<?php
	}

	/**
	 * Check whether the dashboard is enabled.
	 */
	private function enabled(): bool {
		return (bool) $this->filter( 'saltus/framework/observability/dashboard_enabled', true );
	}
}
