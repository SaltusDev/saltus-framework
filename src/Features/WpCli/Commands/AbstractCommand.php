<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

use Saltus\WP\Framework\Features\WpCli\CliGateway;
use Saltus\WP\Framework\Modeler;

abstract class AbstractCommand {
	protected CliGateway $cli;
	/** @var callable */
	private $modeler_resolver;

	public function __construct( CliGateway $cli, callable $modeler_resolver ) {
		$this->cli              = $cli;
		$this->modeler_resolver = $modeler_resolver;
	}

	protected function modeler(): Modeler {
		$modeler = ( $this->modeler_resolver )();
		if ( ! $modeler instanceof Modeler ) {
			$this->fail( 'Saltus models are not loaded.' );
		}
		return $modeler;
	}

	/**
	 * The requested output format, or `table` when none is usable.
	 *
	 * Every accepted value is one `WP_CLI\Utils\format_items()` renders natively,
	 * so a command that declares a format in its synopsis gets that format rather
	 * than a silent fallback.
	 *
	 * @param array<string, mixed> $assoc_args
	 */
	protected function format( array $assoc_args ): string {
		$format = strtolower( (string) ( $assoc_args['format'] ?? 'table' ) );
		return in_array( $format, [ 'table', 'json', 'yaml', 'csv' ], true ) ? $format : 'table';
	}

	/** @return array<mixed> */
	private function decode_json( string $input ): array {
		if ( strpos( $input, '@' ) === 0 ) {
			$path = substr( $input, 1 );
			if ( $path === '' || ! is_readable( $path ) ) {
				$this->fail( 'JSON file is not readable: ' . $path );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Explicit local CLI input.
			$contents = file_get_contents( $path );
			$input    = is_string( $contents ) ? $contents : '';
		}

		$data = json_decode( $input, true );
		if ( ! is_array( $data ) || json_last_error() !== JSON_ERROR_NONE ) {
			$this->fail( 'Expected valid JSON object or array.' );
		}
		return $data;
	}

	/** @return array<string, mixed> */
	protected function json_object( string $input ): array {
		$data   = $this->decode_json( $input );
		$object = [];
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) ) {
				$this->fail( 'Expected a JSON object.' );
				continue;
			}
			$object[ $key ] = $value;
		}
		return $object;
	}

	/** @return array<int, mixed> */
	protected function json_list( string $input ): array {
		$data = $this->decode_json( $input );
		if ( array_keys( $data ) !== range( 0, count( $data ) - 1 ) && $data !== [] ) {
			$this->fail( 'Expected a JSON array.' );
		}
		return array_values( $data );
	}

	/**
	 * @param mixed $result Operation result.
	 * @return mixed
	 */
	protected function ensure_result( $result ) {
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message() );
		}
		return $result;
	}

	/** @param list<string> $args */
	protected function argument( array $args, int $index, string $name ): string {
		$value = isset( $args[ $index ] ) ? trim( (string) $args[ $index ] ) : '';
		if ( $value === '' ) {
			$this->fail( 'Missing required argument: ' . $name );
		}
		return $value;
	}

	protected function fail( string $message ): void {
		$this->cli->error( $message );
		throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal CLI fallback.
	}
}
