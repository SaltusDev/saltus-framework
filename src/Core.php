<?php
/**
 * Saltus Framework
 *
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


use Saltus\WP\Framework\Features\Meta\Meta;
use Saltus\WP\Framework\Features\Settings\Settings;
use Saltus\WP\Framework\Features\DragAndDrop\DragAndDrop;
use Saltus\WP\Framework\Features\RememberTabs\RememberTabs;
use Saltus\WP\Framework\Features\Duplicate\Duplicate;
use Saltus\WP\Framework\Features\SingleExport\SingleExport;
use Saltus\WP\Framework\Features\AdminCols\AdminCols;
use Saltus\WP\Framework\Features\AdminFilters\AdminFilters;


class Core implements Plugin {

	// Main filters to control the flow of the plugin from outside code.
	const SERVICES_FILTER = 'services';

	// Prefixes to use.
	const HOOK_PREFIX    = '';
	const SERVICE_PREFIX = '';


	/**
	 * If services can be filtered out
	 * @var bool */
	protected $enable_filters;

	/**
	 * Service list
	 **/
	protected $service_container;

	/** A list of paths and urls */
	protected $project = [];

	/** Loads paths and models */
	protected $modeler;

	/**
	 * Instanciates Services
	 */
	protected $instantiator;

	public function __construct( string $project_path ) {

		//TODO by pcarvalho: move to project class
		$this->project['path'] = $project_path;

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
	public function register() {
		// Todo validate key:
		\register_activation_hook(
			__FILE__,
			function () {
				$this->activate();
			}
		);

		\register_deactivation_hook(
			__FILE__,
			function () {
				$this->deactivate();
			}
		);

		// loads models and stores the list

		// 1- Loads Services
		$this->register_services();

		// 2- Create a Model Factory with services container
		$model_factory = new ModelFactory( $this->service_container, $this->project );

		// 3- Create a "store" with a factory
		$this->modeler = new Modeler( $model_factory );
		$project_path  = $this->project['path'];
		$priority = apply_filters( 'saltus_modeler_priority', 1 );
		add_action(
			'init',
			function () use ( $project_path ) {
				$this->modeler->init( $project_path );
			},
			$priority
		);

		// 4- When the store starts ( init() ), it will ask the factory to make a cpt/tax
		// and stores the result in either list (cpt or tax list )
		// TODO
	}

	/**
	 * Activate the plugin.
	 *
	 * @return void
	 */
	public function activate() {
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
	public function deactivate() {
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
	public function register_services() {

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
			$services = \apply_filters(
				static::HOOK_PREFIX . static::SERVICES_FILTER,
				$services
			);
		}

		$dependencies = [ $this->project ];
		foreach ( $services as $id => $class ) {
			$this->service_container->register( $id, $class, $dependencies );
		}
	}

	/**
	 * Get the list of services to register.
	 *
	 * @return array<string> Associative array of identifiers mapped to fully
	 *                       qualified class names.
	 */
	protected function get_service_classes(): array {
		return [
			'admin_cols'    => AdminCols::class,
			'admin_filters' => AdminFilters::class,
			'draganddrop'   => DragAndDrop::class,
			'remember_tabs' => RememberTabs::class,
			'duplicate'     => Duplicate::class,
			'meta'          => Meta::class,
			'settings'      => Settings::class,
			'single_export' => SingleExport::class,
		];
	}


	/**
	 * Get the Container that contains the services that make up the
	 * plugin.
	 *
	 * @return Container Container of the plugin.
	 */
	public function get_container(): Container {
		return $this->service_container;
	}
}
