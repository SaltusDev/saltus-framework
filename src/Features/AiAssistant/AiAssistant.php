<?php

namespace Saltus\WP\Framework\Features\AiAssistant;

use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\MCP\MCPConfig;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\AiAssistantController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;

/** Registers inside-admin AI assistants for configured post models. */
final class AiAssistant implements Service, Registerable, RestRouteProvider {

	private AiAssistantProvider $provider;
	/** @var array<string, mixed> */
	private array $project;

	/** @param array<string, mixed> $dependencies */
	public function __construct( array $dependencies = [] ) {
		$this->project  = is_array( $dependencies['project'] ?? null ) ? $dependencies['project'] : [];
		$this->provider = new AiAssistantProvider( $dependencies['modeler_resolver'] ?? null );
	}

	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array {
		return [ new RestRouteDefinition( ModelRestPolicy::CAPABILITY_MODELS, new AiAssistantController( $policy, $this->provider ), 'post_type' ) ];
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets(): void {
		if ( ! current_user_can( 'edit_posts' ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || $screen->base !== 'post' || $screen->post_type === '' ) {
			return;
		}
		$definition = $this->provider->definition( $screen->post_type );
		if ( ! is_array( $definition ) ) {
			return;
		}
		$root_url = rtrim( (string) ( $this->project['root_url'] ?? '' ), '/' );
		wp_enqueue_script(
			'saltus-ai-assistant',
			$root_url . '/Feature/AiAssistant/editor.js',
			[ 'wp-i18n' ],
			\Saltus\WP\Framework\Core::VERSION,
			true
		);
		wp_enqueue_style(
			'saltus-ai-assistant',
			$root_url . '/Feature/AiAssistant/editor.css',
			[],
			\Saltus\WP\Framework\Core::VERSION
		);
		wp_localize_script(
			'saltus-ai-assistant',
			'saltusAiAssistant',
			[
				'endpoint'   => rest_url( MCPConfig::get_namespace() . '/ai-assistant/' . $screen->post_type . '/' . (int) ( $_GET['post'] ?? 0 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'postType'   => $screen->post_type,
				'postId'     => (int) ( $_GET['post'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'definition' => $definition,
			]
		);
	}
}
