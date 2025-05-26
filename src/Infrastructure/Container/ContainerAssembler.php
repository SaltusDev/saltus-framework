<?php
namespace Saltus\WP\Framework\Infrastructure\Container;

/**
 * A simplified implementation of a container Assembler.
 *
 */
class ContainerAssembler {

	public function create( $container ) {
		if ( ! class_exists( $container ) ) {
			throw new \InvalidArgumentException( esc_html( "Container class $container does not exist." ) );
		}
		return new $container();
	}
}
