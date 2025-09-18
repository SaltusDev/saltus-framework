<?php
// class to hold asset's data
namespace Saltus\WP\Framework\Infrastructure\Services\Assets;

use Saltus\WP\Framework\Infrastructure\Service\Service;

class AssetData implements Service {

	/**
	 * The asset name.
	 */
	public string $source;

	/**
	 * The asset identifier.
	 */
	public string $identifier;

	/**
	 * The asset data.
	 */
	public array $data;

	/**
	 * Constructor.
	 *
	 * @param string $src          File path/URL (e.g., "assets/js/script.js")
	 */
	public function __construct( string $src, string $identifier, array $data = [] ) {
		$this->source     = $src;
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
