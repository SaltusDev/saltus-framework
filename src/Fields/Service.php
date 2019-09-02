<?php
namespace Saltus\WP\Framework\Fields;

use Saltus\WP\Framework\Infrastructure\{
	Conditional,
	ServiceInterface
};

/**
 * This sample service only renders a silly "Hello World" notice in the admin
 * backend.
 *
 * It is meant to illustrate how to hook services into the plugin flow
 * and how to have their dependencies by injected.
 *
 * Note that the dependency here is actually an interface, not a class. We can
 * still just transparently use it though.
 */
final class Service implements ServiceInterface, Conditional {

	/**
	 * Check whether the conditional service is currently needed.
	 *
	 * @return bool Whether the conditional service is needed.
	 */
	public static function is_needed(): bool {
		/*
		 * We only load this sample service on the admin backend.
		 * If this conditional returns false, the service is never even
		 * instantiated.
		 */
		return true;
	}

	/**
	 * Instantiate a Service object.
	 *
	 */
	public function __construct() {

	}

	public function get_new() {
		//return new CMB2Fields();
		return new CodestarFields();
	}

}

