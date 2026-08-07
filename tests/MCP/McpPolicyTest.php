<?php

namespace Saltus\WP\Framework\Tests\MCP;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\McpPolicy;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Models\ModelFactory;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\McpPolicy
 */
class McpPolicyTest extends TestCase {

	public function testHasCapabilityReturnsTrueForHealth(): void {
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [] );

		$policy = new McpPolicy( $modeler );

		$this->assertTrue( $policy->has_capability( ModelRestPolicy::CAPABILITY_HEALTH ) );
	}

	public function testHasCapabilityReturnsTrueWhenModelHasMcpTools(): void {
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn(
			[
				'book' => $this->createModelMock( [ 'mcp_tools' => true ], [ 'meta' => [ 'show_in_mcp' => true ] ] ),
			]
		);

		$policy = new McpPolicy( $modeler );

		$this->assertTrue( $policy->has_capability( ModelRestPolicy::CAPABILITY_META, 'post_type' ) );
	}

	public function testHasCapabilityReturnsFalseWhenNoModelsHaveMcpTools(): void {
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn(
			[
				'book' => $this->createModelMock( [ 'show_in_rest' => true ], [] ),
			]
		);

		$policy = new McpPolicy( $modeler );

		$this->assertFalse( $policy->has_capability( ModelRestPolicy::CAPABILITY_META ) );
	}

	public function testHasCapabilityReturnsFalseWhenFeatureDisabledViaShowInMcp(): void {
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn(
			[
				'book' => $this->createModelMock(
					[ 'mcp_tools' => true ],
					[ 'settings' => [ 'show_in_mcp' => false ] ]
				),
			]
		);

		$policy = new McpPolicy( $modeler );

		$this->assertFalse( $policy->has_capability( ModelRestPolicy::CAPABILITY_SETTINGS ) );
	}

	public function testHasCapabilityReturnsTrueWhenFeatureConfigLacksShowInMcp(): void {
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn(
			[
				'book' => $this->createModelMock(
					[ 'mcp_tools' => true ],
					[ 'meta' => [] ]
				),
			]
		);

		$policy = new McpPolicy( $modeler );

		$this->assertTrue( $policy->has_capability( ModelRestPolicy::CAPABILITY_META ) );
	}

	public function testHasCapabilityFiltersByModelType(): void {
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn(
			[
				'book' => $this->createModelMock(
					[ 'mcp_tools' => true ],
					[ 'features' => [ 'duplicate' => [ 'show_in_mcp' => true ] ] ]
				),
			]
		);

		$policy = new McpPolicy( $modeler );

		$this->assertFalse( $policy->has_capability( ModelRestPolicy::CAPABILITY_DUPLICATE, 'taxonomy' ) );
	}

	public function testHasCapabilityReturnsTrueForModelsWhenMcpToolsIsTrue(): void {
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn(
			[
				'book' => $this->createModelMock( [ 'mcp_tools' => true ], [] ),
			]
		);

		$policy = new McpPolicy( $modeler );

		$this->assertTrue( $policy->has_capability( ModelRestPolicy::CAPABILITY_MODELS ) );
	}

	public function testIsEnabledReturnsFalseWhenMcpToolsIsAbsent(): void {
		$modeler = $this->createStub( Modeler::class );
		$policy  = new McpPolicy( $modeler );
		$model   = $this->createModelMock( [ 'show_in_rest' => true ], [] );

		$this->assertFalse( $policy->is_enabled( $model, ModelRestPolicy::CAPABILITY_META ) );
	}

	public function testIsEnabledReturnsFalseWhenMcpToolsIsFalse(): void {
		$modeler = $this->createStub( Modeler::class );
		$policy  = new McpPolicy( $modeler );
		$model   = $this->createModelMock( [ 'mcp_tools' => false ], [] );

		$this->assertFalse( $policy->is_enabled( $model, ModelRestPolicy::CAPABILITY_META ) );
	}

	public function testIsEnabledReturnsTrueForHealth(): void {
		$modeler = $this->createStub( Modeler::class );
		$policy  = new McpPolicy( $modeler );
		$model   = $this->createModelMock( [ 'mcp_tools' => true ], [] );

		$this->assertTrue( $policy->is_enabled( $model, ModelRestPolicy::CAPABILITY_HEALTH ) );
	}

	public function testIsEnabledReturnsTrueForModels(): void {
		$modeler = $this->createStub( Modeler::class );
		$policy  = new McpPolicy( $modeler );
		$model   = $this->createModelMock( [ 'mcp_tools' => true ], [] );

		$this->assertTrue( $policy->is_enabled( $model, ModelRestPolicy::CAPABILITY_MODELS ) );
	}

	public function testIsEnabledChecksFeatureLevelShowInMcp(): void {
		$modeler = $this->createStub( Modeler::class );
		$policy  = new McpPolicy( $modeler );

		$model = $this->createModelMock(
			[ 'mcp_tools' => true ],
			[ 'features' => [ 'single_export' => [ 'show_in_mcp' => false ] ] ]
		);

		$this->assertFalse( $policy->is_enabled( $model, ModelRestPolicy::CAPABILITY_EXPORT ) );
	}

	public function testIsEnabledHandlesMissingFeaturesConfigDefensively(): void {
		$modeler = $this->createStub( Modeler::class );
		$policy  = new McpPolicy( $modeler );

		$model = $this->createModelMock(
			[ 'mcp_tools' => true ],
			[] // config has no features key
		);

		$this->assertTrue( $policy->is_enabled( $model, ModelRestPolicy::CAPABILITY_EXPORT ) );
	}

	public function testIsEnabledHandlesNonArrayFeaturesConfigDefensively(): void {
		$modeler = $this->createStub( Modeler::class );
		$policy  = new McpPolicy( $modeler );

		$model = $this->createModelMock(
			[ 'mcp_tools' => true ],
			[ 'features' => null ] // config features key is null
		);

		$this->assertTrue( $policy->is_enabled( $model, ModelRestPolicy::CAPABILITY_EXPORT ) );
	}

	public function testIsEnabledHandlesExplicitDisable(): void {
		$modeler = $this->createStub( Modeler::class );
		$policy  = new McpPolicy( $modeler );

		$model = $this->createModelMock(
			[ 'mcp_tools' => true ],
			[ 'meta' => false ]
		);

		$this->assertFalse( $policy->is_enabled( $model, ModelRestPolicy::CAPABILITY_META ) );
	}

	public function testGetModelReturnsNullForUnknownModel(): void {
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [] );

		$policy = new McpPolicy( $modeler );

		$this->assertNull( $policy->get_model( 'nonexistent' ) );
	}

	public function testGetModelReturnsModelForKnownName(): void {
		$book   = $this->createModelMock( [], [] );
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $book ] );

		$policy = new McpPolicy( $modeler );

		$this->assertSame( $book, $policy->get_model( 'book' ) );
	}

	/**
	 * @param array<string, mixed> $options
	 * @param array<string, mixed> $config
	 * @return Model&object{options: array<string, mixed>}
	 */
	private function createModelMock( array $options, array $config = [] ) {
		return new class( $options, $config ) implements Model {
			public array $options;
			public array $config;

			public function __construct( array $options, array $config = [] ) {
				$this->options = $options;
				$this->config  = $config;
			}

			public function setup(): void {}
			public function get_name(): string { return 'book'; }
			public function get_type(): string { return 'post_type'; }
			/** @return array<string, mixed> */
			public function get_options(): array { return $this->options; }
			public function get_args(): array { return []; }
			/** @return array<string, mixed> */
			public function get_config(): array { return $this->config; }
		};
	}
}
