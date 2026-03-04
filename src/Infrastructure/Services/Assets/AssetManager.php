<?php
namespace Saltus\WP\Framework\Infrastructure\Services\Assets;

use Saltus\WP\Framework\Infrastructure\Plugin\Project;
use Saltus\WP\Framework\Infrastructure\Container\Invalid;
use Saltus\WP\Framework\Infrastructure\Service\Service;

/**
 * Manage Assets like scripts and styles.
 */
class AssetManager implements Service {

	private $project;

	/**
	 * Suffix for filename
	 */
	public $suffix;

	/**
	 * Assets Directory
	 */
	public $dir;

	/**
	 * Plugin Root Directory
	 */
	public $root_file_path;

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct( $dependencies ) {
		if ( empty( $dependencies['project'] ) || ! $dependencies['project'] instanceof Project ) {
			throw Invalid::from( 'project' );
		}

		if ( ! defined( 'SALTUS_ENV' ) ) {
			define( 'SALTUS_ENV', 'development' );
		}
		$this->project = $dependencies['project'];
		$this->dir     = SALTUS_ENV === 'development' ? '' : 'dist';
		$this->suffix  = SALTUS_ENV === 'development' ? '' : '.min';

		$this->root_file_path = $this->project->file_path;
	}


	/**
	 * Load admin assets.
	 *
	 * @param string   $src          Path of the script relative to the assets directory.
	 * @param string[] $dependencies Optional. An array of registered script handles. Default empty array.
	 *
	 */
	public function load_admin_styles( $src, $dependencies = [] ) {

		add_action(
			'admin_enqueue_scripts',
			function () use ( $src, $dependencies ) {

				global $typenow;
				global $pagenow;
				// only load on necessary pages
				if ( ( $typenow === 'iglobe' &&
						( $pagenow === 'post.php' || $pagenow === 'post-new.php' ) ) ||
						( $typenow === 'iglobe' && $pagenow === 'edit.php' ) // subpages
						) {
					$src  = $this->prepare_src( $src );
					$name = $this->register_fullpath_style( $src, $dependencies );
					wp_enqueue_style( $name );
				}
			}
		);
	}

	/**
	 * Prepare source URL for enqueuing assets by adding the leading path and the production suffix.
	 *
	 * @param string $src Path of the script relative to the assets directory.
	 *
	 * @return string
	 */
	private function prepare_src( $src ) {
		$src_path = dirname( $src );
		$src_name = pathinfo( $src, PATHINFO_FILENAME );
		$src_ext  = pathinfo( $src, PATHINFO_EXTENSION );

		$src_rel_path = "{$this->dir}{$src_path}/{$src_name}{$this->suffix}.{$src_ext}";
		return plugins_url( $src_rel_path, $this->project->file_path );
	}

	/**
	 * Prepare name for enqueuing asset
	 * Strips path from $src
	 *
	 * @param string $src Path of the script relative to the assets directory.
	 * Uses the filename and it will automatically convert to the registered name.
	 * Follows the pattern: <project_name> + extension + <filename>
	 *
	 * @return string
	 */
	private function prepare_name( $src ) {
		$src_name = pathinfo( $src, PATHINFO_FILENAME );
		$src_ext  = pathinfo( $src, PATHINFO_EXTENSION );
		if ( SALTUS_ENV !== 'development' ) {
			$src_name = str_replace( $this->suffix, '', $src_name );
		}
		$src_name = $this->project->name . '_' . $src_ext . '_' . $src_name;
		return $src_name;
	}

	/**
	 * Prepare dependencies for enqueuing asset
	 *
	 * @param string[] $dependencies An array of registered script handles
	 *
	 * @return string[]
	 */
	private function prepare_dependencies( $dependencies ) {
		foreach ( $dependencies as $index => $dependency_name ) {
			$dependency_src         = $this->prepare_src( $dependency_name );
			$dependencies[ $index ] = $this->prepare_name( $dependency_src );
		}
		return $dependencies;
	}

	/**
	 * Wrapper for any local style, skips name, version and media parameter
	 *
	 * @param string   $src          Path of the script relative to the assets directory.
	 * @param string[] $dependencies Optional. An array of registered script handles. Default empty array.
	 *
	 * @return string The name used to register the asset
	 */
	public function register_style( $src = '', $dependencies = [] ) {
		$src = $this->prepare_src( $src );
		return $this->register_fullpath_style( $src, $dependencies );
	}

	/**
	 * Wrapper for any style, skips name and version parameter. Doesn't transform $src
	 *
	 * @param string   $src          Path or URL to the script.
	 * @param string[] $dependencies Optional. An array of registered script handles. Default empty array.
	 *
	 * @return string The name used to register the asset
	 */
	public function register_fullpath_style( $src = '', $dependencies = [] ) {

		$name         = $this->prepare_name( $src );
		$dependencies = $this->prepare_dependencies( $dependencies );
		wp_register_style(
			$name,
			$src,
			$dependencies,
			$this->project->version
		);
		return $name;
	}

	/**
	 * Wrapper for any local script, skips name and version parameter
	 *
	 * @param string   $src          Path of the script relative to the assets directory.
	 * @param string[] $dependencies Optional. An array of registered script handles. Default empty array.
	 * @param bool     $in_footer    Optional. Whether to enqueue the script before instead of in the . Default 'false'.
	 *
	 * @return string The name used to register the asset
	 */
	public function register_script( $src = '', $dependencies = [], $in_footer = \false ) {
		$src = $this->prepare_src( $src );
		return $this->register_fullpath_script( $src, $dependencies, $in_footer );
	}

	/**
	 * Wrapper for any script, skips name and version parameter. Doesn't transform $src
	 *
	 * @param string   $src          Path or URL to the script.
	 * @param string[] $dependencies Optional. An array of registered script handles. Default empty array.
	 * @param bool     $in_footer    Optional. Where to enqueue. Default 'false'.
	 *
	 * @return string The name used to register the asset
	 */
	public function register_fullpath_script( $src = '', $dependencies = [], $in_footer = \false ) {

		$name         = $this->prepare_name( $src );
		$dependencies = $this->prepare_dependencies( $dependencies );
		wp_register_script(
			$name,
			$src,
			$dependencies,
			$this->project->version,
			$in_footer
		);

		return $name;
	}

	/**
	 * Register Assets to the Container
	 *
	 * @param AssetsContainer $assets_container The container to hold the asset.
	 * @param Asset           $asset            The asset to register.
	 *
	 * @return string The name used to register the asset
	 */
	public function register_asset( AssetsContainer $assets_container, Asset $asset ) {

		if ( $asset->type === 'style' ) {
			$name = $this->register_style(
				$asset->source,
				$asset->dependencies
			);

			$assets_container->put( $name, $asset );
			return $name;
		}
		if ( $asset->type === 'script' ) {
			$name = $this->register_script(
				$asset->source,
				$asset->dependencies,
				$asset->in_footer
			);

			$assets_container->put( $name, $asset );
			return $name;
		}
		return '';
	}

	/**
	 * Register multiple assets to the Container
	 *
	 * @param array|ArrayAccess $assets_list     The list of assets to register.
	 * @param AssetsContainer   $assets_container The container to hold the assets.
	 *
	 * @return void
	 */
	public function register_assets( $assets_list, AssetsContainer $assets_container ) {

		if ( is_array( $assets_list ) || $assets_list instanceof \ArrayAccess ) {
			foreach ( $assets_list as $asset ) {
				$this->register_asset( $assets_container, $asset );
			}
		}
	}

	/**
	 * Enqueue all registered assets.
	 *
	 * @param AssetsContainer $assets_container The container holding the assets to enqueue.
	 *
	 * @return void
	 */
	public function enqueue_assets( $assets_container ) {
		if ( ! $assets_container instanceof AssetsContainer ) {
			return;
		}
		foreach ( $assets_container->getAll() as $asset ) {
			$name = $this->prepare_name( $asset->source );
			if ( $asset->type === 'script' ) {
				wp_enqueue_script( $name );
			} elseif ( $asset->type === 'style' ) {
				wp_enqueue_style( $name );
			}
		}
	}

	/**
	 * Enqueue all registered assets.
	 *
	 * @param string $handle      The handle of the asset to enqueue.
	 * @param string $obj_js_name The name of the JavaScript object to localize.
	 * @param array  $data_list   The data to localize.
	 *
	 * @return void
	 */
	public function add_data( string $handle, string $obj_js_name, array $data_list ) {
		$name = $this->prepare_name( $handle );
		wp_localize_script(
			$name,
			$obj_js_name,
			$data_list,
		);
	}

	/**
	 * Register a Gutenberg block.
	 *
	 * @param string $block_name    The handle.
	 * @param string $script_handle The handle of the script to enqueue.
	 * @param string $style_handle  The handle of the style to enqueue.
	 * @param array  $data          The data for the block.
	 *
	 * @return void
	 */
	public function register_gutenberg_block(
		string $block_name,
		string $script_handle,
		string $style_handle,
		array $data
	) {
		$data['editor_script'] = $this->prepare_name( $script_handle );
		$data['editor_style']  = $this->prepare_name( $style_handle );
		register_block_type(
			$this->prepare_name( $block_name ),
			$data
		);
	}
}
