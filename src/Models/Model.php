<?php

namespace Saltus\WP\Framework\Models;

interface Model {

	/**
	 * Setup the data needed to register
	 *
	 */
	public function setup();

	/**
	 * Get the type of the model
	 *
	 * @return string The type of Model
	 */
	public function get_type();

}
