<?php
namespace Saltus\WP\Framework\Features\WpCli;

interface CliGateway {
	/** @param object|string $command_handler */
	public function add_command( string $name, $command_handler ): void;

	/**
	 * @param list<array<string, mixed>> $items Rows to format.
	 * @param list<string> $fields Fields to display.
	 */
	public function format_items( string $format, array $items, array $fields ): void;

	public function line( string $message ): void;
	public function success( string $message ): void;
	public function error( string $message ): void;
}
