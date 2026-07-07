<?php
namespace Saltus\WP\Framework\Infrastructure\Container;

/**
 * A simplified implementation of a container Assembler.
 *
 */
class ContainerAssembler {

	/**
	 * Create a new instance of the given container class.
	 *
	 * @param class-string $container  The fully qualified class name to instantiate.
	 */
	public function create( string $container ): object {
		if ( ! class_exists( $container ) ) {
			throw new \InvalidArgumentException( "Container class $container does not exist." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return new $container();
	}
}
