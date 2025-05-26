<?php
namespace Saltus\WP\Framework\Infrastructure\Container;

/**
 * A simplified implementation of a container Assembler.
 *
 */
class ContainerAssembler {

	public function create( $container ) {
		if ( ! class_exists( $container ) ) {
			//phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \InvalidArgumentException( "Container class $container does not exist." );
		}
		return new $container();
	}
}
