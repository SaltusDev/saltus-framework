<?php
namespace {
	class WP_CLI {
		public static function add_command( string $name, $callable ): void {}
		public static function line( string $message ): void {}
		public static function success( string $message ): void {}
		public static function error( string $message ): void {}
	}
}

namespace WP_CLI\Utils {
	/** @param list<array<string, mixed>> $items @param list<string> $fields */
	function format_items( string $format, array $items, array $fields ): void {}
}
