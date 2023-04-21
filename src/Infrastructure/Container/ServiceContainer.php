<?php
namespace Saltus\WP\Framework\Infrastructure\Container;

use ReflectionClass;
use Saltus\WP\Framework\Exception\SaltusFrameworkThrowable;

use ArrayObject;

use Saltus\WP\Framework\Infrastructure\Service\{
	Service,
	Conditional,
	Actionable
};

use Saltus\WP\Framework\Infrastructure\Plugin\{
	Registerable
};

use Saltus\WP\Framework\Infrastructure\Container\Instantiator;

/**
 * A simplified implementation of a service container.
 *
 * Extend ArrayObject to have default implementations for iterators and
 * array access.
 *
 * Can trigger service registration proccess with CanRegister
 */
class ServiceContainer
	extends ArrayObject
	implements Container, CanRegister {

	/**
	 * Instanciates Services
	 */
	protected $instantiator;

	/**
	 * Service Container
	 */
	public function __construct() {
		$this->instantiator = $this->get_fallback_instantiator();
	}

	/**
	 * Find a service of the container by its identifier and return it.
	 *
	 * @param string $id Identifier of the service to look for.
	 *
	 * @throws Invalid If the service could not be found.
	 *
	 * @return Service Service that was requested.
	 */
	public function get( string $id ) {
		if ( ! $this->has( $id ) ) {
			throw Invalid::from_id( $id );
		}

		$service = $this->offsetGet( $id );

		return $service;
	}

	/**
	 * Check whether the container can return a service for the given
	 * identifier.
	 *
	 * @param string $id Identifier of the service to look for.
	 *
	 * @return bool
	 */
	public function has( string $id ): bool {
		return $this->offsetExists( $id );
	}

	/**
	 * Put a service into the container for later retrieval.
	 *
	 * @param string  $id      Identifier of the service to put into the
	 *                         container.
	 * @param Service $service Service to put into the container.
	 */
	public function put( string $id, $service ) {
		$this->offsetSet( $id, $service );
	}

	/**
	 * Register a single service, and adds it to the container
	 *
	 * Runs Registerable, Actionable
	 *
	 * @param string $id
	 * @param string $class
	 */
	public function register( string $id, string $class, array $dependencies ) {

		// Only instantiate services that are actually needed.
		if ( is_a( $class, Conditional::class, true ) &&
			! $class::is_needed() ) {
			return;
		}

		$service = $this->instantiate( $class, $dependencies );

		$this->put( $id, $service );

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
	 * Instantiate a single service.
	 *
	 * @param string $class Service class to instantiate.
	 *
	 * @throws Invalid If the service could not be properly instantiated.
	 *
	 * @return Service Instantiated service.
	 */
	private function instantiate( $class, array $dependencies ): Service {

		// The service needs to be registered, so instantiate right away.
		$service = $this->make( $class, $dependencies );

		if ( ! $service instanceof Service ) {
			throw Invalid::from( $service );
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
	private function make( string $interface_or_class, array $dependencies = [] ) {

		$reflection = $this->get_class_reflection( $interface_or_class );
		$this->ensure_is_instantiable( $reflection );

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
