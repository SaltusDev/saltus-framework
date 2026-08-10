# AI Assistants

Saltus can add provider-agnostic AI controls to the WordPress post editor for models that define `ai_context`.

```php
'ai_context' => [
	'brand_voice'  => 'Clear, practical, expert, no hype.',
	'field_rules'  => [
		'post_content' => [ 'Maintain technical accuracy' ],
	],
	'audiences'    => [ 'developers', 'site editors' ],
],
```

The framework exposes controls for improving titles, summarizing content, generating excerpts, suggesting terms, and validating content against the configured context. Controls appear on post editor screens only and generated values are applied only after the editor confirms them. Validation results are shown beside the content field and mark the field for attention.

## Providers

Saltus resolves a provider in this order:

1. The `saltus/framework/ai/assistant_actions` filter, if a consuming plugin handles the action.
2. The WordPress AI Client, using credentials from **Settings > Connectors**.
3. Otherwise a `501` response with the code `ai_assistant_no_provider`.

### WordPress Connectors

On WordPress 7.0+ the assistant works with no plugin code. Add credentials for any AI provider under **Settings > Connectors**, and WordPress passes them to its AI client on `init`. Saltus never reads, stores, or transmits API keys, and it hardcodes no provider or model: prompts go through `wp_ai_client_prompt()`, so whichever provider the site configured serves the request.

The model's `ai_context` shapes each prompt. `brand_voice`, `audiences`, and `field_rules` become the system instruction, so the same action produces different results per model.

Availability is reported by the health endpoint under `ai.client_available`, and by `wp saltus health` in the `ai` column.

To pin a provider or model, filter the prompt builder:

```php
add_filter(
	'saltus/framework/ai/prompt_builder',
	function ( $builder, $action, $context, $post_type ) {
		return $builder->using_model_preference( 'claude-sonnet-5', 'gpt-5.1' );
	},
	10,
	4
);
```

The filter receives the configured `WP_AI_Client_Prompt_Builder`, the action name, the normalized `ai_context`, and the post type. Return the builder. Any `using_*` or `as_*` method is available; prefer plain model IDs over `[ provider, model ]` pairs so the site stays provider-agnostic.

Generation errors surface with the status WordPress assigns them, so a network failure returns `503` and a token limit `400`. Saltus adds `ai_assistant_unsupported` when no configured provider supports text generation, `ai_assistant_empty_result` when a provider returns nothing usable, and `ai_assistant_invalid_json` when a term or validation response is not valid JSON.

Requires WordPress 7.0 or newer. On older versions the client step is skipped and only the filter path below applies.

## Provider Filter

A consuming plugin can handle actions itself, overriding the AI client. This runs first, so existing integrations keep working unchanged:

```php
add_filter(
	'saltus/framework/ai/assistant_actions',
	function ( $result, $action, $payload, $context, $post_type, $post, $unused = null ) {
		if ( $action !== 'improve_title' ) {
			return $result;
		}

		return [
			'target' => 'post_title',
			'value'  => my_ai_improve_title( $payload['title'] ?? '', $context ),
		];
	},
	10,
	7
);
```

The filter receives the current result, action name, editor payload, normalized `ai_context`, post type, and post object. It must return an array or `WP_Error`. Arrays may contain:

- `target`: `post_title`, `post_excerpt`, `post_content`, or `terms`.
- `value`: generated text for a text target.
- `suggestions`: a list of term suggestions.
- `violations`: a list of brand-rule validation messages.

Supported actions are `improve_title`, `summarize`, `generate_excerpt`, `suggest_terms`, and `validate_content`. REST calls use the authenticated WordPress admin session and `X-WP-Nonce`; existing posts require `edit_post`, while new posts require `edit_posts`.
