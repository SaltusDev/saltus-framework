<?php
namespace Saltus\WP\Framework\Infrastructure\Container;

use ReflectionClass;
use Saltus\WP\Framework\Exception\SaltusFrameworkThrowable;

use ArrayObject;

use Saltus\WP\Framework\Infrastructure\Service\{
	Service,
	Conditional,
};


/**
 * Class GenericContainer
 *
 * A simplified implementation of a service container.
 *
 * Extends ArrayObject to have default implementations for iterators and
 * array access.
 *
 * @extends ArrayObject<string, mixed>
 */
class GenericContainer
	extends ArrayObject
	implements Container, CanRegister {

	/**
	 * @var Instantiator $instantiator Instantiates services.
	 */
	protected Instantiator $instantiator;

	/**
	 * Constructor.
	 *
	 * Initializes the container with a fallback instantiator.
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
			throw Invalid::from_id( $id ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output
		}

		$service = $this->offsetGet( $id );

		return $service;
	}

	/**
	 * Check if the container has a service for the given identifier.
	 *
	 * @param string $id Identifier of the service to look for.
	 *
	 * @return bool True if the service exists, false otherwise.
	 */
	public function has( string $id ): bool {
		return $this->offsetExists( $id );
	}

	/**
	 * Put a service into the container for later retrieval.
	 *
	 * @param string  $id      Identifier of the service.
	 * @param Service $service Service to put into the container.
	 */
	public function put( string $id, $service ): void {
		$this->offsetSet( $id, $service );
	}

	/**
	 * Register a single service, and add it to the container
	 *
	 * @param string            $id               Identifier of the service.
	 * @param string            $service_class    Fully qualified class name of the service.
	 * @param array<int, mixed> $dependencies      Dependencies for the service.
	 */
	public function register( string $id, string $service_class, array $dependencies = [] ): void {

		// Only instantiate services that are actually needed.
		if ( is_a( $service_class, Conditional::class, true ) &&
			! $service_class::is_needed() ) {
			return;
		}

		if ( ! class_exists( $service_class ) ) {
			throw FailedToMakeInstance::for_unreflectable_class( $service_class ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output
		}

		$service = $this->instantiate( $service_class );

		$this->put( $id, $service );
	}


	/**
	 * Instantiate a single service.
	 *
	 * @param class-string $service_class Service class to instantiate.
	 *
	 * @throws Invalid If the service could not be properly instantiated.
	 *
	 * @return Service Instantiated service.
	 */
	private function instantiate( string $service_class ): Service {

		// The service needs to be registered, so instantiate right away.
		$service = $this->make( $service_class );

		if ( ! $service instanceof Service ) {
			throw Invalid::from( $service ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output
		}

		return $service;
	}

	/**
	 * Make an object instance out of an interface or class.
	 *
	 * @param class-string      $interface_or_class Interface or class name
	 * @param array<int, mixed> $dependencies        Optional. Dependencies of the class.
	 * @return object Instantiated object.
	 */
	private function make( string $interface_or_class, array $dependencies = [] ): object {

		$reflection = $this->get_class_reflection( $interface_or_class );
		$this->ensure_is_instantiable( $reflection );

		$object = $this->instantiator->instantiate( $interface_or_class, $dependencies );

		return $object;
	}

	/**
	 * Get the reflection for a class.
	 *
	 * @param class-string $service_class Class name
	 * @return ReflectionClass<object> Class reflection.
	 */
	private function get_class_reflection( string $service_class ): ReflectionClass {
		return new ReflectionClass( $service_class );
	}


	/**
	 * Ensure that a given reflected class is instantiable.
	 *
	 * @param ReflectionClass<object> $reflection Reflected class to check.
	 *
	 * @throws FailedToMakeInstance If the interface could not be resolved.
	 */
	private function ensure_is_instantiable( ReflectionClass $reflection ): void {
		if ( ! $reflection->isInstantiable() ) {
			throw FailedToMakeInstance::for_unresolved_interface( $reflection->getName() ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output
		}
	}

	/**
	 * Get a fallback instantiator
	 *
	 * @return Instantiator Simplistic fallback instantiator.
	 */
	private function get_fallback_instantiator(): Instantiator {
		return new class() implements Instantiator {

			/**
			 * Make an object instance out of an interface or class.
			 *
				 * @param class-string      $service_class  Class name.
				 * @param array<int, mixed> $dependencies   Optional. Dependencies of the class.
			 *
			 * @return object Instantiated object.
			 */
			public function instantiate( string $service_class, array $dependencies = [] ): object {
				return new $service_class( ...$dependencies );
			}
		};
	}
}
