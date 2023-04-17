<?php
namespace Saltus\WP\Framework\Infrastructure\Container;

/**
 * A simplified implementation of a container Assembler.
 *
 */
class ContainerAssembler {

	public function create() {
		return new GenericContainer();
	}

}
