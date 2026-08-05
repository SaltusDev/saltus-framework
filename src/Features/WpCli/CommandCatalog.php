<?php
namespace Saltus\WP\Framework\Features\WpCli;

/** Authoritative mapping between MCP abilities and WP-CLI commands. @api */
final class CommandCatalog {
	/** @return list<array{ability: string, command: string, description: string}> */
	public static function all(): array {
		return [
			[
				'ability'     => 'get_health',
				'command'     => 'wp saltus health',
				'description' => 'Show framework health and runtime metrics.',
			],
			[
				'ability'     => 'get_context',
				'command'     => 'wp saltus context get <post-type>',
				'description' => 'Show AI governance context for a model.',
			],
			[
				'ability'     => 'list_models',
				'command'     => 'wp saltus model list',
				'description' => 'List loaded Saltus models.',
			],
			[
				'ability'     => 'get_model',
				'command'     => 'wp saltus model get <slug>',
				'description' => 'Show one loaded model.',
			],
			[
				'ability'     => 'list_posts',
				'command'     => 'wp saltus post list <post-type>',
				'description' => 'List posts for a post type.',
			],
			[
				'ability'     => 'get_post',
				'command'     => 'wp saltus post get <id>',
				'description' => 'Show one post.',
			],
			[
				'ability'     => 'create_post',
				'command'     => 'wp saltus post create <post-type> <title>',
				'description' => 'Create a post.',
			],
			[
				'ability'     => 'update_post',
				'command'     => 'wp saltus post update <id>',
				'description' => 'Update a post.',
			],
			[
				'ability'     => 'delete_post',
				'command'     => 'wp saltus post delete <id>',
				'description' => 'Delete or trash a post.',
			],
			[
				'ability'     => 'duplicate_post',
				'command'     => 'wp saltus post duplicate <id>',
				'description' => 'Duplicate a post as a draft.',
			],
			[
				'ability'     => 'export_post',
				'command'     => 'wp saltus post export <id>',
				'description' => 'Export one post as WXR.',
			],
			[
				'ability'     => 'list_terms',
				'command'     => 'wp saltus term list <taxonomy>',
				'description' => 'List taxonomy terms.',
			],
			[
				'ability'     => 'create_term',
				'command'     => 'wp saltus term create <taxonomy> <name>',
				'description' => 'Create a taxonomy term.',
			],
			[
				'ability'     => 'get_settings',
				'command'     => 'wp saltus settings get <post-type>',
				'description' => 'Read model settings.',
			],
			[
				'ability'     => 'update_settings',
				'command'     => 'wp saltus settings update <post-type> <json|@file>',
				'description' => 'Update model settings.',
			],
			[
				'ability'     => 'list_meta_fields',
				'command'     => 'wp saltus meta list',
				'description' => 'List model meta fields.',
			],
			[
				'ability'     => 'get_meta_fields',
				'command'     => 'wp saltus meta get <post-type>',
				'description' => 'Show one model meta schema.',
			],
			[
				'ability'     => 'update_meta_fields',
				'command'     => 'wp saltus meta update <post-type> <post-id> <json|@file>',
				'description' => 'Update registered post meta.',
			],
			[
				'ability'     => 'reorder_posts',
				'command'     => 'wp saltus reorder <json|@file>',
				'description' => 'Update post menu order.',
			],
			[
				'ability'     => 'list_block_models',
				'command'     => 'wp saltus block list',
				'description' => 'List model-driven blocks.',
			],
		];
	}

	/** @return list<string> */
	public static function abilities(): array {
		return array_column( self::all(), 'ability' );
	}
}
