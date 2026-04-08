<?php
namespace Saltus\WP\Framework\Features\DragAndDrop;

use Saltus\WP\Framework\Infrastructure\Service\{
	Assembly,
	Actionable,
	Service,
	Conditional
};


/**
 * Class DragAndDrop
 *
 * Enable an option to manage drag and drop functionality in the admin area.
 */
class DragAndDrop implements Service, Conditional, Actionable, Assembly {

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct() {}

	/**
	 * Check whether the conditional service is currently needed.
	 *
	 * @return bool Whether the conditional service is needed.
	 */
	public static function is_needed(): bool {
		return is_admin();
	}

	/**
	 * Create a new instance of the service provider
	 *
	 * @return object The new instance
	 */
	public static function make( $name, $project, $args ) {
		return new SaltusDragAndDrop( $name, $project );
	}

	/**
	 * Update menu drag and drop in the database
	 *
	 */
	public function add_action() {
		$actions = new UpdateMenuDragAndDrop();
		$actions->add_action();
	}
}
