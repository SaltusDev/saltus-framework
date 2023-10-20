<?php
namespace Saltus\WP\Framework\Features\RememberTabs;

use Saltus\WP\Framework\Infrastructure\Service\{
	Processable
};

final class SaltusRememberTabs implements Processable {

	private $name;
	private $project;

		/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct( string $name, array $project, array $args = null ) {
		$this->project = $project;
		$this->name    = $name;
	}

	public function process() {
		add_action( 'admin_enqueue_scripts', array( $this, 'load_script_css' ) );
		add_filter( 'admin_url', array( $this, 'check_remember_tab_url' ), 1, 10 );
	}

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

	public function load_script_css() {

		if ( ! $this->check_load_script_css() ) {
			return;
		}

		wp_enqueue_script( 'remember_tabs', $this->project['root_url'] . 'Feature/RememberTabs/rememberTabs.js', array(), '1', true );

	}

	/**
	 * Adds check to see if extra parameter is set on admin url on save cpt
	 * Used to remember tab
	 *
	 * @param string $link
	 * @return string
	 */
	public function check_remember_tab_url( $link ) {

		global $current_screen;
		if( ! is_admin() || ! isset( $current_screen ) || $this->name !== $current_screen->post_type || wp_doing_ajax() ) {
			return $link;
		}

		if( isset( $_REQUEST['tab'] ) ) {
			$params['tab'] = absint( $_REQUEST['tab'] );
			$link = add_query_arg( $params, $link );
		}


		return $link;
	}

}
