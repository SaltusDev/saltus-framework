<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Relationships\RelationshipDefinition;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipMetabox;
use Saltus\WP\Framework\Features\Relationships\RelationshipPermissionPolicy;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;
use Saltus\WP\Framework\Features\Relationships\RelationshipStore;
use Saltus\WP\Framework\Models\ModelFactory;

require_once dirname( __DIR__ ) . '/Rest/functions.php';
require_once __DIR__ . '/RelationshipsTest.php';

/**
 * Per-relationship read/write access.
 *
 * The gap these cover is one a passing suite already hid. A relationship could
 * declare `capability`, three admin surfaces honoured it, and REST, MCP, WP-CLI and
 * the manager itself never read it — so a relationship invisible in the post editor
 * was writable over REST by the same user. `capabilities` is the enforced key, and
 * the assertions here are mostly about the places enforcement is easy to forget:
 * the reciprocal side, the cascade path, and the save that follows a read-only
 * render.
 *
 * @covers \Saltus\WP\Framework\Features\Relationships\RelationshipPermissionPolicy
 */
class RelationshipPermissionPolicyTest extends TestCase {

	protected function setUp(): void {
		global $wp_posts, $wp_current_user_can, $wp_filter_values, $wp_filters_registered;

		$wp_posts              = [];
		$wp_current_user_can   = true;
		$wp_filter_values      = [];
		$wp_filters_registered = [];
	}

	protected function tearDown(): void {
		global $wp_posts, $wp_current_user_can, $wp_filter_values, $wp_filters_registered;

		// Shared with every other class. A filter or a post left here changes an
		// unrelated class's result under a random ordering, and the failure surfaces
		// there with nothing pointing back.
		$wp_posts              = [];
		$wp_current_user_can   = true;
		$wp_filter_values      = [];
		$wp_filters_registered = [];
	}

	/** @param array<string, mixed> $attributes */
	private function definition( array $attributes = [] ): RelationshipDefinition {
		return new RelationshipDefinition(
			array_merge(
				[
					'name' => 'actors',
					'from' => 'movie',
					'to'   => 'person',
					'type' => 'has_many',
					'key'  => 'movie_person_actors',
				],
				$attributes
			)
		);
	}

	private function policy( bool $granted = true ): RelationshipPermissionPolicy {
		return new RelationshipPermissionPolicy(
			static function ( string $capability ) use ( $granted ): bool {
				return $granted;
			}
		);
	}

	// -- Resolution ---------------------------------------------------------

	public function testNoRuleLeavesAccessUnchanged(): void {
		$policy     = $this->policy( false );
		$definition = $this->definition();

		// The whole back-compat guarantee: adding this feature must not change what
		// an existing site exposes. A relationship with no rule is readable and
		// writable even when the caller holds no capability at all.
		$this->assertTrue( $policy->can_read( $definition ) );
		$this->assertTrue( $policy->can_write( $definition ) );
		$this->assertFalse( $policy->has_rules( $definition ) );
	}

	public function testRuleIsEnforcedWhenCapabilityIsMissing(): void {
		$definition = $this->definition( [ 'capabilities' => [ 'write' => [ 'manage_cast' ] ] ] );

		$this->assertTrue( $this->policy( true )->can_write( $definition ) );
		$this->assertFalse( $this->policy( false )->can_write( $definition ) );
	}

	public function testReadAndWriteResolveIndependently(): void {
		$definition = $this->definition(
			[
				'capabilities' => [
					'read'  => [ 'view_cast' ],
					'write' => [ 'manage_cast' ],
				],
			]
		);

		$policy = new RelationshipPermissionPolicy(
			static function ( string $capability ): bool {
				return $capability === 'view_cast';
			}
		);

		// Read-yes / write-no is the case the whole split exists for: an addon that
		// wants a relationship visible but not editable.
		$this->assertTrue( $policy->can_read( $definition ) );
		$this->assertFalse( $policy->can_write( $definition ) );
	}

	public function testWriteRuleDoesNotRestrictReading(): void {
		$definition = $this->definition( [ 'capabilities' => [ 'write' => [ 'manage_cast' ] ] ] );

		// Declaring only a write rule must not imply a read rule. Inferring one would
		// silently hide relationships an author only meant to lock for editing.
		$this->assertTrue( $this->policy( false )->can_read( $definition ) );
	}

	public function testAnyCapabilityInTheListGrantsAccess(): void {
		$definition = $this->definition(
			[ 'capabilities' => [ 'write' => [ 'editor_cap', 'admin_cap' ] ] ]
		);

		$policy = new RelationshipPermissionPolicy(
			static function ( string $capability ): bool {
				return $capability === 'admin_cap';
			}
		);

		$this->assertTrue( $policy->can_write( $definition ) );
	}

	public function testBareStringIsAcceptedAsASingleCapability(): void {
		$definition = $this->definition( [ 'capabilities' => [ 'write' => 'manage_cast' ] ] );

		$this->assertSame( [ 'manage_cast' ], $definition->get_capabilities_for( 'write' ) );
		$this->assertFalse( $this->policy( false )->can_write( $definition ) );
	}

	/**
	 * @dataProvider malformedRules
	 * @param mixed $capabilities
	 */
	public function testMalformedRuleIsTreatedAsNoRule( $capabilities ): void {
		$definition = $this->definition( [ 'capabilities' => $capabilities ] );

		// A config typo must not become a lockout. The policy stays permissive and
		// RelationshipConfigRules is what complains — asserted in its own test.
		$this->assertTrue( $this->policy( false )->can_read( $definition ) );
		$this->assertTrue( $this->policy( false )->can_write( $definition ) );
	}

	/** @return array<string, array{mixed}> */
	public static function malformedRules(): array {
		return [
			'not an array'      => [ 'manage_cast' ],
			'empty array'       => [ [] ],
			'empty list'        => [ [ 'write' => [] ] ],
			'empty string'      => [ [ 'write' => '' ] ],
			'non-string values' => [ [ 'write' => [ true, 42, null ] ] ],
			'boolean value'     => [ [ 'write' => true ] ],
		];
	}

	public function testUnrecognizedOperationIsDroppedAtNormalization(): void {
		$definition = $this->definition(
			[
				'capabilities' => [
					'delete' => [ 'manage_cast' ],
					'read'   => [ 'view_cast' ],
				],
			]
		);

		// A stored rule the policy never asks about reads as protection that does not
		// exist: the author sees `delete` in the config and believes the relationship is
		// gated, while only `RelationshipConfigRules` would say otherwise and a site
		// need never run it. Dropping the key makes what is stored exactly what is
		// enforced.
		$this->assertNull( $definition->get_capabilities_for( 'delete' ) );
		$this->assertSame( [ 'read' => [ 'view_cast' ] ], $definition->get_capabilities(), 'A defined operation beside it survives.' );
		$this->assertTrue( $this->policy( false )->can_write( $definition ) );
	}

	// -- Reciprocal inheritance -------------------------------------------

	public function testReciprocalInheritsCapabilities(): void {
		$registry = $this->registry(
			[
				'type'         => 'has_many',
				'model'        => 'person',
				'reciprocal'   => 'acted_in',
				'capabilities' => [ 'write' => [ 'manage_cast' ] ],
			]
		);

		$reciprocal = $registry->get( 'person', 'acted_in' );

		$this->assertInstanceOf( RelationshipDefinition::class, $reciprocal );
		// Both sides write the same row. A rule that stopped at the declaring side
		// would be bypassed by writing through the far end — the same hole that
		// two-directional cardinality enforcement closes.
		$this->assertSame( [ 'manage_cast' ], $reciprocal->get_capabilities_for( 'write' ) );
		$this->assertFalse( $this->policy( false )->can_write( $reciprocal ) );
	}

	public function testReciprocalStillDoesNotInheritCascade(): void {
		$registry = $this->registry(
			[
				'type'           => 'has_many',
				'model'          => 'person',
				'reciprocal'     => 'acted_in',
				'cascade_delete' => true,
				'capabilities'   => [ 'write' => [ 'manage_cast' ] ],
			]
		);

		$reciprocal = $registry->get( 'person', 'acted_in' );

		$this->assertInstanceOf( RelationshipDefinition::class, $reciprocal );
		// Capabilities inherit because they are security; cascade does not because it
		// is semantics. Pinned together so a later change cannot quietly align them.
		$this->assertFalse( $reciprocal->cascades_delete() );
		$this->assertSame( [ 'manage_cast' ], $reciprocal->get_capabilities_for( 'write' ) );
	}

	/** @param array<string, mixed> $declaration */
	private function registry( array $declaration ): RelationshipRegistry {
		$models = [
			'movie'  => new RelationshipModel( 'movie', [ 'actors' => $declaration ] ),
			'person' => new RelationshipModel( 'person', [] ),
		];

		return new RelationshipRegistry(
			new RelationshipModeler( $this->createStub( ModelFactory::class ), $models )
		);
	}

	// -- Addon filter contract --------------------------------------------

	public function testCapabilityListIsFilterable(): void {
		global $wp_filter_values;

		$definition = $this->definition();
		$seen       = [];

		$wp_filter_values['saltus/framework/relationships/capabilities'] = static function (
			$capabilities,
			string $name,
			string $model,
			string $operation
		) use ( &$seen ) {
			$seen[] = [ $name, $model, $operation ];

			return $operation === 'write' ? [ 'manage_cast' ] : $capabilities;
		};

		// An addon can impose a rule on a relationship that declares none. This is the
		// contract that matters for a sibling plugin: strauss prefixing means it cannot
		// import RelationshipDefinition, so the filter passes primitives only.
		$this->assertFalse( $this->policy( false )->can_write( $definition ) );
		$this->assertTrue( $this->policy( false )->can_read( $definition ) );
		$this->assertSame( [ 'actors', 'movie', 'write' ], $seen[0] );
	}

	public function testVerdictIsFilterable(): void {
		global $wp_filter_values;

		$definition = $this->definition( [ 'capabilities' => [ 'write' => [ 'manage_cast' ] ] ] );

		$wp_filter_values['saltus/framework/relationships/can'] = static function (
			bool $allowed,
			string $name,
			string $model,
			string $operation
		): bool {
			return true;
		};

		// The verdict filter exists for rules the capability system cannot express —
		// ownership, workflow state. It runs last, so it can also grant.
		$this->assertTrue( $this->policy( false )->can_write( $definition ) );
	}

	public function testCarelessFilterReturnCannotBecomeADenialOfEverything(): void {
		global $wp_filter_values;

		$definition = $this->definition();

		$wp_filter_values['saltus/framework/relationships/capabilities'] = static function () {
			// A plausible mistake: returning an empty list meaning "no restriction".
			// Taken literally it satisfies nothing and denies everyone, so it is
			// normalized back to "no rule" instead.
			return [];
		};

		$this->assertTrue( $this->policy( false )->can_write( $definition ) );
	}

	// -- Refusals ----------------------------------------------------------

	public function testDenialNamesTheRelationshipAndOperation(): void {
		$definition = $this->definition( [ 'capabilities' => [ 'write' => [ 'manage_cast' ] ] ] );

		$error = $this->policy( false )->reject_denied_write( $definition );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'rest_relationship_forbidden', $error->get_error_code() );

		$data = $error->get_error_data();
		$this->assertSame( 403, $data['status'] );
		$this->assertSame( 'actors', $data['relationship'] );
		$this->assertSame( 'write', $data['operation'] );
		// The hint must not name the capability: describing the site's permission
		// structure to a caller who just failed a check is not a debugging aid.
		$this->assertStringNotContainsString( 'manage_cast', $data['hint'] );
	}

	public function testPermittedOperationIsNotRefused(): void {
		$definition = $this->definition( [ 'capabilities' => [ 'write' => [ 'manage_cast' ] ] ] );

		$this->assertNull( $this->policy( true )->reject_denied_write( $definition ) );
		$this->assertNull( $this->policy( true )->reject_denied_read( $definition ) );
	}

	public function testFilterReadableKeepsKeys(): void {
		$readable = $this->definition( [ 'name' => 'crew' ] );
		$denied   = $this->definition(
			[
				'name'         => 'actors',
				'capabilities' => [ 'read' => [ 'view_cast' ] ],
			]
		);

		$allowed = $this->policy( false )->filter_readable(
			[
				'crew'   => $readable,
				'actors' => $denied,
			]
		);

		// Callers index definitions by name, so a reindexed return would silently
		// break every lookup.
		$this->assertSame( [ 'crew' ], array_keys( $allowed ) );
	}
}
