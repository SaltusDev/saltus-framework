<?php
/**
 * Saltus Framework
 *
 * @version 2.0.0
 * @api
 */
namespace Saltus\WP\Framework;

use Saltus\WP\Framework\Models\ModelFactory;

use Saltus\WP\Framework\Infrastructure\Container\{
	ServiceContainer,
	Container,
	Invalid
};

use Saltus\WP\Framework\Infrastructure\Plugin\{
	Plugin,
	Activateable,
	Deactivateable
};


use Saltus\WP\Framework\Features\AdminCols\AdminCols;
use Saltus\WP\Framework\Features\AdminFilters\AdminFilters;
use Saltus\WP\Framework\Features\DragAndDrop\DragAndDrop;
use Saltus\WP\Framework\Features\Duplicate\Duplicate;
use Saltus\WP\Framework\Features\Meta\Meta;
use Saltus\WP\Framework\Features\QuickEdit\QuickEdit;
use Saltus\WP\Framework\Features\RememberTabs\RememberTabs;
use Saltus\WP\Framework\Features\Settings\Settings;
use Saltus\WP\Framework\Features\SingleExport\SingleExport;
use Saltus\WP\Framework\Features\MCP\MCP;
use Saltus\WP\Framework\Features\Blocks\Blocks;
use Saltus\WP\Framework\Features\WpCli\WpCli;
use Saltus\WP\Framework\Features\Frontend\Frontend;
use Saltus\WP\Framework\Features\AiContext\AiContext;
use Saltus\WP\Framework\Features\AiAssistant\AiAssistant;
use Saltus\WP\Framework\Features\EditorialReview\EditorialReview;
use Saltus\WP\Framework\Features\Privacy\Privacy;
use Saltus\WP\Framework\Features\Relationships\Relationships;
use Saltus\WP\Framework\Features\WebMcp\WebMcp;
use Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy;
use Saltus\WP\Framework\Features\Observability\MetricsApi;
use Saltus\WP\Framework\Features\Observability\ObservabilityDashboard;
use Saltus\WP\Framework\MCP\Tools\ToolContributor;
use Saltus\WP\Framework\Models\Config\ConfigValidationContributor;
use Saltus\WP\Framework\Rest\HealthController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;
use Saltus\WP\Framework\Rest\RestServer;


class Core implements Plugin {

	public const VERSION = '2.0.0';

	/**
	 * Main filters to control the flow of the plugin from outside code.
	 * @var non-empty-string
	 */
	private const SERVICES_FILTER = 'services';

	/**
	 * Prefixes to use.
	 * @var non-empty-string
	 */
	private const HOOK_PREFIX = 'saltus/framework/';


	/**
	 * If services can be filtered out
	 * @var bool
	 */
	protected bool $enable_filters = true;

	/**
	 * Service list
	 **/
	protected ServiceContainer $service_container;

	/** A list of paths and urls */
	/** @var array<string, mixed> */
	protected array $project = [];

	/** Loads paths and models */
	protected ?Modeler $modeler = null;

	/**
	 * Instanciates Services
	 */
	protected ?object $instantiator = null;

	/**
	 * Dedicated registry of RestRouteProvider services.
	 * Populated before the is_needed() gate so REST routes are always
	 * available regardless of the admin/REST_REQUEST context.
	 *
	 * @var list<RestRouteProvider>
	 */
	protected array $rest_route_providers = [];

	/**
	 * Dedicated registry of ToolContributor services.
	 * Populated before the is_needed() gate so MCP tools are always
	 * available regardless of the admin/REST_REQUEST context.
	 *
	 * @var list<ToolContributor>
	 */
	protected array $tool_contributors = [];

	/**
	 * Dedicated registry of ConfigValidationContributor services.
	 *
	 * Populated before the is_needed() gate for a reason specific to validation:
	 * config is validated during `init`, before most features decide they are
	 * needed, and a section whose contributor was gated out would silently stop
	 * being checked. Unvalidated is worse than absent — it looks like a pass.
	 *
	 * @var list<ConfigValidationContributor>
	 */
	protected array $config_contributors = [];

	public function __construct( string $project_path, ?string $plugin_file = null ) {

		//TODO by pcarvalho: move to project class
		$this->project['path']        = $project_path;
		$this->project['plugin_file'] = $plugin_file ?? $project_path;

		// the framework root path
		$this->project['root_path'] = dirname( __DIR__ );

		// the 'plugin-dir' part is just to fool plugins_url to consider the full path
		$this->project['root_url'] = plugins_url( 'vendor/saltus/framework/assets/', $project_path . '/plugin-dir' );

		$this->service_container = new ServiceContainer();
	}

	/**
	 * Register the plugin with the WordPress system.
	 *
	 * @return void
	 */
	public function register(): void {
		$plugin_file = (string) $this->project['plugin_file'];
		if ( is_file( $plugin_file ) ) {
			\register_activation_hook(
				$plugin_file,
				function () {
					$this->activate();
				}
			);

			\register_deactivation_hook(
				$plugin_file,
				function () {
					$this->deactivate();
				}
			);
		}

		// loads models and stores the list

		// 1- Loads Services
		$this->register_services();

		// 2- Create a Model Factory with services container
		$model_factory = new ModelFactory( $this->service_container, $this->project );

		// 3- Create a "store" with a factory, wiring the CLI gateway when available
		$cli_gateway = null;
		if ( $this->service_container->has( 'wp_cli' ) ) {
			$cli_service = $this->service_container->get( 'wp_cli' );
			$cli_gateway = ( $cli_service instanceof \Saltus\WP\Framework\Features\WpCli\WpCli )
				? $cli_service->get_gateway()
				: null;
		}
		$this->modeler = new Modeler(
			$model_factory,
			$cli_gateway,
			function (): array {
				return $this->config_contributors;
			}
		);
		$project_path  = $this->project['path'];
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		$priority = (int) apply_filters( self::HOOK_PREFIX . 'modeler/priority', 1 );
		add_action(
			'init',
			function () use ( $project_path ) {
				$this->modeler->init( $project_path );

				add_action(
					'rest_api_init',
					function () {
						$this->register_rest_routes();
					}
				);

				// If rest_api_init has already fired (e.g., modeler priority >= 100),
				// the hook above will never run, so call routes directly.
				if ( did_action( 'rest_api_init' ) ) {
					$this->register_rest_routes();
				}
			},
			$priority
		);

		// 6- MCP is registered through the default feature list.
	}

	/**
	 * @return list<RestRouteDefinition>
	 */
	private function get_rest_routes( ModelRestPolicy $policy ): array {
		$routes = [
			new RestRouteDefinition(
				ModelRestPolicy::CAPABILITY_HEALTH,
				new HealthController( self::VERSION, null, new WebMcpPolicy( $this->modeler ), $this->modeler )
			),
		];

		$routes = array_merge( $routes, $this->modeler->get_rest_routes( $this->modeler, $policy ) );

		foreach ( $this->rest_route_providers as $provider ) {
			$routes = array_merge( $routes, $provider->get_rest_routes( $this->modeler, $policy ) );
		}

		return $routes;
	}

	/**
	 * If the given service class implements RestRouteProvider, instantiate
	 * it unconditionally and add it to the dedicated registry.
	 *
	 * @param class-string   $service_class Service class name.
	 * @param array<mixed>   $dependencies  Constructor dependencies.
	 */
	private function maybe_register_route_provider( string $service_class, array $dependencies ): void {
		if ( ! is_a( $service_class, RestRouteProvider::class, true ) ) {
			return;
		}

		$instance = $this->service_container->instantiate_unconditionally( $service_class, $dependencies );
		if ( $instance instanceof RestRouteProvider ) {
			$this->rest_route_providers[] = $instance;
		}
	}

	/**
	 * If the given service class implements ToolContributor, instantiate
	 * it unconditionally and add it to the dedicated registry.
	 *
	 * @param class-string   $service_class Service class name.
	 * @param array<mixed>   $dependencies  Constructor dependencies.
	 */
	private function maybe_register_tool_contributor( string $service_class, array $dependencies ): void {
		if ( ! is_a( $service_class, ToolContributor::class, true ) ) {
			return;
		}

		$instance = $this->service_container->instantiate_unconditionally( $service_class, $dependencies );
		if ( $instance instanceof ToolContributor ) {
			$this->tool_contributors[] = $instance;
		}
	}

	/**
	 * If the given service class implements ConfigValidationContributor,
	 * instantiate it unconditionally and add it to the dedicated registry.
	 *
	 * @param class-string $service_class Service class name.
	 * @param array<mixed> $dependencies  Constructor dependencies.
	 */
	private function maybe_register_config_contributor( string $service_class, array $dependencies ): void {
		if ( ! is_a( $service_class, ConfigValidationContributor::class, true ) ) {
			return;
		}

		$instance = $this->service_container->instantiate_unconditionally( $service_class, $dependencies );
		if ( $instance instanceof ConfigValidationContributor ) {
			$this->config_contributors[] = $instance;
		}
	}

	/**
	 * Every registered config validation contributor.
	 *
	 * @return list<ConfigValidationContributor>
	 */
	public function get_config_contributors(): array {
		return $this->config_contributors;
	}

	private function register_rest_routes(): void {
		$rest_policy = new ModelRestPolicy( $this->modeler );
		$rest_server = new RestServer( $rest_policy, $this->get_rest_routes( $rest_policy ) );
		$rest_server->register_routes();
	}

	/**
	 * Activate the plugin.
	 *
	 * @return void
	 */
	public function activate(): void {
		$this->register_services();

		foreach ( $this->service_container as $service ) {
			if ( $service instanceof Activateable ) {
				$service->activate();
			}
		}

		\flush_rewrite_rules();
	}

	/**
	 * Deactivate the plugin.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		$this->register_services();

		foreach ( $this->service_container as $service ) {
			if ( $service instanceof Deactivateable ) {
				$service->deactivate();
			}
		}

		\flush_rewrite_rules();
	}

	/**
	 * Register the individual services of this plugin.
	 *
	 * @throws Invalid If a service is not valid.
	 *
	 * @return void
	 */
	public function register_services(): void {

		// Bail early so we don't instantiate services twice.
		if ( count( $this->service_container ) > 0 ) {
			return;
		}

		// Add the injector as the very first service.
		//TODO by pcarvalho: add injectors
		$services = $this->get_service_classes();

		if ( $this->enable_filters ) {
			/**
			 * Filter the default services that make up this plugin.
			 *
			 * This can be used to add services to the service container for
			 * this plugin.
			 *
			 * @param array<string> $services Associative array of identifier =>
			 *                                class mappings. The provided
			 *                                classes need to implement the
			 *                                Service interface.
			 */
			$hook_name = self::HOOK_PREFIX . self::SERVICES_FILTER;
			$services  = \apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
				$hook_name,
				$services
			);
		}

		$dependencies = [
			'project'             => $this->project,
			'modeler'             => $this->modeler,
			'modeler_resolver'    => function (): ?Modeler {
				return $this->modeler;
			},
			'services'            => $this->service_container,
			'tool_contributors'   => function (): array {
				return $this->tool_contributors;
			},
			// A closure, not the array: the registry is still being filled while
			// dependencies are handed out, so a snapshot taken here would be empty.
			'config_contributors' => function (): array {
				return $this->config_contributors;
			},
		];

		// First pass: populate dedicated registries for RestRouteProvider
		// and ToolContributor unconditionally (bypasses is_needed()).
		// REST routes and MCP tools must be available even when the
		// admin-facing gate returns false during plugin boot.
		foreach ( $services as $service_class ) {
			$this->maybe_register_route_provider( $service_class, $dependencies );
			$this->maybe_register_tool_contributor( $service_class, $dependencies );
			$this->maybe_register_config_contributor( $service_class, $dependencies );
		}

		// Second pass: register services with the is_needed() gate.
		// This determines whether admin hooks (Registerable, Actionable,
		// HasAssets) are wired up.
		foreach ( $services as $id => $service_class ) {
			$service_id = is_string( $id ) ? $id : $service_class;
			$this->service_container->register( $service_id, $service_class, $dependencies );
		}
	}

	/**
	 * Get the list of services to register.
	 *
	 * Protected and non-static so a consumer subclass can override it and have its
	 * list used: `register_services()` dispatches through `$this`. It was briefly
	 * `public static` — invoked via `self::` — purely so config validation could
	 * reach the map without a `Core` instance; that silently bypassed every
	 * override, and validation no longer needs it.
	 *
	 * @return array<string, class-string> Associative array of identifiers mapped
	 *                                     to fully qualified class names.
	 */
	protected function get_service_classes(): array {
		return [
			'admin_cols'       => AdminCols::class,
			'admin_filters'    => AdminFilters::class,
			'ai_context'       => AiContext::class,
			'ai_assistant'     => AiAssistant::class,
			'editorial_review' => EditorialReview::class,
			'blocks'           => Blocks::class,
			'draganddrop'      => DragAndDrop::class,
			'duplicate'        => Duplicate::class,
			'frontend'         => Frontend::class,
			'meta'             => Meta::class,
			'mcp'              => MCP::class,
			'privacy'          => Privacy::class,
			'quick_edit'       => QuickEdit::class,
			'relationships'    => Relationships::class,
			'remember_tabs'    => RememberTabs::class,
			'settings'         => Settings::class,
			'single_export'    => SingleExport::class,
			'webmcp'           => WebMcp::class,
			'metrics_api'      => MetricsApi::class,
			'observability'    => ObservabilityDashboard::class,
			'wp_cli'           => WpCli::class,
		];
	}


	/**
	 * Get the Container that contains the services that make up the
	 * plugin.
	 *
	 * @return Container<string, mixed> Container of the plugin.
	 */
	public function get_container(): Container {
		return $this->service_container;
	}
}
