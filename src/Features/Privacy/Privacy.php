<?php

namespace Saltus\WP\Framework\Features\Privacy;

use Saltus\WP\Framework\Features\Meta\FieldEncryptionPolicy;
use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\Modeler;

/**
 * Registers model meta with WordPress core's privacy request workflow.
 *
 * Core owns the workflow — request creation, email confirmation, batching,
 * the admin screens. Saltus registers one exporter and one eraser and lets core
 * drive them. Reimplementing any of that would mean a second place for a data
 * subject request to be answered, and the two would disagree.
 *
 * Encrypted fields are exported as plaintext, deliberately. A data subject
 * request asks what the site holds about a person; answering with ciphertext
 * answers nothing. Encryption protects the value at rest, not from the person it
 * describes. Erasure removes the row either way.
 *
 * @api
 */
final class Privacy implements Service, Registerable {

	/** Exporter and eraser identifier, namespaced so core can attribute results. */
	private const SLUG = 'saltus-framework';

	/** Posts processed per batch, matching core's own conservative default. */
	private const PER_PAGE = 20;

	/** @var callable */
	private $modeler_resolver;

	private MetaFieldProvider $meta_field_provider;
	private FieldEncryptionPolicy $field_encryption;
	private ?RelationshipManager $relationships;

	/**
	 * @param callable                 $modeler_resolver Resolves the model registry.
	 * @param RelationshipManager|null $relationships    Supplies cascade rules, so erasure
	 *                                                   reaches dependent posts. Optional
	 *                                                   because relationships are a feature
	 *                                                   a site may not have enabled.
	 */
	public function __construct(
		callable $modeler_resolver,
		?MetaFieldProvider $meta_field_provider = null,
		?FieldEncryptionPolicy $field_encryption = null,
		?RelationshipManager $relationships = null
	) {
		$this->modeler_resolver    = $modeler_resolver;
		$this->meta_field_provider = $meta_field_provider ?? new MetaFieldProvider();
		$this->field_encryption    = $field_encryption ?? new FieldEncryptionPolicy( $this->meta_field_provider );
		$this->relationships       = $relationships;
	}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ], 10, 1 );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ], 10, 1 );
	}

	/**
	 * Add the Saltus exporter to core's registry.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return array<string, mixed>
	 */
	public function register_exporter( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : [];

		$exporters[ self::SLUG ] = [
			'exporter_friendly_name' => __( 'Saltus model content', 'saltus-framework' ),
			'callback'               => [ $this, 'export' ],
		];

		return $exporters;
	}

	/**
	 * Add the Saltus eraser to core's registry.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return array<string, mixed>
	 */
	public function register_eraser( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : [];

		$erasers[ self::SLUG ] = [
			'eraser_friendly_name' => __( 'Saltus model content', 'saltus-framework' ),
			'callback'             => [ $this, 'erase' ],
		];

		return $erasers;
	}

	/**
	 * Export model meta for one page of a data subject's posts.
	 *
	 * @param string $email_address Data subject's email.
	 * @param int    $page          One-based page number, supplied by core.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user = $this->user( $email_address );
		if ( $user === null ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		$posts = $this->posts_for( (int) $user->ID, $page );
		$data  = [];

		foreach ( $posts as $post ) {
			$item = $this->export_post( $post );
			if ( $item !== null ) {
				$data[] = $item;
			}
		}

		return [
			'data' => $data,
			// Not done while a full page came back: there may be another. Core calls
			// again with the next page until this reports done.
			'done' => count( $posts ) < self::PER_PAGE,
		];
	}

	/**
	 * Erase model meta for one page of a data subject's posts.
	 *
	 * Meta is removed; the posts themselves are left alone. A post is content the
	 * site owns and may be legally required to keep, and core's own erasers behave
	 * the same way — they anonymize rather than delete. Removing posts here would
	 * also cascade through relationships in ways the request never asked for.
	 *
	 * @param string $email_address Data subject's email.
	 * @param int    $page          One-based page number, supplied by core.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user = $this->user( $email_address );
		if ( $user === null ) {
			return [
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => [],
				'done'           => true,
			];
		}

		$posts    = $this->posts_for( (int) $user->ID, $page );
		$removed  = false;
		$retained = false;
		$messages = [];

		foreach ( $posts as $post ) {
			$outcome = $this->erase_post( $post );
			$removed = $removed || $outcome['removed'];

			if ( $outcome['retained'] ) {
				$retained   = true;
				$messages[] = $outcome['message'];
			}

			// A cascade-declared relationship means the related post exists only to
			// serve this one. Leaving its meta behind would leave the data subject's
			// data on an orphan that the request cannot see but the site still holds.
			$cascade = $this->cascade_targets( $post );

			foreach ( $cascade['dependents'] as $dependent ) {
				$dependent_outcome = $this->erase_post( $dependent );
				$removed           = $removed || $dependent_outcome['removed'];
			}

			// A cascade the operator cannot read is reported rather than skipped: the
			// dependent content is still held, and an erasure that returns silence here
			// reads as complete when it is not.
			foreach ( $cascade['unreadable'] as $relationship ) {
				$retained   = true;
				$messages[] = $this->unreadable_cascade_message( $relationship, $post );
			}
		}

		return [
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => count( $posts ) < self::PER_PAGE,
		];
	}

	/**
	 * Build one export item for a post, or null when it carries no model meta.
	 *
	 * @param \WP_Post $post
	 * @return array<string, mixed>|null
	 */
	private function export_post( \WP_Post $post ): ?array {
		$fields = $this->fields_for( (string) $post->post_type );
		if ( $fields === [] ) {
			return null;
		}

		$data = [];
		foreach ( $fields as $field ) {
			$entry = $this->export_field( $post, $field );
			if ( $entry !== null ) {
				$data[] = $entry;
			}
		}

		if ( $data === [] ) {
			return null;
		}

		return [
			'group_id'    => 'saltus-model-meta',
			'group_label' => __( 'Saltus model fields', 'saltus-framework' ),
			'item_id'     => 'saltus-post-' . (int) $post->ID,
			'data'        => $data,
		];
	}

	/**
	 * One name/value pair for a field, or null when it holds nothing to export.
	 *
	 * @param \WP_Post             $post
	 * @param array<string, mixed> $field Normalized field definition.
	 * @return array{name: string, value: string}|null
	 */
	private function export_field( \WP_Post $post, array $field ): ?array {
		$meta_key = (string) ( $field['meta_key'] ?? '' );
		$path     = (string) ( $field['path'] ?? '' );
		if ( $meta_key === '' || $path === '' ) {
			return null;
		}

		$value = get_post_meta( (int) $post->ID, $meta_key, true );
		if ( $value === '' || $value === null ) {
			return null;
		}

		// Decrypted for export: a request asks what the site holds about the person,
		// and ciphertext answers nothing.
		$value = $this->field_encryption->decrypt_stored(
			$this->modeler(),
			(string) $post->post_type,
			$meta_key,
			$value
		);

		$resolved = $this->value_at_path( $value, $path, $meta_key );
		if ( $resolved === null ) {
			return null;
		}

		$label = (string) ( $field['label'] ?? '' );

		return [
			'name'  => $label !== '' ? $label : $path,
			'value' => is_scalar( $resolved ) ? (string) $resolved : (string) wp_json_encode( $resolved ),
		];
	}

	/**
	 * Remove model meta from one post.
	 *
	 * @param \WP_Post $post
	 * @return array{removed: bool, retained: bool, message: string}
	 */
	private function erase_post( \WP_Post $post ): array {
		$fields = $this->fields_for( (string) $post->post_type );
		if ( $fields === [] ) {
			return [
				'removed'  => false,
				'retained' => false,
				'message'  => '',
			];
		}

		$keys = [];
		foreach ( $fields as $field ) {
			$meta_key = (string) ( $field['meta_key'] ?? '' );
			if ( $meta_key !== '' && ! in_array( $meta_key, $keys, true ) ) {
				$keys[] = $meta_key;
			}
		}

		$removed = false;
		foreach ( $keys as $meta_key ) {
			if ( get_post_meta( (int) $post->ID, $meta_key, true ) === '' ) {
				continue;
			}

			if ( delete_post_meta( (int) $post->ID, $meta_key ) ) {
				$removed = true;
			}
		}

		return [
			'removed'  => $removed,
			'retained' => false,
			'message'  => '',
		];
	}

	/**
	 * Posts a cascade-declared relationship makes dependent on this one, plus the
	 * cascade relationships that could not be read.
	 *
	 * Resolved through the manager's public API rather than by widening its private
	 * cascade helper: the rule that matters here is "declared cascade", and
	 * `RelationshipManager` stays the owner of what cascade means on delete.
	 * `describe()` and `get_related_ids()` are deliberately not used: both filter on
	 * read permission, which would drop dependents from the erasure without saying so.
	 *
	 * @param \WP_Post $post
	 * @return array{dependents: list<\WP_Post>, unreadable: list<string>}
	 */
	private function cascade_targets( \WP_Post $post ): array {
		if ( $this->relationships === null || ! function_exists( 'get_post' ) ) {
			return [
				'dependents' => [],
				'unreadable' => [],
			];
		}

		$resolved   = $this->relationships->cascade_targets_for_erasure(
			(int) $post->ID,
			(string) $post->post_type
		);
		$dependents = [];

		foreach ( $resolved['targets'] as $related_id ) {
			$related = get_post( (int) $related_id );
			if ( $related instanceof \WP_Post ) {
				$dependents[] = $related;
			}
		}

		return [
			'dependents' => $dependents,
			'unreadable' => $resolved['unreadable'],
		];
	}

	/**
	 * Operator warning for a cascade relationship the erasure could not read.
	 *
	 * Addressed to the operator, not the data subject: core shows eraser messages on the
	 * erasure screen to whoever holds `erase_others_personal_data`. That capability is
	 * authority over every relationship, so a read rule blocking one is a configuration
	 * mistake to correct - but bypassing it here would hide the mistake, and skipping
	 * silently would report an erasure that did not happen. The relationship and what is
	 * wrong with it are named, so an operator correcting their own declaration has the
	 * declaration in front of them.
	 */
	private function unreadable_cascade_message( string $relationship, \WP_Post $post ): string {
		return sprintf(
			/* translators: 1: relationship name, 2: post type slug. */
			__( 'Related content was not erased: the "%1$s" relationship on "%2$s" declares a read capability this account does not have, so content it links to is still held. Re-run the erasure as an account that can read this relationship, or correct its capabilities declaration.', 'saltus-framework' ),
			$relationship,
			(string) $post->post_type
		);
	}

	/**
	 * Normalized meta fields for a post type.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function fields_for( string $post_type ): array {
		$models = $this->modeler()->get_models();
		$model  = $models[ $post_type ] ?? null;
		if ( $model === null ) {
			return [];
		}

		$config = $model->get_config();
		$meta   = $config['meta'] ?? null;
		if ( ! is_array( $meta ) ) {
			return [];
		}

		return $this->meta_field_provider->normalize_meta_fields( $meta )['fields'];
	}

	/**
	 * One page of a user's posts across every registered model post type.
	 *
	 * @return list<\WP_Post>
	 */
	private function posts_for( int $user_id, int $page ): array {
		$post_types = [];
		foreach ( $this->modeler()->get_models() as $name => $model ) {
			if ( $model->get_type() === 'post_type' ) {
				$post_types[] = (string) $name;
			}
		}

		if ( $post_types === [] || ! function_exists( 'get_posts' ) ) {
			return [];
		}

		return array_values(
			get_posts(
				[
					'post_type'        => $post_types,
					'post_status'      => 'any',
					'author'           => $user_id,
					'posts_per_page'   => self::PER_PAGE,
					'paged'            => max( 1, $page ),
					'suppress_filters' => false,
				]
			)
		);
	}

	/**
	 * Resolve a dotted path within a stored meta value.
	 *
	 * @param mixed $value
	 * @return mixed|null
	 */
	private function value_at_path( $value, string $path, string $meta_key ) {
		if ( $path === $meta_key || strpos( $path, $meta_key . '.' ) !== 0 ) {
			return $value === '' ? null : $value;
		}

		$segments = explode( '.', substr( $path, strlen( $meta_key ) + 1 ) );
		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}

			$value = $value[ $segment ];
		}

		return $value === '' ? null : $value;
	}

	/** The user behind an email address, or null when there is none. */
	private function user( string $email_address ): ?\WP_User {
		if ( ! function_exists( 'get_user_by' ) ) {
			return null;
		}

		$user = get_user_by( 'email', $email_address );

		return $user instanceof \WP_User ? $user : null;
	}

	private function modeler(): Modeler {
		return ( $this->modeler_resolver )();
	}
}
