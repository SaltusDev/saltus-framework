<?php

namespace Saltus\WP\Framework\Infrastructure\Services\Assets;

use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\Infrastructure\Container\SimpleContainer;

class AssetsContainer extends SimpleContainer implements Service {

	/**
	 * Get all elements stored in the container.
	 *
	 * @return array<string, mixed>
	 */
	public function getAll(): array {
		return $this->getArrayCopy();
	}
}
