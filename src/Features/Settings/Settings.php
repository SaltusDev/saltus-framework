<?php
namespace Saltus\WP\Framework\Features\Settings;

use Saltus\WP\Framework\Infrastructure\Service\{
	Assembly,
	Service,
	Conditional
};
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;
use Saltus\WP\Framework\Rest\SettingsController;

/**
 * Class Settings
 *
 * Enable an option to create Settings page
 */
final class Settings implements Service, Conditional, Assembly, RestRouteProvider {

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
		 * Only load this sample service on the admin backend.
		 */
		return \is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Create a new instance of the service provider
	 *
	 * @param string $name        The name of the custom post type (CPT)
	 * @param array<string, mixed> $project Project information.
	 * @param array<string, mixed> $args    Additional arguments.
	 *
	 * @return object The new instance
	 */
	public static function make( string $name, array $project, array $args ): object {
		return new CodestarSettings( $name, $args );
	}

	/**
	 * @return list<RestRouteDefinition>
	 */
	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array {
		return [
			new RestRouteDefinition(
				ModelRestPolicy::CAPABILITY_SETTINGS,
				new SettingsController( $policy ),
				'post_type'
			),
		];
	}
}
