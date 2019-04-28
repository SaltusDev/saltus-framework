<?php
/**
 * Saltus Framework
 *
 */
namespace Saltus\WP\Framework;

class Core {

	public function __construct( string $project_path ) {

		add_action(
			'init',
			function () use ( $project_path ) {
				new Loader( $project_path );
			}
		);
	}

}

