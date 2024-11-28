<?php
namespace Saltus\WP\Framework\Features\Meta;

use Saltus\WP\Framework\Infrastructure\Service\{
	Assembly,
	Service,
	Conditional
};

/**
 */
final class Meta implements Service, Conditional, Assembly {

	/**
	 * Check whether the conditional service is currently needed.
	 *
	 * @return bool Whether the conditional service is needed.
	 */
	public static function is_needed(): bool {
		/*
		 * Load everywhere since its needed via REST API
		 */
		return true;
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
		return new CodestarMeta( $name, $project, $args );
	}

}

