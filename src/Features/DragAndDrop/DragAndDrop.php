<?php
namespace Saltus\WP\Framework\Features\DragAndDrop;

use Saltus\WP\Framework\Infrastructure\Service\{
	Service,
	Conditional,
};

use Saltus\WP\Framework\Infrastructure\Plugin\{
	Activateable,
	Deactivateable,
};

use Saltus\WP\Framework\Infrastructure\Feature\{
	EnqueueAssets,
};


/**
 */
final class DragAndDrop implements Service, Conditional, Activateable, Deactivateable {

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct() {
	}

	/**
	 * Check whether the conditional service is currently needed.
	 *
	 * @return bool Whether the conditional service is needed.
	 */
	public static function is_needed(): bool {

		/*
		 * Only load this sample service on the admin backend.
		 * If this conditional returns false, the service is never even
		 * instantiated.
		 */
		return \is_admin() && ! \wp_doing_ajax();
	}

	public function activate() {

	}
	public function deactivate() {

	}

	/**
	 * Create a new instance of the service provider
	 *
	 * @return object The new instance
	 */
	public static function make( $name, $project, $args ) : object {
		return new CustomTypeDragAndDrop( $name, $project, $args );
	}

}

