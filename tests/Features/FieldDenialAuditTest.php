<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Meta\FieldQueryGuard;
use Saltus\WP\Framework\MCP\Abilities\AbilityRuntime;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\Tools\ListPosts;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\HealthController;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * A field denial must be auditable and distinguishable from a capability failure.
 *
 * Both are refusals, but they have different fixes: a capability failure means
 * grant a role something, a field denial means change model config. Recording
 * both as `error` would make the log unable to tell an operator which.
 *
 * @covers \Saltus\WP\Framework\MCP\Abilities\AbilityRuntime
 */
class FieldDenialAuditTest extends TestCase {

	protected function setUp(): void {
		global $wpdb, $wp_transients, $wp_current_user_can, $wp_posts, $wp_options;

		$wp_current_user_can = true;
		$wp_posts            = [];
		$wp_transients       = [];
		$wp_options          = [];

		if ( is_object( $wpdb ) ) {
			$wpdb->inserts = [];
			$wpdb->queries = [];
		}
	}

	protected function tearDown(): void {
		global $wp_transients, $wp_current_user_can, $wp_posts;

		$wp_current_user_can = true;
		$wp_posts            = [];
		$wp_transients       = [];
	}

	private function modeler(): Modeler {
		$meta = [
			'employment' => [
				'register_rest_api' => true,
				'fields'            => [
					'title' => [ 'type' => 'text', 'title' => 'Job Title' ],
					'ssn'   => [ 'type' => 'text', 'title' => 'SSN', 'encrypted' => true ],
				],
			],
		];

		$model = $this->createStub( Model::class );
		$model->method( 'get_config' )->willReturn( [ 'meta' => $meta ] );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_args' )->willReturn( [ 'meta' => $meta ] );

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );

		return $modeler;
	}

	private function runtime(): AbilityRuntime {
		return new AbilityRuntime( new AuditLogger(), null, null, null, null, null, new FieldQueryGuard( $this->modeler() ) );
	}

	/** The recorded status for the most recent audit insert. */
	private function last_audit(): array {
		global $wpdb;

		$inserts = array_values(
			array_filter(
				$wpdb->inserts,
				static fn( array $i ): bool => ( $i['table'] ?? '' ) === 'wp_saltus_mcp_audit'
			)
		);

		$this->assertNotSame( [], $inserts, 'The denial must be audited.' );

		return $inserts[ count( $inserts ) - 1 ]['data'];
	}

	public function testFieldDenialIsAudited(): void {
		$this->runtime()->execute( new ListPosts(), [ 'post_type' => 'book', 'orderby' => 'ssn' ] );

		$entry = $this->last_audit();

		$this->assertSame( 'list_posts', $entry['ability'] );
		$this->assertSame( 'field_not_queryable', $entry['error_code'], 'The error code must name what was refused.' );
	}

	/**
	 * The distinguishing requirement. A field denial records its own status, so an
	 * operator can separate it from a capability failure without parsing messages.
	 */
	public function testFieldDenialUsesItsOwnStatusNotGenericError(): void {
		$this->runtime()->execute( new ListPosts(), [ 'post_type' => 'book', 'orderby' => 'ssn' ] );

		$this->assertSame( AbilityRuntime::STATUS_FIELD_DENIED, $this->last_audit()['status'] );
		$this->assertNotSame( 'error', $this->last_audit()['status'] );
	}

	/** A capability failure keeps recording as `error`, so the two stay separable. */
	public function testCapabilityFailureStillRecordsAsError(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;

		$this->runtime()->execute( new ListPosts(), [ 'post_type' => 'book' ] );

		$this->assertSame( 'error', $this->last_audit()['status'] );
	}

	public function testAllowedQueryIsNotRecordedAsDenied(): void {
		global $wpdb;

		$this->runtime()->execute( new ListPosts(), [ 'post_type' => 'book', 'orderby' => 'title' ] );

		foreach ( $wpdb->inserts as $insert ) {
			$this->assertNotSame(
				AbilityRuntime::STATUS_FIELD_DENIED,
				$insert['data']['status'] ?? '',
				'A permitted query must not be logged as a denial.'
			);
		}
	}

	/**
	 * A denial is correct behavior, not an outage. Counting it toward the error
	 * rate would make a working security rule look like a failing framework.
	 */
	public function testFieldDenialDoesNotCountTowardTheHealthErrorRate(): void {
		$logger = new AuditLogger();

		$runtime = new AbilityRuntime( $logger, null, null, null, null, null, new FieldQueryGuard( $this->modeler() ) );
		$runtime->execute( new ListPosts(), [ 'post_type' => 'book', 'orderby' => 'ssn' ] );

		$health = ( new HealthController( '1.0.0', $logger ) )->get_item( new \WP_REST_Request() );
		$data   = rest_ensure_response( $health )->get_data();

		$this->assertIsArray( $data );
		$this->assertSame( 0, $data['audit']['error_count'], 'A field denial is not a framework error.' );
		$this->assertArrayHasKey(
			AbilityRuntime::STATUS_FIELD_DENIED,
			$data['audit']['statuses'],
			'It must still be visible in the status breakdown.'
		);
	}
}
