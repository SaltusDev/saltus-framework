<?php

namespace Saltus\WP\Framework\Models;

use Noodlehaus\AbstractConfig;

class ModelFactory {

	protected $fields_service;

	public function __construct( $fields_service ) {
		$this->fields_service = $fields_service;
	}

	/**
	 * Route to class
	 */
	public function create( AbstractConfig $config ) {

		// soft fail
		if ( ! $config->has( 'type' ) ) {
			return false;
		}

		if ( in_array( $config->get( 'type' ), [ 'post-type', 'cpt', 'posttype', 'post_type' ], true ) ) {
			$cpt = new PostType( $config );
			$cpt->setup();

			$meta     = array();
			$has_meta = false;

			$settings     = array();
			$has_settings = false;

			if ( $config->has( 'meta' ) ) {
				$meta     = $config->get( 'meta' );
				$has_meta = true;
			}
			if ( $config->has( 'settings' ) ) {
				$settings     = $config->get( 'settings' );
				$has_settings = true;
			}

			if ( $has_meta || $has_settings ) {
				$fields = $this->fields_service->make();
				$fields->setup(
					$cpt->name,
					$meta,
					$settings
				);
			}

			// disable block editor only if 'block_editor' is false
			if ( $config->has( 'block_editor' ) && ! $config->get( 'block_editor' ) ) {
				add_filter( 'use_block_editor_for_post_type', array( $cpt, 'disable_block_editor'), 10, 2 );
			}
			return $cpt;

		}
		if ( in_array( $config->get( 'type' ), [ 'taxonomy', 'tax', 'category', 'cat', 'tag' ], true ) ) {
			$taxonomy = new Taxonomy( $config );
			$taxonomy->setup();
			return $taxonomy;
		}

		return false;

	}
}
