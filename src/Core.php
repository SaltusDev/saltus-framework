<?php
/**
 * Saltus Framework
 *
 */
namespace Saltus\WP\Framework;

use Saltus\WP\Framework\Models\ModelFactory;
use Saltus\WP\Framework\Infrastructure\Service\{
	App,
	Service,
	ServiceContainer,
	Conditional,
	Instantiator,
	// Exceptions:
	FailedToMakeInstance,
	Invalid
};

use Saltus\WP\Framework\Exception\SaltusFrameworkThrowable;

use ReflectionClass;

use Saltus\WP\Framework\Infrastructure\Plugin\{
	Plugin,
	Registerable,
	Activateable,
	Deactivateable
};

use Saltus\WP\Framework\Infrastructure\Service\{
	Actionable
};

use Saltus\WP\Framework\Features\Meta\Meta;
use Saltus\WP\Framework\Features\Settings\Settings;
use Saltus\WP\Framework\Features\DragAndDrop\DragAndDrop;
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


	/** @var bool */
	protected $enable_filters;

	/** @var ServiceContainer */
	protected $service_container;

	protected $project = [];

	protected $modeler;

	protected $model_list;

	public function __construct( string $project_path ) {

		//TODO by pcarvalho: move to project class
		$this->project['path'] = $project_path;

		// the framework root path
		$this->project['root_path'] = dirname( __DIR__ );

		// the 'plugin-dir' part is just to fool plugins_url to consider the full path
		$this->project['root_url'] = plugins_url( 'vendor/saltus/framework/assets/', $project_path . '/plugin-dir' );

		$this->instantiator = $this->get_fallback_instantiator();

		$this->service_container = new App();
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

		foreach ( $services as $id => $class ) {
			$this->register_service( $id, $class );
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
			'meta'          => Meta::class,
			'settings'      => Settings::class,
			'draganddrop'   => DragAndDrop::class,
			'duplicate'     => Duplicate::class,
			'single_export' => SingleExport::class,
			'admin_cols'    => AdminCols::class,
			'admin_filters' => AdminFilters::class,
		];
	}

	/**
	 * Register a single service.
	 *
	 * @param string $id
	 * @param string $class
	 */
	protected function register_service( string $id, string $class ) {

		// Only instantiate services that are actually needed.
		if ( is_a( $class, Conditional::class, true ) &&
			! $class::is_needed() ) {
			return;
		}

		$service = $this->instantiate_service( $class );

		$this->service_container->put( $id, $service );

		if ( $service instanceof Registerable ) {
			$service->register();
		}

		if ( $service instanceof Actionable ) {
			add_action(
				'init',
				function () use ( $service ) {
					$service->add_action();
				},
				1
			);
		}

	}


	/**
	 * Get the service container that contains the services that make up the
	 * plugin.
	 *
	 * @return ServiceContainer Service container of the plugin.
	 */
	public function get_container(): ServiceContainer {
		return $this->service_container;
	}

	/**
	 * Instantiate a single service.
	 *
	 * @param string $class Service class to instantiate.
	 *
	 * @throws Invalid If the service could not be properly instantiated.
	 *
	 * @return Service Instantiated service.
	 */
	protected function instantiate_service( $class ): Service {

		// The service needs to be registered, so instantiate right away.
		$service = $this->make( $class );

		if ( ! $service instanceof Service ) {
			throw Invalid::from_service( $service );
		}

		return $service;
	}

	/**
	 * Make an object instance out of an interface or class.
	 *
	 * @param string $interface_or_class Interface or class to make an object
	 *                                   instance out of.
	 * @param array  $arguments          Optional. Additional arguments to pass
	 *                                   to the constructor. Defaults to an
	 *                                   empty array.
	 * @return object Instantiated object.
	 */
	public function make( string $interface_or_class, array $arguments = [] ) {

		$reflection = $this->get_class_reflection( $interface_or_class );
		$this->ensure_is_instantiable( $reflection );

		$dependencies = [];

		$object = $this->instantiator->instantiate( $interface_or_class, $dependencies );

		return $object;
	}

	/**
	 * Get the reflection for a class or throw an exception.
	 *
	 * @param string $class Class to get the reflection for.
	 * @return ReflectionClass Class reflection.
	 * @throws FailedToMakeInstance If the class could not be reflected.
	 */
	private function get_class_reflection( string $class ): ReflectionClass {
		try {
			return new ReflectionClass( $class );
		} catch ( SaltusFrameworkThrowable $exception ) {
			throw FailedToMakeInstance::for_unreflectable_class( $class );
		}
	}


	/**
	 * Ensure that a given reflected class is instantiable.
	 *
	 * @param ReflectionClass $reflection Reflected class to check.
	 * @return void
	 * @throws FailedToMakeInstance If the interface could not be resolved.
	 */
	private function ensure_is_instantiable( ReflectionClass $reflection ) {
		if ( ! $reflection->isInstantiable() ) {
			throw FailedToMakeInstance::for_unresolved_interface( $reflection->getName() );
		}
	}


	/**
	 * Get a fallback instantiator in case none was provided.
	 *
	 * @return Instantiator Simplistic fallback instantiator.
	 */
	private function get_fallback_instantiator(): Instantiator {
		return new class() implements Instantiator {

			/**
			 * Make an object instance out of an interface or class.
			 *
			 * @param string $class        Class to make an object instance out of.
			 * @param array  $dependencies Optional. Dependencies of the class.
			 * @return object Instantiated object.
			 */
			public function instantiate( string $class, array $dependencies = [] ) {
				return new $class( ...$dependencies );
			}
		};

	}

}
