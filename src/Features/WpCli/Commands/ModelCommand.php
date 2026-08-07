<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

final class ModelCommand extends AbstractCommand {
	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$type = (string) ( $assoc_args['type'] ?? '' );
		$rows = [];
		foreach ( $this->modeler()->get_models() as $model ) {
			if ( $type !== '' && $model->get_type() !== $type ) {
				continue;
			}
			$model_args = $model->get_args();
			$rows[]     = [
				'name'  => $model->get_name(),
				'type'  => $model->get_type(),
				'label' => $model_args['labels']['name'] ?? $model->get_name(),
			];
		}
		$this->cli->format_items( $this->format( $assoc_args ), $rows, [ 'name', 'type', 'label' ] );
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function get( array $args, array $assoc_args ): void {
		$name  = $this->argument( $args, 0, 'slug' );
		$model = $this->modeler()->get_models()[ $name ] ?? null;
		if ( $model === null ) {
			$this->fail( 'Model not found: ' . $name );
		}
		$row = [
			'name'    => $model->get_name(),
			'type'    => $model->get_type(),
			'options' => wp_json_encode( $model->get_options() ),
			'config'  => wp_json_encode( $model->get_config() ),
		];
		$this->cli->format_items( $this->format( $assoc_args ), [ $row ], array_keys( $row ) );
	}
}
