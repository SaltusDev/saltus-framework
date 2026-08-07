<?php

namespace Saltus\WP\Framework\Features\AiContext;

use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

/** Provides normalized, model-scoped AI governance context. */
final class AiContextProvider {

	/** @var callable|null */
	private $modeler_resolver;

	private ?Modeler $modeler;

	/**
	 * @param Modeler|callable|null $modeler Model registry or lazy resolver.
	 */
	public function __construct( $modeler = null ) {
		$this->modeler          = $modeler instanceof Modeler ? $modeler : null;
		$this->modeler_resolver = is_callable( $modeler ) ? $modeler : null;
	}

	/**
	 * Get normalized context for a model name.
	 *
	 * @param string $model_name
	 * @return array<string, mixed>|null
	 */
	public function get( string $model_name ): ?array {
		$model = $this->model( $model_name );
		if ( ! $model instanceof Model ) {
			return null;
		}

		$config     = $model->get_config();
		$configured = array_key_exists( 'ai_context', $config );
		$raw        = $configured && is_array( $config['ai_context'] ) ? $config['ai_context'] : [];
		$defaults   = $this->filter( 'saltus/framework/ai_context/defaults', self::defaults(), $model_name );
		$defaults   = is_array( $defaults ) ? $defaults : self::defaults();

		return [
			'model'                => $model_name,
			'configured'           => $configured,
			'brand_voice'          => $this->string_value( $raw, $defaults, 'brand_voice' ),
			'audiences'            => $this->string_list( $raw, $defaults, 'audiences' ),
			'field_rules'          => $this->field_rules( $raw, $defaults ),
			'allowed_statuses'     => $this->string_list( $raw, $defaults, 'allowed_statuses' ),
			'forbidden_actions'    => $this->string_list( $raw, $defaults, 'forbidden_actions' ),
			'require_human_review' => $this->bool_value( $raw, $defaults, 'require_human_review' ),
		];
	}

	/**
	 * Validate a mutating MCP action against model context.
	 *
	 * @param string $tool_name
	 * @param array<string, mixed> $args
	 * @return \WP_Error|null
	 */
	public function validate_mutation( string $tool_name, array $args ): ?\WP_Error {
		$action = $this->mutation_action( $tool_name );
		if ( $action === null ) {
			return null;
		}

		$model_name = (string) ( $args['post_type'] ?? 'posts' );
		if ( $tool_name !== 'create_post' && function_exists( 'get_post' ) ) {
			$post = get_post( (int) ( $args['post_id'] ?? 0 ) );
			if ( $post ) {
				$model_name = (string) $post->post_type;
			}
		}

		$context = $this->get( $model_name );
		if ( $context === null || ! $context['configured'] ) {
			return null;
		}

		return $this->check_action_rules( $tool_name, $action, $model_name, $args, $context );
	}

	private function mutation_action( string $tool_name ): ?string {
		return [
			'create_post'        => 'create',
			'update_post'        => 'update',
			'delete_post'        => 'delete',
			'create_term'        => 'create',
			'duplicate_post'     => 'create',
			'update_meta_fields' => 'update',
			'update_settings'    => 'update',
			'reorder_posts'      => 'update',
		][ $tool_name ] ?? null;
	}

	/**
	 * @param array<string, mixed> $args
	 * @param array<string, mixed> $context
	 */
	private function check_action_rules( string $tool_name, string $action, string $model_name, array $args, array $context ): ?\WP_Error {
		if ( in_array( $action, $context['forbidden_actions'], true ) ) {
			return $this->violation( $model_name, $action, 'forbidden_actions' );
		}
		if ( in_array( 'publish', $context['forbidden_actions'], true ) && ( $args['status'] ?? null ) === 'publish' ) {
			return $this->violation( $model_name, 'publish', 'forbidden_actions' );
		}
		if ( in_array( $tool_name, [ 'create_post', 'update_post' ], true ) && array_key_exists( 'status', $args ) && ! in_array( $args['status'], $context['allowed_statuses'], true ) ) {
			return $this->violation( $model_name, (string) $args['status'], 'allowed_statuses' );
		}
		if ( $tool_name === 'create_post' && ! array_key_exists( 'status', $args ) && ! in_array( 'draft', $context['allowed_statuses'], true ) ) {
			return $this->violation( $model_name, 'draft', 'allowed_statuses' );
		}
		return null;
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return [
			'brand_voice'          => '',
			'audiences'            => [],
			'field_rules'          => [],
			'allowed_statuses'     => [ 'draft', 'pending', 'publish', 'private' ],
			'forbidden_actions'    => [],
			'require_human_review' => false,
		];
	}

	/**
	 * @param array<string, mixed> $raw
	 * @param array<string, mixed> $defaults
	 */
	private function string_value( array $raw, array $defaults, string $key ): string {
		$value = $raw[ $key ] ?? $defaults[ $key ] ?? '';
		return is_string( $value ) ? $value : ( is_string( $defaults[ $key ] ?? null ) ? $defaults[ $key ] : '' );
	}

	/**
	 * @param array<string, mixed> $raw
	 * @param array<string, mixed> $defaults
	 * @return list<string>
	 */
	private function string_list( array $raw, array $defaults, string $key ): array {
		$value = $raw[ $key ] ?? $defaults[ $key ] ?? [];
		if ( ! is_array( $value ) ) {
			$value = $defaults[ $key ] ?? [];
		}
		return array_values( array_filter( $value, 'is_string' ) );
	}

	/**
	 * @param array<string, mixed> $raw
	 * @param array<string, mixed> $defaults
	 * @return array<string, list<string>>
	 */
	private function field_rules( array $raw, array $defaults ): array {
		$value = $raw['field_rules'] ?? $defaults['field_rules'] ?? [];
		if ( ! is_array( $value ) ) {
			$value = [];
		}
		$result = [];
		foreach ( $value as $field => $rules ) {
			if ( ! is_string( $field ) || ! is_array( $rules ) ) {
				continue;
			}
			$result[ $field ] = array_values( array_filter( $rules, 'is_string' ) );
		}
		return $result;
	}

	/**
	 * @param array<string, mixed> $raw
	 * @param array<string, mixed> $defaults
	 */
	private function bool_value( array $raw, array $defaults, string $key ): bool {
		$value = $raw[ $key ] ?? $defaults[ $key ] ?? false;
		return is_bool( $value ) ? $value : (bool) ( $defaults[ $key ] ?? false );
	}

	private function model( string $name ): ?Model {
		$modeler = $this->modeler;
		if ( ! $modeler instanceof Modeler && is_callable( $this->modeler_resolver ) ) {
			$candidate = ( $this->modeler_resolver )();
			$modeler   = $candidate instanceof Modeler ? $candidate : null;
		}
		if ( ! $modeler instanceof Modeler ) {
			return null;
		}
		$models = $modeler->get_models();
		return $models[ $name ] ?? null;
	}

	/**
	 * @param non-empty-string $hook
	 * @param mixed $value
	 * @return mixed
	 */
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hook is a fixed, prefixed argument.
	private function filter( string $hook, $value, string $model_name ) {
		if ( function_exists( 'apply_filters' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hook is a fixed, prefixed argument.
			return apply_filters(
				$hook,
				$value,
				$model_name
			);
		}
		return $value;
	}
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

	private function violation( string $model, string $rule, string $source ): \WP_Error {
		return new \WP_Error(
			'ai_context_violation',
			__( 'The AI context does not permit this mutation.', 'saltus-framework' ),
			[
				'status' => 403,
				'model'  => $model,
				'rule'   => $source,
				'value'  => $rule,
			]
		);
	}
}
