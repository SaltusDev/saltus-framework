<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Modeler;

class RestServer {

	protected Modeler $modeler;

	public function __construct( Modeler $modeler ) {
		$this->modeler = $modeler;
	}

	public function register_routes(): void {
		( new ModelsController( $this->modeler ) )->register_routes();
		( new DuplicateController() )->register_routes();
		( new ExportController() )->register_routes();
		( new SettingsController() )->register_routes();
		( new MetaController( $this->modeler ) )->register_routes();
		( new ReorderController() )->register_routes();
	}
}
