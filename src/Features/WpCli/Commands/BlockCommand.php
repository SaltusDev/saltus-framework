<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

use Saltus\WP\Framework\Features\Blocks\SaltusBlocks;

final class BlockCommand extends AbstractCommand {
	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$definitions = ( new SaltusBlocks( $this->modeler() ) )->definitions();
		$rows        = [];
		foreach ( $definitions as $definition ) {
			foreach ( $definition['blocks'] as $view => $block ) {
				$rows[] = [
					'post_type' => $definition['post_type'],
					'view'      => $view,
					'block'     => $block['name'],
					'fields'    => count( $definition['meta_fields'] ),
				];
			}
		}
		$this->cli->format_items( $this->format( $assoc_args ), $rows, [ 'post_type', 'view', 'block', 'fields' ] );
	}
}
