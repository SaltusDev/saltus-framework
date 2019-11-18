<?php
namespace Saltus\WP\Framework\Models;

use Noodlehaus\AbstractConfig;

abstract class BaseModel {

	/**
	 * The full CPT configuration.
	 *
	 * Includes cache.
	 *
	 * @var [type]
	 */
	protected $config;

	/**
	 * the actual cpt data.
	 *
	 * Includes Name, Type, etc
	 *
	 * @var [type]
	 */
	protected $data;

	// name is required by register_post_type() and register_taxonomy()
	public $name;

	protected $args;

	// data req for computations
	protected $one;
	protected $many;
	protected $i18n;

	public function __construct( AbstractConfig $config_data ) {
		$this->data   = $config_data->all();
		$this->config = $config_data;

		if ( $this->is_disabled() ) {
			return;
		}

		$this->set_name( $config_data->get( 'name' ) );

		// pass only labels
		$this->set_name_labels( $config_data );
	}

	/**
	 * Check to see if model has been disabled
	 *
	 * @return boolean
	 */
	protected function is_disabled() {
		if ( empty( $this->data['active'] ) || $this->data['active'] === true ) {
			return false;
		}
		return true;
	}

	/**
	 * Set name
	 *
	 * Required to register post type
	 */
	protected function set_name( string $name ) {
		$this->name = $name;
	}


	/**
	 * Set required labels
	 *
	 * Based on name, or keys labels.has-one and labels.has-many
	 */
	protected function set_name_labels( AbstractConfig $config ) {
		$this->one  = ( $config['labels.has_one'] ? $config['labels.has_one'] : ucfirst( $this->name ) );
		$this->many = ( $config['labels.has_many'] ? $config['labels.has_many'] : ucfirst( $this->name . 's' ) );
		$this->i18n = ( $config['labels.text_domain'] ? $config['labels.text_domain'] : 'saltus' );
	}

	/**
	 * Set config
	 *
	 * Merge and/or replace defaults with user config
	 */
	protected function set_options( array $options ) {
		if ( empty( $this->data['options'] ) ) {
			$this->options = $options;
			return;
		}
		if ( $this->data['options'] ) {
			$options = array_replace( $options, $this->data['options'] );
		}
		$this->options = $options;
	}

	/**
	 * Set label overrides
	 *
	 * If key labels.overrides exists, add to or replace label defaults
	 */
	protected function set_labels( array $labels ) {
		if ( empty( $this->config['labels.overrides'] ) ) {
			$labels = $labels;
			return;
		}
		if ( $this->config['labels.overrides'] ) {
			$labels = array_replace( $labels, $this->config['labels.overrides'] );
		}
		$this->args['labels'] = $labels;
	}


}
