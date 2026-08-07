<?php

namespace Saltus\WP\Framework\Features\WebMcp;

use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Conditional;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\MCPConfig;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;
use Saltus\WP\Framework\Rest\WebMcpController;
use Saltus\WP\Framework\WebMcp\ManifestBuilder;
use Saltus\WP\Framework\WebMcp\Tools\FilterContent;
use Saltus\WP\Framework\WebMcp\Tools\GetContent;
use Saltus\WP\Framework\WebMcp\Tools\ListContentModels;
use Saltus\WP\Framework\WebMcp\Tools\ListTaxonomyTerms;
use Saltus\WP\Framework\WebMcp\Tools\PublicTool;
use Saltus\WP\Framework\WebMcp\Tools\SearchContent;

/**
 * Exposes model content to in-browser AI agents through WebMCP.
 *
 * Phase 8A registers read-only tools on public frontend views for models that
 * opt in with `webmcp: { enabled: true, frontend: true }`. Browsers without a
 * WebMCP surface receive the bridge script, which returns immediately.
 * @api
 */
final class WebMcp implements Service, Conditional, Registerable, RestRouteProvider {

	/** @var array<string, mixed> */
	private array $project;
	/** @var callable|null */
	private $modeler_resolver;
	private WebMcpPolicy $policy;
	private PublicFieldFilter $fields;

	/**
	 * @param array<string, mixed> $dependencies Framework dependencies.
	 */
	public function __construct( array $dependencies = [] ) {
		$this->project          = is_array( $dependencies['project'] ?? null ) ? $dependencies['project'] : [];
		$this->modeler_resolver = is_callable( $dependencies['modeler_resolver'] ?? null ) ? $dependencies['modeler_resolver'] : null;
		$this->policy           = new WebMcpPolicy( $dependencies['modeler_resolver'] ?? $dependencies['modeler'] ?? null );
		$this->fields           = new PublicFieldFilter();
	}

	/**
	 * Phase 8A is frontend-only. Admin registration arrives in 8B.
	 */
	public static function is_needed(): bool {
		return ! is_admin();
	}

	/** @return list<RestRouteDefinition> */
	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array {
		return [
			new RestRouteDefinition(
				ModelRestPolicy::CAPABILITY_MODELS,
				new WebMcpController( $this->policy, $this->build_tools( $modeler ), new ManifestBuilder(), new AuditLogger() ),
				'post_type'
			),
		];
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_bridge' ] );
	}

	/**
	 * Enqueue the bridge script with the manifest for the current view.
	 *
	 * The manifest is localized rather than fetched so tool discovery costs
	 * no network round trip, matching Cloudflare's bridge behavior.
	 */
	public function enqueue_bridge(): void {
		$modeler = $this->resolve_modeler();
		if ( ! $modeler instanceof Modeler ) {
			return;
		}

		$post_type   = $this->current_post_type();
		$descriptors = ( new ManifestBuilder() )->build( $this->context_tools( $modeler, $post_type ), $post_type );
		if ( $descriptors === [] ) {
			return;
		}

		$root_url = rtrim( (string) ( $this->project['root_url'] ?? '' ), '/' );
		wp_enqueue_script(
			'saltus-webmcp-bridge',
			$root_url . '/Feature/WebMcp/bridge.js',
			[],
			\Saltus\WP\Framework\Core::VERSION,
			true
		);
		wp_localize_script(
			'saltus-webmcp-bridge',
			'saltusWebMcp',
			[
				'endpoint' => rest_url( MCPConfig::get_namespace() . '/webmcp/execute' ),
				'tools'    => ( new ManifestBuilder() )->to_array( $descriptors ),
			]
		);
	}

	/**
	 * Expose the policy so other services can query WebMCP state.
	 */
	public function get_policy(): WebMcpPolicy {
		return $this->policy;
	}

	/**
	 * Build every public tool instance.
	 *
	 * @param Modeler $modeler Model registry.
	 * @return list<PublicTool>
	 */
	public function build_tools( Modeler $modeler ): array {
		$tools = [
			new ListContentModels( $modeler, $this->policy, $this->fields ),
			new SearchContent( $modeler, $this->policy, $this->fields ),
			new GetContent( $modeler, $this->policy, $this->fields ),
			new ListTaxonomyTerms( $modeler, $this->policy, $this->fields ),
			new FilterContent( $modeler, $this->policy, $this->fields ),
		];

		/**
		 * Filter the public WebMCP tools available to in-browser agents.
		 *
		 * @param list<PublicTool> $tools Tool instances.
		 */
		return $this->accept_tools( apply_filters( 'saltus/framework/webmcp/tools', $tools ), $tools );
	}

	/**
	 * Narrow a filtered value back to a list of public tools.
	 *
	 * @param mixed            $filtered Filter return value.
	 * @param list<PublicTool> $fallback Tools to use when unusable.
	 * @return list<PublicTool>
	 */
	private function accept_tools( $filtered, array $fallback ): array {
		if ( ! is_array( $filtered ) ) {
			return $fallback;
		}

		$valid = [];
		foreach ( $filtered as $tool ) {
			if ( $tool instanceof PublicTool ) {
				$valid[] = $tool;
			}
		}

		return $valid;
	}

	/**
	 * Resolve the tools appropriate to the current page.
	 *
	 * Tools are page-scoped rather than a single uniform set: a single-entry
	 * view has no use for taxonomy filtering, and an archive has no entry id.
	 *
	 * @param Modeler     $modeler   Model registry.
	 * @param string|null $post_type Post type context, or null site-wide.
	 * @return list<PublicTool>
	 */
	private function context_tools( Modeler $modeler, ?string $post_type ): array {
		$models = $this->policy->frontend_models();
		if ( $models === [] ) {
			return [];
		}

		$scoped = [];
		foreach ( $this->build_tools( $modeler ) as $tool ) {
			$name = $tool->get_name();

			if ( $post_type !== null && ! $this->policy->allows_tool( $post_type, $name ) ) {
				continue;
			}

			if ( $post_type === null && ! $this->allowed_by_any_model( $name ) ) {
				continue;
			}

			if ( ! $this->is_relevant_here( $name ) ) {
				continue;
			}

			$scoped[] = $tool;
		}

		return $scoped;
	}

	/**
	 * Whether a tool is useful on the current page type.
	 *
	 * @param string $tool_name Tool name.
	 */
	private function is_relevant_here( string $tool_name ): bool {
		$is_singular = function_exists( 'is_singular' ) && is_singular();

		if ( $tool_name === 'filter_content' || $tool_name === 'list_taxonomy_terms' ) {
			return ! $is_singular;
		}

		return true;
	}

	/**
	 * Whether any frontend-enabled model permits a tool.
	 *
	 * @param string $tool_name Tool name.
	 */
	private function allowed_by_any_model( string $tool_name ): bool {
		foreach ( $this->policy->frontend_models() as $model ) {
			if ( $this->policy->allows_tool( $model, $tool_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve the post type context for the current request.
	 */
	private function current_post_type(): ?string {
		if ( function_exists( 'is_singular' ) && is_singular() && function_exists( 'get_post_type' ) ) {
			$post_type = get_post_type();
			if ( is_string( $post_type ) && $post_type !== '' ) {
				return $post_type;
			}
		}

		if ( function_exists( 'get_query_var' ) ) {
			$post_type = get_query_var( 'post_type' );
			if ( is_string( $post_type ) && $post_type !== '' ) {
				return $post_type;
			}
		}

		return null;
	}

	/**
	 * Resolve the modeler from the lazy resolver.
	 */
	private function resolve_modeler(): ?Modeler {
		if ( ! is_callable( $this->modeler_resolver ) ) {
			return null;
		}

		$modeler = ( $this->modeler_resolver )();

		return $modeler instanceof Modeler ? $modeler : null;
	}
}
