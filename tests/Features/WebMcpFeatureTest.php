<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\WebMcp\PublicFieldFilter;
use Saltus\WP\Framework\Features\WebMcp\WebMcp;
use Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\WebMcpController;
use Saltus\WP\Framework\WebMcp\ManifestBuilder;
use Saltus\WP\Framework\WebMcp\ToolDescriptor;
use Saltus\WP\Framework\WebMcp\Tools\SearchContent;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy
 * @covers \Saltus\WP\Framework\Features\WebMcp\PublicFieldFilter
 * @covers \Saltus\WP\Framework\Features\WebMcp\WebMcp
 * @covers \Saltus\WP\Framework\WebMcp\ManifestBuilder
 * @covers \Saltus\WP\Framework\WebMcp\ToolDescriptor
 * @covers \Saltus\WP\Framework\Rest\WebMcpController
 */
class WebMcpFeatureTest extends TestCase {

	protected function setUp(): void {
		global $wp_rest_routes_registered, $wp_scripts_enqueued, $wp_scripts_localized, $wp_object_taxonomies, $wp_filters_registered, $wp_post_type_objects;
		$wp_rest_routes_registered = [];
		$wp_scripts_enqueued       = [];
		$wp_scripts_localized      = [];
		$wp_object_taxonomies      = [];
		$wp_filters_registered     = [];
		$wp_post_type_objects      = [];
	}

	public function testPolicyNormalizesConfigAndAllowsFrontendModels(): void {
		$policy = new WebMcpPolicy( $this->modeler( [ 'webmcp' => [ 'enabled' => true, 'frontend' => true ] ] ) );

		$this->assertTrue( $policy->is_frontend_enabled( 'book' ) );
		$this->assertFalse( $policy->is_admin_enabled( 'book' ) );
		$this->assertTrue( $policy->allows_tool( 'book', 'search_content' ) );
		$this->assertSame( [ 'book' ], $policy->frontend_models() );
		$this->assertTrue( $policy->has_frontend_surface() );
	}

	public function testPolicyTrueShorthandEnablesFrontend(): void {
		$policy = new WebMcpPolicy( $this->modeler( [ 'webmcp' => true ] ) );

		$this->assertTrue( $policy->is_frontend_enabled( 'book' ) );
		$this->assertFalse( $policy->is_admin_enabled( 'book' ) );
	}

	public function testPolicyRespectsToolAllowlist(): void {
		$policy = new WebMcpPolicy( $this->modeler( [ 'webmcp' => [ 'enabled' => true, 'frontend' => true, 'tools' => [ 'search_content' ] ] ] ) );

		$this->assertTrue( $policy->allows_tool( 'book', 'search_content' ) );
		$this->assertFalse( $policy->allows_tool( 'book', 'get_content' ) );
	}

	public function testPolicyExcludesNonPublicModels(): void {
		global $wp_post_type_objects;

		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( 'internal' );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_config' )->willReturn( [ 'webmcp' => true ] );
		$model->method( 'get_args' )->willReturn( [ 'public' => false, 'publicly_queryable' => false ] );

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'internal' => $model ] );

		// Override the stub's default so the policy reads the non-public flag.
		$wp_post_type_objects['internal'] = (object) [
			'name'               => 'internal',
			'publicly_queryable' => false,
		];

		$policy = new WebMcpPolicy( $modeler );

		$this->assertSame( [], $policy->frontend_models() );
		$this->assertFalse( $policy->has_frontend_surface() );
	}

	public function testPublicFieldFilterRequiresRestApiOptIn(): void {
		$modeler = $this->modeler_with_meta(
			[
				'basic_box' => [
					'fields'            => [
						'public_field'  => [ 'type' => 'text', 'title' => 'Public' ],
						'private_field' => [ 'type' => 'text', 'title' => 'Private' ],
					],
					'register_rest_api' => true,
				],
				'hidden_box' => [
					'fields' => [
						'hidden_field' => [ 'type' => 'text', 'title' => 'Hidden' ],
					],
				],
			]
		);

		$filter = new PublicFieldFilter();
		$fields = $filter->fields( $modeler, 'book' );

		$this->assertCount( 1, $fields );
		$this->assertSame( 'public_field', $fields[0]['path'] );
	}

	public function testPublicFieldFilterDeniesSecretFragments(): void {
		$modeler = $this->modeler_with_meta(
			[
				'box' => [
					'fields'            => [
						'api_key'  => [ 'type' => 'text', 'title' => 'API Key' ],
						'password' => [ 'type' => 'text', 'title' => 'Password' ],
						'safe'     => [ 'type' => 'text', 'title' => 'Safe Field' ],
					],
					'register_rest_api' => true,
				],
			]
		);

		$filter = new PublicFieldFilter();
		$fields = $filter->fields( $modeler, 'book' );

		$this->assertCount( 1, $fields );
		$this->assertSame( 'safe', $fields[0]['path'] );
	}

	public function testManifestBuilderWrapsParametersInJsonSchema(): void {
		$builder = new ManifestBuilder();
		$tool    = new SearchContent( $this->modeler( [ 'webmcp' => true ] ), new WebMcpPolicy( $this->modeler( [ 'webmcp' => true ] ) ) );

		$descriptor = $builder->describe( $tool );

		$this->assertInstanceOf( ToolDescriptor::class, $descriptor );
		$this->assertSame( 'search_content', $descriptor->get_name() );

		$schema = $descriptor->get_input_schema();
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'query', $schema['properties'] );
		$this->assertSame( [ 'query' ], $schema['required'] );
	}

	public function testManifestBuilderTruncatesLongDescriptions(): void {
		$builder = new ManifestBuilder();
		$long    = str_repeat( 'word ', 150 );

		$tool = $this->createStub( \Saltus\WP\Framework\MCP\Tools\ToolInterface::class );
		$tool->method( 'get_name' )->willReturn( 'test' );
		$tool->method( 'get_description' )->willReturn( $long );
		$tool->method( 'get_parameters' )->willReturn( [] );

		$descriptor = $builder->describe( $tool );

		$this->assertLessThanOrEqual( ManifestBuilder::MAX_DESCRIPTION, strlen( $descriptor->get_description() ) );
	}

	public function testManifestBuilderRejectsOverlongNames(): void {
		$builder = new ManifestBuilder();

		$tool = $this->createStub( \Saltus\WP\Framework\MCP\Tools\ToolInterface::class );
		$tool->method( 'get_name' )->willReturn( str_repeat( 'x', ManifestBuilder::MAX_NAME + 1 ) );
		$tool->method( 'get_description' )->willReturn( 'Desc' );
		$tool->method( 'get_parameters' )->willReturn( [] );

		$this->assertNull( $builder->describe( $tool ) );
	}

	public function testWebMcpFeatureBuildsToolsAndRegistersRoutes(): void {
		global $wp_rest_routes_registered;

		$modeler = $this->modeler( [ 'webmcp' => true ] );
		$policy  = new ModelRestPolicy( $modeler );
		$feature = new WebMcp( [ 'modeler' => $modeler, 'project' => [] ] );

		$routes = $feature->get_rest_routes( $modeler, $policy );

		$this->assertCount( 1, $routes );
		$routes[0]->register_routes();

		$this->assertGreaterThan( 0, count( $wp_rest_routes_registered ) );

		$registered = array_map(
			fn( $entry ) => $entry['namespace'] . '/' . ltrim( $entry['route'], '/' ),
			$wp_rest_routes_registered
		);

		$this->assertContains( 'saltus-framework/v1/webmcp/manifest', $registered );
		$this->assertContains( 'saltus-framework/v1/webmcp/execute', $registered );
	}

	public function testWebMcpControllerReturnsManifestForEnabledModels(): void {
		$modeler = $this->modeler( [ 'webmcp' => [ 'enabled' => true, 'frontend' => true ] ] );
		$policy  = new WebMcpPolicy( $modeler );
		$feature = new WebMcp( [ 'modeler' => $modeler ] );

		$controller = new WebMcpController( $policy, $feature->build_tools( $modeler ) );

		$request  = (object) [];
		$response = $controller->get_manifest( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'tools', $data );
		$this->assertArrayHasKey( 'models', $data );
		$this->assertSame( [ 'book' ], $data['models'] );
		$this->assertGreaterThan( 0, count( $data['tools'] ) );
	}

	public function testWebMcpControllerRejectsExecuteWhenNoSurfaceEnabled(): void {
		$policy     = new WebMcpPolicy( $this->modeler( [] ) );
		$controller = new WebMcpController( $policy, [] );

		$permission = $controller->manifest_permissions_check( null );

		$this->assertInstanceOf( \WP_Error::class, $permission );
		$this->assertSame( 'saltus_webmcp_disabled', $permission->get_error_code() );
	}

	public function testWebMcpControllerValidatesArgumentsBeforeExecution(): void {
		global $wp_query_posts;

		$modeler = $this->modeler( [ 'webmcp' => true ] );
		$policy  = new WebMcpPolicy( $modeler );
		$feature = new WebMcp( [ 'modeler' => $modeler ] );

		$controller = new WebMcpController( $policy, $feature->build_tools( $modeler ) );

		$request = new class {
			public function get_json_params() {
				return [
					'tool'      => 'search_content',
					'arguments' => [ 'query' => 'test' ],
				];
			}
		};

		// Seed the global so WP_Query has something to return.
		$wp_query_posts = [];

		$response = $controller->execute_tool( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'tool', $data );
		$this->assertSame( 'search_content', $data['tool'] );
	}

	private function modeler( array $config ): Modeler {
		global $wp_post_type_objects;

		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( 'book' );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_config' )->willReturn( $config );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true ] );
		$model->method( 'get_args' )->willReturn( [ 'public' => true, 'publicly_queryable' => true ] );

		// Seed the global so get_post_type_object returns a complete stub.
		$wp_post_type_objects['book'] = (object) [
			'name'               => 'book',
			'publicly_queryable' => true,
			'public'             => true,
		];

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );

		return $modeler;
	}

	private function modeler_with_meta( array $meta ): Modeler {
		return $this->modeler( [ 'webmcp' => true, 'meta' => $meta ] );
	}
}
