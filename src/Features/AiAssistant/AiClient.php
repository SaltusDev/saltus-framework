<?php

namespace Saltus\WP\Framework\Features\AiAssistant;

/**
 * Generates assistant content through the WordPress AI Client.
 *
 * Credentials are never read or stored here. WordPress passes API keys saved under
 * Settings > Connectors into the AI client's default provider registry on `init`,
 * so prompts resolve to whichever provider the site has configured.
 *
 * @api
 */
final class AiClient {

	/** Temperature for editorial tasks, low so results stay close to the source text. */
	private const TEMPERATURE = 0.2;

	/**
	 * Whether the WordPress AI Client is present and enabled for this request.
	 *
	 * Returns false on WordPress versions without the built-in client, and whenever
	 * the site or a plugin has disabled AI through `wp_supports_ai`.
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_ai_client_prompt' )
			&& function_exists( 'wp_supports_ai' )
			&& wp_supports_ai();
	}

	/**
	 * Generate plain text for an assistant action.
	 *
	 * @param array<string, mixed> $context Normalized AI context for the model.
	 * @return string|\WP_Error Generated text, or an error when generation is unavailable or fails.
	 */
	public function generate_text( string $prompt, array $context, string $action = '', string $post_type = '' ) {
		$builder = $this->builder( $prompt, $context, $action, $post_type );
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		$text = $builder->generate_text();
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$text = trim( $text );
		if ( $text === '' ) {
			return $this->error( 'ai_assistant_empty_result', __( 'The AI provider returned no usable text.', 'saltus-framework' ), 502 );
		}

		return $text;
	}

	/**
	 * Generate a JSON list of strings for an assistant action.
	 *
	 * @param array<string, mixed> $context Normalized AI context for the model.
	 * @param array<string, mixed> $schema  JSON schema describing the expected shape.
	 * @return list<string>|\WP_Error Decoded list of strings, or an error.
	 */
	public function generate_json( string $prompt, array $context, array $schema, string $action = '', string $post_type = '' ) {
		$builder = $this->builder( $prompt, $context, $action, $post_type );
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		$json = $builder->as_json_response( $schema )->generate_text();
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		if ( trim( $json ) === '' ) {
			return $this->error( 'ai_assistant_empty_result', __( 'The AI provider returned no usable text.', 'saltus-framework' ), 502 );
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return $this->error( 'ai_assistant_invalid_json', __( 'The AI provider returned malformed JSON.', 'saltus-framework' ), 502 );
		}

		return $this->string_list( $decoded );
	}

	/**
	 * Build a configured prompt builder, or an error when generation is not possible.
	 *
	 * @param array<string, mixed> $context
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error
	 */
	private function builder( string $prompt, array $context, string $action, string $post_type ) {
		if ( ! self::is_available() ) {
			return $this->error( 'ai_assistant_client_unavailable', __( 'The WordPress AI Client is not available on this site.', 'saltus-framework' ), 501 );
		}

		$builder = wp_ai_client_prompt( $prompt )->using_temperature( self::TEMPERATURE );

		$instruction = $this->system_instruction( $context );
		if ( $instruction !== '' ) {
			$builder = $builder->using_system_instruction( $instruction );
		}

		/**
		 * Filters the prompt builder before generation.
		 *
		 * Use this to pin a provider or model, for example by calling
		 * `using_model_preference()` or `using_provider()` on the builder. Saltus itself
		 * lets WordPress resolve the provider from the configured connectors.
		 *
		 * @param \WP_AI_Client_Prompt_Builder $builder   The configured prompt builder.
		 * @param string                       $action    Assistant action name.
		 * @param array<string, mixed>         $context   Normalized AI context for the model.
		 * @param string                       $post_type Model the action runs against.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'saltus/framework/ai/prompt_builder', $builder, $action, $context, $post_type );
			if ( $filtered instanceof \WP_AI_Client_Prompt_Builder ) {
				$builder = $filtered;
			}
		}

		if ( ! $builder->is_supported_for_text_generation() ) {
			return $this->error( 'ai_assistant_unsupported', __( 'No configured AI provider supports text generation. Add credentials under Settings > Connectors.', 'saltus-framework' ), 501 );
		}

		return $builder;
	}

	/**
	 * Compose a system instruction from the model's AI context.
	 *
	 * @param array<string, mixed> $context
	 */
	private function system_instruction( array $context ): string {
		$lines = [ __( 'You are an editorial assistant for a WordPress site.', 'saltus-framework' ) ];

		$brand_voice = isset( $context['brand_voice'] ) && is_string( $context['brand_voice'] ) ? trim( $context['brand_voice'] ) : '';
		if ( $brand_voice !== '' ) {
			$lines[] = sprintf( /* translators: %s: brand voice description. */ __( 'Brand voice: %s', 'saltus-framework' ), $brand_voice );
		}

		$audiences = isset( $context['audiences'] ) && is_array( $context['audiences'] ) ? $this->string_list( $context['audiences'] ) : [];
		if ( $audiences !== [] ) {
			$lines[] = sprintf( /* translators: %s: comma-separated audience list. */ __( 'Audiences: %s', 'saltus-framework' ), implode( ', ', $audiences ) );
		}

		foreach ( $this->field_rules( $context ) as $field => $rules ) {
			$lines[] = sprintf(
				/* translators: 1: field name, 2: comma-separated rules. */
				__( 'Rules for %1$s: %2$s', 'saltus-framework' ),
				$field,
				implode( '; ', $rules )
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, list<string>>
	 */
	private function field_rules( array $context ): array {
		$raw = isset( $context['field_rules'] ) && is_array( $context['field_rules'] ) ? $context['field_rules'] : [];
		$out = [];
		foreach ( $raw as $field => $rules ) {
			if ( ! is_string( $field ) || ! is_array( $rules ) ) {
				continue;
			}
			$strings = $this->string_list( $rules );
			if ( $strings !== [] ) {
				$out[ $field ] = $strings;
			}
		}
		return $out;
	}

	/**
	 * @param array<mixed> $values
	 * @return list<string>
	 */
	private function string_list( array $values ): array {
		$strings = [];
		foreach ( $values as $value ) {
			if ( is_string( $value ) && trim( $value ) !== '' ) {
				$strings[] = trim( $value );
			}
		}
		return $strings;
	}

	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
