<?php
namespace Saltus\WP\Framework\Features\Settings;

use Saltus\WP\Framework\Infrastructure\Service\{
	Service,
	Conditional
};

/**
 */
final class Settings implements Service, Conditional {

	/**
	 * Check whether the conditional service is currently needed.
	 *
	 * @return bool Whether the conditional service is needed.
	 */
	public static function is_needed(): bool {
		/*
		 * Only load this sample service on the admin backend.
		 * If this conditional returns false, the service is never even
		 * instantiated.
		 */
		return \is_admin();
	}

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct() {

	}

	/**
	 * Create a new instance of the service provider
	 *
	 * @return object The new instance
	 */
	public static function make( $name, $project, $args ) {
		return new CodestarSettings( $name, $project, $args );
	}

}

