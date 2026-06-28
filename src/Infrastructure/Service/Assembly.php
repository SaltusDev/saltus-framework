<?php
namespace Saltus\WP\Framework\Infrastructure\Service;

/**
 * A conceptual service.
 *
 * Splitting your logic up into independent services makes the approach of
 * assembling a plugin more systematic and scalable and lowers the cognitive
 * load when the code base increases in size.
 */
interface Assembly {

	/**
	 * Create a new instance of the service provider
	 *
	 * @param string               $name    Service name.
	 * @param array<string, mixed> $project Project data.
	 * @param array<string, mixed> $args    Service arguments.
	 * @return object The new instance
	 */
	public static function make( string $name, array $project, array $args ): object;
}
