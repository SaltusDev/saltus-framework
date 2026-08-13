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
use Saltus\WP\Framework\Models\Config\ConfigValidationContributor;
use Saltus\WP\Framework\Models\Config\ConfigValidator;
use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Models\ConfigError;
use Saltus\WP\Framework\Models\ConfigValidationResult;
use Saltus\WP\Framework\Models\ConfigValidationSummary;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Models\ModelFactory;
use Saltus\WP\Framework\Features\WpCli\CliGateway;
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

	/** Built on first use; validation is not needed until a model arrives. */
	private ?ConfigValidator $config_validator = null;

	/**
	 * Accumulated verdicts, or null until the first config is validated.
	 *
	 * Null and empty mean different things to a caller: null is "nothing has been
	 * loaded yet, so the question cannot be answered", while an empty summary is
	 * "loading ran and found no configs". Health and the CLI both report the former
	 * as unavailable rather than as a valid site.
	 */
	private ?ConfigValidationSummary $config_validation = null;

	/** CLI gateway for reporting config problems without corrupting stdout. */
	private ?CliGateway $cli_gateway = null;

	/**
	 * Resolves the features owning their own config sections.
	 *
	 * @var callable|null
	 */
	private $config_contributors = null;

	/** @var array<string, Model> */
	protected array $model_list = [];



	/**
	 * Construct the modeler.
	 * @param ModelFactory $model_factory
	 * @param CliGateway|null $cli_gateway Optional gateway for CLI reporting.
	 */
	/**
	 * @param callable|null $config_contributors Returns the registered contributors.
	 */
	public function __construct( ModelFactory $model_factory, ?CliGateway $cli_gateway = null, ?callable $config_contributors = null ) {
		$this->model_factory = $model_factory;
		$this->cli_gateway   = $cli_gateway;
		// A resolver rather than the list: Core is still filling its registry when
		// the modeler is built, so a snapshot taken here would always be empty.
		$this->config_contributors = $config_contributors;
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
	 * Validation happens here rather than in `process_config()` because this is the
	 * one point every model passes through — single configs and each child of a
	 * multi-model file alike — so a model cannot reach the factory unchecked.
	 *
	 * @param $config The set of configurations for the cpt/tax
	 */
	protected function create( AbstractConfig $config ): void {
		if ( ! $this->passes_validation( $config ) ) {
			return;
		}

		$model = $this->model_factory->create( $config );
		if ( $model === null ) {
			return;
		}
		$this->add( $model );
	}

	/**
	 * Validate a config, log what was found, and report whether to proceed.
	 *
	 * Errors stop registration; warnings are logged and the model registers anyway.
	 * The verdict is cached against a hash of the config, so the common case of an
	 * unchanged config costs a hash rather than a full walk. There is no file path
	 * or mtime to key on — configs are plain arrays by the time they arrive, and
	 * filter-injected ones never had a file — so content is the only stable key,
	 * and a changed config simply produces a different one.
	 */
	protected function passes_validation( AbstractConfig $config ): bool {
		$data   = $config->all();
		$result = $this->validation_verdict( $data );

		// Recorded before the error check below returns, so a rejected model still
		// appears in the summary. A config that failed to register is exactly what
		// health and the CLI need to report.
		$this->config_validation = ( $this->config_validation ?? new ConfigValidationSummary() )->with( $result );

		foreach ( $result->get_warnings() as $warning ) {
			$this->report_config_problem( $warning, $data );
		}

		if ( ! $result->has_errors() ) {
			return true;
		}

		foreach ( $result->get_errors() as $error ) {
			$this->report_config_problem( $error, $data );
		}

		return false;
	}

	/**
	 * Every model's verdict, or null when nothing has been validated yet.
	 *
	 * Null is the honest answer before `init()` runs: reporting a valid site
	 * because no config has been examined would be the same mistake the v1.8.4
	 * audit fix corrected — an absent source read as a clean result.
	 */
	public function get_config_validation(): ?ConfigValidationSummary {
		return $this->config_validation;
	}

	/**
	 * The cached verdict for a config, computing and storing it when absent.
	 *
	 * @param array<string|int, mixed> $data
	 */
	private function validation_verdict( array $data ): ConfigValidationResult {
		$cache_key = 'saltus_config_valid_' . md5( (string) wp_json_encode( $data ) );

		if ( $this->validation_cache_enabled() ) {
			$cached = get_transient( $cache_key );

			// Stored as a plain array, not an object: a serialized instance would break
			// the moment either value object changes shape.
			if ( is_array( $cached ) ) {
				return ConfigValidationResult::from_array( $cached );
			}
		}

		$result = $this->config_validator()->validate( $data );

		if ( $this->validation_cache_enabled() ) {
			// Same fallback AuditLogger uses: the constant is WordPress-only, and this
			// class is exercised outside it.
			$day = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
			set_transient( $cache_key, $result->to_array(), $day );
		}

		return $result;
	}

	/**
	 * Whether verdicts may be cached.
	 *
	 * Off while developing, where a config changes constantly and a stale verdict
	 * is worse than recomputing. `saltus/framework/config/cache_validation`.
	 */
	private function validation_cache_enabled(): bool {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return false;
		}

		$enabled = ! ( defined( 'WP_DEBUG' ) && WP_DEBUG );

		return (bool) apply_filters( 'saltus/framework/config/cache_validation', $enabled );
	}

	/**
	 * Surface one config problem where a developer will see it.
	 *
	 * `_doing_it_wrong` is the WordPress-native channel for "you have configured
	 * this incorrectly": it respects WP_DEBUG, is capturable in tests, and does not
	 * write to the audit log, which records agent activity rather than authoring
	 * mistakes.
	 *
	 * Under WP-CLI it goes to the warning channel instead. `_doing_it_wrong` raises
	 * a PHP notice, and a notice raised during registration prints to **stdout** —
	 * inside whatever a command is emitting. One stray line makes `--format=json`
	 * unparseable, so every `wp saltus` call looks broken even when registration
	 * succeeded. The CliGateway abstraction provides warning(), which routes to
	 * stderr and keeps stdout clean for piping.
	 *
	 * @param array<string|int, mixed> $data Config the problem was found in.
	 */
	private function report_config_problem( ConfigError $problem, array $data ): void {
		$report = $problem->describe() . "\n" . $problem->render_excerpt( $data );

		if ( $this->cli_gateway !== null ) {
			$this->cli_gateway->warning( $report );

			return;
		}

		if ( ! function_exists( '_doing_it_wrong' ) ) {
			return;
		}

		_doing_it_wrong( 'Saltus model config', esc_html( $report ), '1.8.5' );
	}

	/** The validator, built once per Modeler. */
	private function config_validator(): ConfigValidator {
		if ( ! $this->config_validator instanceof ConfigValidator ) {
			$contributors = [];

			if ( is_callable( $this->config_contributors ) ) {
				$resolved = ( $this->config_contributors )();
				if ( is_array( $resolved ) ) {
					$contributors = array_values(
						array_filter(
							$resolved,
							static fn( $c ): bool => $c instanceof ConfigValidationContributor
						)
					);
				}
			}

			$this->config_validator = new ConfigValidator( null, $contributors );
		}

		return $this->config_validator;
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
