<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Observability\ObservabilityDashboard;

/**
 * @covers \Saltus\WP\Framework\Features\Observability\ObservabilityDashboard
 */
class ObservabilityDashboardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();

		parent::tearDown();
	}

	private function reset(): void {
		global $wp_actions_registered, $wp_admin_pages, $wp_scripts_enqueued, $wp_styles_enqueued, $wp_scripts_localized, $wp_filter_values;

		$wp_actions_registered = [];
		$wp_admin_pages        = [];
		$wp_scripts_enqueued   = [];
		$wp_styles_enqueued    = [];
		$wp_scripts_localized  = [];
		$wp_filter_values      = [];
	}

	public function test_register_hooks_menu_and_assets(): void {
		global $wp_actions_registered;

		( new ObservabilityDashboard() )->register();

		$hooks = array_column( $wp_actions_registered, 'hook_name' );

		$this->assertContains( 'admin_menu', $hooks );
		$this->assertContains( 'admin_enqueue_scripts', $hooks );
	}

	/**
	 * The disable filter has to prevent registration, not just rendering. A site
	 * that turns the dashboard off should not get the menu entry either.
	 */
	public function test_disable_filter_registers_nothing(): void {
		global $wp_actions_registered, $wp_filter_values;

		$wp_filter_values['saltus/framework/observability/dashboard_enabled'] = false;

		( new ObservabilityDashboard() )->register();

		$this->assertSame( [], $wp_actions_registered );
	}

	public function test_menu_page_is_gated_on_manage_options(): void {
		global $wp_admin_pages;

		( new ObservabilityDashboard() )->add_menu_page();

		$this->assertCount( 1, $wp_admin_pages );
		$this->assertSame( 'manage_options', $wp_admin_pages[0]['capability'] );
		$this->assertSame( 'saltus-metrics', $wp_admin_pages[0]['menu_slug'] );
	}

	/**
	 * Assets load on the metrics screen only. Enqueuing framework assets on every
	 * admin page is the standard plugin failure — it slows down screens that have
	 * nothing to do with this feature and can break other plugins' editors.
	 */
	public function test_assets_are_not_enqueued_on_other_admin_screens(): void {
		global $wp_scripts_enqueued, $wp_styles_enqueued;

		$dashboard = new ObservabilityDashboard();

		foreach ( [ 'index.php', 'post.php', 'edit.php', 'tools_page_something-else', 'options-general.php' ] as $hook ) {
			$dashboard->enqueue_assets( $hook );
		}

		$this->assertSame( [], $wp_scripts_enqueued );
		$this->assertSame( [], $wp_styles_enqueued );
	}

	public function test_assets_are_enqueued_on_the_metrics_screen(): void {
		global $wp_scripts_enqueued, $wp_styles_enqueued;

		( new ObservabilityDashboard() )->enqueue_assets( 'tools_page_saltus-metrics' );

		$script_handles = array_column( $wp_scripts_enqueued, 'handle' );
		$style_handles  = array_column( $wp_styles_enqueued, 'handle' );

		$this->assertContains( 'saltus-observability-dashboard', $script_handles );
		$this->assertContains( 'saltus-observability-dashboard', $style_handles );
	}

	/**
	 * The screen hook the dashboard listens for must match what
	 * add_management_page() actually produces for this slug. These are set in two
	 * places, and a rename in one is invisible until the page renders blank.
	 */
	public function test_the_enqueue_hook_matches_the_registered_menu_slug(): void {
		global $wp_admin_pages, $wp_scripts_enqueued;

		$dashboard = new ObservabilityDashboard();
		$dashboard->add_menu_page();

		$hook = 'tools_page_' . $wp_admin_pages[0]['menu_slug'];
		$dashboard->enqueue_assets( $hook );

		$this->assertNotSame( [], $wp_scripts_enqueued, "assets must enqueue on {$hook}" );
	}

	public function test_localizes_a_nonce_and_ajax_url(): void {
		global $wp_scripts_localized;

		( new ObservabilityDashboard() )->enqueue_assets( 'tools_page_saltus-metrics' );

		$this->assertCount( 1, $wp_scripts_localized );
		$this->assertSame( 'saltusMetrics', $wp_scripts_localized[0]['object_name'] );
		$this->assertArrayHasKey( 'nonce', $wp_scripts_localized[0]['l10n'] );
		$this->assertArrayHasKey( 'ajaxUrl', $wp_scripts_localized[0]['l10n'] );
	}

	/**
	 * The localized nonce has to be for the action the AJAX handler verifies.
	 * A mismatch here fails every request with a 403 that looks like a
	 * permissions problem.
	 */
	public function test_the_localized_nonce_is_for_the_metrics_action(): void {
		global $wp_scripts_localized;

		( new ObservabilityDashboard() )->enqueue_assets( 'tools_page_saltus-metrics' );

		$this->assertSame( 'nonce-saltus_metrics', $wp_scripts_localized[0]['l10n']['nonce'] );
	}

	public function test_renders_the_mount_point_the_script_expects(): void {
		ob_start();
		( new ObservabilityDashboard() )->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="saltus-metrics-dashboard"', $html );
	}
}
