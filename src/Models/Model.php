<?php
namespace Saltus\WP\Framework\Models;

class Model implements ModelInterface {

	protected $data;

	// data req for register_post_type() and register_taxonomy()
	protected $name;
	protected $config;
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

		$this->setName( $data['name'] );

		// pass only labels
		$this->setNameLabels( $data );
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
	protected function setName( $name ) {
		$this->name = $name;
	}


	/**
	 * Set required labels
	 *
	 * Based on name, or keys labels.has-one and labels.has-many
	 */
	protected function setNameLabels( $data ) {
		$this->one  = ( $data['labels.has_one'] ? $data['labels.has_one'] : ucfirst( $this->name ) );
		$this->many = ( $data['labels.has_many'] ? $data['labels.has_many'] : ucfirst( $this->name . 's' ) );
		$this->i18n = ( $data['labels.text_domain'] ? $data['labels.text_domain'] : 'saltus' );
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
		$this->config = $config;
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


	/**
	 * Get the type of the model
	 *
	 * @return string The type of Model
	 */
	public function get_type() {
		return '';
	}
}
