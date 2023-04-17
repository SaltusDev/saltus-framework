<?php
namespace Saltus\WP\Framework\Infrastructure\Container;

use ArrayAccess;
use Countable;
use Traversable;

/**
 * The container collects all items to manage them.
 *
 * This is based on PSR-11 and should extend that one if Composer dependencies
 * are being used. Relying on a standardized interface like PSR-11 means you'll
 * be able to easily swap out the implementation for something else later on.
 *
 * @see https://www.php-fig.org/psr/psr-11/
 */
interface Container extends Traversable, Countable, ArrayAccess {

	/**
	 * Find a item of the container by its identifier and return it.
	 *
	 * @param string $id Identifier of the item to look for.
	 *
	 * @throws Invalid If the item could not be found.
	 *
	 * @return mixed A element
	 */
	public function get( string $id );

	/**
	 * Check whether the container can return a item for the given
	 * identifier.
	 *
	 * @param string $id Identifier of the item to look for.
	 *
	 * @return bool
	 */
	public function has( string $id ): bool;

	/**
	 * Put a item into the container for later retrieval.
	 *
	 * @param string  $id      Identifier of the item to put into the
	 *                         container.
	 * @param mixed   $item    Item to put into the container.
	 */
	public function put( string $id, $item );
}
