<?php
// class to hold assets

namespace Saltus\WP\Framework\Infrastructure\Services\Assets;

use Saltus\WP\Framework\Infrastructure\Service\Service;

class Asset implements Service {

	/**
	 * The asset name.
	 */
	public string $source;

	/**
	 * The asset type.
	 */
	public string $type;

	/**
	 * The asset dependencies.
	 */
	/** @var array<int, string> */
	public array $dependencies;

	/**
	 * indicates if the asset should be loaded in the footer.
	 */
	public bool $in_footer;

	/**
	 * Constructor.
	 *
	 * @param string $src          File path/URL (e.g., "assets/js/script.js")
	 * @param array<int, string> $dependencies Script/style dependencies (e.g., ['jquery'])
	 * @param bool   $in_footer    Load in footer (for scripts only)
	 * @param string $type         Explicitly set "script" or "style" (auto-detected if empty)
	 */
	public function __construct( string $src, array $dependencies = [], bool $in_footer = false, string $type = '' ) {
		$this->source       = $src;
		$this->dependencies = $dependencies;
		$this->in_footer    = $in_footer;

		if ( ! empty( $type ) ) {
			$this->type = $type;
			return;
		}
		// Strip query strings
		$ext = pathinfo( explode( '?', $src )[0], PATHINFO_EXTENSION );
		if ( $ext === 'js' ) {
			$this->type = 'script';
			return;
		} elseif ( $ext === 'css' ) {
			$this->type = 'style';
			return;
		}
		$this->type = 'unknown';
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
	 * Get the asset type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return $this->type;
	}
	/**
	 * Get the asset dependencies.
	 *
	 * @return array<int, string>
	 */
	public function get_dependencies(): array {
		return $this->dependencies;
	}

	/**
	 * Get the asset in_footer.
	 *
	 * @return bool
	 */
	public function get_in_footer(): bool {
		return $this->in_footer;
	}
}
