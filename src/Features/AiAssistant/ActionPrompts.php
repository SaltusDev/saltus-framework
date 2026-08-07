<?php

namespace Saltus\WP\Framework\Features\AiAssistant;

/**
 * Builds prompts for the built-in assistant actions.
 *
 * Each action declares whether it expects prose or a JSON list, which keeps the
 * generated shape aligned with what AiAssistantProvider::normalize_result() accepts.
 */
final class ActionPrompts {

	public const MODE_TEXT = 'text';
	public const MODE_JSON = 'json';

	/** Actions that return a JSON list rather than prose, mapped to their result key. */
	private const JSON_ACTIONS = [
		'suggest_terms'    => 'suggestions',
		'validate_content' => 'violations',
	];

	public function mode( string $action ): string {
		return isset( self::JSON_ACTIONS[ $action ] ) ? self::MODE_JSON : self::MODE_TEXT;
	}

	/** Result key a JSON action fills, empty for text actions. */
	public function result_key( string $action ): string {
		return self::JSON_ACTIONS[ $action ] ?? '';
	}

	/**
	 * Compose the user prompt for an action.
	 *
	 * @param array<string, mixed> $payload Field values submitted from the editor.
	 * @return string Empty when the action is unknown.
	 */
	public function prompt( string $action, array $payload ): string {
		$title   = $this->text( $payload, 'title' );
		$content = $this->text( $payload, 'content' );
		$excerpt = $this->text( $payload, 'excerpt' );

		switch ( $action ) {
			case 'improve_title':
				return sprintf(
					/* translators: 1: current title, 2: post content. */
					__( "Improve this title. Reply with the improved title only, no quotes or explanation.\n\nTitle: %1\$s\n\nContent:\n%2\$s", 'saltus-framework' ),
					$title,
					$content
				);
			case 'summarize':
				return sprintf(
					/* translators: 1: post title, 2: post content. */
					__( "Summarize the content below in two or three sentences. Reply with the summary only.\n\nTitle: %1\$s\n\nContent:\n%2\$s", 'saltus-framework' ),
					$title,
					$content
				);
			case 'generate_excerpt':
				return sprintf(
					/* translators: 1: post title, 2: post content, 3: existing excerpt. */
					__( "Write a single-sentence excerpt for the content below. Reply with the excerpt only.\n\nTitle: %1\$s\n\nContent:\n%2\$s\n\nExisting excerpt: %3\$s", 'saltus-framework' ),
					$title,
					$content,
					$excerpt
				);
			case 'suggest_terms':
				return sprintf(
					/* translators: 1: post title, 2: post content. */
					__( "Suggest up to eight taxonomy terms for the content below. Reply with a JSON array of strings.\n\nTitle: %1\$s\n\nContent:\n%2\$s", 'saltus-framework' ),
					$title,
					$content
				);
			case 'validate_content':
				return sprintf(
					/* translators: 1: post title, 2: post content. */
					__( "Check the content below against the editorial rules in your instructions. Reply with a JSON array of strings, one per violation found, or an empty array when it complies.\n\nTitle: %1\$s\n\nContent:\n%2\$s", 'saltus-framework' ),
					$title,
					$content
				);
		}

		return '';
	}

	/**
	 * JSON schema for actions that return a list of strings.
	 *
	 * @return array<string, mixed>
	 */
	public function schema(): array {
		return [
			'type'  => 'array',
			'items' => [ 'type' => 'string' ],
		];
	}

	/** @param array<string, mixed> $payload */
	private function text( array $payload, string $key ): string {
		$value = $payload[ $key ] ?? '';
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
