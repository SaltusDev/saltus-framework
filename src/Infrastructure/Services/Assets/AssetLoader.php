<?php
// trait to load assets
namespace Saltus\WP\Framework\Infrastructure\Services\Assets;

use Saltus\WP\Framework\Infrastructure\Services\Assets\AssetManager;
use Saltus\WP\Framework\Infrastructure\Services\Assets\AssetsContainer;
use Saltus\WP\Framework\Infrastructure\Service\Factory;
use Saltus\WP\Framework\Infrastructure\Service\ServiceFactory;

trait AssetLoader {

	private $assets_container = null;
	private $assets_list      = null;
	private $data             = null;

	/**
	 * register the assets list
	 *
	 * @param array $assets_list List of assets to load.
	 */
	public function register_assets() {

		try {
			$factory = $this->services->get( ServiceFactory::class );
			$assets  = $this->services->get( AssetManager::class );
			if ( ! $factory instanceof Factory ) {
				throw new \RuntimeException( ServiceFactory::class . ' must implement Factory' );
			}

			$this->assets_container = $factory->create( AssetsContainer::class );
		} catch ( \Throwable $exception ) {

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions
				error_log( 'Failed to create Assets: ' . $exception->getMessage() );
			}
			return;
		}

		$assets->register_assets( $this->assets_list, $this->assets_container );
	}

	/**
	 * Register assets
	 */
	public function enqueue_assets() {
		try {
			$assets = $this->services->get( AssetManager::class );
			$assets->enqueue_assets( $this->assets_container );
			if ( ! is_array( $this->data ) ) {
				return;
			}
			foreach ( $this->data as $data ) {
				$assets->add_data(
					$this->assets_container,
					$data->get_source(),
					$data->get_identifier(),
					$data->get_data(),
				);
			}
		} catch ( \Throwable $exception ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions
				error_log( 'Failed to create Assets: ' . $exception->getMessage() );
			}
			return;
		}
	}
}
