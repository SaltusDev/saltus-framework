<?php

namespace Saltus\WP\Framework\Features\WpCli\Commands;

use Saltus\WP\Framework\Features\AiContext\AiContextProvider;

/** Displays normalized AI governance context for a model. */
final class ContextCommand extends AbstractCommand {

	/**
	 * @param list<string> $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function get( array $args, array $assoc_args ): void {
		$name    = $this->argument( $args, 0, 'post-type' );
		$context = ( new AiContextProvider( function () {
			return $this->modeler();
		} ) )->get( $name );
		if ( $context === null ) {
			$this->fail( 'Model not found: ' . $name );
		}

		$row = [
			'model'      => $context['model'],
			'configured' => $context['configured'],
			'context'    => wp_json_encode( $context ),
		];
		$this->cli->format_items( $this->format( $assoc_args ), [ $row ], [ 'model', 'configured', 'context' ] );
	}
}
