<?php
namespace Saltus\WP\Framework\Features\SingleExport;

use Saltus\WP\Framework\Infrastructure\Service\{
	Assembly,
	Service,
	Conditional
};

/**
 * Class SingleExport
 *
 * Enable an option to export single entry
 */
class SingleExport implements Service, Conditional, Assembly {

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
		 * This service loads only in the admin edit screen
		 */
		return is_admin();
	}

	/**
	 * Create a new instance of the service provider
	 *
	 * @param string $name        The name of the custom post type (CPT) to export.
	 * @param array|null $project Project information.
	 * @param array|null $args    Additional arguments for the export.
	 *
	 * @return object The new instance
	 */
	public static function make( $name, $project, $args ) {
		return new SaltusSingleExport( $name, $args );
	}
}
