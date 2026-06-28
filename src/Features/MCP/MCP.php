<?php
namespace Saltus\WP\Framework\Features\MCP;

use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\MCP\Abilities\AbilityRegistrar;

/**
 * Enables Saltus MCP support.
 *
 * WordPress-native abilities are registered when the host WordPress version
 * exposes the Abilities API. Older WordPress versions continue to use the
 * standalone stdio MCP server as the compatibility path.
 */
class MCP implements Service, Registerable {

	private AbilityRegistrar $abilityRegistrar;

	/**
	 * @param array<int, mixed> $dependencies Framework dependencies injected by the service container.
	 */
	public function __construct( array $dependencies = [], ?AbilityRegistrar $abilityRegistrar = null ) {
		$this->abilityRegistrar = $abilityRegistrar ?? new AbilityRegistrar();
	}

	public function register(): void {
		if ( $this->transport() !== 'native' ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this->abilityRegistrar, 'registerCategory' ] );
		add_action( 'wp_abilities_api_init', [ $this->abilityRegistrar, 'register' ] );
	}

	public function transport(): string {
		return $this->abilityRegistrar->hasNativeApi() ? 'native' : 'legacy';
	}
}
