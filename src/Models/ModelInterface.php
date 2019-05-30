<?php

namespace Saltus\WP\Framework\Models;

interface ModelInterface {

	/**
	 * Get the type of the model
	 *
	 * @return string The type of Model
	 */
	public function get_type(): string;
}
