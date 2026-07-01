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
 * Adapted from https://github.com/johnbillion/extended-cpts by johnbillion with notable changes:
 *   - models can override the default sort order
 */
final class SaltusAdminCols implements Processable {

	/**
	 * The name of the custom post type (CPT)
	 */
	private string $name;

	/**
	 * List of columns
	 */
	/** @var array<string, mixed> */
	private array $args;

	/** @var array<string, string>|null */
	private ?array $default_columns = null;

	/** @var array<string, string>|null */
	private ?array $managed_columns = null;

	private const DEFAULT_KEEP_COLUMNS = [ 'cb', 'title' ];

	/**
	 * Instantiate this Service object.
	 *
	 * @param string $name The name of the custom post type (CPT)
	 * @param array<string, mixed> $args List of columns
	 */
	public function __construct( string $name, array $args ) {
		$this->name = $name;
		$this->args = $args;
		// normalize the columns to unify all the different column types
		$this->normalize_columns();
	}

	/**
	 * Register filters for this feature
	 *
	 * @return void
	 */
	public function process(): void {

		add_filter( 'manage_posts_columns',                       [ $this, 'log_default_cols' ], 0 );
		add_filter( 'manage_pages_columns',                       [ $this, 'log_default_cols' ], 0 );
		add_filter( 'manage_media_columns',                       [ $this, 'log_default_cols' ], 0 );
		if ( $this->name === 'attachment' ) {
			add_filter( 'manage_upload_sortable_columns', [ $this, 'sortables' ] );
			add_filter( 'manage_media_columns',         [ $this, 'manage_columns' ] );
			add_action( 'manage_media_custom_column',   [ $this, 'manage_custom_columns' ], 10, 2 );
		} else {
			add_filter( "manage_edit-{$this->name}_sortable_columns", [ $this, 'sortables' ] );
			add_filter( "manage_{$this->name}_posts_columns",         [ $this, 'manage_columns' ] );
			add_action( "manage_{$this->name}_posts_custom_column",   [ $this, 'manage_custom_columns' ], 10, 2 );
		}

		add_action( 'load-edit.php',                              [ $this, 'default_sort' ] );
		add_action( 'pre_get_posts',                              [ $this, 'maybe_sort_by_fields' ] );
		add_filter( 'posts_clauses',                              [ $this, 'maybe_sort_by_taxonomy' ], 10, 2 );
	}

	/**
	 * Logs the default columns so we don't remove any custom columns added by other plugins.
	 *
	 * @param array<string, string> $cols The default columns for this post type screen
	 * @return array<string, string> The default columns for this post type screen
	 */
	public function log_default_cols( array $cols ): array {
		$this->default_columns = $cols;

		return $this->default_columns;
	}

	/**
	 * Adds the custom columns to the list of sortable columns.
	 *
	 * @param array<string,string> $cols Array of sortable columns keyed by the column ID.
	 * @return array<string,string> Updated array of sortable columns.
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
	 * @param array<string,string> $cols Associative array of columns
	 * @return array<string,string> Updated array of columns
	 */
	public function manage_columns( array $cols ): array {
		// This function gets called multiple times, so let's cache it for efficiency:
		if ( isset( $this->managed_columns ) ) {
			return $this->managed_columns;
		}

		$new_cols = [];
		# Add existing columns we want to keep:
		foreach ( $cols as $id => $title ) {
			if ( \in_array( $id, self::DEFAULT_KEEP_COLUMNS, true ) && ! isset( $this->args[ $id ] ) ) {
				$new_cols[ $id ] = $title;
			}
		}

		/**
		 * if a column is set to false in the configuration, array_filter()
		 * will remove it, effectively disabling it
		 *
		 * @var array<string,(string|mixed[])>
		 */
		$admin_cols = array_filter( $this->args );

		foreach ( $admin_cols as $id => $col ) {
			if ( ! is_array( $col ) ) {
				continue;
			}

			if ( isset( $col['cap'] ) && ! \current_user_can( $col['cap'] ) ) {
				continue;
			}
			$new_cols = $this->resolve_column( $new_cols, $id, $col, $cols );
		}

		# Re-add any custom columns:
		$custom   = \array_diff_key( $cols, $this->default_columns ?? [] );
		$new_cols = \array_merge( $new_cols, $custom );

		$this->managed_columns = $new_cols;
		return $this->managed_columns;
	}

	/**
	 * Returns a sensible title for the current item (usually the arguments array for a column)
	 *
	 * @param array<string,mixed> $item     An array of arguments.
	 * @param string              $fallback Fallback item title.
	 * @return string The item title.
	 */
	protected function get_item_title( array $item, string $fallback = '' ): string {
		if ( isset( $item['title'] ) ) {
			return $item['title'];

		} elseif ( isset( $item['taxonomy'] ) ) {
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
		return $fallback;
	}

	/**
	 * Output the column data for the custom columns.
	 *
	 * @param string $col     The column name.
	 * @param int    $post_id The post ID.
	 */
	public function manage_custom_columns( string $col, int $post_id ): void {
		# Shorthand:
		$c = $this->args;

		# We're only interested in the custom columns:
		$custom_cols = array_filter( array_keys( $c ) );

		if ( ! in_array( $col, $custom_cols, true ) ) {
			return;
		}

		if ( isset( $c[ $col ]['post_cap'] ) && ! current_user_can( $c[ $col ]['post_cap'], get_the_ID() ) ) {
			return;
		}
		$post = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		if ( ! isset( $c[ $col ]['link'] ) ) {
			$c[ $col ]['link'] = 'list';
		}

		if ( isset( $c[ $col ]['function'] ) ) {
			call_user_func( $c[ $col ]['function'], $post );
		} elseif ( isset( $c[ $col ]['meta_key'] ) ) {
			$this->col_post_meta( $post, $c[ $col ]['meta_key'], $c[ $col ] );
		} elseif ( isset( $c[ $col ]['taxonomy'] ) ) {
			$this->col_taxonomy( $post, $c[ $col ]['taxonomy'], $c[ $col ] );
		} elseif ( isset( $c[ $col ]['post_field'] ) ) {
			$this->col_post_field( $post, $c[ $col ]['post_field'], $c[ $col ] );
		} elseif ( isset( $c[ $col ]['featured_image'] ) ) {
			$this->col_featured_image( $post, $c[ $col ]['featured_image'], $c[ $col ] );
		}
	}

	/**
	 * Outputs column data for a post meta field.
	 *
	 * @param \WP_Post             $post     The post object.
	 * @param string              $meta_key The post meta key.
	 * @param array<string,mixed> $args     Array of arguments for this field.
	 */
	public function col_post_meta( \WP_Post $post, string $meta_key, array $args ): void {
		$vals = get_post_meta( $post->ID, $meta_key, false );
		$echo = [];

		sort( $vals );

		if ( isset( $args['date_format'] ) ) {
			if ( $args['date_format'] === true ) {
				$args['date_format'] = get_option( 'date_format' );
			}
			if ( ! is_string( $args['date_format'] ) ) {
				$args['date_format'] = 'Y-m-d';
			}
			$echo = $this->col_date_format( $vals, $args['date_format'] );
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
	 * Formats the date values for the column.
	 *
	 * @param array<string> $vals        The values to format.
	 * @param string       $date_format The date format to use.
	 * @return array<string> The formatted date values.
	 */
	/**
	 * @param array<int, mixed> $vals
	 * @return array<int, string>
	 */
	private function col_date_format( array $vals, string $date_format ): array {

		$echo = [];
		foreach ( $vals as $val ) {
			if ( ! is_scalar( $val ) ) {
				continue;
			}
			$val = (string) $val;
			try {
				$val_time = ( new \DateTime( '@' . $val ) )->format( 'U' );
			} catch ( \Exception $e ) {
				$val_time = strtotime( $val );
			}

			if ( $val_time !== false ) {
				$val = $val_time;
			}

			if ( is_numeric( $val ) ) {
				$echo[] = (string) date_i18n( $date_format, (int) $val );
			} elseif ( ! empty( $val ) ) {
				$echo[] = (string) mysql2date( $date_format, $val );
			}
		}
		return $echo;
	}

	/**
	 * Outputs column data for a taxonomy's term names.
	 *
	 * @param \WP_Post             $post     The post object.
	 * @param string              $taxonomy The taxonomy name.
	 * @param array<string,mixed> $args     Array of arguments for this field.
	 */
	public function col_taxonomy( \WP_Post $post, string $taxonomy, array $args ): void {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			return;
		}

		$terms = get_the_terms( $post, $taxonomy );
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
				$out[] = $this->col_taxonomy_link( $args['link'], $tax, $taxonomy, $term, $post );
			} else {
				$out[] = esc_html( $term->name );
			}
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo implode( ', ', $out );
	}
	/**
	 * Outputs column data for a taxonomy term link.
	 *
	 * @param string              $link     The link type.
	 * @param \WP_Taxonomy        $tax      The taxonomy object.
	 * @param string              $taxonomy The taxonomy name.
	 * @param \WP_Term            $term     The term object.
	 * @param \WP_Post             $post     The post object.
	 */
	private function col_taxonomy_link( string $link, \WP_Taxonomy $tax, string $taxonomy, \WP_Term $term, \WP_Post $post ): string {
		$out = '';
		switch ( $link ) {

			case 'view':
				if ( $tax->public ) {
					$term_link = get_term_link( $term );
					if ( is_wp_error( $term_link ) ) {
						$out = esc_html( $term->name );
						break;
					}
					$out = sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( $term_link ),
						esc_html( $term->name )
					);
				} else {
					$out = esc_html( $term->name );
				}
				break;

			case 'edit':
				if ( current_user_can( $tax->cap->edit_terms ) ) {
					$term_link = get_edit_term_link( $term->term_id, $taxonomy, $post->post_type );

					if ( is_string( $term_link ) ) {
						$out = sprintf(
							'<a href="%s">%s</a>',
							esc_url( $term_link ),
							esc_html( $term->name )
						);
					} else {
						$out = esc_html( $term->name );
					}
				} else {
					$out = esc_html( $term->name );
				}
				break;

			case 'list':
				$link = add_query_arg(
					[
						'post_type' => $post->post_type,
						$taxonomy   => $term->slug,
					],
					admin_url( 'edit.php' )
				);
				$out  = sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( $link ),
					esc_html( $term->name )
				);
				break;
		}
		return $out;
	}
	/**
	 * Outputs column data for a post field.
	 *
	 * @param \WP_Post             $post  The post object.
	 * @param string              $field The post field.
	 * @param array<string,mixed> $args  Array of arguments for this field.
	 */
	public function col_post_field( \WP_Post $post, string $field, array $args ): void {
		// Handle date fields with common logic
		$date_fields = [ 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' ];

		if ( in_array( $field, $date_fields, true ) ) {
			$value = get_post_field( $field, $post );
			if ( ! is_string( $value ) || $value === '0000-00-00 00:00:00' ) {
				return;
			}
			$format = $args['date_format'] ?? get_option( 'date_format' );
			if ( ! is_string( $format ) ) {
				$format = 'Y-m-d';
			}
			$formatted = mysql2date( $format, $value );
			echo esc_html( is_string( $formatted ) ? $formatted : '' );
			return;
		}

		// Map other fields to handlers
		$handlers = [
			'post_status'  => function () use ( $post ) {
				$post_status = get_post_status( $post );
				if ( ! is_string( $post_status ) ) {
					return '';
				}
				$status = get_post_status_object( $post_status );
				return esc_html( is_object( $status ) ? $status->label : '' );
			},
			'post_author'  => fn() => esc_html( get_the_author() ),
			'post_title'   => fn() => esc_html( get_the_title() ),
			'post_excerpt' => fn() => esc_html( get_the_excerpt() ),
		];

		if ( isset( $handlers[ $field ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $handlers[ $field ]();
			return;
		}

		// Default
		$value = get_post_field( $field, $post );
		echo esc_html( is_scalar( $value ) ? (string) $value : '' );
	}


	/**
	 * Outputs column data for a post's featured image.
	 *
	 * @param \WP_Post                  $post       The post object.
	 * @param string                   $image_size The image size.
	 * @param array<string,string|int> $args       Array of `width` and `height` attributes for the image.
	 */
	public function col_featured_image( \WP_Post $post, string $image_size, array $args ): void {
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
	public function default_sort(): void {
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
	 * @param \WP_Query $wp_query The current `WP_Query` object.
	 */
	public function maybe_sort_by_fields( \WP_Query $wp_query ): void {
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
	 * Filters the query's SQL clauses so the posts can be sorted by taxonomy terms.
	 *
	 * @param array<string,string> $clauses  The current query's SQL clauses.
	 * @param \WP_Query             $wp_query The current `WP_Query` object.
	 * @return array<string,string> The updated SQL clauses
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
	 * @param array<string,mixed> $vars      The public query vars, usually from `$wp_query->query`.
	 * @param array<string,mixed> $sortables The sortables valid for this query (usually the value of the `admin_cols` or
	 *                                       `site_sortables` argument when registering an extended post type.
	 * @return array<string,mixed> The list of private and public query vars to apply to the query.
	 */
	public static function get_sort_field_vars( array $vars, array $sortables ): array {
		$admin_col = self::get_requested_sortable_column( $vars, $sortables );
		if ( $admin_col === null ) {
			return [];
		}

		$return = [];

		if ( isset( $admin_col['meta_key'] ) ) {
			$return['meta_key'] = $admin_col['meta_key'];
			$return['orderby']  = 'meta_value';
			if ( isset( $admin_col['orderby'] ) ) {
				$return['orderby'] = $admin_col['orderby'];
			}
		} elseif ( isset( $admin_col['post_field'] ) ) {
			if ( ! is_string( $admin_col['post_field'] ) ) {
				return [];
			}
			$field             = str_replace( 'post_', '', $admin_col['post_field'] );
			$return['orderby'] = $field;
		}

		if ( isset( $vars['order'] ) ) {
			$return['order'] = $vars['order'];
		}

		return $return;
	}

	/**
	 * Resolve the requested sortable admin column config.
	 *
	 * @param array<string,mixed> $vars      The public query vars.
	 * @param array<string,mixed> $sortables Sortable column configs.
	 * @return array<string,mixed>|null The requested column config.
	 */
	private static function get_requested_sortable_column( array $vars, array $sortables ): ?array {
		if ( ! isset( $vars['orderby'] ) || ! is_string( $vars['orderby'] ) ) {
			return null;
		}

		if ( ! isset( $sortables[ $vars['orderby'] ] ) || ! is_array( $sortables[ $vars['orderby'] ] ) ) {
			return null;
		}

		$admin_col = $sortables[ $vars['orderby'] ];

		if ( isset( $admin_col['sortable'] ) && ! $admin_col['sortable'] ) {
			return null;
		}

		return $admin_col;
	}

	/**
	 * Get the array of SQL clauses for the given sortables, to apply to the current query in order to
	 * sort it by the requested orderby field.
	 *
	 * @param array<string,string> $clauses   The query's SQL clauses.
	 * @param array<string,mixed>  $vars      The public query vars, usually from `$wp_query->query`.
	 * @param array<string,mixed>  $sortables The sortables valid for this query (usually the value of the `admin_cols` or
	 *                                        `site_sortables` argument when registering an extended post type).
	 * @return array<string,string> The list of SQL clauses to apply to the query.
	 */
	public static function get_sort_taxonomy_clauses( array $clauses, array $vars, array $sortables ): array {
		/** @var \wpdb $wpdb */
		global $wpdb;
		if ( ! isset( $vars['orderby'] ) ||
			! \is_string( $vars['orderby'] ) ||
			! isset( $sortables[ $vars['orderby'] ] ) ) {
			return [];
		}

		$admin_col = $sortables[ $vars['orderby'] ];

		if ( ! is_array( $admin_col ) ||
			! isset( $admin_col['taxonomy'] ) ||
			( isset( $admin_col['sortable'] ) && ! $admin_col['sortable'] ) ) {
			return [];
		}

		# Taxonomy term ordering courtesy of http://scribu.net/wordpress/sortable-taxonomy-columns.html
		$clauses['join']   .= "
			LEFT OUTER JOIN {$wpdb->term_relationships} as ext_cpts_tr
			ON ( {$wpdb->posts}.ID = ext_cpts_tr.object_id )
			LEFT OUTER JOIN {$wpdb->term_taxonomy} as ext_cpts_tt
			ON ( ext_cpts_tr.term_taxonomy_id = ext_cpts_tt.term_taxonomy_id )
			LEFT OUTER JOIN {$wpdb->terms} as ext_cpts_t
			ON ( ext_cpts_tt.term_id = ext_cpts_t.term_id )
		";
		$clauses['where']  .= $wpdb->prepare( ' AND ( taxonomy = %s OR taxonomy IS NULL )', $admin_col['taxonomy'] );
		$clauses['groupby'] = 'ext_cpts_tr.object_id';
		$clauses['orderby'] = 'GROUP_CONCAT( ext_cpts_t.name ORDER BY name ASC ) ';
		// Default to DESC to match WordPress behaviour when order is not specified.
		$clauses['orderby'] .= ( isset( $vars['order'] ) && ( strtoupper( $vars['order'] ) === 'ASC' ) ) ? 'ASC' : 'DESC';

		return $clauses;
	}

	/**
	 * Normalizes column args to a consistent array shape.
	*/
	private function normalize_columns(): void {
		foreach ( $this->args as $id => $col ) {
			if ( $col === 'author' ) {
				$this->args[ $id ] = [ 'type' => 'author' ];
			} elseif ( \is_string( $col ) ) {
				$this->args[ $id ] = [
					'type'  => 'native',
					'value' => $col,
				];
			} elseif ( \is_array( $col ) ) {
				$this->args[ $id ] = \array_merge( [ 'type' => 'custom' ], $col );
			} else {
				$this->args[ $id ] = [ 'type' => 'custom' ];
			}
		}
	}


	/**
	 * Applies a single normalized column to the columns array.
	 *
	 * @param array<string,string> $new_cols  The accumulated column definitions.
	 * @param array<string,mixed>  $col  The column configuration array.
	 * @param array<string,string> $cols  The registered column definitions.
	 * @return array<string,string>
	 */
	private function resolve_column( array $new_cols, string $id, array $col, array $cols ): array {
		if ( $col['type'] === 'author' ) {
			$key              = \class_exists( 'coauthors_plus' ) ? 'coauthors' : 'author';
			$new_cols[ $key ] = \esc_html__( 'Author', 'saltus-framework' );
			return $new_cols;
		}

		if ( $col['type'] === 'native' ) {
			$value = $col['value'];
			if ( is_string( $value ) && isset( $cols[ $value ] ) ) {
				$new_cols[ $value ] = $cols[ $value ];
			} elseif ( isset( $cols[ $id ] ) ) {
				$new_cols[ $id ] = \esc_html( is_scalar( $value ) ? (string) $value : '' );
			}
			return $new_cols;
		}

		$title = isset( $col['title_cb'] )
			? \call_user_func( $col['title_cb'], $col )
			: \esc_html( $this->get_item_title( $col, $id ) );

		if ( isset( $col['title_icon'] ) ) {
			$title = \sprintf(
				'<span class="dashicons %s" aria-hidden="true"></span><span class="screen-reader-text">%s</span>',
				\esc_attr( $col['title_icon'] ),
				$title
			);
		}

		$new_cols[ $id ] = $title;
		return $new_cols;
	}
}
