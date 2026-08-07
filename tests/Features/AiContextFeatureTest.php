<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\AiContext\AiContextProvider;
use Saltus\WP\Framework\Features\AiContext\AiContext;
use Saltus\WP\Framework\MCP\Tools\GetContext;
use Saltus\WP\Framework\Rest\AiContextController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/** @covers \Saltus\WP\Framework\Features\AiContext\AiContextProvider */
class AiContextFeatureTest extends TestCase {

	protected function setUp(): void {
		global $wp_filters_registered, $wp_filter_values;
		$wp_filters_registered = [];
		$wp_filter_values     = [];
	}

	public function testNormalizesContextAndAppliesDefaultsFilter(): void {
		$modeler = $this->modeler( [
			'ai_context' => [
				'brand_voice'       => 'Clear',
				'audiences'         => [ 'developers', 12 ],
				'field_rules'       => [ 'post_content' => [ 'Accurate', 12 ] ],
				'allowed_statuses'  => [ 'draft' ],
				'forbidden_actions' => [ 'delete' ],
			],
		] );
		$provider = new AiContextProvider( $modeler );

		$context = $provider->get( 'book' );

		$this->assertTrue( $context['configured'] );
		$this->assertSame( 'Clear', $context['brand_voice'] );
		$this->assertSame( [ 'developers' ], $context['audiences'] );
		$this->assertSame( [ 'Accurate' ], $context['field_rules']['post_content'] );
		$this->assertSame( [ 'draft' ], $context['allowed_statuses'] );
		$this->assertSame( [ 'delete' ], $context['forbidden_actions'] );
	}

	public function testMalformedValuesFallBackSafely(): void {
		$provider = new AiContextProvider( $this->modeler( [ 'ai_context' => [ 'audiences' => 'developers', 'require_human_review' => 'yes' ] ] ) );
		$context  = $provider->get( 'book' );

		$this->assertSame( [], $context['audiences'] );
		$this->assertFalse( $context['require_human_review'] );
		$this->assertSame( [ 'draft', 'pending', 'publish', 'private' ], $context['allowed_statuses'] );
	}

	public function testMutationRulesRejectForbiddenActionsAndStatuses(): void {
		$provider = new AiContextProvider( $this->modeler( [ 'ai_context' => [ 'allowed_statuses' => [ 'draft' ], 'forbidden_actions' => [ 'delete', 'publish' ] ] ] ) );

		$this->assertNull( $provider->validate_mutation( 'create_post', [ 'post_type' => 'book', 'status' => 'draft' ] ) );
		$this->assertSame( 'ai_context_violation', $provider->validate_mutation( 'create_post', [ 'post_type' => 'book', 'status' => 'publish' ] )->get_error_code() );
		$this->assertSame( 'ai_context_violation', $provider->validate_mutation( 'delete_post', [ 'post_type' => 'book', 'post_id' => 12 ] )->get_error_code() );
	}

	public function testFilterCanOverrideDefaults(): void {
		add_filter( 'saltus/framework/ai_context/defaults', static function ( array $defaults ): array {
			$defaults['allowed_statuses'] = [ 'pending' ];
			return $defaults;
		} );
		$provider = new AiContextProvider( $this->modeler( [ 'ai_context' => [] ] ) );

		$this->assertSame( [ 'pending' ], $provider->get( 'book' )['allowed_statuses'] );
	}

	public function testDiscoveryToolAndRestRouteUseTheContextSurface(): void {
		global $wp_rest_routes_registered;
		$modeler = $this->modeler( [ 'ai_context' => [ 'brand_voice' => 'Clear' ] ] );
		$provider = new AiContextProvider( $modeler );
		$feature  = new AiContext( [ 'modeler_resolver' => static function () use ( $modeler ): Modeler { return $modeler; } ] );
		$tools    = $feature->get_mcp_tools( $modeler, new ModelRestPolicy( $modeler ) );

		$this->assertInstanceOf( GetContext::class, $tools[0] );
		$this->assertSame( '/saltus-framework/v1/context/book', $tools[0]->build_rest_request( [ 'post_type' => 'book' ] )->get_route() );
		$controller = new AiContextController( new ModelRestPolicy( $modeler ), $provider );
		$controller->register_routes();
		$routes = array_column( $wp_rest_routes_registered, 'route' );
		$this->assertTrue( (bool) array_filter( $routes, static fn( string $route ): bool => strpos( $route, '/context/' ) !== false ) );
	}

	/** @param array<string, mixed> $config */
	private function modeler( array $config ): Modeler {
		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( 'book' );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_config' )->willReturn( $config );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true, 'mcp_tools' => true ] );
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );
		return $modeler;
	}
}
