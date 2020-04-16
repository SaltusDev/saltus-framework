<?php
namespace Saltus\WP\Framework\Features\AdminFilters;

/**
 * Enable custom administration filters
 *
 * Adapted from https://github.com/johnbillion/extended-cpts by johnbillion
 */
final class SaltusAdminFilters {

	private $name;
	private $project;
	private $args;

	public $site_filters;

	/**
	 * Instantiate this Service object.
	 *
	 */
	public function __construct( string $name, array $project, array $args ) {
		$this->project      = $project;
		$this->name         = $name;
		$this->args         = $args;
		$this->site_filters = [];

		$this->register();
	}

	public function register() {

		add_action( 'load-edit.php',         [ $this, 'default_filter' ] );
		add_filter( 'pre_get_posts',         [ $this, 'maybe_filter' ] );
		add_filter( 'query_vars',            [ $this, 'add_query_vars' ] );
		add_action( 'restrict_manage_posts', [ $this, 'filters' ] );
	}

	/**
	 * Sets the default sort field and sort order on our post type admin screen.
	 */
	public function default_filter() {
		if ( $this->get_current_post_type() !== $this->name ) {
			return;
		}

		# Loop over our filters to find the default filter (if there is one):
		foreach ( $this->args as $id => $filter ) {
			if ( isset( $_GET[ $id ] ) && '' !== $_GET[ $id ] ) {
				continue;
			}

			if ( is_array( $filter ) && isset( $filter['default'] ) ) {
				$_GET[ $id ] = $filter['default'];
				return;
			}
		}
	}

	/**
	 * Filters posts by our custom admin filters.
	 *
	 * @param WP_Query $wp_query A `WP_Query` object
	 */
	public function maybe_filter( \WP_Query $wp_query ) {
		if ( empty( $wp_query->query['post_type'] ) || ! in_array( $this->name, (array) $wp_query->query['post_type'], true ) ) {
			return;
		}

		$vars = $this->get_filter_vars( $wp_query->query, $this->site_filters, $this->name );

		if ( empty( $vars ) ) {
			return;
		}

		foreach ( $vars as $key => $value ) {
			if ( is_array( $value ) ) {
				$query = $wp_query->get( $key );
				if ( empty( $query ) ) {
					$query = [];
				}
				$value = array_merge( $query, $value );
			}
			$wp_query->set( $key, $value );
		}
	}

	/**
	 * Get the array of private query vars for the given filters, to apply to the current query in order to filter it by the
	 * given public query vars.
	 *
	 * @param array  $query     The public query vars, usually from `$wp_query->query`.
	 * @param array  $filters   The filters valid for this query (usually the value of the `admin_filters` or
	 *                          `site_filters` argument when registering an extended post type).
	 * @param string $post_type The post type name.
	 * @return array The list of private query vars to apply to the query.
	 */
	public static function get_filter_vars( array $query, array $filters, string $post_type ) : array {
		$return = [];

		foreach ( $filters as $filter_key => $filter ) {
			$meta_query = [];
			$date_query = [];

			if ( ! isset( $query[ $filter_key ] ) || ( '' === $query[ $filter_key ] ) ) {
				continue;
			}

			if ( isset( $filter['cap'] ) && ! current_user_can( $filter['cap'] ) ) {
				continue;
			}

			if ( isset( $filter['meta_key'] ) ) {
				$meta_query = [
					'key'   => $filter['meta_key'],
					'value' => wp_unslash( $query[ $filter_key ] ),
				];
			} elseif ( isset( $filter['meta_search_key'] ) ) {
				$meta_query = [
					'key'     => $filter['meta_search_key'],
					'value'   => wp_unslash( $query[ $filter_key ] ),
					'compare' => 'LIKE',
				];
			} elseif ( isset( $filter['meta_key_exists'] ) ) {
				$meta_query = [
					'key'     => wp_unslash( $query[ $filter_key ] ),
					'compare' => 'EXISTS',
				];
			} elseif ( isset( $filter['meta_exists'] ) ) {
				$meta_query = [
					'key'     => wp_unslash( $query[ $filter_key ] ),
					'compare' => 'NOT IN',
					'value'   => [ '', '0', 'false', 'null' ],
				];
			} elseif ( isset( $filter['post_date'] ) ) {
				$date_query = [
					$filter['post_date'] => wp_unslash( $query[ $filter_key ] ),
					'inclusive'          => true,
				];
			} else {
				continue;
			}

			if ( isset( $filter['meta_query'] ) ) {
				$meta_query = array_merge( $meta_query, $filter['meta_query'] );
			}

			if ( isset( $filter['date_query'] ) ) {
				$date_query = array_merge( $date_query, $filter['date_query'] );
			}

			if ( ! empty( $meta_query ) ) {
				$return['meta_query'][] = $meta_query;
			}

			if ( ! empty( $date_query ) ) {
				$return['date_query'][] = $date_query;
			}
		}

		return $return;
	}

	/**
	 * Add our filter names to the public query vars.
	 *
	 * @param string[] $vars Public query variables.
	 * @return string[] Updated public query variables.
	 */
	public function add_query_vars( array $vars ) : array {
		$filters = array_keys( $this->site_filters );

		return array_merge( $vars, $filters );
	}

	/**
	 * Returns the name of the post type for the current request.
	 *
	 * @return string The post type name.
	 */
	protected static function get_current_post_type() : string {
		if ( function_exists( 'get_current_screen' ) && is_object( get_current_screen() ) && 'edit' === get_current_screen()->base ) {
			return get_current_screen()->post_type;
		} else {
			return '';
		}
	}

	/**
	 * Outputs custom filter controls on the admin screen for this post type.
	 *
	 * @link https://github.com/johnbillion/extended-cpts/wiki/Admin-filters
	 */
	public function filters() {
		global $wpdb;

		if ( $this->get_current_post_type() !== $this->name ) {
			return;
		}

		$pto = get_post_type_object( $this->name );

		foreach ( $this->args as $filter_key => $filter ) {
			if ( isset( $filter['cap'] ) && ! current_user_can( $filter['cap'] ) ) {
				continue;
			}

			$id = 'filter_' . $filter_key;

			$hook = "ext-cpts/{$this->name}/filter-output/{$filter_key}";

			if ( has_action( $hook ) ) {
				/**
				 * Allows a filter's output to be overridden.
				 *
				 * @since 4.3.0
				 *
				 * @param Extended_CPT_Admin $this   The post type admin controller instance.
				 * @param array              $filter The filter arguments.
				 * @param string             $id     The filter's `id` attribute value.
				 */
				do_action( $hook, $this, $filter, $id );
				continue;
			}

			if ( isset( $filter['taxonomy'] ) ) {
				$tax = get_taxonomy( $filter['taxonomy'] );

				if ( empty( $tax ) ) {
					continue;
				}

				$walker = new WalkerTaxonomyDropdown( [
					'field' => 'slug',
				] );

				# If we haven't specified a title, use the all_items label from the taxonomy:
				if ( ! isset( $filter['title'] ) ) {
					$filter['title'] = $tax->labels->all_items;
				}

				printf(
					'<label for="%1$s" class="screen-reader-text">%2$s</label>',
					esc_attr( $id ),
					esc_html( $tax->labels->filter_by ?? $tax->labels->singular_name )
				);

				# Output the dropdown:
				wp_dropdown_categories( [
					'show_option_all' => $filter['title'],
					'hide_empty'      => false,
					'hide_if_empty'   => true,
					'hierarchical'    => true,
					'show_count'      => false,
					'orderby'         => 'name',
					'selected_cats'   => get_query_var( $tax->query_var ),
					'id'              => $id,
					'name'            => $tax->query_var,
					'taxonomy'        => $filter['taxonomy'],
					'walker'          => $walker,
				] );
			} elseif ( isset( $filter['meta_key'] ) ) {
				# If we haven't specified a title, generate one from the meta key:
				if ( ! isset( $filter['title'] ) ) {
					$filter['title'] = str_replace( [
						'-',
						'_',
					], ' ', $filter['meta_key'] );
					$filter['title'] = ucwords( $filter['title'] ) . 's';
					$filter['title'] = sprintf( 'All %s', $filter['title'] );
				}

				# If we haven't specified a label, generate one from the meta key:
				if ( ! isset( $filter['label'] ) ) {
					$filter['label'] = str_replace( [
						'-',
						'_',
					], ' ', $filter['meta_key'] );
					$filter['label'] = ucwords( $filter['label'] );
					$filter['label'] = sprintf( 'Filter by %s', $filter['label'] );
				}

				if ( ! isset( $filter['options'] ) ) {
					# Fetch all the values for our meta key:
					$filter['options'] = $wpdb->get_col( $wpdb->prepare( "
						SELECT DISTINCT meta_value
						FROM {$wpdb->postmeta} as m
						JOIN {$wpdb->posts} as p ON ( p.ID = m.post_id )
						WHERE m.meta_key = %s
						AND m.meta_value != ''
						AND p.post_type = %s
						ORDER BY m.meta_value ASC
					", $filter['meta_key'], $this->name ) );
				} elseif ( is_callable( $filter['options'] ) ) {
					$filter['options'] = call_user_func( $filter['options'] );
				}

				if ( empty( $filter['options'] ) ) {
					continue;
				}

				$selected = wp_unslash( get_query_var( $filter_key ) );

				$use_key = false;

				foreach ( $filter['options'] as $k => $v ) {
					if ( ! is_numeric( $k ) ) {
						$use_key = true;
						break;
					}
				}

				printf(
					'<label for="%1$s" class="screen-reader-text">%2$s</label>',
					esc_attr( $id ),
					esc_html( $filter['label'] )
				);

				# Output the dropdown:
				?>
				<select name="<?php echo esc_attr( $filter_key ); ?>" id="<?php echo esc_attr( $id ); ?>">
					<?php if ( ! isset( $filter['default'] ) ) { ?>
						<option value=""><?php echo esc_html( $filter['title'] ); ?></option>
					<?php } ?>
					<?php
					foreach ( $filter['options'] as $k => $v ) {
						$key = ( $use_key ? $k : $v );
						?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected, $key ); ?>><?php echo esc_html( $v ); ?></option>
					<?php } ?>
				</select>
				<?php
			} elseif ( isset( $filter['meta_search_key'] ) ) {
				# If we haven't specified a title, generate one from the meta key:
				if ( ! isset( $filter['title'] ) ) {
					$filter['title'] = str_replace( [
						'-',
						'_',
					], ' ', $filter['meta_search_key'] );
					$filter['title'] = ucwords( $filter['title'] );
				}

				$value = wp_unslash( get_query_var( $filter_key ) );

				# Output the search box:
				?>
				<label for="<?php echo esc_attr( $id ); ?>"><?php printf( '%s:', esc_html( $filter['title'] ) ); ?></label>&nbsp;<input type="text" name="<?php echo esc_attr( $filter_key ); ?>" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<?php
			} elseif ( isset( $filter['meta_exists'] ) || isset( $filter['meta_key_exists'] ) ) {
				# If we haven't specified a title, use the all_items label from the post type:
				if ( ! isset( $filter['title'] ) ) {
					$filter['title'] = $pto->labels->all_items;
				}

				$selected = wp_unslash( get_query_var( $filter_key ) );
				$fields   = $filter['meta_exists'] ?? $filter['meta_key_exists'];

				if ( 1 === count( $fields ) ) {
					# Output a checkbox:
					foreach ( $fields as $v => $t ) {
						?>
						<input type="checkbox" name="<?php echo esc_attr( $filter_key ); ?>" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $v ); ?>" <?php checked( $selected, $v ); ?>><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $t ); ?></label>
						<?php
					}
				} else {
					if ( ! isset( $filter['label'] ) ) {
						$filter['label'] = $pto->labels->name;
					}

					printf(
						'<label for="%1$s" class="screen-reader-text">%2$s</label>',
						esc_attr( $id ),
						esc_html( $filter['label'] )
					);

					# Output a dropdown:
					?>
					<select name="<?php echo esc_attr( $filter_key ); ?>" id="<?php echo esc_attr( $id ); ?>">
						<?php if ( ! isset( $filter['default'] ) ) { ?>
							<option value=""><?php echo esc_html( $filter['title'] ); ?></option>
						<?php } ?>
						<?php foreach ( $fields as $v => $t ) { ?>
							<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $selected, $v ); ?>><?php echo esc_html( $t ); ?></option>
						<?php } ?>
					</select>
					<?php
				}
			} elseif ( isset( $filter['post_date'] ) ) {
				$value = wp_unslash( get_query_var( $filter_key ) );

				if ( ! isset( $filter['title'] ) ) {
					$filter['title'] = ucwords( $filter['post_date'] );
				}

				?>
				<label for="<?php echo esc_attr( $id ); ?>"><?php printf( '%s:', esc_html( $filter['title'] ) ); ?></label>&nbsp;
				<input type="date" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $filter_key ); ?>" value="<?php echo esc_attr( $value ); ?>" size="12" placeholder="yyyy-mm-dd" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}">
				<?php
			} elseif ( isset( $filter['post_author'] ) ) {
				$value = wp_unslash( get_query_var( 'author' ) );

				if ( ! isset( $filter['title'] ) ) {
					$filter['title'] = __( 'All Authors', 'extended-cpts' );
				}

				if ( ! isset( $filter['label'] ) ) {
					$filter['label'] = __( 'Author', 'extended-cpts' );
				}

				printf(
					'<label for="%1$s" class="screen-reader-text">%2$s</label>',
					esc_attr( $id ),
					esc_html( $filter['label'] )
				);

				if ( ! isset( $filter['options'] ) ) {
					# Fetch all the values for our field:
					$filter['options'] = $wpdb->get_col( $wpdb->prepare( "
						SELECT DISTINCT post_author
						FROM {$wpdb->posts}
						WHERE post_type = %s
					", $this->name ) );
				} elseif ( is_callable( $filter['options'] ) ) {
					$filter['options'] = call_user_func( $filter['options'] );
				}

				if ( empty( $filter['options'] ) ) {
					continue;
				}

				# Output a dropdown:
				wp_dropdown_users( [
					'id'                => $id,
					'include'           => $filter['options'],
					'name'              => 'author',
					'option_none_value' => '0',
					'selected'          => $value,
					'show_option_none'  => $filter['title'],
				] );
			}
		}
	}

}

