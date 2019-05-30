<?php
/**
 * Saltus Framework
 *
 */
namespace Saltus\WP\Framework;

use Saltus\WP\Framework\Models\ModelFactory;

class Core {

	protected $project = [];

	protected $modeler;

	protected $model_list;

	public function __construct( string $project_path ) {

		//TODO by pcarvalho: move to project class
		$this->project['path'] = $project_path;

		// loads models and stores the list
		$model_factory = new ModelFactory();
		$this->modeler = new Modeler( $model_factory );
	}

	/**
	 * Register the plugin with the WordPress system.
	 *
	 * @return void
	 */
	public function register() {
		$project_path = $this->project['path'];
		add_action(
			'init',
			function () use ( $project_path ) {
				$this->modeler->init( $project_path );
			}
		);
	}
}
