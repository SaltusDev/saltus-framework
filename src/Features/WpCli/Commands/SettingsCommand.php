<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

use Saltus\WP\Framework\Features\Settings\SettingsManager;

final class SettingsCommand extends AbstractCommand {
	private SettingsManager $settings;

	public function __construct( \Saltus\WP\Framework\Features\WpCli\CliGateway $cli, callable $modeler_resolver, ?SettingsManager $settings = null ) {
		parent::__construct( $cli, $modeler_resolver );
		$this->settings = $settings ?? new SettingsManager();
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function get( array $args, array $assoc_args ): void {
		$post_type = sanitize_key( $this->argument( $args, 0, 'post-type' ) );
		$this->require_model( $post_type );
		$row             = $this->settings->get_settings( $post_type );
		$row['settings'] = wp_json_encode( $row['settings'] );
		$this->cli->format_items( $this->format( $assoc_args ), [ $row ], [ 'post_type', 'settings' ] );
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function update( array $args, array $assoc_args ): void {
		$post_type = sanitize_key( $this->argument( $args, 0, 'post-type' ) );
		$this->require_model( $post_type );
		$payload = $this->json_object( $this->argument( $args, 1, 'json|@file' ) );
		$result  = $this->ensure_result( $this->settings->update_settings( $post_type, $payload ) );
		$status  = is_array( $result ) ? (string) ( $result['status'] ?? 'updated' ) : 'updated';
		$this->cli->success( ucfirst( $status ) . ' settings for ' . $post_type . '.' );
	}

	private function require_model( string $post_type ): void {
		if ( ! isset( $this->modeler()->get_models()[ $post_type ] ) ) {
			$this->fail( 'Model not found: ' . $post_type );
		}
	}
}
