<?php
/**
 * Saltus Framework
 *
 */
namespace Saltus\WP\Plugin\Saltus\Framework;

class Core {

	public function __construct( string $project_path ) {

		new Loader( $project_path );
	}

}

