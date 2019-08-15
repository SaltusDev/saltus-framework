<?php

namespace Saltus\WP\Framework\Models;

class ModelFactory {

	protected $fields_service;

	public function __construct( $fields_service ) {
		$this->fields_service = $fields_service;
	}

	/**
	 * Route to class
	 */
	public function create( $config ) {

		if ( ! $config->has( 'type' ) ) {
			return false;
		}

		if ( in_array( $config->get( 'type' ), [ 'post-type', 'cpt', 'posttype', 'post_type' ], true ) ) {
			$cpt = new PostType( $config );
			$cpt->setup();
			if ( $config->has( 'meta' ) ) {
				$meta = $this->fields_service->get_new();
				$meta->setup( $cpt->name, $config->get( 'meta' ) );

				add_action( 'cmb2_admin_init', array( $meta, 'init' ), 0 );
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