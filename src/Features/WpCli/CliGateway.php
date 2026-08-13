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

	/** Non-fatal notice. Goes to stderr, so it cannot corrupt piped stdout. */
	public function warning( string $message ): void;

	/**
	 * Exit with a status code, having already written output.
	 *
	 * Distinct from `error()`, which prints its own message and exits: a command
	 * that has just formatted a table of problems needs the table to stand as the
	 * explanation and only the exit status to signal failure. Routing that through
	 * `error()` would append a redundant message and, under `--format=json`, emit a
	 * second non-JSON line into piped stdout.
	 */
	public function halt( int $code ): void;
}
