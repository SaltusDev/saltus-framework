<?php
/**
 * Loads paths and models.
 *
 * This is a simplified version of soberwp/Models
 */
namespace Saltus\WP\Framework;

use Saltus\WP\Framework\Models\ConfigNoFile;

use Noodlehaus\Config;

use Saltus\WP\Framework\Models\PostType;
use Saltus\WP\Framework\Models\Taxonomy;

class Loader {

	protected $path;
	protected $file;
	protected $config;

	public function __construct( $project_path ) {

		$this->get_path( $project_path );
		$this->load();
	}

	/**
	 * Get custom path
	 */
	protected function get_path( $project_path ) {

		$path = $project_path . '/src/models/';
		if ( has_filter( 'saltus/framework/models/path' ) ) {
			$path = apply_filters( 'saltus/framework/models/path', $path );
		}

		if ( file_exists( $path ) ) {
			$this->path = $path;
			return;
		}

	}

	/**
	 * Load Models
	 */
	protected function load() {
		if ( file_exists( $this->path ) ) {
			$path = new \RecursiveDirectoryIterator( $this->path );
			foreach ( new \RecursiveIteratorIterator( $path ) as $filename => $file ) {
				if ( in_array( pathinfo( $file, PATHINFO_EXTENSION ), [ 'json', 'php', 'yml', 'yaml' ] ) ) {
					$this->config = new Config( $file );
					( $this->is_multiple() ? $this->load_each() : $this->route( $this->config ) );
				}
			}
		}
	}

	/**
	 * Is multidimensional config
	 */
	protected function is_multiple() {
		return ( is_array( current( $this->config->all() ) ) );
	}

	/**
	 * Load each from multidimensional config
	 */
	protected function load_each() {
		foreach ( $this->config as $config ) {
			$this->route( new ConfigNoFile( $config ) );
		}
	}

	/**
	 * Route to class
	 */
	protected function route( $config ) {
		if ( in_array( $config['type'], [ 'post-type', 'cpt', 'posttype', 'post_type' ] ) ) {

			( new PostType( $config ) )->run();
		}
		if ( in_array( $config['type'], [ 'taxonomy', 'tax', 'category', 'cat', 'tag' ] ) ) {
			//( new Taxonomy( $config ) )->run();
		}
	}
}
