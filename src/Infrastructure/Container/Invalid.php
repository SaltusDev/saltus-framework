<?php
namespace Saltus\WP\Framework\Infrastructure\Container;

use Saltus\WP\Framework\Exception\SaltusFrameworkThrowable;
use InvalidArgumentException;

class Invalid
	extends InvalidArgumentException
	implements SaltusFrameworkThrowable {

	/**
	 * Create a new instance of the exception for a item class name that is
	 * not recognized.
	 *
	 * @param string|object $item Class name of the item that was not
	 *                               recognized.
	 *
	 * @return static
	 */
	public static function from( $item ) {
		$message = \sprintf(
			'The item "%s" is not recognized and cannot be registered.',
			\is_object( $item )
				? \get_class( $item )
				: (string) $item
		);

		return new static( $message );
	}

	/**
	 * Create a new instance of the exception for a item identifier that is
	 * not recognized.
	 *
	 * @param string $item_id Identifier of the item that is not being
	 *                           recognized.
	 *
	 * @return static
	 */
	public static function from_id( string $item_id ) {
		$message = \sprintf(
			'The item ID "%s" is not recognized and cannot be retrieved.',
			$item_id
		);

		return new static( $message );
	}
}
