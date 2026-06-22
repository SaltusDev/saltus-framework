<?php
namespace Saltus\WP\Framework\Infrastructure\Container;

/**
 * A simplified implementation of a container Assembler.
 *
 */
class ContainerAssembler {

	/**
	 * @param class-string $container
	 */
	public function create( string $container ): object {
		if ( ! class_exists( $container ) ) {
			throw new \InvalidArgumentException( "Container class $container does not exist." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return new $container();
	}
}
