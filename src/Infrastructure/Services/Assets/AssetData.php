<?php

namespace Saltus\WP\Framework\Infrastructure\Services\Assets;

use Saltus\WP\Framework\Infrastructure\Service\Service;

/**
 * A data container for assets to be localized.
 *
 * This class holds the data that will be made available to a specific script
 * using `wp_localize_script`.
 */
class AssetData implements Service {

	/**
	 * The asset source path, used as a handle.
	 */
	private string $source;

	/**
	 * Name for the JavaScript object that will contain the data.
	 */
	private string $identifier;

	/**
	 * The data to be made available to the script.
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param string $source       File path/URL (e.g., "assets/js/script.js").
	 * @param string $identifier   Name for the JavaScript object that will contain the data.
	 * @param array  $data         The data to be made available to the script.
	 */
	public function __construct( string $source, string $identifier, array $data = [] ) {
		$this->source     = $source;
		$this->identifier = $identifier;
		$this->data       = $data;
	}

	/**
	 * Get the asset source.
	 *
	 * @return string
	 */
	public function get_source(): string {
		return $this->source;
	}

	/**
	 * Get the asset identifier.
	 *
	 * @return string
	 */
	public function get_identifier(): string {
		return $this->identifier;
	}

	/**
	 * Get the asset data.
	 *
	 * @return array
	 */
	public function get_data(): array {
		return $this->data;
	}
}
