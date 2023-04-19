<?php
namespace Saltus\WP\Framework\Infrastructure\Container;

/**
 * Something that triggers class instantiation
 *
 */
interface CanRegister {

	/**
	 * Register the service.
	 *
	 * @return void
	 */
	public function register( string $id, string $class, array $dependencies );
}
