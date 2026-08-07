<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\MCP\Tools\UpdateMetaFields;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

final class MetaCommand extends AbstractCommand {
	private MetaFieldProvider $provider;

	public function __construct( \Saltus\WP\Framework\Features\WpCli\CliGateway $cli, callable $modeler_resolver, ?MetaFieldProvider $provider = null ) {
		parent::__construct( $cli, $modeler_resolver );
		$this->provider = $provider ?? new MetaFieldProvider();
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$modeler = $this->modeler();
		$items   = $this->provider->all_post_type_meta(
			$modeler,
			null,
			static function (): bool {
				return true;
			}
		);
		$rows    = [];
		foreach ( $items as $item ) {
			$rows[] = [
				'post_type' => $item['post_type'],
				'fields'    => count( $item['normalized']['fields'] ?? [] ),
				'writable'  => count( $item['normalized']['rest_meta_keys'] ?? [] ),
			];
		}
		$this->cli->format_items( $this->format( $assoc_args ), $rows, [ 'post_type', 'fields', 'writable' ] );
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function get( array $args, array $assoc_args ): void {
		$post_type = sanitize_key( $this->argument( $args, 0, 'post-type' ) );
		$result    = $this->ensure_result( $this->provider->post_type_meta( $this->modeler(), null, $post_type ) );
		$row       = [
			'post_type' => $post_type,
			'meta'      => wp_json_encode( $result ),
		];
		$this->cli->format_items( $this->format( $assoc_args ), [ $row ], [ 'post_type', 'meta' ] );
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function update( array $args, array $assoc_args ): void {
		$post_type = sanitize_key( $this->argument( $args, 0, 'post-type' ) );
		$post_id   = (int) $this->argument( $args, 1, 'post-id' );
		$payload   = $this->json_object( $this->argument( $args, 2, 'json|@file' ) );
		$modeler   = $this->modeler();
		$result    = ( new UpdateMetaFields( $this->provider ) )->update_meta_fields( $modeler, new ModelRestPolicy( $modeler ), $post_type, $post_id, $payload );
		$this->ensure_result( $result );
		$this->cli->success( 'Updated meta for post ' . $post_id . '.' );
	}
}
