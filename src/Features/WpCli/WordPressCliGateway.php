<?php
namespace Saltus\WP\Framework\Features\WpCli;

final class WordPressCliGateway implements CliGateway {
	public function add_command( string $name, $command_handler ): void {
		\WP_CLI::add_command( $name, $command_handler );
	}

	/**
	 * @param list<array<string, mixed>> $items Rows to format.
	 * @param list<string> $fields Fields to display.
	 */
	public function format_items( string $format, array $items, array $fields ): void {
		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	public function line( string $message ): void {
		\WP_CLI::line( $message );
	}

	public function success( string $message ): void {
		\WP_CLI::success( $message );
	}

	public function error( string $message ): void {
		\WP_CLI::error( $message );
	}

	public function warning( string $message ): void {
		\WP_CLI::warning( $message );
	}

	public function halt( int $code ): void {
		\WP_CLI::halt( $code );
	}
}
