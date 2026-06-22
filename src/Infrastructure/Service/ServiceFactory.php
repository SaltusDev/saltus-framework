<?php
namespace Saltus\WP\Framework\Infrastructure\Service;

use Saltus\WP\Framework\Infrastructure\Service\Service;

class ServiceFactory implements Service, Factory {

	/**
	 * @param class-string      $class_name Class to instantiate.
	 * @param array<int, mixed> $args       Constructor arguments.
	 */
	public function create( string $class_name, array $args = [] ): object {
		if ( ! class_exists( $class_name ) ) {
			throw new \InvalidArgumentException( "Class $class_name does not exist." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$reflection = new \ReflectionClass( $class_name );

		return $reflection->newInstanceArgs( $args );
	}
}
