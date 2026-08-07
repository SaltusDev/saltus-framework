<?php

namespace Saltus\WP\Framework\Features\WebMcp;

use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

/**
 * Resolves which models expose WebMCP tools to in-browser agents.
 *
 * Configuration lives at the top level of the model array under the `webmcp`
 * key, consistent with `ai_context`:
 *
 *     webmcp:
 *       enabled: true
 *       frontend: true
 *       tools:
 *         - search_content
 *
 * The surface is opt-in. A model without a `webmcp` section exposes nothing.
 * @api
 */
final class WebMcpPolicy {

	/** Model types that may expose WebMCP tools. */
	private const SUPPORTED_MODEL_TYPE = 'post_type';

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
	 * Normalize a raw `webmcp` config value into a predictable shape.
	 *
	 * Accepts `true` as shorthand for frontend registration with all tools.
	 *
	 * @param mixed $value Raw configuration value.
	 * @return array{enabled: bool, frontend: bool, admin: bool, tools: list<string>}
	 */
	public static function normalize_config( $value ): array {
		$defaults = [
			'enabled'  => false,
			'frontend' => false,
			'admin'    => false,
			'tools'    => [],
		];

		if ( $value === true ) {
			return [
				'enabled'  => true,
				'frontend' => true,
				'admin'    => false,
				'tools'    => [],
			];
		}

		if ( ! is_array( $value ) ) {
			return $defaults;
		}

		$enabled = array_key_exists( 'enabled', $value ) ? (bool) $value['enabled'] : true;

		return [
			'enabled'  => $enabled,
			'frontend' => array_key_exists( 'frontend', $value ) ? (bool) $value['frontend'] : $enabled,
			'admin'    => array_key_exists( 'admin', $value ) ? (bool) $value['admin'] : false,
			'tools'    => self::normalize_tools( $value['tools'] ?? null ),
		];
	}

	/**
	 * Get the normalized WebMCP configuration for a model name.
	 *
	 * @param string $model_name Post type slug.
	 * @return array{enabled: bool, frontend: bool, admin: bool, tools: list<string>}|null
	 */
	public function config( string $model_name ): ?array {
		$model = $this->model( $model_name );
		if ( ! $model instanceof Model ) {
			return null;
		}

		return self::normalize_config( $model->get_config()['webmcp'] ?? null );
	}

	/**
	 * Whether a model exposes WebMCP tools on public frontend views.
	 *
	 * @param string $model_name Post type slug.
	 */
	public function is_frontend_enabled( string $model_name ): bool {
		$config = $this->config( $model_name );

		return $config !== null && $config['enabled'] && $config['frontend'];
	}

	/**
	 * Whether a model exposes WebMCP tools on admin screens.
	 *
	 * Reserved for Phase 8B. Frontend registration never consults this.
	 *
	 * @param string $model_name Post type slug.
	 */
	public function is_admin_enabled( string $model_name ): bool {
		$config = $this->config( $model_name );

		return $config !== null && $config['enabled'] && $config['admin'];
	}

	/**
	 * Whether a named tool is permitted for a model.
	 *
	 * An empty allowlist permits every tool; a populated one is exclusive.
	 *
	 * @param string $model_name Post type slug.
	 * @param string $tool_name  Tool name.
	 */
	public function allows_tool( string $model_name, string $tool_name ): bool {
		$config = $this->config( $model_name );
		if ( $config === null || ! $config['enabled'] ) {
			return false;
		}

		if ( $config['tools'] === [] ) {
			return true;
		}

		return in_array( $tool_name, $config['tools'], true );
	}

	/**
	 * Get every post type model with frontend WebMCP enabled.
	 *
	 * Models whose post type is not publicly queryable are excluded: a tool
	 * returning content the visitor cannot otherwise reach would leak data.
	 *
	 * @return list<string> Post type slugs.
	 */
	public function frontend_models(): array {
		$models = [];

		foreach ( $this->models() as $name => $model ) {
			if ( $model->get_type() !== self::SUPPORTED_MODEL_TYPE ) {
				continue;
			}

			if ( ! $this->is_frontend_enabled( (string) $name ) ) {
				continue;
			}

			if ( ! $this->is_publicly_queryable( (string) $name ) ) {
				continue;
			}

			$models[] = (string) $name;
		}

		return $models;
	}

	/**
	 * Whether any model exposes a frontend WebMCP surface.
	 */
	public function has_frontend_surface(): bool {
		return $this->frontend_models() !== [];
	}

	/**
	 * Whether a post type is registered as publicly queryable.
	 *
	 * Falls back to the model's own options when the post type object is not
	 * yet registered, which is the case during early boot and in unit tests.
	 *
	 * @param string $post_type Post type slug.
	 */
	private function is_publicly_queryable( string $post_type ): bool {
		if ( function_exists( 'get_post_type_object' ) ) {
			$object = get_post_type_object( $post_type );
			if ( is_object( $object ) ) {
				return (bool) $object->publicly_queryable;
			}
		}

		$model = $this->model( $post_type );
		if ( ! $model instanceof Model ) {
			return false;
		}

		$args = $model->get_args();
		if ( array_key_exists( 'publicly_queryable', $args ) ) {
			return (bool) $args['publicly_queryable'];
		}

		return ! array_key_exists( 'public', $args ) || (bool) $args['public'];
	}

	/**
	 * Coerce a raw tool allowlist into a list of non-empty strings.
	 *
	 * @param mixed $value Raw allowlist value.
	 * @return list<string>
	 */
	private static function normalize_tools( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$tools = [];
		foreach ( $value as $tool ) {
			if ( is_string( $tool ) && trim( $tool ) !== '' ) {
				$tools[] = trim( $tool );
			}
		}

		return $tools;
	}

	/**
	 * Resolve a single model by name.
	 *
	 * @param string $model_name Model name.
	 */
	private function model( string $model_name ): ?Model {
		$models = $this->models();
		$model  = $models[ $model_name ] ?? null;

		return $model instanceof Model ? $model : null;
	}

	/**
	 * Resolve every registered model.
	 *
	 * @return array<string, Model>
	 */
	private function models(): array {
		$modeler = $this->resolve_modeler();
		if ( ! $modeler instanceof Modeler ) {
			return [];
		}

		return $modeler->get_models();
	}

	/**
	 * Resolve the modeler, memoizing the lazy resolver result.
	 */
	private function resolve_modeler(): ?Modeler {
		if ( $this->modeler instanceof Modeler ) {
			return $this->modeler;
		}

		if ( is_callable( $this->modeler_resolver ) ) {
			$modeler = ( $this->modeler_resolver )();
			if ( $modeler instanceof Modeler ) {
				$this->modeler = $modeler;
			}
		}

		return $this->modeler;
	}
}
