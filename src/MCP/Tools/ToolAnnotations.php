<?php
namespace Saltus\WP\Framework\MCP\Tools;

/**
 * The behavioural facts a client needs before it calls a tool.
 *
 * Three questions, in the vocabulary WordPress uses for ability annotations:
 * does the tool change anything, can it overwrite or remove what is already
 * there, and does calling it twice differ from calling it once. They are facts
 * about the tool, so they live here rather than in any one consumer: the
 * editorial review queue asks the first question to decide what needs a human,
 * and the abilities layer publishes all three to agents.
 *
 * A tool with no entry answers `null` to everything, which is WordPress' own
 * default and means "not stated". Guessing would be worse than silence: a
 * wrong `readonly` invites an agent to call a write tool unprompted.
 *
 * @api
 */
final class ToolAnnotations {

	/**
	 * Nothing is claimed about a tool that has no entry.
	 *
	 * @var array<string, null>
	 */
	private const UNKNOWN = [
		'readonly'    => null,
		'destructive' => null,
		'idempotent'  => null,
	];

	/**
	 * Reads: no writes, nothing removed, repeatable.
	 *
	 * @var list<string>
	 */
	private const READS = [
		'get_health',
		'list_models',
		'get_model',
		'list_posts',
		'get_post',
		'list_terms',
		'get_context',
		'list_block_models',
		'list_meta_fields',
		'get_meta_fields',
		'get_settings',
		'list_relationships',
		'get_related',
		'export_post',
	];

	/**
	 * Writes that only add, paired with whether they overwrite.
	 *
	 * `destructive` is the agent-facing safety signal, so it follows what the
	 * tool can do to existing data rather than which HTTP verb it borrows.
	 * `reorder_posts` and `attach_related` are both POSTs and land on opposite
	 * sides: reordering rewrites the menu order already stored, while attaching
	 * adds a relation that was not there.
	 *
	 * @var array<string, bool>
	 */
	private const WRITES = [
		'create_post'        => false,
		'create_term'        => false,
		'duplicate_post'     => false,
		'attach_related'     => false,
		'update_post'        => true,
		'update_meta_fields' => true,
		'update_settings'    => true,
		'reorder_posts'      => true,
		'sync_related'       => true,
		'delete_post'        => true,
		'detach_related'     => true,
	];

	/**
	 * The annotations for a tool.
	 *
	 * `idempotent` is stated for reads and deliberately left unstated for
	 * writes. WordPress reads the pair `destructive` and `idempotent` as a
	 * request for the DELETE verb on its ability run route, and that route takes
	 * a DELETE payload from the query string rather than the body. Declaring a
	 * write idempotent would therefore push a post's content into a URL. The
	 * property is real - updating twice does land the same row - but saying so
	 * costs more than it buys, so it stays unstated until the verb and the
	 * payload are decided separately.
	 *
	 * @param string $tool Tool short name, as `ToolInterface::get_name()` gives it.
	 * @return array{readonly: bool|null, destructive: bool|null, idempotent: bool|null}
	 */
	public static function for_tool( string $tool ): array {
		if ( in_array( $tool, self::READS, true ) ) {
			return [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			];
		}

		if ( isset( self::WRITES[ $tool ] ) ) {
			return [
				'readonly'    => false,
				'destructive' => self::WRITES[ $tool ],
				'idempotent'  => null,
			];
		}

		return self::UNKNOWN;
	}

	/**
	 * Whether a tool changes state.
	 *
	 * Answers false for an unlisted tool. A tool nobody described is not
	 * evidence of a write, and treating it as one would put third-party read
	 * tools through the review queue.
	 *
	 * @param string $tool Tool short name.
	 */
	public static function is_mutating( string $tool ): bool {
		return self::for_tool( $tool )['readonly'] === false;
	}
}
