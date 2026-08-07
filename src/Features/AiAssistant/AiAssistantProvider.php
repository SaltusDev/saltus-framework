<?php

namespace Saltus\WP\Framework\Features\AiAssistant;

use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Features\AiContext\AiContextProvider;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

/** Provides model-scoped admin assistant definitions and dispatches actions. */
final class AiAssistantProvider {

	private ?Modeler $modeler;
	/** @var callable|null */
	private $modeler_resolver;
	private AiContextProvider $context_provider;
	private MetaFieldProvider $meta_fields;
	private AiClient $client;
	private ActionPrompts $prompts;

	/** @param Modeler|callable|null $modeler */
	public function __construct( $modeler = null, ?AiContextProvider $context_provider = null, ?MetaFieldProvider $meta_fields = null, ?AiClient $client = null, ?ActionPrompts $prompts = null ) {
		$this->modeler          = $modeler instanceof Modeler ? $modeler : null;
		$this->modeler_resolver = is_callable( $modeler ) ? $modeler : null;
		$this->context_provider = $context_provider ?? new AiContextProvider( $modeler );
		$this->meta_fields      = $meta_fields ?? new MetaFieldProvider();
		$this->client           = $client ?? new AiClient();
		$this->prompts          = $prompts ?? new ActionPrompts();
	}

	/** @return array<string, mixed>|null */
	public function definition( string $post_type ): ?array {
		$model = $this->model( $post_type );
		if ( ! $model instanceof Model || $model->get_type() !== 'post_type' ) {
			return null;
		}

		$context = $this->context_provider->get( $post_type );
		if ( ! is_array( $context ) || empty( $context['configured'] ) ) {
			return null;
		}

		$args = $model->get_args();
		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : [];

		return [
			'model'       => $post_type,
			'context'     => $context,
			'actions'     => $this->actions(),
			'field_rules' => $context['field_rules'],
			'fields'      => $this->meta_fields->normalize_meta_fields( $meta )['fields'],
		];
	}

	/**
	 * Dispatch an assistant action through the consuming plugin.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|\WP_Error
	 */
	public function dispatch( string $post_type, string $action, array $payload ) {
		$definition = $this->definition( $post_type );
		if ( $definition === null ) {
			return new \WP_Error( 'ai_assistant_unavailable', __( 'AI assistants are not configured for this model.', 'saltus-framework' ), [ 'status' => 404 ] );
		}
		$known_actions = array_column( $definition['actions'], 'name' );
		if ( ! in_array( $action, $known_actions, true ) ) {
			return new \WP_Error( 'ai_assistant_action_invalid', __( 'The requested AI assistant action is not available.', 'saltus-framework' ), [ 'status' => 400 ] );
		}

		$post    = null;
		$post_id = (int) ( $payload['post_id'] ?? 0 );
		if ( $post_id > 0 && function_exists( 'get_post' ) ) {
			$post = get_post( $post_id );
		}
		$result = $this->filter( null, $action, $payload, $definition['context'], $post_type, $post );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_array( $result ) ) {
			return $this->normalize_result( $action, $result );
		}

		// No consuming plugin handled the action, so generate through the WordPress AI Client.
		if ( ! AiClient::is_available() ) {
			return new \WP_Error( 'ai_assistant_no_provider', __( 'No AI assistant provider handled this action.', 'saltus-framework' ), [ 'status' => 501 ] );
		}

		$generated = $this->generate( $action, $this->prompt_payload( $payload, $post ), $definition['context'], $post_type );
		if ( is_wp_error( $generated ) ) {
			return $generated;
		}
		return $this->normalize_result( $action, $generated );
	}

	/**
	 * Generate a result for an action through the AI client.
	 *
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>|\WP_Error
	 */
	private function generate( string $action, array $payload, array $context, string $post_type ) {
		$prompt = $this->prompts->prompt( $action, $payload );
		if ( $prompt === '' ) {
			return new \WP_Error( 'ai_assistant_action_invalid', __( 'The requested AI assistant action is not available.', 'saltus-framework' ), [ 'status' => 400 ] );
		}

		if ( $this->prompts->mode( $action ) === ActionPrompts::MODE_JSON ) {
			$list = $this->client->generate_json( $prompt, $context, $this->prompts->schema(), $action, $post_type );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			return [ $this->prompts->result_key( $action ) => $list ];
		}

		$text = $this->client->generate_text( $prompt, $context, $action, $post_type );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		return [ 'value' => $text ];
	}

	/**
	 * Fill missing prompt input from the post being edited.
	 *
	 * @param array<string, mixed> $payload
	 * @param \WP_Post|null $post
	 * @return array<string, mixed>
	 */
	private function prompt_payload( array $payload, ?\WP_Post $post ): array {
		if ( ! $post instanceof \WP_Post ) {
			return $payload;
		}
		$fallbacks = [
			'title'   => (string) $post->post_title,
			'content' => (string) $post->post_content,
			'excerpt' => (string) $post->post_excerpt,
		];
		foreach ( $fallbacks as $key => $value ) {
			$current = $payload[ $key ] ?? '';
			if ( ! is_scalar( $current ) || trim( (string) $current ) === '' ) {
				$payload[ $key ] = $value;
			}
		}
		return $payload;
	}

	/** @return list<array{name: string, label: string, target: string}> */
	public function actions(): array {
		return [
			[
				'name'   => 'improve_title',
				'label'  => __( 'Improve title', 'saltus-framework' ),
				'target' => 'post_title',
			],
			[
				'name'   => 'summarize',
				'label'  => __( 'Summarize', 'saltus-framework' ),
				'target' => 'post_excerpt',
			],
			[
				'name'   => 'generate_excerpt',
				'label'  => __( 'Generate excerpt', 'saltus-framework' ),
				'target' => 'post_excerpt',
			],
			[
				'name'   => 'suggest_terms',
				'label'  => __( 'Suggest terms', 'saltus-framework' ),
				'target' => 'terms',
			],
			[
				'name'   => 'validate_content',
				'label'  => __( 'Validate content', 'saltus-framework' ),
				'target' => 'post_content',
			],
		];
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private function normalize_result( string $action, array $result ): array {
		$targets = [
			'improve_title'    => 'post_title',
			'summarize'        => 'post_excerpt',
			'generate_excerpt' => 'post_excerpt',
			'suggest_terms'    => 'terms',
			'validate_content' => 'post_content',
		];
		$target  = (string) ( $result['target'] ?? $targets[ $action ] ?? '' );
		if ( ! in_array( $target, [ 'post_title', 'post_excerpt', 'post_content', 'terms' ], true ) ) {
			$target = $targets[ $action ] ?? '';
		}
		$output = [
			'action' => $action,
			'target' => $target,
		];
		if ( array_key_exists( 'value', $result ) && is_string( $result['value'] ) ) {
			$output['value'] = $result['value'];
		}
		if ( isset( $result['suggestions'] ) && is_array( $result['suggestions'] ) ) {
			$output['suggestions'] = array_values( array_filter( $result['suggestions'], 'is_string' ) );
		}
		if ( isset( $result['violations'] ) && is_array( $result['violations'] ) ) {
			$output['violations'] = array_values( array_filter( $result['violations'], 'is_string' ) );
		}
		return $output;
	}

	/** @return Model|null */
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
	 * @param mixed $value
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $context
	 * @param \WP_Post|null $post
	 * @return mixed
	 */
	private function filter( $value, string $action, array $payload, array $context, string $post_type, ?\WP_Post $post ) {
		if ( function_exists( 'apply_filters' ) ) {
			return apply_filters( 'saltus/framework/ai/assistant_actions', $value, $action, $payload, $context, $post_type, $post );
		}
		return $value;
	}
}
