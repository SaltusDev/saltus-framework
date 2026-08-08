<?php

namespace Saltus\WP\Framework\Features\WebMcp;

use Saltus\WP\Framework\Features\EditorialReview\ProposalService;
use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Conditional;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\MCPConfig;
use Saltus\WP\Framework\MCP\Tools\ToolContributor;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;
use Saltus\WP\Framework\Rest\WebMcpController;
use Saltus\WP\Framework\WebMcp\ManifestBuilder;
use Saltus\WP\Framework\WebMcp\Tools\AdminTool;
use Saltus\WP\Framework\WebMcp\Tools\FilterContent;
use Saltus\WP\Framework\WebMcp\Tools\GetContent;
use Saltus\WP\Framework\WebMcp\Tools\ListContentModels;
use Saltus\WP\Framework\WebMcp\Tools\ListTaxonomyTerms;
use Saltus\WP\Framework\WebMcp\Tools\PublicTool;
use Saltus\WP\Framework\WebMcp\Tools\SearchContent;
use Saltus\WP\Framework\WebMcp\WebMcpTool;

/**
 * Exposes model content to in-browser AI agents through WebMCP.
 *
 * Read-only tools register on public frontend views for models that opt in with
 * `webmcp: { enabled: true, frontend: true }`. Models adding `admin: true` also
 * expose their capability-gated abilities on the relevant wp-admin screens,
 * where every mutating call is queued for human review rather than applied.
 * Browsers without a WebMCP surface receive the bridge script, which returns
 * immediately.
 * @api
 */
final class WebMcp implements Service, Conditional, Registerable, RestRouteProvider {

	/** @var array<string, mixed> */
	private array $project;
	/** @var callable|null */
	private $modeler_resolver;
	/** @var callable|null */
	private $contributor_resolver;
	private WebMcpPolicy $policy;
	private PublicFieldFilter $fields;
	private AdminToolSet $admin_tools;
	private ProposalService $proposals;

	/**
	 * @param array<string, mixed> $dependencies Framework dependencies.
	 * @param ProposalService|null $proposals    Review queue, defaulted when null.
	 */
	public function __construct( array $dependencies = [], ?ProposalService $proposals = null ) {
		$this->project              = is_array( $dependencies['project'] ?? null ) ? $dependencies['project'] : [];
		$this->modeler_resolver     = is_callable( $dependencies['modeler_resolver'] ?? null ) ? $dependencies['modeler_resolver'] : null;
		$this->contributor_resolver = is_callable( $dependencies['tool_contributors'] ?? null ) ? $dependencies['tool_contributors'] : null;
		$this->policy               = new WebMcpPolicy( $dependencies['modeler_resolver'] ?? $dependencies['modeler'] ?? null );
		$this->fields               = new PublicFieldFilter();
		$this->admin_tools          = new AdminToolSet();
		$this->proposals            = $proposals ?? new ProposalService();
	}

	/**
	 * Needed on the frontend always, and in the admin for the governed surface.
	 *
	 * The admin branch is not gated on `has_admin_surface()` here: models are not
	 * yet registered when the service container runs `is_needed()`, so the policy
	 * would report no surface for every site. The check happens at enqueue time,
	 * when the modeler is populated.
	 */
	public static function is_needed(): bool {
		return true;
	}

	/** @return list<RestRouteDefinition> */
	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array {
		return [
			new RestRouteDefinition(
				ModelRestPolicy::CAPABILITY_MODELS,
				new WebMcpController(
					$this->policy,
					array_merge( $this->build_tools( $modeler ), $this->build_admin_tools( $modeler ) ),
					new ManifestBuilder(),
					new AuditLogger()
				),
				'post_type'
			),
		];
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_bridge' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_bridge' ] );
	}

	/**
	 * Enqueue the bridge script with the manifest for the current public view.
	 *
	 * The manifest is localized rather than fetched so tool discovery costs
	 * no network round trip, matching Cloudflare's bridge behavior.
	 */
	public function enqueue_bridge(): void {
		$modeler = $this->resolve_modeler();
		if ( ! $modeler instanceof Modeler ) {
			return;
		}

		$post_type = $this->current_post_type();

		$this->localize_bridge( $this->context_tools( $modeler, $post_type ), $post_type, false );
	}

	/**
	 * Enqueue the bridge on an admin screen that exposes tools.
	 *
	 * Admin tools act as the logged-in user, so the localized payload carries a
	 * REST nonce and the endpoint for refreshing it.
	 */
	public function enqueue_admin_bridge(): void {
		$modeler = $this->resolve_modeler();
		if ( ! $modeler instanceof Modeler || ! $this->policy->has_admin_surface() ) {
			return;
		}

		$screen = new AdminScreen();
		$tools  = $this->screen_tools( $modeler, $screen );
		if ( $tools === [] ) {
			return;
		}

		$this->localize_bridge( $tools, $screen->post_type(), true );
	}

	/**
	 * Register the bridge script and hand it the descriptors for this request.
	 *
	 * @param list<WebMcpTool> $tools         Tools to project.
	 * @param string|null      $post_type     Post type context, if any.
	 * @param bool             $authenticated Whether to include a REST nonce.
	 */
	private function localize_bridge( array $tools, ?string $post_type, bool $authenticated ): void {
		$builder     = new ManifestBuilder();
		$descriptors = $builder->build( $tools, $post_type );
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

		$namespace = MCPConfig::get_namespace();
		$payload   = [
			'endpoint' => rest_url( $namespace . '/webmcp/execute' ),
			'tools'    => $builder->to_array( $descriptors ),
		];

		if ( $authenticated ) {
			// The nonce endpoint lets the bridge recover from a nonce that expired
			// while the screen sat open, which is the common case for an admin tab
			// left running beside an agent conversation.
			$payload['nonce']         = wp_create_nonce( 'wp_rest' );
			$payload['nonceEndpoint'] = rest_url( $namespace . '/webmcp/nonce' );
			$payload['surface']       = WebMcpTool::SURFACE_ADMIN;
		}

		wp_localize_script( 'saltus-webmcp-bridge', 'saltusWebMcp', $payload );
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
	 * Build the admin tool set by projecting existing abilities.
	 *
	 * Abilities come from the same `ToolContributor` registry MCP/Abilities and
	 * `wp saltus` read, so the browser surface is a projection of tools already
	 * maintained rather than a parallel set. Each is wrapped in `AdminTool`,
	 * which adds the nonce requirement and the review-queue write posture.
	 *
	 * @param Modeler $modeler Model registry.
	 * @return list<AdminTool>
	 */
	public function build_admin_tools( Modeler $modeler ): array {
		$names = [];
		foreach ( $this->admin_tools->screens() as $screen ) {
			foreach ( $this->admin_tools->for_screen( $screen ) as $name ) {
				$names[ $name ] = true;
			}
		}

		$tools = [];
		foreach ( $this->contributed_tools( $modeler ) as $tool ) {
			if ( isset( $names[ $tool->get_name() ] ) ) {
				$tools[] = new AdminTool( $tool, $this->proposals );
			}
		}

		return $tools;
	}

	/**
	 * Collect every ability contributed by the framework's feature services.
	 *
	 * @param Modeler $modeler Model registry.
	 * @return list<ToolInterface>
	 */
	private function contributed_tools( Modeler $modeler ): array {
		// The modeler contributes the model-derived tools itself, and the resolver
		// supplies the feature services' own.
		$contributors = [ $modeler ];

		if ( is_callable( $this->contributor_resolver ) ) {
			$resolved = ( $this->contributor_resolver )();
			if ( is_array( $resolved ) ) {
				foreach ( $resolved as $contributor ) {
					if ( $contributor instanceof ToolContributor ) {
						$contributors[] = $contributor;
					}
				}
			}
		}

		$tools = [];
		foreach ( $contributors as $contributor ) {
			foreach ( $contributor->get_mcp_tools( $modeler ) as $tool ) {
				// Keyed by name so two contributors offering the same ability
				// yield one tool rather than a duplicate registration.
				$tools[ $tool->get_name() ] = $tool;
			}
		}

		return array_values( $tools );
	}

	/**
	 * Resolve the admin tools available on the current screen.
	 *
	 * Two gates apply. The screen map decides which tools are meaningful here,
	 * and the user's capabilities decide which they may see at all — an editor
	 * and an administrator on the same screen get different sets.
	 *
	 * @param Modeler     $modeler Model registry.
	 * @param AdminScreen $screen  Current screen resolver.
	 * @return list<WebMcpTool>
	 */
	private function screen_tools( Modeler $modeler, AdminScreen $screen ): array {
		$allowed = $this->admin_tools->for_screen( $screen->resolve() );
		if ( $allowed === [] ) {
			return [];
		}

		$post_type = $screen->post_type();
		$scoped    = [];

		foreach ( $this->build_admin_tools( $modeler ) as $tool ) {
			$name = $tool->get_name();

			if ( ! in_array( $name, $allowed, true ) ) {
				continue;
			}

			if ( ! $this->model_allows( $name, $post_type ) ) {
				continue;
			}

			if ( ! $this->user_can_discover( $tool ) ) {
				continue;
			}

			$scoped[] = $tool;
		}

		return $scoped;
	}

	/**
	 * Whether the model in context permits a tool on the admin surface.
	 *
	 * @param string      $tool_name Tool name.
	 * @param string|null $post_type Post type context, or null screen-wide.
	 */
	private function model_allows( string $tool_name, ?string $post_type ): bool {
		if ( $post_type !== null && in_array( $post_type, $this->policy->admin_models(), true ) ) {
			return $this->policy->allows_tool( $post_type, $tool_name );
		}

		return $this->policy->allowed_by_any_model( $tool_name, WebMcpTool::SURFACE_ADMIN );
	}

	/**
	 * Whether the current user may be offered a tool.
	 *
	 * @param WebMcpTool $tool Tool to check.
	 */
	private function user_can_discover( WebMcpTool $tool ): bool {
		$capability = $tool->get_discovery_capability();
		if ( $capability === null ) {
			return true;
		}

		return function_exists( 'current_user_can' ) && current_user_can( $capability );
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

			if ( $post_type === null && ! $this->policy->allowed_by_any_model( $name ) ) {
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
