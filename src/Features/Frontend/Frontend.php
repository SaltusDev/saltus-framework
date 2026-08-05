<?php
namespace Saltus\WP\Framework\Features\Frontend;

use Saltus\WP\Framework\Infrastructure\Service\{
	Assembly,
	Conditional,
	Service
};

/**
 * Enables model-driven frontend shortcodes.
 * @api
 */
final class Frontend implements Service, Conditional, Assembly {

	public static function is_needed(): bool {
		return ! is_admin();
	}

	public static function make( string $name, array $project, array $args ): object {
		return new SaltusFrontend( $name, $project, $args );
	}
}
