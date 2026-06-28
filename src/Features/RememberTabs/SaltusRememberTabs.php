<?php
namespace Saltus\WP\Framework\Features\RememberTabs;

use Saltus\WP\Framework\Infrastructure\Service\{
	Processable
};

final class SaltusRememberTabs implements Processable {

	/**
	 * @var string $name Project information.
	 */
	/** @var array<string, mixed> */
	private array $project;

	/**
	 * Instantiate this Service object.
	 *
	 * @param array<string, mixed> $project Project information.
	 */
	public function __construct( array $project ) {
		$this->project = $project;
	}

	/**
	 * Process the functionality
	 */
	public function process(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'load_script_css' ] );
	}
	/**
	 * Check if the script and CSS should be loaded
	 *
	 * @return bool
	 */
	private function check_load_script_css(): bool {

		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( strstr( $_SERVER['REQUEST_URI'], 'action=edit' ) || strstr( $_SERVER['REQUEST_URI'], 'wp-admin/post-new.php' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Load the script and CSS
	 */
	public function load_script_css(): void {

		if ( ! $this->check_load_script_css() ) {
			return;
		}

		wp_enqueue_script(
			'remember_tabs',
			$this->project['root_url'] . 'Feature/RememberTabs/rememberTabs.js',
			[],
			'1',
			true
		);
	}
}
