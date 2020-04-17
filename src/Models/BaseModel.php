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
	protected $ui_labels;
	protected $one_low;
	protected $many_low;

	public function __construct( AbstractConfig $config_data ) {
		$this->data   = $config_data->all();
		$this->config = $config_data;

		if ( $this->is_disabled() ) {
			return;
		}

		$this->set_name( $config_data->get( 'name' ) );

		// setup default global labels
		$this->set_name_labels( $config_data );

		// set ui labels to override
		$this->set_ui_label_overrides( $config_data );

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
	 * Set labels to override in ui
	 *
	 * Based on labels.ui_overrides values
	 */
	protected function set_ui_label_overrides( AbstractConfig $config ) {
		$this->ui_labels = ( $config['labels.ui_overrides'] ? $config['labels.ui_overrides'] : [] );
	}

	/**
	 * Set required labels
	 *
	 * Based on name, or keys labels.has-one and labels.has-many
	 */
	protected function set_name_labels( AbstractConfig $config ) {
		$this->one            = ( $config['labels.has_one'] ? $config['labels.has_one'] : ucfirst( $this->name ) );
		$this->many           = ( $config['labels.has_many'] ? $config['labels.has_many'] : ucfirst( $this->name . 's' ) );
		$this->i18n           = ( $config['labels.text_domain'] ? $config['labels.text_domain'] : 'saltus' );
		$this->featured_image = ( $config['labels.featured_image'] ? $config['labels.featured_image'] : '' );

		# Lower-casing is not forced if the name looks like an initialism, eg. FAQ.
		if ( ! preg_match( '/[A-Z]{2,}/', $this->one ) ) {
			$this->one_low = strtolower( $this->one );
		} else {
			$this->one_low = $this->one;
		}

		if ( ! preg_match( '/[A-Z]{2,}/', $this->many ) ) {
			$this->many_low = strtolower( $this->many );
		} else {
			$this->many_low = $this->many;
		}
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
		}
		if ( $this->config['labels.overrides'] ) {
			$labels = array_replace( $labels, $this->config['labels.overrides'] );
		}
		$this->args['labels'] = $labels;
	}

	/**
	 * Adds our post type updated messages.
	 *
	 * The messages are as follows:
	 *
	 *   1 => "Post updated. {View Post}"
	 *   2 => "Custom field updated."
	 *   3 => "Custom field deleted."
	 *   4 => "Post updated."
	 *   5 => "Post restored to revision from [date]."
	 *   6 => "Post published. {View post}"
	 *   7 => "Post saved."
	 *   8 => "Post submitted. {Preview post}"
	 *   9 => "Post scheduled for: [date]. {Preview post}"
	 *  10 => "Post draft updated. {Preview post}"
	 *
	 * @param array[] $messages An array of post updated message arrays keyed by post type.
	 * @return array[] Updated array of post updated messages.
	 */
	public function post_updated_messages( array $messages ) : array {
		global $post;

		$pto = get_post_type_object( $this->name );

		$messages[ $this->name ] = [
			1  => sprintf(
				( $pto->publicly_queryable ? '%1$s updated. <a href="%2$s">View %3$s</a>' : '%1$s updated.' ),
				esc_html( $this->one ),
				esc_url( get_permalink( $post ) ),
				esc_html( $this->one_low )
			),
			2  => 'Custom field updated.',
			3  => 'Custom field deleted.',
			4  => sprintf(
				'%s updated.',
				esc_html( $this->one )
			),
			5  => isset( $_GET['revision'] ) ? sprintf(
				'%1$s restored to revision from %2$s',
				esc_html( $this->one ),
				wp_post_revision_title( intval( $_GET['revision'] ), false )
			) : false,
			6  => sprintf(
				( $pto->publicly_queryable ? '%1$s published. <a href="%2$s">View %3$s</a>' : '%1$s published.' ),
				esc_html( $this->one ),
				esc_url( get_permalink( $post ) ),
				esc_html( $this->one_low )
			),
			7  => sprintf(
				'%s saved.',
				esc_html( $this->one )
			),
			8  => sprintf(
				( $pto->publicly_queryable ? '%1$s submitted. <a target="_blank" href="%2$s">Preview %3$s</a>' : '%1$s submitted.' ),
				esc_html( $this->one ),
				esc_url( get_preview_post_link( $post ) ),
				esc_html( $this->one_low )
			),
			9  => sprintf(
				( $pto->publicly_queryable ? '%1$s scheduled for: <strong>%2$s</strong>. <a target="_blank" href="%3$s">Preview %4$s</a>' : '%1$s scheduled for: <strong>%2$s</strong>.' ),
				esc_html( $this->one ),
				esc_html( date_i18n( 'M j, Y @ G:i', strtotime( $post->post_date ) ) ),
				esc_url( get_permalink( $post ) ),
				esc_html( $this->one_low )
			),
			10 => sprintf(
				( $pto->publicly_queryable ? '%1$s draft updated. <a target="_blank" href="%2$s">Preview %3$s</a>' : '%1$s draft updated.' ),
				esc_html( $this->one ),
				esc_url( get_preview_post_link( $post ) ),
				esc_html( $this->one_low )
			),
		];

		return $messages;
	}

	/**
	 * Adds our bulk post type updated messages.
	 *
	 * The messages are as follows:
	 *
	 *  - updated   => "Post updated." | "[n] posts updated."
	 *  - locked    => "Post not updated, somebody is editing it." | "[n] posts not updated, somebody is editing them."
	 *  - deleted   => "Post permanently deleted." | "[n] posts permanently deleted."
	 *  - trashed   => "Post moved to the trash." | "[n] posts moved to the trash."
	 *  - untrashed => "Post restored from the trash." | "[n] posts restored from the trash."
	 *
	 * @param array[] $messages An array of bulk post updated message arrays keyed by post type.
	 * @param int[]   $counts   An array of counts for each key in `$messages`.
	 * @return array Updated array of bulk post updated messages.
	 */
	public function bulk_post_updated_messages( array $messages, array $counts ) : array {
		$messages[ $this->name ] = [
			'updated'   => sprintf(
				$this->n( '%2$s updated.', '%1$s %3$s updated.', $counts['updated'] ),
				esc_html( number_format_i18n( $counts['updated'] ) ),
				esc_html( $this->one ),
				esc_html( $this->many_low )
			),
			'locked'    => sprintf(
				$this->n( '%2$s not updated, somebody is editing it.', '%1$s %3$s not updated, somebody is editing them.', $counts['locked'] ),
				esc_html( number_format_i18n( $counts['locked'] ) ),
				esc_html( $this->one ),
				esc_html( $this->many_low )
			),
			'deleted'   => sprintf(
				$this->n( '%2$s permanently deleted.', '%1$s %3$s permanently deleted.', $counts['deleted'] ),
				esc_html( number_format_i18n( $counts['deleted'] ) ),
				esc_html( $this->one ),
				esc_html( $this->many_low )
			),
			'trashed'   => sprintf(
				$this->n( '%2$s moved to the trash.', '%1$s %3$s moved to the trash.', $counts['trashed'] ),
				esc_html( number_format_i18n( $counts['trashed'] ) ),
				esc_html( $this->one ),
				esc_html( $this->many_low )
			),
			'untrashed' => sprintf(
				$this->n( '%2$s restored from the trash.', '%1$s %3$s restored from the trash.', $counts['untrashed'] ),
				esc_html( number_format_i18n( $counts['untrashed'] ) ),
				esc_html( $this->one ),
				esc_html( $this->many_low )
			),
		];

		return $messages;
	}

	/**
	 * A non-localised version of _n()
	 *
	 * @param string $single The text that will be used if $number is 1
	 * @param string $plural The text that will be used if $number is not 1
	 * @param int    $number The number to compare against to use either `$single` or `$plural`
	 * @return string Either `$single` or `$plural` text
	 */
	protected static function n( string $single, string $plural, int $number ) : string {
		return ( 1 === intval( $number ) ) ? $single : $plural;
	}
}
