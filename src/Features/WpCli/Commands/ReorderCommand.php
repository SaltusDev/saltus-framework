<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

use Saltus\WP\Framework\Features\DragAndDrop\ReorderPostsService;

final class ReorderCommand extends AbstractCommand {
	private ReorderPostsService $reorder_service;

	public function __construct( \Saltus\WP\Framework\Features\WpCli\CliGateway $cli, callable $modeler_resolver, ?ReorderPostsService $reorder_service = null ) {
		parent::__construct( $cli, $modeler_resolver );
		$this->reorder_service = $reorder_service ?? new ReorderPostsService();
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$payload = $this->json_list( $this->argument( $args, 0, 'json|@file' ) );
		$result  = $this->reorder_service->reorder( $payload );
		$this->ensure_result( $result );
		$this->cli->success( 'Reordered ' . $result['updated'] . ' posts.' );
	}
}
