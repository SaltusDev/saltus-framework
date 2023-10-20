<?php
namespace Saltus\WP\Framework\Features\RememberTabs;

use Saltus\WP\Framework\Infrastructure\Service\{
	Assembly,
	Service,
	Conditional
};

/**
 */
class RememberTabs implements Service, Conditional, Assembly {

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
		return new SaltusRememberTabs( $name, $project, $args );
	}

	/**
	 * Check whether the conditional service is currently needed.
	 *
	 * @return bool Whether the conditional service is needed.
	 */
	public static function is_needed(): bool {

		/*
		 * This service loads in most screens:
		 */
		return is_admin();
	}

}
