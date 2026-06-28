<?php
namespace Saltus\WP\Framework\Infrastructure\Container;

use ArrayObject;

use Saltus\WP\Framework\Infrastructure\{
	Service\Service,
	Container\Container,
	Container\Invalid
};

/**
 * A simplified implementation of a service container.
 *
 * Extend ArrayObject to have default implementations for iterators and
 * array access.
 *
 * @extends ArrayObject<string, mixed>
 */
class SimpleContainer extends ArrayObject implements Container {


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
	public function put( string $id, $service ): void {
		$this->offsetSet( $id, $service );
	}
}
