<?php
namespace Saltus\WP\Framework\Infrastructure\Plugin;

use Saltus\WP\Framework\Infrastructure\Container\Container;

/**
 * A plugin is basically nothing more than a convention on how manage the
 * lifecycle of a modular piece of code, so that you can:
 *  1. activate it,
 *  2. register it with the framework, and
 *  3. deactivate it again.
 *
 * This is what this interface represents, by assembling the separate,
 * segregated interfaces for each of these lifecycle actions.
 *
 * Additionally, we provide a means to get access to the plugin's container that
 * collects all the features it is made up of. This allows direct access to the
 * features to outside code if needed.
 */
interface Plugin extends Activateable, Deactivateable, Registerable {

	/**
	 * Get the container that contains the features that make up the
	 * plugin.
	 *
	 * @return Container Container of the plugin.
	 */
	public function get_container(): Container;
}
