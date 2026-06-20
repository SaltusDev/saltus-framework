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
	 * @param array<int, mixed> $dependencies
	 */
	public function register( string $id, string $service_class, array $dependencies ): void;
}
