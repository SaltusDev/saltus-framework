<?php
namespace Saltus\WP\Framework\Features\AdminFilters;

use Saltus\WP\Framework\Infrastructure\Service\{
	Service,
	Conditional
};


/**
 * Adds admin filters in the post type admin archive
 */
class AdminFilters implements Service, Conditional {

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct() {}

	/**
	 * Create a new instance of the service provider
	 *
	 * @return object The new instance
	 */
	public static function make( $name, $project, $args ) {
		return new SaltusAdminFilters( $name, $project, $args );
	}

	/**
	 * Check whether the conditional service is currently needed.
	 *
	 * @return bool Whether the conditional service is needed.
	 */
	public static function is_needed(): bool {

		/*
		 * This service loads only in the admin edit screen
		 */
		return is_admin();
	}

}
