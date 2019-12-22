<?php
namespace Saltus\WP\Framework\Infrastructure\Service;

use ArrayObject;

/**
 * A simplified implementation of a service container.
 *
 * Extend ArrayObject to have default implementations for iterators and
 * array access.
 */
final class App
	extends ArrayObject
	implements ServiceContainer {

	/**
	 * Find a service of the container by its identifier and return it.
	 *
	 * @param string $id Identifier of the service to look for.
	 *
	 * @throws Invalid If the service could not be found.
	 *
	 * @return Service Service that was requested.
	 */
	public function get( string $id ): Service {
		if ( ! $this->has( $id ) ) {
			throw Invalid::from_service_id( $id );
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
	public function put( string $id, Service $service ) {
		$this->offsetSet( $id, $service );
	}
}
