<?php
/**
 * Admin Columns
 *
 * @package Saltus/WP/Framework
 */

namespace Saltus\WP\Framework\Features\AdminCols;

use Saltus\WP\Framework\Infrastructure\Service\{
	Processable
};

/**
 * Enable custom administration columns
 *
 * Adapted from https://github.com/johnbillion/extended-cpts by johnbillion
 */
final class SaltusAdminCols implements Processable {

	private $name;
	private $project;
	private $args;

	/**
	 * @var array Default columns
	 */
	private $default_columns;

	/**
	 * @var array Managed columsn
	 */
	private $managed_columns = null;

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct( string $name, array $project, array $args ) {
		$this->project = $project;
		$this->name    = $name;
		$this->args    = $args;
	}

	/**
	 * Register filters for this feature
	 *
	 * @return void
	 */
	public function process() {

		add_filter( 'manage_posts_columns',                       [ $this, 'log_default_cols' ], 0 );
		add_filter( 'manage_pages_columns',                       [ $this, 'log_default_cols' ], 0 );
		add_filter( "manage_edit-{$this->name}_sortable_columns", [ $this, 'sortables' ] );
		add_filter( "manage_{$this->name}_posts_columns",         [ $this, 'manage_columns' ] );
		add_action( "manage_{$this->name}_posts_custom_column",   [ $this, 'manage_custom_columns' ], 10, 2 );
		add_action( 'load-edit.php',                              [ $this, 'default_sort' ] );
		add_filter( 'pre_get_posts',                              [ $this, 'maybe_sort_by_fields' ] );
		add_filter( 'posts_clauses',                              [ $this, 'maybe_sort_by_taxonomy' ], 10, 2 );
	}

	/**
	 * Logs the default columns so we don't remove any custom columns added by other plugins.
	 *
	 * @param array $cols The default columns for this post type screen
	 * @return array The default columns for this post type screen
	 */
	public function log_default_cols( array $cols ): array {
		$this->default_columns = $cols;

		return $this->default_columns;
	}

	/**
	 * Adds our custom columns to the list of sortable columns.
	 *
	 * @param array $cols Array of sortable columns keyed by the column ID.
	 * @return array Updated array of sortable columns.
	 */
	public function sortables( array $cols ): array {
		foreach ( $this->args as $id => $col ) {
			if ( ! is_array( $col ) ) {
				continue;
			}
			if ( isset( $col['sortable'] ) && ! $col['sortable'] ) {
				continue;
			}
			if ( isset( $col['meta_key'] ) || isset( $col['taxonomy'] ) || isset( $col['post_field'] ) ) {
				$cols[ $id ] = $id;
			}
		}

		return $cols;
	}

	/**
	 * Adds columns to the admin screen for this post type.
	 *
	 * @link https://github.com/johnbillion/extended-cpts/wiki/Admin-columns
	 *
	 * @param array $cols Associative array of columns
	 * @return array Updated array of columns
	 */
	public function manage_columns( array $cols ): array {
		// This function gets called multiple times, so let's cache it for efficiency:
		if ( isset( $this->managed_columns ) ) {
			return $this->managed_columns;
		}

		$new_cols = [];
		$keep     = [
			'cb',
			'title',
		];

		# Add existing columns we want to keep:
		foreach ( $cols as $id => $title ) {
			if ( in_array( $id, $keep, true ) && ! isset( $this->args[ $id ] ) ) {
				$new_cols[ $id ] = $title;
			}
		}

		# Add our custom columns:
		foreach ( array_filter( $this->args ) as $id => $col ) {
			if ( is_string( $col ) && isset( $cols[ $col ] ) ) {
				# Existing (ie. built-in) column with id as the value
				$new_cols[ $col ] = $cols[ $col ];
			} elseif ( is_string( $col ) && isset( $cols[ $id ] ) ) {
				# Existing (ie. built-in) column with id as the key and title as the value
				$new_cols[ $id ] = esc_html( $col );
			} elseif ( $col === 'author' ) {
				# Automatic support for Co-Authors Plus plugin and special case for
				# displaying author column when the post type doesn't support 'author'
				if ( class_exists( 'coauthors_plus' ) ) {
					$k = 'coauthors';
				} else {
					$k = 'author';
				}
				$new_cols[ $k ] = esc_html__( 'Author', 'extended-cpts' );
			} elseif ( is_array( $col ) ) {
				if ( isset( $col['cap'] ) && ! current_user_can( $col['cap'] ) ) {
					continue;
				}

				if ( isset( $col['title_cb'] ) ) {
					$new_cols[ $id ] = call_user_func( $col['title_cb'], $col );
				} else {
					$title = esc_html( $col['title'] ?? $this->get_item_title( $col ) ?? $id );

					if ( isset( $col['title_icon'] ) ) {
						$title = sprintf(
							'<span class="dashicons %s" aria-hidden="true"></span><span class="screen-reader-text">%s</span>',
							esc_attr( $col['title_icon'] ),
							$title
						);
					}

					$new_cols[ $id ] = $title;
				}
			}
		}

		# Re-add any custom columns:
		$custom   = array_diff_key( $cols, $this->default_columns );
		$new_cols = array_merge( $new_cols, $custom );

		$this->managed_columns = $new_cols;
		return $this->managed_columns;
	}

	/**
	 * Returns a sensible title for the current item (usually the arguments array for a column)
	 *
	 * @param array $item An array of arguments
	 * @return string|null The item title
	 */
	protected function get_item_title( array $item ) {
		if ( isset( $item['taxonomy'] ) ) {
			$tax = get_taxonomy( $item['taxonomy'] );
			if ( $tax ) {
				return $tax->labels->name;
			}
			return $item['taxonomy'];
		} elseif ( isset( $item['post_field'] ) ) {
			return ucwords( trim( str_replace(
				[
					'post_',
					'_',
				],
				' ',
				$item['post_field']
			) ) );
		} elseif ( isset( $item['meta_key'] ) ) {
			return ucwords( trim( str_replace(
				[
					'_',
					'-',
				],
				' ',
				$item['meta_key']
			) ) );
		}
		return null;
	}

	/**
	 * Output the column data for our custom columns.
	 *
	 * @param string $col The column name
	 */
	public function manage_custom_columns( string $col, $post_id ) {
		# Shorthand:
		$c = $this->args;

		# We're only interested in our custom columns:
		$custom_cols = array_filter( array_keys( $c ) );

		if ( ! in_array( $col, $custom_cols, true ) ) {
			return;
		}

		if ( isset( $c[ $col ]['post_cap'] ) && ! current_user_can( $c[ $col ]['post_cap'], get_the_ID() ) ) {
			return;
		}

		if ( ! isset( $c[ $col ]['link'] ) ) {
			$c[ $col ]['link'] = 'list';
		}

		if ( isset( $c[ $col ]['function'] ) ) {
			call_user_func( $c[ $col ]['function'] );
		} elseif ( isset( $c[ $col ]['meta_key'] ) ) {
			$this->col_post_meta( $c[ $col ]['meta_key'], $c[ $col ] );
		} elseif ( isset( $c[ $col ]['taxonomy'] ) ) {
			$this->col_taxonomy( $post_id, $c[ $col ]['taxonomy'], $c[ $col ] );
		} elseif ( isset( $c[ $col ]['post_field'] ) ) {
			$this->col_post_field( $post_id, $c[ $col ]['post_field'], $c[ $col ] );
		} elseif ( isset( $c[ $col ]['featured_image'] ) ) {
			$this->col_featured_image( $c[ $col ]['featured_image'], $c[ $col ] );
		}
	}

	/**
	 * Outputs column data for a post meta field.
	 *
	 * @param string $meta_key The post meta key
	 * @param array  $args     Array of arguments for this field
	 */
	public function col_post_meta( string $meta_key, array $args ) {
		$vals = get_post_meta( get_the_ID(), $meta_key, false );
		$echo = [];

		sort( $vals );

		if ( isset( $args['date_format'] ) ) {
			if ( $args['date_format'] === true ) {
				$args['date_format'] = get_option( 'date_format' );
			}

			foreach ( $vals as $val ) {
				$val_time = strtotime( $val );

				if ( $val_time ) {
					$val = $val_time;
				}

				if ( is_numeric( $val ) ) {
					$echo[] = date_i18n( $args['date_format'], $val );
				} elseif ( ! empty( $val ) ) {
					$echo[] = mysql2date( $args['date_format'], $val );
				}
			}
		} else {
			foreach ( $vals as $val ) {

				if ( ! empty( $val ) || ( $val === '0' ) ) {
					$echo[] = $val;
				}
			}
		}

		if ( empty( $echo ) ) {
			echo '&#8212;';
		} else {
			echo esc_html( implode( ', ', $echo ) );
		}
	}

	/**
	 * Outputs column data for a taxonomy's term names.
	 *
	 * @param string $taxonomy The taxonomy name
	 * @param array  $args     Array of arguments for this field
	 */
	public function col_taxonomy( int $post_id, string $taxonomy, array $args ) {

		$post  = get_post( $post_id );
		$terms = get_the_terms( $post_id, $taxonomy );
		$tax   = get_taxonomy( $taxonomy );

		if ( is_wp_error( $terms ) ) {
			echo esc_html( $terms->get_error_message() );
			return;
		}

		if ( empty( $terms ) ) {
			printf(
				'<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">%s</span>',
				esc_html( $tax->labels->no_terms )
			);
			return;
		}

		$out = [];

		foreach ( $terms as $term ) {
			if ( $args['link'] ) {
				switch ( $args['link'] ) {

					case 'view':
						if ( $tax->public ) {
							// https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards/issues/1096
							// @codingStandardsIgnoreStart
							$out[] = sprintf(
								'<a href="%1$s">%2$s</a>',
								esc_url( get_term_link( $term ) ),
								esc_html( $term->name )
							);
							// @codingStandardsIgnoreEnd
						} else {
							$out[] = esc_html( $term->name );
						}
						break;

					case 'edit':
						if ( current_user_can( $tax->cap->edit_terms ) ) {
							$out[] = sprintf(
								'<a href="%1$s">%2$s</a>',
								esc_url( get_edit_term_link( $term->term_id, $taxonomy, $post->post_type ) ),
								esc_html( $term->name )
							);
						} else {
							$out[] = esc_html( $term->name );
						}
						break;

					case 'list':
						$link  = add_query_arg(
							[
								'post_type' => $post->post_type,
								$taxonomy   => $term->slug,
							],
							admin_url( 'edit.php' )
						);
						$out[] = sprintf(
							'<a href="%1$s">%2$s</a>',
							esc_url( $link ),
							esc_html( $term->name )
						);
						break;

				}
			} else {
				$out[] = esc_html( $term->name );
			}
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo implode( ', ', $out );
	}

	/**
	 * Outputs column data for a post field.
	 *
	 * @param string $field The post field
	 * @param array  $args  Array of arguments for this field
	 */
	public function col_post_field( int $post_id, string $field, array $args ) {
		$post = get_post( $post_id );
		switch ( $field ) {

			case 'post_date':
			case 'post_date_gmt':
			case 'post_modified':
			case 'post_modified_gmt':
				if ( get_post_field( $field, $post ) === '0000-00-00 00:00:00' ) {
					break;
				}
				if ( ! isset( $args['date_format'] ) ) {
					$args['date_format'] = get_option( 'date_format' );
				}
				echo esc_html( mysql2date( $args['date_format'], get_post_field( $field, $post ) ) );
				break;

			case 'post_status':
				$status = get_post_status_object( get_post_status( $post ) );
				if ( $status ) {
					echo esc_html( $status->label );
				}
				break;

			case 'post_author':
				echo esc_html( get_the_author() );
				break;

			case 'post_title':
				echo esc_html( get_the_title() );
				break;

			case 'post_excerpt':
				echo esc_html( get_the_excerpt() );
				break;

			default:
				echo esc_html( get_post_field( $field, $post ) );
				break;

		}
	}

	/**
	 * Outputs column data for a post's featured image.
	 *
	 * @param string $image_size The image size
	 * @param array  $args       Array of `width` and `height` attributes for the image
	 */
	public function col_featured_image( string $image_size, array $args ) {
		if ( ! function_exists( 'has_post_thumbnail' ) ) {
			return;
		}

		if ( isset( $args['width'] ) ) {
			$width = is_numeric( $args['width'] ) ? sprintf( '%dpx', $args['width'] ) : $args['width'];
		} else {
			$width = 'auto';
		}

		if ( isset( $args['height'] ) ) {
			$height = is_numeric( $args['height'] ) ? sprintf( '%dpx', $args['height'] ) : $args['height'];
		} else {
			$height = 'auto';
		}

		$image_atts = [
			'style' => esc_attr( sprintf(
				'width:%1$s;height:%2$s',
				$width,
				$height
			) ),
			'title' => '',
		];

		if ( has_post_thumbnail() ) {
			the_post_thumbnail( $image_size, $image_atts );
		}
	}


	/**
	 * Sets the default sort field and sort order on our post type admin screen.
	 */
	public function default_sort() {
		if ( $this->get_current_post_type() !== $this->name ) {
			return;
		}

		# If we've already ordered the screen, bail out:
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['orderby'] ) ) {
			return;
		}

		# Loop over our columns to find the default sort column (if there is one):
		foreach ( $this->args as $id => $col ) {
			if ( is_array( $col ) && isset( $col['default'] ) ) {
				$_GET['orderby'] = $id;
				$_GET['order']   = ( strtolower( $col['default'] ) === 'desc' ? 'desc' : 'asc' );
				break;
			}
		}
	}

	/**
	 * Returns the name of the post type for the current request.
	 *
	 * @return string The post type name.
	 */
	protected static function get_current_post_type(): string {
		if ( function_exists( 'get_current_screen' ) && is_object( get_current_screen() ) && get_current_screen()->base === 'edit' ) {
			return get_current_screen()->post_type;
		}
		return '';
	}

	/**
	 * Sets the relevant query vars for sorting posts by our admin sortables.
	 *
	 * @param WP_Query $wp_query The current `WP_Query` object.
	 */
	public function maybe_sort_by_fields( \WP_Query $wp_query ) {
		if ( empty( $wp_query->query['post_type'] ) || ! in_array( $this->name, (array) $wp_query->query['post_type'], true ) ) {
			return;
		}

		$sort = $this->get_sort_field_vars( $wp_query->query, $this->args );

		if ( empty( $sort ) ) {
			return;
		}

		foreach ( $sort as $key => $value ) {
			$wp_query->set( $key, $value );
		}
	}

	/**
	 * Filters the query's SQL clauses so we can sort posts by taxonomy terms.
	 *
	 * @param array    $clauses  The current query's SQL clauses.
	 * @param WP_Query $wp_query The current `WP_Query` object.
	 * @return array The updated SQL clauses.
	 */
	public function maybe_sort_by_taxonomy( array $clauses, \WP_Query $wp_query ): array {
		if ( empty( $wp_query->query['post_type'] ) || ! in_array( $this->name, (array) $wp_query->query['post_type'], true ) ) {
			return $clauses;
		}

		$sort = $this->get_sort_taxonomy_clauses( $clauses, $wp_query->query, $this->args );

		if ( empty( $sort ) ) {
			return $clauses;
		}

		return array_merge( $clauses, $sort );
	}

	/**
	 * Get the array of private and public query vars for the given sortables, to apply to the current query in order to
	 * sort it by the requested orderby field.
	 *
	 * @param array $vars      The public query vars, usually from `$wp_query->query`.
	 * @param array $sortables The sortables valid for this query (usually the value of the `admin_cols` or
	 *                         `site_sortables` argument when registering an extended post type.
	 * @return array The list of private and public query vars to apply to the query.
	 */
	public static function get_sort_field_vars( array $vars, array $sortables ): array {
		if ( ! isset( $vars['orderby'] ) ) {
			return [];
		}

		if ( ! is_string( $vars['orderby'] ) ) {
			return [];
		}

		if ( ! isset( $sortables[ $vars['orderby'] ] ) ) {
			return [];
		}

		$orderby = $sortables[ $vars['orderby'] ];

		if ( ! is_array( $orderby ) ) {
			return [];
		}

		if ( isset( $orderby['sortable'] ) && ! $orderby['sortable'] ) {
			return [];
		}

		$return = [];

		if ( isset( $orderby['meta_key'] ) ) {
			$return['meta_key'] = $orderby['meta_key'];
			$return['orderby']  = 'meta_value';
			// @TODO meta_value_num
		} elseif ( isset( $orderby['post_field'] ) ) {
			$field             = str_replace( 'post_', '', $orderby['post_field'] );
			$return['orderby'] = $field;
		}

		if ( isset( $vars['order'] ) ) {
			$return['order'] = $vars['order'];
		}

		return $return;
	}

	/**
	 * Get the array of SQL clauses for the given sortables, to apply to the current query in order to
	 * sort it by the requested orderby field.
	 *
	 * @param array $clauses   The query's SQL clauses.
	 * @param array $vars      The public query vars, usually from `$wp_query->query`.
	 * @param array $sortables The sortables valid for this query (usually the value of the `admin_cols` or
	 *                         `site_sortables` argument when registering an extended post type).
	 * @return array The list of SQL clauses to apply to the query.
	 */
	public static function get_sort_taxonomy_clauses( array $clauses, array $vars, array $sortables ): array {
		global $wpdb; // Global WPDB class object

		if ( ! isset( $vars['orderby'] ) ) {
			return [];
		}

		if ( ! is_string( $vars['orderby'] ) ) {
			return [];
		}

		if ( ! isset( $sortables[ $vars['orderby'] ] ) ) {
			return [];
		}

		$orderby = $sortables[ $vars['orderby'] ];

		if ( ! is_array( $orderby ) ) {
			return [];
		}

		if ( isset( $orderby['sortable'] ) && ! $orderby['sortable'] ) {
			return [];
		}

		if ( ! isset( $orderby['taxonomy'] ) ) {
			return [];
		}

		# Taxonomy term ordering courtesy of http://scribu.net/wordpress/sortable-taxonomy-columns.html
		$clauses['join']    .= "
			LEFT OUTER JOIN {$wpdb->term_relationships} as ext_cpts_tr
			ON ( {$wpdb->posts}.ID = ext_cpts_tr.object_id )
			LEFT OUTER JOIN {$wpdb->term_taxonomy} as ext_cpts_tt
			ON ( ext_cpts_tr.term_taxonomy_id = ext_cpts_tt.term_taxonomy_id )
			LEFT OUTER JOIN {$wpdb->terms} as ext_cpts_t
			ON ( ext_cpts_tt.term_id = ext_cpts_t.term_id )
		";
		$clauses['where']   .= $wpdb->prepare( ' AND ( taxonomy = %s OR taxonomy IS NULL )', $orderby['taxonomy'] );
		$clauses['groupby']  = 'ext_cpts_tr.object_id';
		$clauses['orderby']  = 'GROUP_CONCAT( ext_cpts_t.name ORDER BY name ASC ) ';
		$clauses['orderby'] .= ( isset( $vars['order'] ) && ( strtoupper( $vars['order'] ) === 'ASC' ) ) ? 'ASC' : 'DESC';

		return $clauses;
	}
}
