# AI Context

Saltus models can declare an `ai_context` section that gives MCP clients governance rules for content mutations.

```php
'ai_context' => [
	'brand_voice'          => 'Clear, practical, expert, no hype.',
	'audiences'            => [ 'developers', 'site editors' ],
	'field_rules'          => [
		'post_content' => [ 'Maintain technical accuracy' ],
	],
	'allowed_statuses'     => [ 'draft', 'pending' ],
	'forbidden_actions'    => [ 'delete', 'publish' ],
	'require_human_review' => true,
],
```

The normalized context is available through the `saltus/get-context` ability and `GET /saltus-framework/v1/context/{post_type}`. Models without an `ai_context` section remain compatible with the existing MCP behavior.

`allowed_statuses` applies to create and update operations. The default create status is `draft`. `forbidden_actions` can block `create`, `update`, or `delete`; `publish` blocks create/update requests whose status is `publish`. Violations are rejected before the WordPress REST request is dispatched.

`brand_voice`, `audiences`, and `field_rules` also shape the system instruction the AI assistant sends when generating content. See [AI Assistants](ai-assistants.md).

`require_human_review` is exposed as agent guidance and queues mutating MCP tools for editorial review. Note that the `saltus/framework/editorial_review/require_human_review` filter receives only the tool name, so it cannot currently vary the decision per model.

Malformed values are ignored in favor of typed defaults. Defaults can be customized with the `saltus/framework/ai_context/defaults` filter, which receives the defaults array and model name.
