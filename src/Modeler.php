<?php
/**
 * Loads paths and models from the paths
 *
 * This is a simplified version of soberwp/Models
 */
namespace Saltus\WP\Framework;

use Noodlehaus\Config;
use Saltus\WP\Framework\Models\Config\NoFile;

class Modeler {

	protected $model_factory;

	protected $model_list;

	public function __construct( $model_factory ) {
		$this->model_factory = $model_factory;
		// should contain a list of loaded models

	}

	public function init( $project_path ) {
		$path = $this->get_path( $project_path );
		if ( ! $path ) {
			return;
		}
		$this->load( $path );
	}

	/**
	 * Get custom path
	 */
	protected function get_path( $project_path ) {

		$path = $project_path . '/src/models/';
		if ( has_filter( 'saltus_models_path' ) ) {
			$path = apply_filters( 'saltus_models_path', $path );
		}

		if ( file_exists( $path ) ) {
			return $path;
		}
		return false;

	}

	/**
	 * Load Models
	 */
	protected function load( $path ) {
		if ( file_exists( $path ) ) {
			$path_dir      = new \RecursiveDirectoryIterator( $path );
			$path_dir_iter = new \RecursiveIteratorIterator( $path_dir );

			foreach ( $path_dir_iter as $filename => $file ) {
				if ( ! in_array( pathinfo( $file, PATHINFO_EXTENSION ), [ 'json', 'php', 'yml', 'yaml' ], true ) ) {
					continue;
				}
				$config = new Config( $file );
				( $this->is_multiple( $config ) ?
					$this->iterate_multiple( $config ) :
					$this->create( $config )
				);
			}
		}

		// check for models added with filters
		if ( has_filter( 'saltus_models' ) ) {
			$model  = apply_filters( 'saltus_models', [] );
			( ! empty( $model ) && count( $model ) > 0 ?
					$this->iterate_multiple($model ) :
					$this->create( $model )
				);
		}
	}

	/**
	 * Is multidimensional config
	 */
	protected function is_multiple( $config ) {
		return ( is_array( current( $config->all() ) ) );
	}

	/**
	 * Load each from multidimensional config
	 *
	 * Creates a new config from the part
	 */
	protected function iterate_multiple( $config ) {
		foreach ( $config as $single_config ) {
			$this->create( new NoFile( $single_config ) );
		}
	}

	protected function create( $config ) {
		$model = $this->model_factory->create( $config );
		if ( $model === false ) {
			return false;
		}
		$this->add( $model );
	}

	protected function add( $model ) {
		$this->model_list[ $model->get_type() ] = $model;
	}

}
