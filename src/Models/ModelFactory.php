<?php

namespace Saltus\WP\Framework\Models;

use Noodlehaus\Config;
/*

use Saltus\WP\Framework\Models\ConfigNoFile;
use Saltus\WP\Framework\Models\PostType;
use Saltus\WP\Framework\Models\Taxonomy;
*/

class ModelFactory {

	/**
	 * Route to class
	 */
	public function create( $config ) {
		if ( in_array( $config['type'], [ 'post-type', 'cpt', 'posttype', 'post_type' ], true ) ) {

			return ( new PostType( $config ) )->run();

		}
		//if ( in_array( $config['type'], [ 'taxonomy', 'tax', 'category', 'cat', 'tag' ] ) ) {
			//$model = ( new Taxonomy( $config ) )->run();
			//$model_list['taxonomy'] = $model;
		//}

		throw InvalidModel::from_service( $service );

	}
}
