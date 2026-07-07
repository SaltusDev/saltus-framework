<?php
namespace Saltus\WP\Framework\Infrastructure\Services\Assets;

use Saltus\WP\Framework\Infrastructure\Container\Container;

/**
 * Base class for services that register and enqueue framework assets.
 */
abstract class AssetLoadingService implements HasAssets {
	use AssetLoader;

	protected Container $services;
}
