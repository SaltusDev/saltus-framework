<?php
namespace Saltus\WP\Framework\Infrastructure\Service;

/**
 * A conceptual service.
 *
 * Splitting your logic up into independent services makes the approach of
 * assembling a plugin more systematic and scalable and lowers the cognitive
 * load when the code base increases in size.
 */
interface Service {

	/**
	 * Create a new instance of the service provider
	 *
	 * @return object The new instance
	 */
	public static function make( $name, $project, $args );
}
