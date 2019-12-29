<?php
namespace Saltus\WP\Framework\Features\DragAndDrop;

use Saltus\WP\Framework\Infrastructure\Service\{
	Actionable,
	Service,
	Conditional
};

use Saltus\WP\Framework\Infrastructure\Plugin\{
	Activateable,
	Deactivateable
};

/**
 */
class DragAndDrop implements Service, Conditional, Activateable, Deactivateable, Actionable {

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct() {}

	/**
	 * Create a new instance of the service provider
	 *
	 * @return object The new instance
	 */
	public static function make( $name, $project, $args ) {
		return new SaltusDragAndDrop( $name, $project, $args );
	}

	/**
	 * Check whether the conditional service is currently needed.
	 *
	 * @return bool Whether the conditional service is needed.
	 */
	public static function is_needed(): bool {

		/*
		 * This service loads in most screens:
		 * - admin: in the edit screen
		 * - ajax:  while updating menu order
		 * - front: during pre_get_posts, etc
		 */
		return true;
	}

	public function activate() {

	}
	public function deactivate() {

	}

	public function add_action() {
		$actions = new UpdateMenuDragAndDrop();
		$actions->add_action();
	}

}
