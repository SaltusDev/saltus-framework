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

## Provider Filter

The framework does not send content to an AI service. A consuming plugin handles its provider credentials and model API through:

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
