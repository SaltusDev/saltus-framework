<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Meta\FieldPermissionPolicy;
use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Features\WebMcp\PublicFieldFilter;
use Saltus\WP\Framework\MCP\Tools\GetMetaFields;
use Saltus\WP\Framework\MCP\Tools\ListMetaFields;
use Saltus\WP\Framework\MCP\Tools\UpdateMetaFields;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\MetaController;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * One field config, four surfaces, one answer.
 *
 * The phase's design constraint is "one resolution point, four consumers" — a
 * per-surface implementation is how a private field leaks through the surface
 * someone forgot. These tests assert the *same* config produces the *same*
 * denial through REST, MCP, WP-CLI, and WebMCP, so adding a fifth surface that
 * skips the policy shows up here rather than in production.
 *
 * @covers \Saltus\WP\Framework\Features\Meta\FieldPermissionPolicy
 */
class FieldPermissionParityTest extends TestCase {

	protected function setUp(): void {
		global $wp_current_user_can, $wp_posts, $wp_post_meta, $wp_post_type_objects;

		$wp_current_user_can  = true;
		$wp_posts             = [];
		$wp_post_meta         = [];
		$wp_post_type_objects = [];
	}

	protected function tearDown(): void {
		global $wp_current_user_can, $wp_posts, $wp_post_meta, $wp_post_type_objects;

		$wp_current_user_can  = true;
		$wp_posts             = [];
		$wp_post_meta         = [];
		$wp_post_type_objects = [];
	}

	/**
	 * One metabox: `title` is open, `salary` requires `manage_options` for both
	 * read and write. Unserialized so each field owns its own meta key, which is
	 * what lets a write to `title` be tested independently of `salary`.
	 *
	 * @return array<string, mixed>
	 */
	private function meta(): array {
		return [
			'employment' => [
				'register_rest_api' => true,
				'fields'            => [
					'title'  => [
						'type'  => 'text',
						'title' => 'Job Title',
					],
					'salary' => [
						'type'        => 'number',
						'title'       => 'Salary',
						'permissions' => [
							'read'  => [ 'manage_options' ],
							'write' => [ 'manage_options' ],
						],
					],
				],
			],
		];
	}

	private function modeler(): Modeler {
		$meta = $this->meta();

		$model = $this->createStub( Model::class );
		$model->method( 'get_config' )->willReturn( [ 'meta' => $meta ] );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_args' )->willReturn(
			[
				'meta'           => $meta,
				'label_singular' => 'Book',
				'label_plural'   => 'Books',
			]
		);

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );

		return $modeler;
	}

	/** Deny `manage_options` but allow the post-type edit capability. */
	private function deny_manage_options(): void {
		global $wp_current_user_can;
		$wp_current_user_can = [
			'manage_options' => false,
			'edit_posts'     => true,
			'edit_post'      => true,
		];
	}

	private function seed_post(): void {
		global $wp_posts;
		$post            = new \WP_Post( [ 'post_type' => 'book', 'post_title' => 'A Book' ] );
		$post->ID        = 10;
		$wp_posts[10]    = $post;
	}

	/** Field paths present in a normalized payload. */
	private function paths( array $payload ): array {
		$fields = $payload['normalized']['fields'] ?? [];

		return array_map( static fn( array $f ): string => (string) $f['path'], is_array( $fields ) ? $fields : [] );
	}

	// --- Read parity ---

	public function testRestReadHidesTheDeniedField(): void {
		$this->deny_manage_options();
		$modeler = $this->modeler();

		$controller = new MetaController( $modeler );
		$request    = new \WP_REST_Request();
		$request->set_param( 'post_type', 'book' );

		$paths = $this->paths( rest_ensure_response( $controller->get_items( $request ) )->get_data() );

		$this->assertContains( 'title', $paths );
		$this->assertNotContains( 'salary', $paths, 'REST must not advertise a field the caller cannot read.' );
	}

	public function testMcpReadHidesTheDeniedField(): void {
		$this->deny_manage_options();

		$result = ( new GetMetaFields() )->get_meta_fields( $this->modeler(), null, 'book' );

		$this->assertIsArray( $result );
		$paths = $this->paths( $result );

		$this->assertContains( 'title', $paths );
		$this->assertNotContains( 'salary', $paths, 'MCP must not advertise a field the caller cannot read.' );
	}

	public function testMcpListHidesTheDeniedField(): void {
		$this->deny_manage_options();

		$entries = ( new ListMetaFields() )->list_meta_fields( $this->modeler(), null, static fn( string $pt ): bool => true );

		$this->assertCount( 1, $entries );
		$paths = $this->paths( $entries[0] );

		$this->assertContains( 'title', $paths );
		$this->assertNotContains( 'salary', $paths );
	}

	/**
	 * WebMCP composes rather than duplicates: a field must be both publicly
	 * exposable and permitted. An anonymous caller fails every capability check,
	 * so a field with any rule is never public.
	 */
	public function testWebMcpComposesWithThePolicy(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;

		$fields = ( new PublicFieldFilter() )->fields( $this->modeler(), 'book' );
		$paths  = array_map( static fn( array $f ): string => (string) $f['path'], $fields );

		$this->assertContains( 'title', $paths, 'A REST-opted-in field with no rule stays public.' );
		$this->assertNotContains( 'salary', $paths, 'A field declaring permissions is never publicly readable.' );
	}

	/**
	 * The composition ordering matters: the policy runs after the filter hook, so
	 * a third-party filter cannot re-add a denied field. If it ran first, the hook
	 * would be a bypass.
	 */
	public function testPublicFieldsFilterCannotReAddADeniedField(): void {
		global $wp_current_user_can, $wp_filters_registered;
		$wp_current_user_can = false;

		$policy = new FieldPermissionPolicy();
		$all    = $policy->normalized_fields( $this->modeler(), 'book' );

		add_filter(
			'saltus/framework/webmcp/public_fields',
			static function ( $fields ) use ( $all ) {
				// A filter trying to publish everything, including the denied field.
				return $all;
			}
		);

		$fields = ( new PublicFieldFilter() )->fields( $this->modeler(), 'book' );
		$paths  = array_map( static fn( array $f ): string => (string) $f['path'], $fields );

		$wp_filters_registered = [];

		$this->assertNotContains( 'salary', $paths, 'The filter hook must not be a way around the policy.' );
	}

	// --- Write parity ---

	public function testRestWriteRejectsTheDeniedField(): void {
		$this->deny_manage_options();
		$this->seed_post();

		$controller = new MetaController( $this->modeler() );
		$request    = new \WP_REST_Request();
		$request->set_param( 'post_type', 'book' );
		$request->set_param( 'post_id', 10 );
		$request->set_body_params( [ 'meta' => [ 'salary' => 999 ] ] );

		$result = $controller->update_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_field_forbidden', $result->get_error_code() );
	}

	public function testMcpAndCliWriteRejectTheDeniedField(): void {
		$this->deny_manage_options();
		$this->seed_post();

		// `wp saltus meta update` calls this same method, so this covers both.
		$result = ( new UpdateMetaFields() )->update_meta_fields( $this->modeler(), null, 'book', 10, [ 'salary' => 999 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_field_forbidden', $result->get_error_code() );
	}

	/**
	 * A rejection must not be a silent skip. A 200 with the key absent is
	 * indistinguishable from "written, value unchanged", so the denial is an
	 * error and nothing is persisted.
	 */
	public function testDeniedWritePersistsNothing(): void {
		global $wp_post_meta;

		$this->deny_manage_options();
		$this->seed_post();

		( new UpdateMetaFields() )->update_meta_fields( $this->modeler(), null, 'book', 10, [ 'salary' => 999 ] );

		$this->assertSame( '', get_post_meta( 10, 'salary', true ), 'A denied write must not reach storage.' );
	}

	/**
	 * A denied field in the payload rejects the whole request rather than writing
	 * the allowed part. Partial application would leave the caller unable to tell
	 * which half landed.
	 */
	public function testMixedWriteRejectsRatherThanPartiallyApplying(): void {
		$this->deny_manage_options();
		$this->seed_post();

		$result = ( new UpdateMetaFields() )->update_meta_fields(
			$this->modeler(),
			null,
			'book',
			10,
			[ 'title' => 'Engineer', 'salary' => 999 ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '', get_post_meta( 10, 'title', true ), 'The allowed field must not be written either.' );
	}

	public function testAllowedWriteStillSucceeds(): void {
		$this->deny_manage_options();
		$this->seed_post();

		$result = ( new UpdateMetaFields() )->update_meta_fields( $this->modeler(), null, 'book', 10, [ 'title' => 'Engineer' ] );

		$this->assertIsArray( $result, 'A field with no rule must remain writable.' );
		$this->assertSame( 'Engineer', get_post_meta( 10, 'title', true ) );
	}

	// --- The caller who holds the capability ---

	/**
	 * The mirror image: with the capability granted, every surface behaves exactly
	 * as it did before the policy existed. Without this, a policy that denied
	 * everything unconditionally would pass every test above.
	 */
	public function testHolderOfTheCapabilitySeesAndWritesEverything(): void {
		global $wp_current_user_can;
		$wp_current_user_can = true;
		$this->seed_post();

		$modeler = $this->modeler();

		$rest_paths = $this->paths( ( new GetMetaFields() )->get_meta_fields( $modeler, null, 'book' ) );
		$this->assertContains( 'salary', $rest_paths );
		$this->assertContains( 'title', $rest_paths );

		$result = ( new UpdateMetaFields() )->update_meta_fields( $modeler, null, 'book', 10, [ 'salary' => 999 ] );
		$this->assertIsArray( $result );
		$this->assertSame( 999, get_post_meta( 10, 'salary', true ) );
	}

	/**
	 * The backward-compatibility guarantee at the surface level: a post type
	 * declaring no rules is untouched even for a caller with no capabilities.
	 */
	public function testPostTypeWithoutRulesIsUnaffectedOnEverySurface(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;
		$this->seed_post();

		$meta  = [
			'employment' => [
				'register_rest_api' => true,
				'fields'            => [
					'title' => [ 'type' => 'text', 'title' => 'Job Title' ],
				],
			],
		];
		$model = $this->createStub( Model::class );
		$model->method( 'get_config' )->willReturn( [ 'meta' => $meta ] );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_args' )->willReturn( [ 'meta' => $meta ] );

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );

		$this->assertContains( 'title', $this->paths( ( new GetMetaFields() )->get_meta_fields( $modeler, null, 'book' ) ) );
		$this->assertIsArray(
			( new UpdateMetaFields() )->update_meta_fields( $modeler, null, 'book', 10, [ 'title' => 'Engineer' ] ),
			'No rules means no new denial, regardless of capabilities.'
		);
	}
}
