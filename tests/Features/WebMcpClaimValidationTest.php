<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\WebMcp\WebMcp;
use Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\WebMcpController;
use Saltus\WP\Framework\WebMcp\ResultBudget;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * Validates the two claims from the issue:
 * 1. ResultBudget::shrink_lists() with pass bound can return oversized payload
 * 2. WebMcpController::get_manifest() models key only reports frontend models
 *
 * @covers \Saltus\WP\Framework\WebMcp\ResultBudget
 * @covers \Saltus\WP\Framework\Rest\WebMcpController
 * @covers \Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy
 */
class WebMcpClaimValidationTest extends TestCase {

	protected function setUp(): void {
		global $wp_filter_values, $wp_post_type_objects, $wp_current_user_id, $wp_current_user_can;
		$wp_filter_values     = [];
		$wp_post_type_objects = [];
		$wp_current_user_id   = 0;
		$wp_current_user_can  = [];
	}

	protected function tearDown(): void {
		global $wp_filter_values, $wp_post_type_objects, $wp_current_user_id, $wp_current_user_can;
		$wp_filter_values     = [];
		$wp_post_type_objects = [];
		// Restored to null, not 0: the harness reads null as "user 1" and 0 as a
		// real anonymous visitor, and classes that seed a user id without setting
		// this one first would otherwise inherit the anonymous value from here.
		$wp_current_user_id   = null;
		$wp_current_user_can  = [];
	}

	/**
	 * Claim 1: shrink_lists with MAX_LIST_PASSES bound must guarantee fit.
	 *
	 * Before fix: A payload with many short list entries could hit the pass
	 * bound and return a result still exceeding the budget.
	 *
	 * After fix: If the pass bound is reached without fitting, all remaining
	 * list entries are dropped to guarantee the result fits.
	 */
	public function testShrinkListsGuaranteesFitEvenWithManyShortEntries(): void {
		global $wp_filter_values;

		// Set a tight budget that would require dropping >50 entries.
		$wp_filter_values['saltus/framework/webmcp/output_budget'] = 300;

		$budget = new ResultBudget();

		// Create a pathological case: multiple lists with many short entries.
		// Total character count will be ~2400 chars (8x budget), requiring
		// more than 50 passes to shrink incrementally.
		$payload = [
			'list_a' => array_fill( 0, 30, [ 'id' => 1, 'name' => 'short entry' ] ),
			'list_b' => array_fill( 0, 30, [ 'id' => 2, 'name' => 'short entry' ] ),
			'list_c' => array_fill( 0, 30, [ 'id' => 3, 'name' => 'short entry' ] ),
		];

		$this->assertGreaterThan(
			300,
			$budget->measure( $payload ),
			'Test fixture must exceed budget to be meaningful.'
		);

		$clamped = $budget->apply( $payload );

		$this->assertLessThanOrEqual(
			300,
			$budget->measure( $clamped ),
			'After fix, shrink_lists must guarantee the payload fits even when pass bound is reached.'
		);
		$this->assertTrue(
			$clamped['truncated'],
			'The truncated flag must be set when entries are dropped.'
		);
	}

	/**
	 * Claim 2: get_manifest() models key must include all enabled models the
	 * caller is cleared to know about.
	 *
	 * Originally the key reported only frontend_models(), hiding admin-only
	 * models from an admin caller that manifest_permissions_check() had already
	 * allowed. Reporting enabled_models() unconditionally overshot in the other
	 * direction: admin_models() skips the publicly-queryable filter by design, so
	 * a public route echoing it hands anonymous visitors every private slug.
	 *
	 * The key is now capability-gated — admin slugs for callers who could be
	 * offered admin tools, frontend slugs for everyone else.
	 */
	public function testGetManifestIncludesAdminModelsForCapableUser(): void {
		global $wp_post_type_objects, $wp_current_user_id, $wp_current_user_can;

		$wp_current_user_id  = 1;
		$wp_current_user_can = [ 'edit_posts' => true ];

		$data = $this->manifestData();

		$this->assertArrayHasKey( 'models', $data );
		$this->assertContains( 'article', $data['models'], 'Frontend model must be included.' );
		$this->assertContains( 'internal', $data['models'], 'Admin model must reach a capable caller.' );
		$this->assertCount( 2, $data['models'], 'Both models should be present.' );
	}

	/**
	 * The manifest route takes anonymous callers, so the admin-only slug must not
	 * be in the payload they receive. `internal` is registered with
	 * `publicly_queryable => false`: naming it tells a visitor a private type
	 * exists and what to guess at elsewhere.
	 */
	public function testGetManifestHidesAdminModelsFromAnonymousCaller(): void {
		$data = $this->manifestData();

		$this->assertSame( [ 'article' ], $data['models'], 'Only the public model may reach an anonymous caller.' );
	}

	/**
	 * A logged-in user below the capability floor is treated as the public.
	 */
	public function testGetManifestHidesAdminModelsFromSubscriber(): void {
		global $wp_current_user_id, $wp_current_user_can;

		$wp_current_user_id  = 2;
		$wp_current_user_can = [ 'read' => true ];

		$data = $this->manifestData();

		$this->assertSame( [ 'article' ], $data['models'] );
	}

	/**
	 * Build a manifest response over one frontend model and one admin-only model.
	 *
	 * @return array<string, mixed>
	 */
	private function manifestData(): array {
		global $wp_post_type_objects;

		$frontend_model = $this->createStub( Model::class );
		$frontend_model->method( 'get_name' )->willReturn( 'article' );
		$frontend_model->method( 'get_type' )->willReturn( 'post_type' );
		$frontend_model->method( 'get_config' )->willReturn(
			[
				'webmcp' => [
					'enabled'  => true,
					'frontend' => true,
					'admin'    => false,
				],
			]
		);
		$frontend_model->method( 'get_args' )->willReturn( [ 'public' => true, 'publicly_queryable' => true ] );

		$admin_model = $this->createStub( Model::class );
		$admin_model->method( 'get_name' )->willReturn( 'internal' );
		$admin_model->method( 'get_type' )->willReturn( 'post_type' );
		$admin_model->method( 'get_config' )->willReturn(
			[
				'webmcp' => [
					'enabled'  => true,
					'frontend' => false,
					'admin'    => true,
				],
			]
		);
		$admin_model->method( 'get_args' )->willReturn( [ 'public' => false ] );

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn(
			[
				'article'  => $frontend_model,
				'internal' => $admin_model,
			]
		);

		// Seed globals for publicly_queryable check.
		$wp_post_type_objects['article'] = (object) [
			'name'               => 'article',
			'publicly_queryable' => true,
		];
		$wp_post_type_objects['internal'] = (object) [
			'name'               => 'internal',
			'publicly_queryable' => false,
		];

		$policy  = new WebMcpPolicy( $modeler );
		$feature = new WebMcp( [ 'modeler' => $modeler ] );

		$controller = new WebMcpController( $policy, $feature->build_tools( $modeler ) );

		$response = $controller->get_manifest( (object) [] );
		$data     = $response->get_data();

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Edge case: ensure admin-only models are NOT included in frontend_models().
	 *
	 * This verifies the policy itself is correct and only the manifest
	 * controller needed the fix.
	 */
	public function testPolicyFrontendModelsExcludesAdminOnlyModels(): void {
		global $wp_post_type_objects;

		$admin_model = $this->createStub( Model::class );
		$admin_model->method( 'get_name' )->willReturn( 'internal' );
		$admin_model->method( 'get_type' )->willReturn( 'post_type' );
		$admin_model->method( 'get_config' )->willReturn(
			[
				'webmcp' => [
					'enabled'  => true,
					'frontend' => false,
					'admin'    => true,
				],
			]
		);
		$admin_model->method( 'get_args' )->willReturn( [ 'public' => false ] );

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'internal' => $admin_model ] );

		$wp_post_type_objects['internal'] = (object) [
			'name'               => 'internal',
			'publicly_queryable' => false,
		];

		$policy = new WebMcpPolicy( $modeler );

		$this->assertSame( [], $policy->frontend_models(), 'Admin-only models must not appear in frontend_models().' );
		$this->assertSame( [ 'internal' ], $policy->admin_models(), 'Admin-only model must appear in admin_models().' );
		$this->assertSame( [ 'internal' ], $policy->enabled_models(), 'Admin-only model must appear in enabled_models().' );
	}
}
