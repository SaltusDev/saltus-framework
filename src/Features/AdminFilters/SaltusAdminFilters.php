<?php
namespace Saltus\WP\Framework\Features\AdminFilters;

use Saltus\WP\Framework\Infrastructure\Service\{
	Processable
};

/**
 * Enable custom administration filters
 *
 * Adapted from https://github.com/johnbillion/extended-cpts by johnbillion with notable changes:
 *   - models can override the default sort order
 *   - reduce cyclomatic complexity of some functions
 */
final class SaltusAdminFilters implements Processable {

	/**
	 * @var string $name The name of the custom post type (CPT)
	 */
	private $name;

	/**
	 * @var array $args List of filters
	 */
	private $args;

	/**
	 * @var array $site_filters List of filters
	 */
	public $site_filters = [];

	/**
	 * Instantiate this Service object.
	 *
	 * @param string $name The name of the custom post type (CPT)
	 * @param array  $args List of filters
	 *
	 */
	public function __construct( string $name, array $args ) {
		$this->name = $name;
		$this->args = $args;
		foreach ( $args as $id => $filter ) {
			$this->site_filters[ $id ] = $filter;
		}
	}

	/**
	 * Process the filters.
	 */
	public function process() {
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
			// phpcs:ignore WordPress.Security.NonceVerification
			if ( empty( $_GET[ $id ] ) ) {
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
	 * @param \WP_Query $wp_query A `WP_Query` object
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
	 * Get private query vars derived from public filter query vars.
	 *
	 * @param array  $query     Public query vars.
	 * @param array  $filters   Registered filters.
	 * @param string $post_type Post type.
	 *
	 * @return array
	 */
	public static function get_filter_vars( array $query, array $filters, string $post_type ): array {
		$return = [];

		foreach ( $filters as $filter_key => $filter ) {

			if ( ! self::should_process_filter( $query, $filter_key, $filter ) ) {
				continue;
			}

			$hook = "saltus/framework/admin_filters/{$post_type}/filter_query/{$filter_key}";

			if ( has_filter( $hook ) ) {
				/**
				 * Allows a filter's private query vars to be overridden.
				 *
				 * @param array<string,mixed> $return The private query vars.
				 * @param array<string,mixed> $query  The public query vars.
				 * @param array<string,mixed> $filter The filter arguments.
				 */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
				$return = apply_filters( $hook, $return, $query, $filter );
				continue;
			}

			$meta_query_key = wp_unslash( $query[ $filter_key ] );

			$meta_query = self::build_meta_query( $filter, $meta_query_key );
			$date_query = self::build_date_query( $filter, $meta_query_key );

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
	 * Determine if a filter should be processed.
	 *
	 * @param array  $query      Public query vars.
	 * @param string $filter_key Filter key.
	 * @param array  $filter     Filter config.
	 *
	 * @return bool
	 */
	private static function should_process_filter( array $query, string $filter_key, array $filter ): bool {

		if ( ! isset( $query[ $filter_key ] ) || $query[ $filter_key ] === '' ) {
			return false;
		}

		if ( isset( $filter['cap'] ) && ! current_user_can( $filter['cap'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Build a meta_query clause.
	 *
	 * @param array  $filter Filter config.
	 * @param string $value  Public value.
	 *
	 * @return array
	 */
	private static function build_meta_query( array $filter, string $meta_query_key ): array {

		if ( isset( $filter['meta_key'] ) ) {
			// notice that the values and key are reversed for searching
			return self::create_meta_clause(
				$filter['meta_key'],
				$meta_query_key,
				$filter
			);
		}

		if ( isset( $filter['meta_search_key'] ) ) {
			// notice that the values and key are reversed for searching
			return self::create_meta_clause(
				$filter['meta_search_key'],
				$meta_query_key,
				array_merge( $filter, [ 'compare' => 'LIKE' ] )
			);
		}

		if ( isset( $filter['meta_key_exists'] ) ) {
			return self::create_meta_clause(
				$meta_query_key,
				null,
				[ 'compare' => 'EXISTS' ]
			);
		}

		if ( isset( $filter['meta_exists'] ) ) {
			return self::create_meta_clause(
				$meta_query_key,
				[ '', '0', 'false', 'null' ],
				[ 'compare' => 'NOT IN' ]
			);
		}

		return [];
	}

	/**
	 * Create a single meta_query clause.
	 *
	 * @param string       $key    Meta key.
	 * @param string|array $value  Meta value.
	 * @param array        $args   Additional args.
	 *
	 * @return array
	 */
	private static function create_meta_clause( string $meta_query_key, $value, array $args ): array {

		$clause = [
			'key' => $meta_query_key,
		];

		if ( $value !== null ) {
			$clause['value'] = $value;
		}

		if ( isset( $args['compare'] ) ) {
			$clause['compare'] = $args['compare'];
		}

		if ( isset( $args['type'] ) ) {
			$clause['type'] = $args['type'];
		}

		if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
			$clause = array_merge( $clause, $args['meta_query'] );
		}

		return $clause;
	}
	/**
	 * Build a date_query clause.
	 *
	 * @param array  $filter Filter config.
	 * @param string $value  Public value.
	 *
	 * @return array
	 */
	private static function build_date_query( array $filter, string $meta_query_key ): array {

		if ( ! isset( $filter['post_date'] ) ) {
			return [];
		}

		$date_query = [
			$filter['post_date'] => $meta_query_key,
			'inclusive'          => true,
		];

		if ( isset( $filter['date_query'] ) && is_array( $filter['date_query'] ) ) {
			$date_query = array_merge( $date_query, $filter['date_query'] );
		}

		return $date_query;
	}

	/**
	 * Add filter names to the public query vars.
	 *
	 * @param array<int,string> $vars Public query variables
	 * @return array<int,string> Updated public query variables
	 */
	public function add_query_vars( array $vars ): array {
		$filters = array_keys( $this->site_filters );

		return array_merge( $vars, $filters );
	}

	/**
	 * Returns the name of the post type for the current request.
	 *
	 * @return string The post type name.
	 */
	protected static function get_current_post_type(): string {
		if ( function_exists( 'get_current_screen' ) &&
			is_object( get_current_screen() ) &&
			get_current_screen()->base === 'edit' ) {
			return get_current_screen()->post_type;
		}
		return '';
	}

	private function resolve_filter_type( array $filter ): ?string {
		if ( isset( $filter['taxonomy'] ) ) {
			return 'taxonomy';
		}

		if ( isset( $filter['meta_key'] ) ) {
			return 'meta_key';
		}

		if ( isset( $filter['meta_search_key'] ) ) {
			return 'meta_search_key';
		}

		if ( isset( $filter['meta_exists'] ) || isset( $filter['meta_key_exists'] ) ) {
			return 'meta_exists';
		}

		if ( isset( $filter['post_date'] ) ) {
			return 'post_date';
		}

		if ( isset( $filter['post_author'] ) ) {
			return 'post_author';
		}

		return null;
	}

	/**
	 * Outputs custom filter controls on the admin screen for this post type.
	 *
	 * @link https://github.com/johnbillion/extended-cpts/wiki/Admin-filters
	 */
	public function filters(): void {
		global $wpdb;

		if ( $this->get_current_post_type() !== $this->name ) {
			return;
		}

		foreach ( $this->args as $filter_id => $filter ) {

			if ( isset( $filter['cap'] ) && ! current_user_can( $filter['cap'] ) ) {
				continue;
			}

			$filter_key = $filter['key'] ?? $filter_id;
			$id         = 'filter_' . $filter_id;
			$hook       = "saltus/framework/admin_filters/filter_output/{$filter_id}";

			if ( has_action( $hook ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
				do_action( $hook, $this, $filter, $id );
				continue;
			}

			$type = $this->resolve_filter_type( $filter );

			if ( $type === null ) {
				continue;
			}

			$this->dispatch_filter(
				$type,
				$filter,
				$id,
				$wpdb
			);

		}
	}

	private function dispatch_filter(
		string $type,
		array $filter,
		string $id,
		\wpdb $wpdb
	): void {

		switch ( $type ) {
			case 'taxonomy':
				$this->render_taxonomy_filter( $filter, $id );
				break;

			case 'meta_key':
				$this->render_meta_key_filter( $filter, $id, $wpdb );
				break;

			case 'meta_search_key':
				$this->render_meta_search_key_filter( $filter, $id );
				break;

			case 'meta_exists':
				$this->render_meta_exists_filter( $filter, $id );
				break;

			case 'post_date':
				$this->render_post_date_filter( $filter, $id );
				break;

			case 'post_author':
				$this->render_post_author_filter( $filter, $id, $wpdb );
				break;
		}
	}

	private function render_taxonomy_filter(
		array $filter,
		string $id
	): void {

		if ( ! isset( $filter['taxonomy'] ) ) {
			return;
		}

		$taxonomy = $filter['taxonomy'];

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$tax = get_taxonomy( $taxonomy );

		if ( ! isset( $filter['title'] ) ) {
			$filter['title'] = $tax->labels->all_items;
		}

		$filter_key   = $filter['key'] ?? $id;
		$selected     = wp_unslash( get_query_var( $filter_key ) );
		$filter_label = $filter['label'] ?? $filter['title'];

		printf(
			'<label for="%1$s" class="screen-reader-text">%2$s</label>',
			esc_attr( $id ),
			esc_html( $filter_label )
		);

		wp_dropdown_categories(
			[
				'id'                => $id,
				'hide_empty'        => false,
				'hide_if_empty'     => false,
				'hierarchical'      => $tax->hierarchical,
				'name'              => $filter_key,
				'option_none_value' => '',
				'orderby'           => 'name',
				'selected'          => $selected,
				'selected_cats'     => get_query_var( $tax->query_var ),
				'show_count'        => false,
				'show_option_all'   => $filter_label,
				'taxonomy'          => $taxonomy,
				'value_field'       => 'slug',
			]
		);
	}

	private function render_meta_key_filter(
		array $filter,
		string $id,
		\wpdb $wpdb
	): void {

		$filter = $this->normalize_meta_filter( $filter, $id );
		if ( ! $filter ) {
			return;
		}

		$options = $this->resolve_meta_filter_options( $filter, $wpdb );
		if ( empty( $options ) ) {
			return;
		}

		[$selected, $use_key] = $this->resolve_meta_filter_state( $filter, $options );

		$this->render_meta_filter_select(
			$filter,
			$id,
			$options,
			$selected,
			$use_key
		);
	}
	private function normalize_meta_filter( array $filter, string $id ): ?array {
		if ( empty( $filter['meta_key'] ) ) {
			return null;
		}

		$filter['title'] ??= ucwords( str_replace( '_', ' ', $filter['meta_key'] ) );
		$filter['label'] ??= $filter['title'];
		$filter['key']   ??= $id;

		return $filter;
	}
	private function resolve_meta_filter_options( array $filter, \wpdb $wpdb ): array {
		$options = isset( $filter['options'] ) ? $filter['options'] : [];

		if ( is_callable( $options ) ) {
			$options = call_user_func( $options );
		}

		if ( ! empty( $options ) ) {
			return $options;
		}

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT m.meta_value
				FROM {$wpdb->postmeta} m
				INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE m.meta_key = %s
				AND m.meta_value != ''
				AND p.post_type = %s
				ORDER BY m.meta_value ASC",
				$filter['meta_key'],
				$this->name
			)
		);
	}

	private function resolve_meta_filter_state( array $filter, array $options ): array {
		$selected = wp_unslash( get_query_var( $filter['key'] ) );

		if ( isset( $filter['use_key'] ) ) {
			$use_key = (bool) $filter['use_key'];
		} else {
			foreach ( $options as $k => $v ) {
				if ( ! is_int( $k ) ) {
					$use_key = true;
					break;
				}
			}
		}

		return array( $selected, $use_key );
	}

	private function render_meta_filter_select(
		array $filter,
		string $id,
		array $options,
		$selected,
		bool $use_key
	): void {

		$filter_key   = $filter['key'];
		$filter_label = $filter['label'];
		printf(
			'<label for="%1$s" class="screen-reader-text">%2$s</label>',
			esc_attr( $id ),
			esc_html( $filter_label )
		);
		// Build the default option if needed
		$default_option = ! isset( $filter['default'] )
			? sprintf( '<option value="">%s</option>', esc_html( $filter['title'] ) )
			: '';

		// Build all the options
		$options_html = '';
		foreach ( $options as $k => $v ) {
			$key           = $use_key ? $k : $v;
			$options_html .= sprintf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $selected, $key, false ),
				esc_html( $v )
			);
		}

		// Output the complete select element
		printf(
			'<select name="%1$s" id="%2$s">%3$s%4$s</select>',
			esc_attr( $filter_key ),
			esc_attr( $id ),
			$default_option, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$options_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	private function render_meta_search_key_filter(
		array $filter,
		string $id
	): void {

		if ( ! isset( $filter['meta_search_key'] ) ) {
			return;
		}

		if ( ! isset( $filter['title'] ) ) {
			$filter['title'] = ucwords( str_replace( [ '-', '_' ], ' ', $filter['meta_search_key'] ) );
		}

		$filter_label = $filter['label'] ?? $filter['title'];
		$value        = wp_unslash( get_query_var( $filter['key'] ?? $id ) );

		// Output the search box:
		printf(
			'<label for="%1$s" class="screen-reader-text">%2$s</label><input type="text" name="%3$s" id="%1$s" value="%4$s" />',
			esc_attr( $id ),
			esc_html( $filter_label ),
			esc_attr( $filter['key'] ?? $id ),
			esc_attr( $value )
		);
	}

	private function render_meta_exists_filter(
		array $filter,
		string $id
	): void {

		$filter = $this->normalize_meta_exists_filter( $filter, $id );

		if ( $filter === null ) {
			return;
		}

		$selected = wp_unslash( get_query_var( $filter['key'] ) );
		$fields   = $filter['fields'];

		if ( count( $fields ) === 1 ) {
			$this->render_meta_exists_checkbox( $filter, $id, $fields, $selected );
			return;
		}

		$this->render_meta_exists_select( $filter, $id, $fields, $selected );
	}

	private function normalize_meta_exists_filter(
		array $filter,
		string $id
	): ?array {

		if (
			! isset( $filter['meta_exists'] ) &&
			! isset( $filter['meta_key_exists'] )
		) {
			return null;
		}

		if ( isset( $filter['meta_exists'] ) ) {
			$filter['fields'] = $filter['meta_exists'];
		} else {
			$filter['fields'] = $filter['meta_key_exists'];
		}

		if ( ! isset( $filter['title'] ) ) {
			$filter['title'] = __( 'All', 'saltus-framework' );
		}

		if ( ! isset( $filter['label'] ) ) {
			$filter['label'] = $filter['title'];
		}

		if ( ! isset( $filter['key'] ) ) {
			$filter['key'] = $id;
		}

		return $filter;
	}

	private function render_meta_exists_checkbox(
		array $filter,
		string $id,
		array $fields,
		$selected
	): void {

		$html = '';

		foreach ( $fields as $value => $label ) {

			$html .= sprintf(
				'<input type="checkbox" name="%1$s" id="%2$s" value="%3$s" %4$s />',
				esc_attr( $filter['key'] ),
				esc_attr( $id ),
				esc_attr( $value ),
				checked( $selected, $value, false )
			);

			$html .= sprintf(
				'<label for="%1$s">%2$s</label>',
				esc_attr( $id ),
				esc_html( $label )
			);
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}

	private function render_meta_exists_select(
		array $filter,
		string $id,
		array $fields,
		$selected
	): void {

		$html = sprintf(
			'<label for="%1$s" class="screen-reader-text">%2$s</label>',
			esc_attr( $id ),
			esc_html( $filter['label'] )
		);

		$html .= sprintf(
			'<select name="%1$s" id="%2$s">',
			esc_attr( $filter['key'] ),
			esc_attr( $id )
		);

		if ( ! isset( $filter['default'] ) ) {
			$html .= sprintf(
				'<option value="">%s</option>',
				esc_html( $filter['title'] )
			);
		}

		foreach ( $fields as $value => $label ) {

			$html .= sprintf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}

		$html .= '</select>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}


	private function render_post_date_filter(
		array $filter,
		string $id
	): void {

		if ( ! isset( $filter['post_date'] ) ) {
			return;
		}
		// ignore others if theres more than one filter
		if ( is_array( $filter['post_date'] ) ) {
			$filter['post_date'] = array_pop( $filter['post_date'] );
		}

		if ( ! isset( $filter['title'] ) ) {
			$filter['title'] = __( 'All', 'saltus-framework' );
		}

		$filter_key   = $filter['key'] ?? $id;
		$value        = wp_unslash( get_query_var( $filter_key ) );
		$filter_label = $filter['label'] ?? $filter['title'];
		printf(
			'<label for="%1$s">%2$s:</label>&nbsp;<input type="date" id="%1$s" name="%3$s" value="%4$s" size="12" placeholder="yyyy-mm-dd" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}">',
			esc_attr( $id ),
			esc_html( $filter_label ),
			esc_attr( $filter_key ),
			esc_attr( $value )
		);
	}

	/**
	 * Render a post author filter.
	 */
	private function render_post_author_filter(
		array $filter,
		string $id,
		\wpdb $wpdb
	): void {

		if ( ! isset( $filter['post_author'] ) ) {
			return;
		}

		if ( ! isset( $filter['title'] ) ) {
			$filter['title'] = __( 'All', 'saltus-framework' );
		}

		if ( ! isset( $filter['label'] ) ) {
			$filter['label'] = __( 'Author', 'saltus-framework' );
		}

		printf(
			'<label for="%1$s" class="screen-reader-text">%2$s</label>',
			esc_attr( $id ),
			esc_html( $filter['label'] )
		);

		if ( ! isset( $filter['options'] ) ) {
			# Fetch all the values for our field:
			$filter['options'] = $wpdb->get_col(
				$wpdb->prepare(
					"
						SELECT DISTINCT post_author
						FROM {$wpdb->posts}
						WHERE post_type = %s
					",
					$this->name
				)
			);
		} elseif ( is_callable( $filter['options'] ) ) {
			$filter['options'] = call_user_func( $filter['options'] );
		}

		if ( empty( $filter['options'] ) ) {
			return;
		}

		$value = wp_unslash( get_query_var( 'author' ) );
		// Output a list of authors:
		wp_dropdown_users(
			[
				'id'                => $id,
				'include'           => $filter['options'],
				'name'              => 'author',
				'option_none_value' => '0',
				'selected'          => $value,
				'show_option_none'  => $filter['title'],
			]
		);
	}
}
