<?php
namespace Saltus\WP\Framework\Features\FeatureA;

final class SaltusFeatureA {

	private $name;
	private $meta;


	/**
	 * Instantiate the class
	 */
	public function __construct() {

	}

	public function setup( $name, $meta = array(), $settings = array() ) {

		$this->name = $name;
		$this->meta = $meta;
	}

}
