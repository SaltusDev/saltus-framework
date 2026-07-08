<?php
namespace Saltus\WP\Framework\Features\MCP;

use Saltus\WP\Framework\Infrastructure\Plugin\Activateable;
use Saltus\WP\Framework\Infrastructure\Plugin\Deactivateable;
use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\MCP\Abilities\AbilityRegistrar;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\Cache\TransientCache;
use Saltus\WP\Framework\MCP\McpPolicy;
use Saltus\WP\Framework\MCP\Tools\ToolContributor;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * Enables Saltus MCP support.
 *
 * WordPress-native abilities are registered when the host WordPress version
 * exposes the Abilities API. Older WordPress versions skip native ability
 * registration.
 */
class MCP implements Service, Registerable, Activateable, Deactivateable {

	private const AUDIT_CLEANUP_HOOK = 'saltus_framework_mcp_audit_cleanup';

	/** @var array<string, mixed> */
	private array $dependencies;
	private ?AbilityRegistrar $ability_registrar;
	private ?Modeler $modeler;
	/** @var callable|null */
	private $modeler_resolver;
	private ?ModelRestPolicy $policy;
	private ?McpPolicy $mcp_policy;

	/**
	 * @param array<string, mixed> $dependencies Framework dependencies injected by the service container.
	 */
	public function __construct( array $dependencies = [], ?AbilityRegistrar $ability_registrar = null ) {
		$modeler                 = $dependencies['modeler'] ?? null;
		$this->dependencies      = $dependencies;
		$this->ability_registrar = $ability_registrar;
		$this->modeler           = $modeler instanceof Modeler ? $modeler : null;
		$this->modeler_resolver  = is_callable( $dependencies['modeler_resolver'] ?? null ) ? $dependencies['modeler_resolver'] : null;
		$this->policy            = null;
		$this->mcp_policy        = null;
	}

	public function register(): void {
		if ( $this->transport() !== 'native' ) {
			return;
		}

		add_action(
			self::AUDIT_CLEANUP_HOOK,
			function (): void {
				( new AuditLogger() )->cleanup_expired_entries();
			}
		);
		add_action(
			'wp_abilities_api_categories_init',
			function (): void {
				$this->ability_registrar()->register_category();
			}
		);
		add_action(
			'wp_abilities_api_init',
			function (): void {
				$this->ability_registrar()->register();
			}
		);
		foreach ( [ 'save_post', 'deleted_post', 'created_term', 'edited_term', 'delete_term', 'added_option', 'updated_option', 'deleted_option' ] as $hook ) {
			add_action(
				$hook,
				function (): void {
					( new TransientCache() )->clear();
				}
			);
		}
	}

	public function activate() {
		if ( $this->transport() !== 'native' ) {
			return;
		}

		$this->schedule_audit_cleanup();
	}

	public function deactivate() {
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return;
		}

		wp_clear_scheduled_hook( self::AUDIT_CLEANUP_HOOK );
	}

	public function transport(): string {
		if ( $this->ability_registrar instanceof AbilityRegistrar ) {
			return $this->ability_registrar->has_native_api() ? 'native' : 'legacy';
		}

		return function_exists( 'wp_register_ability' ) ? 'native' : 'legacy';
	}

	private function ability_registrar(): AbilityRegistrar {
		if ( $this->ability_registrar instanceof AbilityRegistrar ) {
			return $this->ability_registrar;
		}

		$this->ability_registrar = new AbilityRegistrar( $this->tool_provider(), null, $this->mcp_policy() );

		return $this->ability_registrar;
	}

	private function schedule_audit_cleanup(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		if ( wp_next_scheduled( self::AUDIT_CLEANUP_HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), 'daily', self::AUDIT_CLEANUP_HOOK );
	}

	private function tool_provider(): ToolProvider {
		$provider = new ToolProvider();
		$modeler  = $this->modeler();
		if ( ! $modeler instanceof Modeler ) {
			return $provider;
		}

		foreach ( $this->contributors() as $contributor ) {
			foreach ( $contributor->get_mcp_tools( $modeler, $this->policy() ) as $tool ) {
				$provider->register( $tool );
			}
		}

		return $provider;
	}

	/**
	 * @return ToolContributor[]
	 */
	private function contributors(): array {
		$contributors = [];
		$modeler      = $this->modeler();
		if ( $modeler instanceof ToolContributor ) {
			$contributors[] = $modeler;
		}

		// Use the pre-built ToolContributor registry populated by Core
		// before the is_needed() gate, so tools are always available.
		$contributor_callback = $this->dependencies['tool_contributors'] ?? null;
		if ( is_callable( $contributor_callback ) ) {
			$contributors = array_merge( $contributors, $contributor_callback() );
		} else {
			// Fallback when MCP is instantiated directly (outside Core):
			// iterate the services container for ToolContributor instances.
			$services = $this->dependencies['services'] ?? [];
			foreach ( $services as $service ) {
				if ( $service instanceof ToolContributor ) {
					$contributors[] = $service;
				}
			}
		}

		return $contributors;
	}

	private function modeler(): ?Modeler {
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

	private function policy(): ?ModelRestPolicy {
		$modeler = $this->modeler();
		if ( ! $modeler instanceof Modeler ) {
			return null;
		}

		if ( ! $this->policy instanceof ModelRestPolicy ) {
			$this->policy = new ModelRestPolicy( $modeler );
		}

		return $this->policy;
	}

	private function mcp_policy(): ?McpPolicy {
		$modeler = $this->modeler();
		if ( ! $modeler instanceof Modeler ) {
			return null;
		}

		if ( ! $this->mcp_policy instanceof McpPolicy ) {
			$this->mcp_policy = new McpPolicy( $modeler );
		}

		return $this->mcp_policy;
	}
}
