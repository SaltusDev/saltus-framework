<?php
declare( strict_types=1 );
namespace Saltus\WP\Framework\Features\AdminFilters;

/**
 * A term walker class for a dropdown menu.
 *
 * @uses Walker
 */
class WalkerTaxonomyDropdown extends \Walker {

	/**
	 * @var string $tree_type The type of tree structure being used (e.g., 'category').
	 */
	public $tree_type = 'category';

	/**
	 * @var array<string> $db_fields Database fields used for parent/child relationships and term IDs.
	 */
	public $db_fields = [
		'parent' => 'parent',
		'id'     => 'term_id',
	];

	/**
	 * @var string|null $field The field to use for the dropdown value.
	 */
	public ?string $field = null;

	/**
	 * Class constructor.
	 *
	 * @param array|null $args Optional arguments.
	 *                         - 'field': The field to use for the dropdown value.
	 */
	public function __construct( ?array $args = null ) {
		if ( $args && isset( $args['field'] ) ) {
			$this->field = $args['field'];
		}
	}

	/**
	 * Start the element output.
	 *
	 * @param string $output            Passed by reference. Used to append additional content.
	 * @param object $term_object       Term data object.
	 * @param int    $depth             Depth of term in reference to parents.
	 * @param array  $args              Optional arguments.
	 *                                  - 'taxonomy': The taxonomy name.
	 *                                  - 'selected_cats': Array of selected term values.
	 *                                  - 'selected': Array of selected term IDs.
	 *                                  - 'show_count': Whether to show the term count.
	 * @param int    $current_object_id Current object ID
	 * @param int    $current_object_id Current object ID.
	 */
	public function start_el( &$output, $term_object, $depth = 0, $args = [], $current_object_id = 0 ) {
		$pad = str_repeat( '&nbsp;', $depth * 3 );
		$tax = get_taxonomy( $args['taxonomy'] );

		if ( $this->field ) {
			$value = $term_object->{$this->field};
		} else {
			$value = $tax->hierarchical ? $term_object->term_id : $term_object->name;
		}

		if ( empty( $term_object->term_id ) && ! $tax->hierarchical ) {
			$value = '';
		}

		/** @deprecated 1.2.0 */
		$cat_name = apply_filters( 'list_cats', $term_object->name, $term_object );
		$cat_name = apply_filters( 'saltus/framework/admin_filters/category_list', $term_object->name, $term_object );
		$output  .= "\t<option class=\"level-{$depth}\" value=\"" . esc_attr( $value ) . '"';

		if ( isset( $args['selected_cats'] ) && in_array( $value, (array) $args['selected_cats'], true ) ) {
			$output .= ' selected="selected"';
		} elseif ( isset( $args['selected'] ) && in_array( $term_object->term_id, (array) $args['selected'], true ) ) {
			$output .= ' selected="selected"';
		}

		$output .= '>';
		$output .= $pad . esc_html( $cat_name );

		if ( $args['show_count'] ) {
			$output .= '&nbsp;&nbsp;(' . esc_html( number_format_i18n( $term_object->count ) ) . ')';
		}

		$output .= "</option>\n";
	}
}
