<?php
namespace Saltus\WP\Framework\Infrastructure\Plugin;

/**
 * Something that can be activated.
 *
 * By tagging a service with this interface, the system will automatically hook
 * it up to the WordPress activation hook.
 *
 * This way, we can just add the simple interface marker and not worry about how
 * to wire up the code to reach that part during the static activation hook.
 */
interface Activateable {

	/**
	 * Activate the service.
	 *
	 * @return void
	 */
	public function activate();
}
