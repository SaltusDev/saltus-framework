<?php
/**
 * Saltus Framework
 *
 */
namespace Saltus\WP\Framework;

use Saltus\WP\Framework\Models\ModelFactory;
use Saltus\WP\Framework\Infrastructure\ServiceContainer;
use Saltus\WP\Framework\Infrastructure\PluginInterface;
use Saltus\WP\Framework\Infrastructure\ServiceInterface;
use Saltus\WP\Framework\Infrastructure\ServiceContainerInterface;

use Saltus\WP\Framework\Infrastructure\Instantiator;

use Saltus\WP\Framework\Exception\FailedToMakeInstance;
use Saltus\WP\Framework\Exception\InvalidService;

use ReflectionClass;
use Throwable;

use Saltus\WP\Framework\Infrastructure\{
	Conditional,
	Registerable
};

class Core implements PluginInterface {


	// Main filters to control the flow of the plugin from outside code.
	public const SERVICES_FILTER = 'services';

	// Prefixes to use.
	protected const HOOK_PREFIX    = '';
	protected const SERVICE_PREFIX = '';


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

		$this->instantiator = $this->get_fallback_instantiator();

		$this->service_container = new ServiceContainer();

		$this->register_services();

		// loads models and stores the list

		// 1- Get the service for 'fields'
		$fields_service = $this->service_container->get( 'fields' );

		// 2- Create a Model Factory with fields service
		// For now its the only Service it needs
		$model_factory = new ModelFactory( $fields_service );

		// 3- Create a "store" with a factory
		$this->modeler = new Modeler( $model_factory );

		// 4- When the store starts ( init() ), it will ask the factory to make a cpt/tax
		// and stores the result in either list (cpt or tax list )
	}

	/**
	 * Register the plugin with the WordPress system.
	 *
	 * @return void
	 */
	public function register(): void {
		$project_path = $this->project['path'];
		add_action(
			'init',
			function () use ( $project_path ) {
				$this->modeler->init( $project_path );
			}
		);
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
	 * @throws InvalidService If a service is not valid.
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
	 * Register a single service.
	 *
	 * @param string $id
	 * @param string $class
	 */
	protected function register_service( string $id, string $class ): void {
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
	}


	/**
	 * Get the service container that contains the services that make up the
	 * plugin.
	 *
	 * @return ServiceContainerInterface Service container of the plugin.
	 */
	public function get_container(): ServiceContainerInterface {
		return $this->service_container;
	}

	/**
	 * Instantiate a single service.
	 *
	 * @param string $class Service class to instantiate.
	 *
	 * @throws InvalidService If the service could not be properly instantiated.
	 *
	 * @return ServiceInterface Instantiated service.
	 */
	protected function instantiate_service( $class ): ServiceInterface {

		// The service needs to be registered, so instantiate right away.
		$service = $this->make( $class );

		if ( ! $service instanceof ServiceInterface ) {
			throw InvalidService::from_service( $service );
		}

		return $service;
	}

	/**
	 * Get the list of services to register.
	 *
	 * @return array<string> Associative array of identifiers mapped to fully
	 *                       qualified class names.
	 */
	protected function get_service_classes(): array {
		return [
			// maybe register also for cpt_fields, taxonomy_fields
			'fields' => Fields\Service::class,
		];
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
	public function make( string $class ): object {

		$reflection = $this->get_class_reflection( $class );
		$this->ensure_is_instantiable( $reflection );

		$dependencies = [];

		$object = $this->instantiator->instantiate( $class, $dependencies );

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
		} catch ( Throwable $exception ) {
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
	private function ensure_is_instantiable( ReflectionClass $reflection ): void {
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
			public function instantiate( string $class, array $dependencies = [] ): object {
				return new $class( ...$dependencies );
			}
		};

	}

}
