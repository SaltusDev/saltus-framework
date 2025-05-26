<?php
namespace Saltus\WP\Framework\Infrastructure\Plugin;

/**
 * The Project class, where data is defined.
 */
class Project {

	/**
	 * Unique identifier (slug)
	 */
	public $name;

	/**
	 * Current version.
	 */
	public $version;

	/**
	 * Plugin file path
	 */
	public $file_path;


	/**
	 * Setup the class variables
	 *
	 * @param string $name      Plugin name.
	 * @param string $version   Plugin version. Use semver.
	 * @param string $file_path Plugin file path
	 */
	public function __construct( string $name, string $version, string $file_path ) {
		$this->name      = $name;
		$this->version   = $version;
		$this->file_path = $file_path;
	}

	/**
	 * Get the identifier, also used for i18n domain.
	 *
	 * @return string The unique identifier (slug)
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Get the current version.
	 *
	 * @return string The current version.
	 */
	public function get_version() {
		return $this->version;
	}
}
