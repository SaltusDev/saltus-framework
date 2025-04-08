<?php
namespace Saltus\WP\Framework\Features\RememberTabs;

use Saltus\WP\Framework\Infrastructure\Service\{
	Processable
};

final class SaltusRememberTabs implements Processable {

	/**
	 * @var string $name The name of the custom post type (CPT)
	 */
	private $name;

	/**
	 * @var string $name Project information.
	 */
	private $project;

	/**
	 * Instantiate this Service object.
	 *
	 * @param string $name The name of the custom post type (CPT)
	 * @param array  $project Project information.
	 */
	public function __construct( string $name, array $project ) {
		$this->name    = $name;
		$this->project = $project;
	}

	/**
	 * Process the functionality
	 */
	public function process() {
		add_action( 'admin_enqueue_scripts', [ $this, 'load_script_css' ] );
		add_filter( 'admin_url', [ $this, 'check_remember_tab_url' ], 10, 1 );
	}
	/**
	 * Check if the script and CSS should be loaded
	 *
	 * @return bool
	 */
	private function check_load_script_css() {

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
	public function load_script_css() {

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

	/**
	 * Adds check to see if extra parameter is set on admin url on save cpt
	 * Used to remember tab
	 *
	 * @param string $link Admin url
	 * @return string The url
	 */
	public function check_remember_tab_url( $link ) {

		global $current_screen;
		if ( ! is_admin() || ! isset( $current_screen ) || $this->name !== $current_screen->post_type || wp_doing_ajax() ) {
			return $link;
		}

		if ( isset( $_REQUEST['tab'] ) ) {
			$params['tab'] = absint( $_REQUEST['tab'] );
			$link          = add_query_arg( $params, $link );
		}

		return $link;
	}
}
