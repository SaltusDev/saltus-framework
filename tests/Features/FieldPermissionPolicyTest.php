<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Meta\FieldPermissionPolicy;
use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\Features\Meta\FieldPermissionPolicy
 */
class FieldPermissionPolicyTest extends TestCase {

	protected function setUp(): void {
		global $wp_current_user_can;
		$wp_current_user_can = true;
	}

	protected function tearDown(): void {
		global $wp_current_user_can;
		$wp_current_user_can = true;
	}

	/**
	 * A modeler over one post type with the given meta config.
	 *
	 * @param array<string, mixed> $meta
	 */
	private function modeler( array $meta, string $post_type = 'book' ): Modeler {
		$model = $this->createStub( Model::class );
		$model->method( 'get_config' )->willReturn( [ 'meta' => $meta ] );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_args' )->willReturn( [ 'meta' => $meta ] );

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ $post_type => $model ] );

		return $modeler;
	}

	/**
	 * An unserialized metabox with two plain fields, one carrying permissions.
	 *
	 * @param array<string, mixed> $permissions
	 * @return array<string, mixed>
	 */
	private function meta_with_permissions( array $permissions ): array {
		return [
			'employment' => [
				'register_rest_api' => true,
				'fields'            => [
					'title'  => [
						'type'  => 'text',
						'title' => 'Job Title',
					],
					'salary' => array_merge(
						[
							'type'  => 'number',
							'title' => 'Salary',
						],
						$permissions === [] ? [] : [ 'permissions' => $permissions ]
					),
				],
			],
		];
	}

	/** Normalized field by path, for asserting against one field. */
	private function field( Modeler $modeler, string $path, string $post_type = 'book' ): array {
		$policy = new FieldPermissionPolicy();
		foreach ( $policy->normalized_fields( $modeler, $post_type ) as $field ) {
			if ( (string) $field['path'] === $path ) {
				return $field;
			}
		}

		$this->fail( 'No normalized field at path ' . $path );
	}

	/**
	 * The backward-compatibility guarantee. A field with no rule must stay
	 * exactly as accessible as before this policy existed — the phase explicitly
	 * inverts the usual "deny by omission" default so existing sites do not break.
	 */
	public function testFieldWithoutRulesIsAllowedEvenWhenCallerHasNoCapabilities(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;

		$modeler = $this->modeler( $this->meta_with_permissions( [] ) );
		$policy  = new FieldPermissionPolicy();
		$fields  = $policy->normalized_fields( $modeler, 'book' );

		$this->assertNotSame( [], $fields );
		foreach ( $fields as $field ) {
			$this->assertTrue( $policy->can_read( $field, $fields ), (string) $field['path'] );
			$this->assertTrue( $policy->can_write( $field, $fields ), (string) $field['path'] );
		}
	}

	public function testReadRuleDeniesCallerLackingTheCapability(): void {
		global $wp_current_user_can;
		$wp_current_user_can = [ 'manage_options' => false ];

		$modeler = $this->modeler( $this->meta_with_permissions( [ 'read' => [ 'manage_options' ] ] ) );
		$policy  = new FieldPermissionPolicy();
		$fields  = $policy->normalized_fields( $modeler, 'book' );

		$this->assertFalse( $policy->can_read( $this->field( $modeler, 'salary' ), $fields ) );
		$this->assertTrue( $policy->can_read( $this->field( $modeler, 'title' ), $fields ), 'A sibling without a rule is unaffected.' );
	}

	public function testReadRuleAllowsCallerHoldingTheCapability(): void {
		global $wp_current_user_can;
		$wp_current_user_can = [ 'manage_options' => true ];

		$modeler = $this->modeler( $this->meta_with_permissions( [ 'read' => [ 'manage_options' ] ] ) );
		$policy  = new FieldPermissionPolicy();

		$this->assertTrue( $policy->can_read( $this->field( $modeler, 'salary' ), $policy->normalized_fields( $modeler, 'book' ) ) );
	}

	/** Read and write resolve independently: readable but not writable is valid. */
	public function testReadAndWriteResolveIndependently(): void {
		global $wp_current_user_can;
		$wp_current_user_can = [ 'edit_others_posts' => true, 'manage_options' => false ];

		$modeler = $this->modeler(
			$this->meta_with_permissions(
				[
					'read'  => [ 'edit_others_posts' ],
					'write' => [ 'manage_options' ],
				]
			)
		);
		$policy  = new FieldPermissionPolicy();
		$fields  = $policy->normalized_fields( $modeler, 'book' );
		$salary  = $this->field( $modeler, 'salary' );

		$this->assertTrue( $policy->can_read( $salary, $fields ) );
		$this->assertFalse( $policy->can_write( $salary, $fields ) );
	}

	/** A rule listing several capabilities grants on any one of them. */
	public function testAnyListedCapabilityGrantsAccess(): void {
		global $wp_current_user_can;
		$wp_current_user_can = [ 'manage_options' => false, 'edit_others_posts' => true ];

		$modeler = $this->modeler( $this->meta_with_permissions( [ 'read' => [ 'manage_options', 'edit_others_posts' ] ] ) );
		$policy  = new FieldPermissionPolicy();

		$this->assertTrue( $policy->can_read( $this->field( $modeler, 'salary' ), $policy->normalized_fields( $modeler, 'book' ) ) );
	}

	public function testSingleCapabilityStringIsAcceptedAsARule(): void {
		global $wp_current_user_can;
		$wp_current_user_can = [ 'manage_options' => false ];

		$modeler = $this->modeler( $this->meta_with_permissions( [ 'read' => 'manage_options' ] ) );
		$policy  = new FieldPermissionPolicy();

		$this->assertFalse( $policy->can_read( $this->field( $modeler, 'salary' ), $policy->normalized_fields( $modeler, 'book' ) ) );
	}

	/**
	 * The leak this prevents: denying a serialized parent while its children stay
	 * readable would let a caller reconstruct the parent from its parts.
	 */
	public function testRuleOnAParentGovernsItsNestedChildren(): void {
		global $wp_current_user_can;
		$wp_current_user_can = [ 'manage_options' => false ];

		$meta    = [
			'compensation' => [
				'data_type'         => 'serialize',
				'register_rest_api' => true,
				'fields'            => [
					'package' => [
						'type'        => 'fieldset',
						'title'       => 'Package',
						'permissions' => [ 'read' => [ 'manage_options' ] ],
						'fields'      => [
							'base'  => [ 'type' => 'number', 'title' => 'Base' ],
							'bonus' => [ 'type' => 'number', 'title' => 'Bonus' ],
						],
					],
					'notes'   => [ 'type' => 'text', 'title' => 'Notes' ],
				],
			],
		];
		$modeler = $this->modeler( $meta );
		$policy  = new FieldPermissionPolicy();
		$fields  = $policy->normalized_fields( $modeler, 'book' );

		$this->assertFalse( $policy->can_read( $this->field( $modeler, 'compensation.package' ), $fields ) );
		$this->assertFalse(
			$policy->can_read( $this->field( $modeler, 'compensation.package.base' ), $fields ),
			'A child of a denied parent must not be readable on its own.'
		);
		$this->assertTrue(
			$policy->can_read( $this->field( $modeler, 'compensation.notes' ), $fields ),
			'A sibling of the denied parent is unaffected.'
		);
	}

	public function testFilterReadableDropsDeniedFieldsAndKeepsTheRest(): void {
		global $wp_current_user_can;
		$wp_current_user_can = [ 'manage_options' => false ];

		$modeler = $this->modeler( $this->meta_with_permissions( [ 'read' => [ 'manage_options' ] ] ) );
		$policy  = new FieldPermissionPolicy();

		$paths = array_column( $policy->filter_readable( $policy->normalized_fields( $modeler, 'book' ) ), 'path' );

		$this->assertContains( 'title', $paths );
		$this->assertNotContains( 'salary', $paths );
	}

	/**
	 * A serialized metabox stores many fields under one meta key. A whole-key
	 * write cannot honor a rule on one part, so the key is denied outright.
	 */
	public function testDeniedWriteKeysReportsTheMetaKeyForASerializedField(): void {
		global $wp_current_user_can;
		$wp_current_user_can = [ 'manage_options' => false ];

		$meta    = [
			'compensation' => [
				'data_type'         => 'serialize',
				'register_rest_api' => true,
				'fields'            => [
					'salary' => [
						'type'        => 'number',
						'title'       => 'Salary',
						'permissions' => [ 'write' => [ 'manage_options' ] ],
					],
					'notes'  => [ 'type' => 'text', 'title' => 'Notes' ],
				],
			],
		];
		$modeler = $this->modeler( $meta );
		$policy  = new FieldPermissionPolicy();

		$denied = $policy->denied_write_keys( $modeler, 'book' );

		$this->assertArrayHasKey( 'compensation', $denied );
		$this->assertSame( 'compensation.salary', $denied['compensation'], 'The reported path names what caused the denial.' );
	}

	public function testDeniedWriteKeysIsEmptyWhenNothingDeclaresRules(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;

		$modeler = $this->modeler( $this->meta_with_permissions( [] ) );

		$this->assertSame( [], ( new FieldPermissionPolicy() )->denied_write_keys( $modeler, 'book' ) );
	}

	public function testDeniedReadPathsListsEveryDeniedPath(): void {
		global $wp_current_user_can;
		$wp_current_user_can = [ 'manage_options' => false ];

		$modeler = $this->modeler( $this->meta_with_permissions( [ 'read' => [ 'manage_options' ] ] ) );

		$this->assertSame( [ 'salary' ], ( new FieldPermissionPolicy() )->denied_read_paths( $modeler, 'book' ) );
	}

	public function testHasRulesDistinguishesConfiguredFromUnconfiguredPostTypes(): void {
		$policy = new FieldPermissionPolicy();

		$this->assertTrue( $policy->has_rules( $this->modeler( $this->meta_with_permissions( [ 'read' => [ 'manage_options' ] ] ) ), 'book' ) );
		$this->assertFalse( $policy->has_rules( $this->modeler( $this->meta_with_permissions( [] ) ), 'book' ) );
	}

	/**
	 * A malformed rule must not silently become a denial of everything nor an
	 * accidental grant — it preserves today's behavior and stays a config bug.
	 */
	public function testMalformedRuleIsTreatedAsNoRule(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;

		foreach ( [ [ 'read' => [] ], [ 'read' => 42 ], [ 'read' => [ '', null ] ], [ 'read' => null ] ] as $permissions ) {
			$modeler = $this->modeler( $this->meta_with_permissions( $permissions ) );
			$policy  = new FieldPermissionPolicy();

			$this->assertTrue(
				$policy->can_read( $this->field( $modeler, 'salary' ), $policy->normalized_fields( $modeler, 'book' ) ),
				'Malformed rule ' . wp_json_encode( $permissions ) . ' must not change access.'
			);
		}
	}

	public function testUnknownPostTypeResolvesToNoFields(): void {
		$policy = new FieldPermissionPolicy();

		$this->assertSame( [], $policy->normalized_fields( $this->modeler( [] ), 'nonexistent' ) );
		$this->assertFalse( $policy->has_rules( $this->modeler( [] ), 'nonexistent' ) );
	}

	/**
	 * The resolver is injectable so a surface can answer for a specific user
	 * rather than the current one — WP-CLI runs as no user at all.
	 */
	public function testCapabilityResolverIsInjectable(): void {
		$asked  = [];
		$policy = new FieldPermissionPolicy(
			new MetaFieldProvider(),
			static function ( string $capability ) use ( &$asked ): bool {
				$asked[] = $capability;

				return false;
			}
		);

		$modeler = $this->modeler( $this->meta_with_permissions( [ 'read' => [ 'manage_options' ] ] ) );
		$fields  = $policy->normalized_fields( $modeler, 'book' );

		$this->assertFalse( $policy->can_read( $this->field( $modeler, 'salary' ), $fields ) );
		$this->assertSame( [ 'manage_options' ], $asked, 'The injected resolver must be the one consulted.' );
	}
}
