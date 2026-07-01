<?php
namespace Saltus\WP\Framework\Features\MCP;

use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\MCP\Abilities\AbilityRegistrar;
use Saltus\WP\Framework\MCP\Cache\TransientCache;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

/**
 * Enables Saltus MCP support.
 *
 * WordPress-native abilities are registered when the host WordPress version
 * exposes the Abilities API. Older WordPress versions skip native ability
 * registration.
 */
class MCP implements Service, Registerable {

	private AbilityRegistrar $ability_registrar;

	/**
	 * @param array<int, mixed> $dependencies Framework dependencies injected by the service container.
	 */
	public function __construct( array $dependencies = [], ?AbilityRegistrar $ability_registrar = null ) {
		$modeler                 = $dependencies['modeler'] ?? null;
		$policy                  = $modeler instanceof Modeler ? new ModelRestPolicy( $modeler ) : null;
		$this->ability_registrar = $ability_registrar ?? new AbilityRegistrar( null, null, $policy );
	}

	public function register(): void {
		if ( $this->transport() !== 'native' ) {
			return;
		}

		add_action(
			'wp_abilities_api_categories_init',
			function (): void {
				$this->ability_registrar->register_category();
			}
		);
		add_action(
			'wp_abilities_api_init',
			function (): void {
				$this->ability_registrar->register();
			}
		);
		foreach ( [ 'save_post', 'deleted_post', 'created_term', 'edited_term', 'delete_term', 'updated_option' ] as $hook ) {
			add_action(
				$hook,
				function (): void {
					( new TransientCache() )->clear();
				}
			);
		}
	}

	public function transport(): string {
		return $this->ability_registrar->has_native_api() ? 'native' : 'legacy';
	}
}
