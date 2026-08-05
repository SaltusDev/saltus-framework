<?php

namespace Saltus\WP\Framework\Tests\Features;

use Saltus\WP\Framework\Features\AiAssistant\AiAssistantProvider;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\AiAssistantController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Tests\TestCase;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/** @covers \Saltus\WP\Framework\Features\AiAssistant\AiAssistantProvider */
/** @covers \Saltus\WP\Framework\Rest\AiAssistantController */
class AiAssistantTest extends TestCase {

	protected function setUp(): void {
		global $wp_filters_registered, $wp_filter_values, $wp_rest_routes_registered, $wp_posts, $wp_current_user_can;
		$wp_filters_registered = [];
		$wp_filter_values     = [];
		$wp_rest_routes_registered = [];
		$wp_posts             = [];
		$wp_current_user_can  = true;
	}

	public function testConfiguredModelExposesActionsAndNormalizedFields(): void {
		$provider = new AiAssistantProvider( $this->modeler() );
		$definition = $provider->definition( 'book' );

		$this->assertIsArray( $definition );
		$this->assertSame( 'book', $definition['model'] );
		$this->assertContains( 'improve_title', array_column( $definition['actions'], 'name' ) );
		$this->assertSame( 'subtitle', $definition['fields'][0]['path'] );
	}

	public function testDispatchesThroughProviderFilterAndNormalizesOutput(): void {
		add_filter(
			'saltus/framework/ai/assistant_actions',
			static function ( $value, string $action, array $payload ): array {
				return [ 'target' => 'post_title', 'value' => strtoupper( $payload['title'] ), 'suggestions' => [ 'One', 12 ] ];
			},
			10,
			7
		);
		$provider = new AiAssistantProvider( $this->modeler() );
		$result = $provider->dispatch( 'book', 'improve_title', [ 'title' => 'A book' ] );

		$this->assertSame( 'improve_title', $result['action'] );
		$this->assertSame( 'A BOOK', $result['value'] );
		$this->assertSame( [ 'One' ], $result['suggestions'] );
	}

	public function testUnknownActionAndMissingProviderReturnErrors(): void {
		$provider = new AiAssistantProvider( $this->modeler() );
		$this->assertSame( 'ai_assistant_action_invalid', $provider->dispatch( 'book', 'unknown', [] )->get_error_code() );
		$this->assertSame( 'ai_assistant_no_provider', $provider->dispatch( 'book', 'improve_title', [] )->get_error_code() );
		$this->assertNull( $provider->definition( 'missing' ) );
	}

	public function testControllerRegistersActionRouteAndChecksPostPermissions(): void {
		$modeler = $this->modeler();
		$controller = new AiAssistantController( new ModelRestPolicy( $modeler ), new AiAssistantProvider( $modeler ) );
		$controller->register_routes();

		$this->assertCount( 1, $GLOBALS['wp_rest_routes_registered'] );
		$this->assertStringContainsString( '/ai-assistant/', $GLOBALS['wp_rest_routes_registered'][0]['route'] );
		$request = new \WP_REST_Request( [ 'post_type' => 'book', 'post_id' => 0 ] );
		$this->assertTrue( $controller->permissions_check( $request ) );

		$GLOBALS['wp_current_user_can'] = false;
		$this->assertSame( 'rest_forbidden', $controller->permissions_check( $request )->get_error_code() );
	}

	public function testControllerRejectsMissingAndMismatchedPosts(): void {
		$modeler   = $this->modeler();
		$controller = new AiAssistantController( new ModelRestPolicy( $modeler ), new AiAssistantProvider( $modeler ) );
		$missing = new \WP_REST_Request( [ 'post_type' => 'book', 'post_id' => 12 ] );
		$this->assertSame( 'ai_assistant_post_not_found', $controller->permissions_check( $missing )->get_error_code() );

		$GLOBALS['wp_posts'][12] = new \WP_Post( [ 'ID' => 12, 'post_type' => 'movie' ] );
		$this->assertSame( 'ai_assistant_post_type_mismatch', $controller->permissions_check( $missing )->get_error_code() );
	}

	/** @return Modeler */
	private function modeler(): Modeler {
		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( 'book' );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_config' )->willReturn( [ 'ai_context' => [ 'field_rules' => [] ] ] );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true ] );
		$model->method( 'get_args' )->willReturn( [ 'meta' => [ 'box' => [ 'fields' => [ 'subtitle' => [ 'type' => 'text' ] ] ] ] ] );
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );
		return $modeler;
	}
}
