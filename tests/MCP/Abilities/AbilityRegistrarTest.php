<?php

namespace Saltus\WP\Framework\Tests\MCP\Abilities;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Abilities\AbilityDefinitionFactory;
use Saltus\WP\Framework\MCP\Abilities\AbilityRegistrar;
use Saltus\WP\Framework\MCP\Abilities\AbilityRuntime;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimiter;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @phpstan-import-type AbilityDefinition from \Saltus\WP\Framework\MCP\Abilities\AbilityDefinitionFactory
 */
class AbilityRegistrarTest extends TestCase {

	protected function setUp(): void {
		global $wpdb, $wp_abilities_registered, $wp_options, $wp_rest_request_log, $wp_transients, $wp_current_user_can, $wp_taxonomy_objects;

		$wp_abilities_registered = [];
		$wp_options              = [];
		$wp_rest_request_log     = [];
		$wp_transients           = [];
		$wp_current_user_can     = true;
		$wp_taxonomy_objects     = [];
		if ( ! is_object( $wpdb ) ) {
			$wpdb = $this->fakeWpdb();
		}
		$wpdb->inserts = [];
		$wpdb->queries = [];
	}

	public function testRegisterMapsAllMcpToolsToNativeAbilities(): void {
		global $wp_abilities_registered;

		$registered = ( new AbilityRegistrar() )->register();

		$this->assertCount( 16, $registered );
		$this->assertArrayHasKey( 'saltus/list-models', $wp_abilities_registered );
		$this->assertArrayHasKey( 'saltus/list-meta-fields', $wp_abilities_registered );
		$this->assertArrayHasKey( 'saltus/get-meta-fields', $wp_abilities_registered );
		$this->assertSame( 'List Models', $wp_abilities_registered['saltus/list-models']['label'] );
		$this->assertSame( 'list_models', $wp_abilities_registered['saltus/list-models']['meta']['mcp_tool'] );
		$this->assertArrayHasKey( 'input_schema', $wp_abilities_registered['saltus/list-models'] );
		$this->assertArrayHasKey( 'callback', $wp_abilities_registered['saltus/list-models'] );
		$this->assertArrayHasKey( 'execute_callback', $wp_abilities_registered['saltus/list-models'] );
		$this->assertArrayHasKey( 'permission_callback', $wp_abilities_registered['saltus/list-models'] );
	}

	public function testRegisterFiltersRestBackedAbilitiesWhenPolicyIsInjected(): void {
		global $wp_abilities_registered;

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn(
			[
				'book' => $this->createModelMock(
					[
						'show_in_rest' => true,
						'saltus_rest'  => [
							'models' => true,
							'meta'   => true,
						],
					]
				),
			]
		);

		$registered = ( new AbilityRegistrar( null, null, new ModelRestPolicy( $modeler ) ) )->register();

		$this->assertContains( 'saltus/list-models', $registered );
		$this->assertContains( 'saltus/list-meta-fields', $registered );
		$this->assertArrayHasKey( 'saltus/list-posts', $wp_abilities_registered );
		$this->assertArrayNotHasKey( 'saltus/update-settings', $wp_abilities_registered );
		$this->assertArrayNotHasKey( 'saltus/duplicate-post', $wp_abilities_registered );
	}

	public function testPermissionCallbackReusesWordPressCapabilityGate(): void {
		global $wp_abilities_registered, $wp_current_user_can;

		( new AbilityRegistrar() )->register();
		$permissionCallback = $wp_abilities_registered['saltus/list-models']['permission_callback'];

		$wp_current_user_can = true;
		$this->assertTrue( $permissionCallback() );

		$wp_current_user_can = false;
		$this->assertFalse( $permissionCallback() );
	}

	public function testCallbackDispatchesThroughRestRequest(): void {
		global $wp_abilities_registered, $wp_rest_request_log;

		$runtime = new AbilityRuntime( null, new RateLimiter( 1, 60 ) );
		( new AbilityRegistrar( null, new AbilityDefinitionFactory( $runtime ) ) )->register();

		$callback = $wp_abilities_registered['saltus/update-settings']['execute_callback'];
		$result   = $callback(
			[
				'post_type' => 'book',
				'settings'  => [ 'featured' => true ],
			]
		);

		$this->assertSame( [ 'ok' => true, 'route' => '/saltus-framework/v1/settings/book' ], $result );
		$this->assertSame( 'PUT', $wp_rest_request_log[0]['method'] );
		$this->assertSame( '/saltus-framework/v1/settings/book', $wp_rest_request_log[0]['route'] );
		$this->assertSame( [ 'featured' => true ], $wp_rest_request_log[0]['params'] );
	}

	public function testListPostsCallbackDispatchesTermFiltersThroughRestRequest(): void {
		global $wp_abilities_registered, $wp_rest_request_log, $wp_taxonomy_objects;

		$wp_taxonomy_objects['genre'] = (object) [
			'name'      => 'genre',
			'rest_base' => 'genres',
		];

		( new AbilityRegistrar() )->register();

		$callback = $wp_abilities_registered['saltus/list-posts']['execute_callback'];
		$result   = $callback(
			[
				'post_type' => 'movie',
				'per_page'  => 6,
				'orderby'   => 'date',
				'order'     => 'desc',
				'terms'     => [
					'genre' => [ 12 ],
				],
			]
		);

		$this->assertSame( [ 'ok' => true, 'route' => '/wp/v2/movie' ], $result );
		$this->assertSame( 'GET', $wp_rest_request_log[0]['method'] );
		$this->assertSame( '/wp/v2/movie', $wp_rest_request_log[0]['route'] );
		$this->assertSame( [ 12 ], $wp_rest_request_log[0]['query']['genres'] );
		$this->assertSame( 6, $wp_rest_request_log[0]['query']['per_page'] );
	}

	public function testListMetaFieldsCallbackDispatchesThroughRestRequest(): void {
		global $wp_abilities_registered, $wp_rest_request_log;

		( new AbilityRegistrar() )->register();

		$callback = $wp_abilities_registered['saltus/list-meta-fields']['execute_callback'];
		$result   = $callback();

		$this->assertSame( [ 'ok' => true, 'route' => '/saltus-framework/v1/meta' ], $result );
		$this->assertSame( 'GET', $wp_rest_request_log[0]['method'] );
		$this->assertSame( '/saltus-framework/v1/meta', $wp_rest_request_log[0]['route'] );
	}

	public function testReadCallbacksUseTransientCache(): void {
		global $wp_abilities_registered, $wp_rest_request_log;

		( new AbilityRegistrar() )->register();

		$callback = $wp_abilities_registered['saltus/list-meta-fields']['execute_callback'];

		$this->assertSame( [ 'ok' => true, 'route' => '/saltus-framework/v1/meta' ], $callback() );
		$this->assertSame( [ 'ok' => true, 'route' => '/saltus-framework/v1/meta' ], $callback() );

		$this->assertCount( 1, $wp_rest_request_log );
	}

	public function testMutatingCallbacksClearTransientCache(): void {
		global $wp_abilities_registered, $wp_options, $wp_rest_request_log;

		( new AbilityRegistrar() )->register();

		$wp_abilities_registered['saltus/list-meta-fields']['execute_callback']();
		$this->assertNotEmpty( $wp_options['saltus_mcp_cache_keys'] ?? [] );

		$wp_abilities_registered['saltus/update-settings']['execute_callback'](
			[
				'post_type' => 'book',
				'settings'  => [ 'featured' => true ],
			]
		);

		$this->assertArrayNotHasKey( 'saltus_mcp_cache_keys', $wp_options );
		$this->assertCount( 2, $wp_rest_request_log );
	}

	public function testCallbacksWriteAuditRecords(): void {
		global $wpdb, $wp_abilities_registered;

		( new AbilityRegistrar() )->register();

		$wp_abilities_registered['saltus/list-meta-fields']['execute_callback']();

		$this->assertNotEmpty( $wpdb->inserts );
		$this->assertSame( 'wp_saltus_mcp_audit', $wpdb->inserts[0]['table'] );
		$this->assertSame( 'list_meta_fields', $wpdb->inserts[0]['data']['ability'] );
		$this->assertSame( 'success', $wpdb->inserts[0]['data']['status'] );
	}

	public function testCallbacksReturnRateLimitError(): void {
		global $wp_abilities_registered;

		$runtime = new AbilityRuntime( null, new RateLimiter( 1, 60 ) );
		( new AbilityRegistrar( null, new AbilityDefinitionFactory( $runtime ) ) )->register();

		$callback = $wp_abilities_registered['saltus/update-settings']['execute_callback'];
		$args     = [
			'post_type' => 'book',
			'settings'  => [ 'featured' => true ],
		];
		$callback( $args );
		$result = $callback( $args );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
	}

	private function fakeWpdb(): object {
		return new class implements \Saltus\WP\Framework\MCP\Audit\AuditDatabase {
			public string $prefix = 'wp_';
			/** @var list<array<string, mixed>> */
			public array $inserts = [];
			/** @var list<string> */
			public array $queries = [];

			public function prefix(): string {
				return $this->prefix;
			}

			/**
			 * @param array<string, mixed> $data
			 * @param list<string> $format
			 */
			public function insert( string $table, array $data, array $format = [] ): bool {
				$this->inserts[] = compact( 'table', 'data', 'format' );
				return true;
			}

			public function query( string $query ): bool {
				$this->queries[] = $query;
				return true;
			}

			public function prepare( string $query, mixed ...$args ): string {
				foreach ( $args as $arg ) {
					$query = preg_replace( '/%[dsf]/', (string) $arg, $query, 1 );
				}
				return $query;
			}

			public function get_results( string $query, mixed $output = null ): array {
				return array_reverse( array_map( fn( array $insert ) => $insert['data'], $this->inserts ) );
			}

			public function get_charset_collate(): string {
				return '';
			}
		};
	}

	/**
	 * @param array<string, mixed> $options
	 * @return Model&object{options: array<string, mixed>}
	 */
	private function createModelMock( array $options ) {
		return new class( $options ) implements Model {
			/** @var array<string, mixed> */
			public array $options;

			/**
			 * @param array<string, mixed> $options
			 */
			public function __construct( array $options ) {
				$this->options = $options;
			}

			public function setup(): void {}

			public function get_name(): string {
				return 'book';
			}

			public function get_type(): string {
				return 'post_type';
			}
		};
	}
}
