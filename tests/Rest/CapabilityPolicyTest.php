<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\CapabilityPolicy;
use WP_Post;

require_once __DIR__ . '/functions.php';

/**
 * CapabilityPolicy decides whether a model exposes a capability over REST or
 * MCP. The two gates deliberately differ in their default: REST is opt-out and
 * MCP is opt-in, so a model that says nothing is readable over REST but
 * invisible to agents.
 *
 * @covers \Saltus\WP\Framework\Rest\CapabilityPolicy
 */
class CapabilityPolicyTest extends TestCase {

	protected function setUp(): void {
		global $wp_posts;

		$wp_posts = [];
	}

	/**
	 * @param array<string, mixed> $config  Model config.
	 * @param array<string, mixed> $options Registration options.
	 */
	private function model( string $name, array $config = [], array $options = [], string $type = 'post_type' ): Model {
		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( $name );
		$model->method( 'get_type' )->willReturn( $type );
		$model->method( 'get_config' )->willReturn( $config );
		$model->method( 'get_options' )->willReturn( $options );
		$model->method( 'get_args' )->willReturn( [ 'public' => true ] );

		return $model;
	}

	/** @param array<string, Model> $models */
	private function policy( array $models ): CapabilityPolicy {
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( $models );

		return new CapabilityPolicy( $modeler );
	}

	public function testHealthIsAlwaysAvailableRegardlessOfConfig(): void {
		$policy = $this->policy( [] );

		// Health is how a client checks whether anything works at all, so it can
		// never be gated off — including with no models registered.
		$this->assertTrue( $policy->has_capability( CapabilityPolicy::GATE_REST, CapabilityPolicy::CAPABILITY_HEALTH ) );
		$this->assertTrue( $policy->has_capability( CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_HEALTH ) );

		$model = $this->model( 'book', [], [ 'show_in_rest' => false, 'show_in_mcp' => false ] );
		$this->assertTrue( $this->policy( [ 'book' => $model ] )->is_enabled( $model, CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_HEALTH ) );
	}

	public function testRestModelsAreEnabledUnlessExplicitlyDisabled(): void {
		$silent   = $this->model( 'silent', [], [] );
		$enabled  = $this->model( 'enabled', [], [ 'show_in_rest' => true ] );
		$disabled = $this->model( 'disabled', [], [ 'show_in_rest' => false ] );

		$policy = $this->policy( [] );

		$this->assertTrue(
			$policy->is_enabled( $silent, CapabilityPolicy::GATE_REST, CapabilityPolicy::CAPABILITY_MODELS ),
			'REST is opt-out: a model that says nothing is exposed.'
		);
		$this->assertTrue( $policy->is_enabled( $enabled, CapabilityPolicy::GATE_REST, CapabilityPolicy::CAPABILITY_MODELS ) );
		$this->assertFalse( $policy->is_enabled( $disabled, CapabilityPolicy::GATE_REST, CapabilityPolicy::CAPABILITY_MODELS ) );
	}

	public function testMcpModelsRequireAnExplicitOptIn(): void {
		$silent  = $this->model( 'silent', [], [] );
		$enabled = $this->model( 'enabled', [], [ 'show_in_mcp' => true ] );

		$policy = $this->policy( [] );

		$this->assertFalse(
			$policy->is_enabled( $silent, CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS ),
			'MCP is opt-in: silence must not expose a model to agents.'
		);
		$this->assertTrue( $policy->is_enabled( $enabled, CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS ) );
	}

	/**
	 * A truthy non-boolean option is cast, so 'yes' or 1 still reads as enabled.
	 */
	public function testGateOptionsAreCastToBoolean(): void {
		$policy = $this->policy( [] );

		$this->assertTrue(
			$policy->is_enabled( $this->model( 'book', [], [ 'show_in_mcp' => 1 ] ), CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS )
		);
		$this->assertFalse(
			$policy->is_enabled( $this->model( 'book', [], [ 'show_in_rest' => 0 ] ), CapabilityPolicy::GATE_REST, CapabilityPolicy::CAPABILITY_MODELS )
		);
	}

	public function testMetaCapabilityInheritsTheGlobalGateWhenTheSectionIsAbsent(): void {
		$policy = $this->policy( [] );

		$opted_in = $this->model( 'book', [], [ 'show_in_mcp' => true ] );
		$this->assertTrue( $policy->is_enabled( $opted_in, CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_META ) );

		$silent = $this->model( 'book', [], [] );
		$this->assertFalse( $policy->is_enabled( $silent, CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_META ) );
	}

	/**
	 * A declared section without the gate key means "this feature exists and has
	 * no opinion on the gate", which resolves to enabled — otherwise declaring
	 * meta at all would silently hide it.
	 */
	public function testADeclaredSectionWithoutTheGateKeyIsEnabled(): void {
		$model = $this->model( 'book', [ 'meta' => [ 'box' => [ 'fields' => [] ] ] ], [] );

		$this->assertTrue(
			$this->policy( [] )->is_enabled( $model, CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_META )
		);
	}

	public function testASectionGateOverridesTheGlobalGate(): void {
		$policy = $this->policy( [] );

		// Globally exposed, but meta specifically withheld.
		$narrowed = $this->model( 'book', [ 'meta' => [ 'show_in_mcp' => false ] ], [ 'show_in_mcp' => true ] );
		$this->assertFalse( $policy->is_enabled( $narrowed, CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_META ) );

		// Globally silent, but meta specifically opened.
		$widened = $this->model( 'book', [ 'meta' => [ 'show_in_mcp' => true ] ], [] );
		$this->assertTrue( $policy->is_enabled( $widened, CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_META ) );
	}

	public function testAScalarSectionIsTreatedAsTheGateValue(): void {
		$policy = $this->policy( [] );

		$this->assertTrue( $policy->is_enabled( $this->model( 'book', [ 'settings' => true ] ), CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_SETTINGS ) );
		$this->assertFalse( $policy->is_enabled( $this->model( 'book', [ 'settings' => false ] ), CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_SETTINGS ) );
	}

	/**
	 * Feature capabilities live under config['features'] with their own key
	 * names, which do not all match the capability constant.
	 */
	public function testFeatureCapabilitiesResolveThroughTheirFeatureKey(): void {
		$policy = $this->policy( [] );

		$map = [
			CapabilityPolicy::CAPABILITY_DUPLICATE => 'duplicate',
			CapabilityPolicy::CAPABILITY_EXPORT    => 'single_export',
			CapabilityPolicy::CAPABILITY_REORDER   => 'drag_and_drop',
		];

		foreach ( $map as $capability => $feature_key ) {
			$enabled = $this->model( 'book', [ 'features' => [ $feature_key => true ] ] );
			$this->assertTrue(
				$policy->is_enabled( $enabled, CapabilityPolicy::GATE_MCP, $capability ),
				sprintf( '%s should resolve through features.%s', $capability, $feature_key )
			);

			$disabled = $this->model( 'book', [ 'features' => [ $feature_key => false ] ] );
			$this->assertFalse( $policy->is_enabled( $disabled, CapabilityPolicy::GATE_MCP, $capability ) );
		}
	}

	public function testAnUnknownCapabilityFallsBackToTheGlobalGate(): void {
		$policy = $this->policy( [] );

		$this->assertTrue( $policy->is_enabled( $this->model( 'book', [], [ 'show_in_mcp' => true ] ), CapabilityPolicy::GATE_MCP, 'no_such_capability' ) );
		$this->assertFalse( $policy->is_enabled( $this->model( 'book', [], [] ), CapabilityPolicy::GATE_MCP, 'no_such_capability' ) );
	}

	public function testAMalformedFeaturesSectionIsIgnored(): void {
		$model = $this->model( 'book', [ 'features' => 'not-an-array' ], [ 'show_in_mcp' => true ] );

		$this->assertTrue(
			$this->policy( [] )->is_enabled( $model, CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_DUPLICATE )
		);
	}

	public function testHasCapabilityIsTrueWhenAnyModelQualifies(): void {
		$off = $this->model( 'off', [], [] );
		$on  = $this->model( 'on', [], [ 'show_in_mcp' => true ] );

		$this->assertTrue(
			$this->policy( [ 'off' => $off, 'on' => $on ] )->has_capability( CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS )
		);
		$this->assertFalse(
			$this->policy( [ 'off' => $off ] )->has_capability( CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS )
		);
	}

	public function testHasCapabilityCanBeNarrowedToAModelType(): void {
		$post_type = $this->model( 'book', [], [ 'show_in_mcp' => true ] );
		$taxonomy  = $this->model( 'genre', [], [ 'show_in_mcp' => true ], 'taxonomy' );

		$policy = $this->policy( [ 'book' => $post_type, 'genre' => $taxonomy ] );

		$this->assertTrue( $policy->has_capability( CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS, 'taxonomy' ) );

		$only_taxonomies = $this->policy( [ 'genre' => $taxonomy ] );
		$this->assertFalse( $only_taxonomies->has_capability( CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS, 'post_type' ) );
	}

	public function testGetEnabledModelsReturnsOnlyQualifyingModelsKeyedByName(): void {
		$on  = $this->model( 'on', [], [ 'show_in_mcp' => true ] );
		$off = $this->model( 'off', [], [] );

		$enabled = $this->policy( [ 'on' => $on, 'off' => $off ] )
			->get_enabled_models( CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS );

		$this->assertSame( [ 'on' ], array_keys( $enabled ) );
		$this->assertSame( $on, $enabled['on'] );
	}

	public function testGetEnabledModelsRespectsTheTypeFilter(): void {
		$post_type = $this->model( 'book', [], [ 'show_in_mcp' => true ] );
		$taxonomy  = $this->model( 'genre', [], [ 'show_in_mcp' => true ], 'taxonomy' );

		$policy = $this->policy( [ 'book' => $post_type, 'genre' => $taxonomy ] );

		$this->assertSame(
			[ 'book' ],
			array_keys( $policy->get_enabled_models( CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS, 'post_type' ) )
		);
	}

	public function testGetModelLooksUpByName(): void {
		$book   = $this->model( 'book', [], [ 'show_in_mcp' => true ] );
		$policy = $this->policy( [ 'book' => $book ] );

		$this->assertSame( $book, $policy->get_model( 'book' ) );
		$this->assertNull( $policy->get_model( 'missing' ) );
	}

	public function testGetModelArgsExposesRegistrationArgs(): void {
		$book = $this->model( 'book', [], [ 'show_in_mcp' => true ] );

		$this->assertSame( [ 'public' => true ], $this->policy( [ 'book' => $book ] )->get_model_args( $book ) );
	}

	public function testIsPostTypeEnabledRequiresAPostTypeModel(): void {
		$book  = $this->model( 'book', [], [ 'show_in_mcp' => true ] );
		$genre = $this->model( 'genre', [], [ 'show_in_mcp' => true ], 'taxonomy' );

		$policy = $this->policy( [ 'book' => $book, 'genre' => $genre ] );

		$this->assertTrue( $policy->is_post_type_enabled( 'book', CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS ) );
		$this->assertFalse(
			$policy->is_post_type_enabled( 'genre', CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS ),
			'A taxonomy must not answer a post-type question.'
		);
		$this->assertFalse( $policy->is_post_type_enabled( 'missing', CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS ) );
	}

	public function testIsPostEnabledResolvesThePostsType(): void {
		global $wp_posts;

		$wp_posts[7] = new WP_Post( [ 'ID' => 7, 'post_type' => 'book' ] );
		$wp_posts[8] = new WP_Post( [ 'ID' => 8, 'post_type' => 'other' ] );

		$policy = $this->policy( [ 'book' => $this->model( 'book', [], [ 'show_in_mcp' => true ] ) ] );

		$this->assertTrue( $policy->is_post_enabled( 7, CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS ) );
		$this->assertFalse( $policy->is_post_enabled( 8, CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS ) );
	}

	public function testIsPostEnabledIsFalseForAMissingPost(): void {
		$policy = $this->policy( [ 'book' => $this->model( 'book', [], [ 'show_in_mcp' => true ] ) ] );

		$this->assertFalse( $policy->is_post_enabled( 999, CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS ) );
		$this->assertFalse( $policy->is_post_enabled( 0, CapabilityPolicy::GATE_MCP, CapabilityPolicy::CAPABILITY_MODELS ) );
	}

	public function testCapabilityConstantsAreStableStrings(): void {
		// These appear in consuming plugins and config files, so the values are
		// part of the public contract, not internal names.
		$this->assertSame( 'models', CapabilityPolicy::CAPABILITY_MODELS );
		$this->assertSame( 'meta', CapabilityPolicy::CAPABILITY_META );
		$this->assertSame( 'settings', CapabilityPolicy::CAPABILITY_SETTINGS );
		$this->assertSame( 'duplicate', CapabilityPolicy::CAPABILITY_DUPLICATE );
		$this->assertSame( 'export', CapabilityPolicy::CAPABILITY_EXPORT );
		$this->assertSame( 'reorder', CapabilityPolicy::CAPABILITY_REORDER );
		$this->assertSame( 'health', CapabilityPolicy::CAPABILITY_HEALTH );
		$this->assertSame( 'blocks', CapabilityPolicy::CAPABILITY_BLOCKS );
		$this->assertSame( 'show_in_rest', CapabilityPolicy::GATE_REST );
		$this->assertSame( 'show_in_mcp', CapabilityPolicy::GATE_MCP );
	}
}
