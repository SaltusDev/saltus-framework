<?php
/**
 * Loads paths and models from the paths
 *
 * This is a simplified version of soberwp/Models
 */
namespace Saltus\WP\Framework;

use Noodlehaus\AbstractConfig;
use Noodlehaus\Config;
use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Models\ModelFactory;
use Saltus\WP\Framework\MCP\Tools\CreatePost;
use Saltus\WP\Framework\MCP\Tools\CreateTerm;
use Saltus\WP\Framework\MCP\Tools\DeletePost;
use Saltus\WP\Framework\MCP\Tools\GetModel;
use Saltus\WP\Framework\MCP\Tools\GetPost;
use Saltus\WP\Framework\MCP\Tools\ListModels;
use Saltus\WP\Framework\MCP\Tools\ListPosts;
use Saltus\WP\Framework\MCP\Tools\ListTerms;
use Saltus\WP\Framework\MCP\Tools\ToolContributor;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\MCP\Tools\UpdatePost;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\ModelsController;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;

class Modeler implements RestRouteProvider, ToolContributor {

	protected ModelFactory $model_factory;

	/** @var array<string, Model> */
	protected array $model_list = [];

	public function __construct( ModelFactory $model_factory ) {
		$this->model_factory = $model_factory;
		// should contain a list of loaded models
	}

	public function init( string $project_path ): void {
		$path = $this->get_path( $project_path );
		if ( ! $path ) {
			return;
		}
		$this->load( $path );
	}

	/**
	 * Get custom path
	 */
	protected function get_path( string $project_path ): ?string {

		$path = $project_path . '/src/models/';
		if ( has_filter( 'saltus_models_path' ) ) {
			/** @deprecated 1.2.0 */
			$path = apply_filters( 'saltus_models_path', $path );
		}
		if ( has_filter( 'saltus/framework/models/path' ) ) {
			$path = apply_filters( 'saltus/framework/models/path', $path );
		}

		if ( file_exists( $path ) ) {
			return $path;
		}
		return null;
	}

	/**
	 * Load Models
	 */
	protected function load( string $path ): void {
		if ( file_exists( $path ) ) {
			$path_dir      = new \RecursiveDirectoryIterator( $path );
			$path_dir_iter = new \RecursiveIteratorIterator( $path_dir );

			$files = [];
			foreach ( $path_dir_iter as $filename => $file ) {
				if ( ! in_array( pathinfo( $file, PATHINFO_EXTENSION ), [ 'json', 'php', 'yml', 'yaml' ], true ) ) {
					continue;
				}
				$files[] = $file; // Collect valid files
			}

			// sort by ascending names so it loads in the desired order
			usort(
				$files,
				function ( $a, $b ) {
					return strcmp( $a->getFilename(), $b->getFilename() );
				}
			); // Sort by filename

			foreach ( $files as $file ) { // Iterate over sorted files
				$config = new Config( $file );
				( $this->is_multiple( $config ) ?
					$this->iterate_multiple( $config ) :
					$this->create( $config )
				);
			}
		}

		// check for models added with filters
		if ( has_filter( 'saltus_models' ) ) {
			/** @deprecated 1.2.0 */
			$model = apply_filters( 'saltus_models', [] );
			( ! empty( $model ) && count( $model ) > 0 ?
					$this->iterate_multiple( $model ) :
					$this->create( $model )
				);
		}
		// check for models added with filters
		if ( has_filter( 'saltus/framework/models/extra_models' ) ) {
			/**
			 * parse the models and create them.
			 * Useful for models that are the parsed models
			 *
			 * @param array $empty_list Empty list for extra models
			 */
			$empty_list = [];
			$model      = apply_filters( 'saltus/framework/models/extra_models', $empty_list );
			( ! empty( $model ) && count( $model ) > 0 ?
					$this->iterate_multiple( $model ) :
					$this->create( $model )
				);
		}
	}

	/**
	 * Is multidimensional config
	 */
	protected function is_multiple( AbstractConfig $config ): bool {
		return ( is_array( current( $config->all() ) ) );
	}

	/**
	 * Load each from multidimensional config
	 *
	 * Creates a new config from the part
	 */
	protected function iterate_multiple( AbstractConfig $config ): void {
		foreach ( $config as $single_config ) {
			$this->create( new NoFile( $single_config ) );
		}
	}

	/**
	 * Creates the model in the factory
	 *
	 * @param $config The set of configurations for the cpt/tax
	 */
	protected function create( AbstractConfig $config ): void {
		$model = $this->model_factory->create( $config );
		if ( $model === null ) {
			return;
		}
		$this->add( $model );
	}

	/**
	 * Adds the model to a list
	 */
	protected function add( Model $model ): void {
		$this->model_list[ $model->get_name() ] = $model;
	}

	/**
	 * Return all loaded models.
	 *
	 * @return array<string, \Saltus\WP\Framework\Models\Model> Associative array keyed by model name.
	 */
	public function get_models(): array {
		return $this->model_list;
	}

	/**
	 * @return list<RestRouteDefinition>
	 */
	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array {
		return [
			new RestRouteDefinition(
				ModelRestPolicy::CAPABILITY_MODELS,
				new ModelsController( $this, $policy )
			),
		];
	}

	/**
	 * @return list<ToolInterface>
	 */
	public function get_mcp_tools( Modeler $modeler, ?ModelRestPolicy $policy = null ): array {
		return [
			new ListModels(),
			new GetModel(),
			new ListPosts(),
			new GetPost(),
			new CreatePost(),
			new UpdatePost(),
			new DeletePost(),
			new ListTerms(),
			new CreateTerm(),
		];
	}
}
