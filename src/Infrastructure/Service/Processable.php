<?php
namespace Saltus\WP\Framework\Infrastructure\Service;

/**
 * Something that can be registered.
 *
 */
interface Processable {

	/**
	 * Register the service.
	 *
	 * @return void
	 */
	public function process();
}
