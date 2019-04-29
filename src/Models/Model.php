<?php
namespace Saltus\WP\Framework\Models;

class Model {

	protected $data;

	// data req for register_post_type() and register_taxonomy()
	protected $name;
	protected $args;

	// data req for computations
	protected $one;
	protected $many;
	protected $i18n;

	public function __construct( $data ) {
		$this->data = $data;

		if ( $this->isDisabled() ) {
			return;
		}

		$this->setName();
		$this->setNameLabels();
	}

	/**
	 * Check to see if model has been disabled
	 *
	 * @return boolean
	 */
	protected function isDisabled() {
		return ( ( $this->data['active'] === false ) ? true : false );
	}

	/**
	 * Set name
	 *
	 * Required to register post type
	 */
	protected function setName() {
		$this->name = $this->data['name'];
	}


	/**
	 * Set required labels
	 *
	 * Based on name, or keys labels.has-one and labels.has-many
	 */
	protected function setNameLabels() {
		$this->one  = ( $this->data['labels.has_one'] ? $this->data['labels.has_one'] : ucfirst( $this->name ) );
		$this->many = ( $this->data['labels.has_many'] ? $this->data['labels.has_many'] : ucfirst( $this->name . 's' ) );
		$this->i18n = ( $this->data['labels.text_domain'] ? $this->data['labels.text_domain'] : 'sober' );
	}

	/**
	 * Set config
	 *
	 * Merge and/or replace defaults with user config
	 */
	protected function setConfig( array $config ) {
		if ( $this->data['config'] ) {
			$config = array_replace( $config, $this->data['config'] );
		}
		$this->args['config'] = $config;
	}

	/**
	 * Set label overrides
	 *
	 * If key labels.overrides exists, add to or replace label defaults
	 */
	protected function setLabels( array $labels ) {
		if ( $this->data['labels.overrides'] ) {
			$labels = array_replace( $labels, $this->data['labels.overrides'] );
		}
		$this->args['labels'] = $labels;
	}
}
