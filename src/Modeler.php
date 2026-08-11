<?php
/**
 * Loads paths and models from the paths
 *
 * This is a simplified version of soberwp/Models
 * @api
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
use Saltus\WP\Framework\MCP\Tools\GetHealth;
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



	/**
	 * Construct the modeler.
	 * @param ModelFactory $model_factory
	 */
	public function __construct( ModelFactory $model_factory ) {
		$this->model_factory = $model_factory;
		// should contain a list of loaded models
	}

	/**
	 * Initialize the modeler.
	 *
	 * @param string $project_path The project path.
	 */
	public function init( string $project_path ): void {
		$path = $this->get_path( $project_path );
		if ( ! $path ) {
			return;
		}
		$this->load( $path );
	}

	/**
	 * Get custom path
	 *
	 * @param string $project_path The project path.
	 *
	 * @return string|null The path.
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
	 * Load Models.
	 *
	 * @param string $path The path to the model
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
				$this->process_config( $config );
			}
		}

		// check for models added with filters
		if ( has_filter( 'saltus_models' ) ) {
			/** @deprecated 1.2.0 */
			$model = apply_filters( 'saltus_models', [] );
			$this->process_config( $model );
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
			$this->process_config( $model );
		}
	}

	/**
	 * Process a single model config or a list of model configs.
	 *
	 * @param AbstractConfig|array<string|int, mixed> $config Model config data.
	 */
	protected function process_config( $config ): void {
		$data = $config instanceof AbstractConfig ? $config->all() : $config;

		if ( $data === [] ) {
			return;
		}

		// Re-wrapped rather than mutated in place: `AbstractConfig` exposes no way
		// to reorder its data, and normalizing here means both the file-loaded and
		// filter-injected paths get the same treatment.
		$wrapped_config = new NoFile( $this->sort_config( $data ) );

		( $this->is_multiple( $wrapped_config ) ?
			$this->iterate_multiple( $wrapped_config ) :
			$this->create( $wrapped_config )
		);
	}

	/**
	 * Is this config a list of models rather than a single one?
	 *
	 * Decided by whether `type` is present at the top level, not by inspecting the
	 * first key. Every registerable model must declare `type` at depth 0 —
	 * `ModelFactory::create()` returns null without it — so a config carrying one
	 * is a single model and a config without one is a map of model-name => config.
	 *
	 * This used to test `is_array( current( ... ) )`, which made the answer depend
	 * on *key order*: a single model listing `labels` before `type` was read as a
	 * list of models, and each of its top-level keys then reached `create()` with
	 * no `type` of its own, so the post type silently never registered. Key order
	 * is now normalized too (see `sort_config()`), but detection no longer relies
	 * on it either way — a multi-model file may legitimately hold a stray scalar.
	 */
	protected function is_multiple( AbstractConfig $config ): bool {
		return ! $config->has( 'type' );
	}

	/**
	 * Normalize top-level key order so authoring order cannot change meaning.
	 *
	 * Scalars first, then array-valued keys, each group keeping its original
	 * relative order. Safe because no consumer reads model config positionally —
	 * every other access is by key — so this only removes order as a variable.
	 *
	 * @param array<string|int, mixed> $config Raw config data.
	 * @return array<string|int, mixed>
	 */
	protected function sort_config( array $config ): array {
		$scalars = array_filter( $config, static fn( $value ): bool => ! is_array( $value ) );
		$arrays  = array_filter( $config, static fn( $value ): bool => is_array( $value ) );

		return $scalars + $arrays;
	}

	/**
	 * Load each from multidimensional config
	 *
	 * Creates a new config from the part
	 */
	protected function iterate_multiple( AbstractConfig $config ): void {
		foreach ( $config as $single_config ) {
			// A stray scalar at the top of a multi-model file is not a model. Without
			// this, `new NoFile( 'string' )` would reach `create()` and fail there.
			if ( ! is_array( $single_config ) ) {
				continue;
			}

			$this->create( new NoFile( $this->sort_config( $single_config ) ) );
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
	*
	 * @param Model $model The model.
	 */
	protected function add( Model $model ): void {
		$this->model_list[ $model->get_name() ] = $model;
	}

	/**
	 * Return all loaded models.
	 *
	 * @return array<string, Model> Associative array keyed by model name.
	 */
	public function get_models(): array {
		return $this->model_list;
	}

	/**
	 * Get rest routes.
	 *
	 * @param Modeler $modeler
	 * @param ModelRestPolicy $policy
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
	 * Get MCP tools.
	 *
	 * @param Modeler $modeler
	 * @param ModelRestPolicy|null $policy
	 * @return list<ToolInterface>
	 */
	public function get_mcp_tools( Modeler $modeler, ?ModelRestPolicy $policy = null ): array {
		return [
			new GetHealth(),
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
