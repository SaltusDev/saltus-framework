<?php
namespace Saltus\WP\Framework\Features\Meta;

use Saltus\WP\Framework\Infrastructure\Service\{
	Assembly,
	Service,
	Conditional
};

/**
 * Class Meta
 *
 * Enable an option to manage meta fields
 */
final class Meta implements Service, Conditional, Assembly {

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct() {}

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
	 * Create a new instance of the service provider
	 *
	 * @param string $name        The name of the custom post type (CPT)
	 * @param array|null $project Project information.
	 * @param array|null $args    Additional arguments.
	 *
	 * @return object The new instance
	 */
	public static function make( $name, $project, $args ) {
		return new CodestarMeta( $name, $args );
	}
}
