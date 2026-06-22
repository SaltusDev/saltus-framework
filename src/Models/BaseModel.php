<?php
namespace Saltus\WP\Framework\Models;

use Noodlehaus\AbstractConfig;

abstract class BaseModel {

	/**
	 * The full CPT configuration.
	 *
	 * Includes cache.
	 *
	 * @var AbstractConfig
	 */
	protected $config;

	/**
	 * the actual cpt data.
	 *
	 * Includes Name, Type, etc
	 *
	 * @var array<string, mixed>
	 */
	protected array $data;

	/**
	 * Set of options for registering Post Types
	 *
	 * @var array<string, mixed>
	 */
	protected array $options = [];

	/**
	 * name is required by register_post_type() and register_taxonomy()
	 */
	public string $name = '';

	/**
	 * Optional paramenters to register the cpt
	 *
	 * @see https://developer.wordpress.org/reference/functions/register_post_type/#parameters
	 */
	/** @var array<string, mixed> */
	protected array $args = [];

	/**
	 * data req for computations
	 */
	/** @var array<string, string> */
	protected array $bulk_messages   = [];
	protected string $i18n           = 'saltus';
	protected string $featured_image = '';
	protected string $many           = '';
	protected string $many_low       = '';
	/** @var array<string, string> */
	protected array $messages = [];
	protected string $one     = '';
	protected string $one_low = '';
	/** @var array<string, string> */
	protected array $ui_labels = [];

	/**
	 * Constructor.
	 *
	 * @param AbstractConfig $config_data The configuration data for the model.
	 */
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

		// set messages to override
		$this->set_messages( $config_data );
	}

	/**
	 * Check to see if model has been disabled
	 *
	 * @return boolean
	 */
	protected function is_disabled(): bool {
		if ( empty( $this->data['active'] ) || $this->data['active'] === true ) {
			return false;
		}
		return true;
	}

	/**
	 * Set name
	 *
	 * Required to register post type
	 *
	 * @param string $name The name of the post type.
	 */
	protected function set_name( string $name ): void {
		$this->name = $name;
	}

	/**
	 * Set labels to override in ui
	 *
	 * Based on labels.overrides.ui values
	 *
	 * @param AbstractConfig $config The configuration labels for the model.
	 */
	protected function set_ui_label_overrides( AbstractConfig $config ): void {
		$ui_labels       = $config['labels.overrides.ui'];
		$this->ui_labels = is_array( $ui_labels ) ? $ui_labels : [];
	}

	/**
	 * Set messages overrides
	 *
	 * Based on labels.overrides.messages and label.overrides.bulk_messages values
	 *
	 * @param AbstractConfig $config The configuration labels for the model.
	 */
	protected function set_messages( AbstractConfig $config ): void {
		$messages            = $config['labels.overrides.messages'];
		$bulk_messages       = $config['labels.overrides.bulk_messages'];
		$this->messages      = is_array( $messages ) ? $messages : [];
		$this->bulk_messages = is_array( $bulk_messages ) ? $bulk_messages : [];
	}

	/**
	 * Set required labels
	 *
	 * Based on name, or keys labels.has-one and labels.has-many
	 *
	 * @param AbstractConfig $config The configuration labels for the model.
	 */
	protected function set_name_labels( AbstractConfig $config ): void {
		$this->one            = is_string( $config['labels.has_one'] ) ? $config['labels.has_one'] : ucfirst( $this->name );
		$this->many           = is_string( $config['labels.has_many'] ) ? $config['labels.has_many'] : ucfirst( $this->name . 's' );
		$this->i18n           = is_string( $config['labels.text_domain'] ) ? $config['labels.text_domain'] : 'saltus';
		$this->featured_image = is_string( $config['labels.featured_image'] ) ? $config['labels.featured_image'] : '';

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
	 *
	 * @param array<string, mixed> $options User defined options
	 */
	protected function set_options( array $options ): void {
		if ( empty( $this->data['options'] ) ) {
			$this->options = $options;
			return;
		}

		$options = array_replace( $options, $this->data['options'] );

		$this->options = $options;
	}

	/**
	 * Set label overrides
	 *
	 * If key labels.overrides exists, add to or replace label defaults
	 *
	 * @param array<string, string> $labels User defined labels
	 */
	protected function set_labels( array $labels ): void {
		if ( empty( $this->config['labels.overrides.labels'] ) ) {
			$labels = $labels;
		}
		if ( $this->config['labels.overrides.labels'] ) {
			$labels = array_replace( $labels, $this->config['labels.overrides.labels'] );
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
	 * @param array $messages An array of post updated message arrays keyed by post type.
	 *
	 * @return array          Updated array of post updated messages.
	 */

	/**
	 * Filter post updated messages for this CPT.
	 *
	 * @param array<string, array<int, string|false>> $messages Post updated messages.
	 * @return array<string, array<int, string|false>>
	 */
	public function post_updated_messages( array $messages ): array {
		$post = get_post();
		if ( ! $post ) {
			return $messages;
		}

		$pto = get_post_type_object( $this->name );
		if ( ! $pto ) {
			return $messages;
		}

		$date      = esc_html( date_i18n( 'M j, Y @ G:i', strtotime( $post->post_date ) ) );
		$permalink = esc_url( get_permalink( $post ) );
		$preview   = esc_url( get_preview_post_link( $post ) );

		$search  = [ '{permalink}', '{date}', '{preview_url}' ];
		$replace = [ $permalink, $date, $preview ];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$revision_title = isset( $_GET['revision'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? wp_post_revision_title( absint( $_GET['revision'] ), false )
			: false;

		$messages[ $this->name ] = [
			1  => $this->resolve_message(
				'post_updated',
				$pto->publicly_queryable
					? \sprintf(
						/* translators: 1: post type label, 2: permalink, 3: post type label lowercase */
						__( '%1$s updated. <a href="%2$s">View %3$s</a>', 'saltus-framework' ),
						esc_html( $this->one ),
						$permalink,
						esc_html( $this->one_low )
					)
					: \sprintf(
						/* translators: 1: post type label */
						__( '%s updated.', 'saltus-framework' ),
						esc_html( $this->one )
					),
				$search,
				$replace
			),
			2  => $this->resolve_message(
				'custom_field_updated',
				__( 'Custom field updated.', 'saltus-framework' ),
				$search,
				$replace
			),
			3  => $this->resolve_message(
				'custom_field_deleted',
				__( 'Custom field deleted.', 'saltus-framework' ),
				$search,
				$replace
			),
			4  => $this->resolve_message(
				'post_updated_short',
				\sprintf(
					/* translators: 1: post type label */
					__( '%s updated.', 'saltus-framework' ),
					esc_html( $this->one )
				),
				$search,
				$replace
			),
			5  => $revision_title
				? $this->resolve_message(
					'post_restored',
					\sprintf(
						/* translators: 1: post type label, 2: revision date */
						__( '%1$s restored to revision from %2$s', 'saltus-framework' ),
						esc_html( $this->one ),
						$revision_title
					),
					$search,
					$replace
				)
				: false,
			6  => $this->resolve_message(
				'post_published',
				$pto->publicly_queryable
					? \sprintf(
						/* translators: 1: post type label, 2: permalink, 3: post type label lowercase */
						__( '%1$s published. <a href="%2$s">View %3$s</a>', 'saltus-framework' ),
						esc_html( $this->one ),
						$permalink,
						esc_html( $this->one_low )
					)
					: \sprintf(
						/* translators: 1: post type label */
						__( '%s published.', 'saltus-framework' ),
						esc_html( $this->one )
					),
				$search,
				$replace
			),
			7  => $this->resolve_message(
				'post_saved',
				\sprintf(
					/* translators: 1: post type label */
					__( '%s saved.', 'saltus-framework' ),
					esc_html( $this->one )
				),
				$search,
				$replace
			),
			8  => $this->resolve_message(
				'post_submitted',
				$pto->publicly_queryable
					? \sprintf(
						/* translators: 1: post type label, 2: preview url, 3: post type label lowercase */
						__( '%1$s submitted. <a target="_blank" href="%2$s">Preview %3$s</a>', 'saltus-framework' ),
						esc_html( $this->one ),
						$preview,
						esc_html( $this->one_low )
					)
					: \sprintf(
						/* translators: 1: post type label */
						__( '%s submitted.', 'saltus-framework' ),
						esc_html( $this->one )
					),
				$search,
				$replace
			),
			9  => $this->resolve_message(
				'post_scheduled',
				$pto->publicly_queryable
					? \sprintf(
						/* translators: 1: post type label, 2: date, 3: permalink, 4: post type label lowercase */
						__( '%1$s scheduled for: <strong>%2$s</strong>. <a target="_blank" href="%3$s">Preview %4$s</a>', 'saltus-framework' ),
						esc_html( $this->one ),
						$date,
						$permalink,
						esc_html( $this->one_low )
					)
					: \sprintf(
						/* translators: 1: post type label, 2: date */
						__( '%1$s scheduled for: <strong>%2$s</strong>.', 'saltus-framework' ),
						esc_html( $this->one ),
						$date
					),
				$search,
				$replace
			),
			10 => $this->resolve_message(
				'post_draft_updated',
				$pto->publicly_queryable
					? \sprintf(
						/* translators: 1: post type label, 2: preview url, 3: post type label lowercase */
						__( '%1$s draft updated. <a target="_blank" href="%2$s">Preview %3$s</a>', 'saltus-framework' ),
						esc_html( $this->one ),
						$preview,
						esc_html( $this->one_low )
					)
					: \sprintf(
						/* translators: 1: post type label */
						__( '%s draft updated.', 'saltus-framework' ),
						esc_html( $this->one )
					),
				$search,
				$replace
			),
		];

		return $messages;
	}

	/**
	 * Resolve a message from overrides or use the default.
	 *
	 * @param string $key     Message key.
	 * @param string $default_msg Default message.
	 * @param array<int, string> $search  Placeholder search values.
	 * @param array<int, string> $replace Placeholder replace values.
	 * @return string
	 */
	private function resolve_message( string $key, string $default_msg, array $search, array $replace ): string {
		return isset( $this->messages[ $key ] )
			? str_replace( $search, $replace, $this->messages[ $key ] )
			: $default_msg;
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
	 * @param array<string, array<string, string>> $messages An array of bulk post updated message arrays keyed by post type.
	 * @param array<string, int>                   $counts   An array of counts for each key in `$messages`.
	 *
	 * @return array<string, array<string, string>> Updated array of bulk post updated messages.
	 */
	public function bulk_post_updated_messages( array $messages, array $counts ): array {
		$messages[ $this->name ] = [
			'updated'   => isset( $this->bulk_messages['updated_singular'] ) && isset( $this->bulk_messages['updated_plural'] ) ?
				$this->n( $this->bulk_messages['updated_singular'], $this->bulk_messages['updated_plural'], $counts['updated'] ) :
				sprintf(
					$this->n( '%2$s updated.', '%1$s %3$s updated.', $counts['updated'] ),
					esc_html( number_format_i18n( $counts['updated'] ) ),
					esc_html( $this->one ),
					esc_html( $this->many_low )
				),
			'locked'    => isset( $this->bulk_messages['locked_singular'] ) && isset( $this->bulk_messages['locked_plural'] ) ?
				$this->n( $this->bulk_messages['locked_singular'], $this->bulk_messages['locked_plural'], $counts['locked'] ) :
				sprintf(
					$this->n( '%2$s not updated, somebody is editing it.', '%1$s %3$s not updated, somebody is editing them.', $counts['locked'] ),
					esc_html( number_format_i18n( $counts['locked'] ) ),
					esc_html( $this->one ),
					esc_html( $this->many_low )
				),
			'deleted'   => isset( $this->bulk_messages['deleted_singular'] ) && isset( $this->bulk_messages['deleted_plural'] ) ?
				$this->n( $this->bulk_messages['deleted_singular'], $this->bulk_messages['deleted_plural'], $counts['deleted'] ) :
				sprintf(
					$this->n( '%2$s permanently deleted.', '%1$s %3$s permanently deleted.', $counts['deleted'] ),
					esc_html( number_format_i18n( $counts['deleted'] ) ),
					esc_html( $this->one ),
					esc_html( $this->many_low )
				),
			'trashed'   => isset( $this->bulk_messages['trashed_singular'] ) && isset( $this->bulk_messages['trashed_plural'] ) ?
				$this->n( $this->bulk_messages['trashed_singular'], $this->bulk_messages['trashed_plural'], $counts['trashed'] ) :
				sprintf(
					$this->n( '%2$s moved to the trash.', '%1$s %3$s moved to the trash.', $counts['trashed'] ),
					esc_html( number_format_i18n( $counts['trashed'] ) ),
					esc_html( $this->one ),
					esc_html( $this->many_low )
				),
			'untrashed' => isset( $this->bulk_messages['untrashed_singular'] ) && isset( $this->bulk_messages['untrashed_plural'] ) ?
				$this->n( $this->bulk_messages['untrashed_singular'], $this->bulk_messages['untrashed_plural'], $counts['untrashed'] ) :
				sprintf(
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
	 *
	 * @return string        Either `$single` or `$plural` text
	 */
	protected static function n( string $single, string $plural, int $number ): string {
		return ( intval( $number ) === 1 ) ? $single : $plural;
	}

	/**
	 * Return the sanitized model name for WordPress registration APIs.
	 *
	 * @return lowercase-string&non-empty-string
	 */
	public function get_registration_name(): string {
		/** @var lowercase-string $name */
		$name = sanitize_key( $this->name );
		if ( $name === '' ) {
			throw new \InvalidArgumentException( 'Model name cannot be empty.' );
		}

		$max_length = $this->get_type() === 'taxonomy' ? 32 : 20;

		if ( strlen( $name ) > $max_length ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Model name "%s" exceeds the maximum %d character limit for %s registration.',
					$name,
					$max_length,
					$this->get_type()
				)
			);
		}

		return $name;
	}
}
