<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\RestTool;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * Contract tests for the shared RestTool base class.
 *
 * Every REST-backed MCP tool inherits argument filtering, route resolution, and
 * capability lookup from here, so a regression in this class silently changes
 * the behaviour of a dozen tools at once.
 *
 * @covers \Saltus\WP\Framework\MCP\Tools\RestTool
 */
class RestToolContractTest extends TestCase {

	/** Concrete subclass exposing the protected helpers under test. */
	private RestTool $tool;

	protected function setUp(): void {
		global $wp_post_type_objects, $wp_taxonomy_objects, $wp_current_user_can, $wp_filter_values;

		$wp_post_type_objects = [];
		$wp_taxonomy_objects  = [];
		$wp_current_user_can  = true;
		$wp_filter_values     = [];

		$this->tool = new class() extends RestTool {
			public function get_name(): string {
				return 'probe';
			}

			public function get_description(): string {
				return 'Probe tool.';
			}

			/** @return array<string, mixed> */
			public function get_parameters(): array {
				return [];
			}

			public function build_rest_request( array $args ): ?WP_REST_Request {
				return $this->request( 'GET', '/wp/v2/probe' );
			}

			/** Expose protected helpers for direct assertion. */
			public function call( string $method, ...$args ) {
				return $this->{$method}( ...$args );
			}
		};
	}

	public function testDefaultsAreConservative(): void {
		$this->assertFalse( $this->tool->is_cacheable(), 'Tools must opt in to caching, not out.' );
		$this->assertSame( 300, $this->tool->cache_ttl() );
		$this->assertNull( $this->tool->get_rest_capability() );
	}

	public function testDefaultPermissionRequiresReadCapability(): void {
		global $wp_current_user_can;

		$wp_current_user_can = [ 'read' => true ];
		$this->assertTrue( $this->tool->has_permission( [] ) );

		$wp_current_user_can = [ 'read' => false ];
		$this->assertFalse( $this->tool->has_permission( [] ) );
	}

	public function testOnlyArgsKeepsRequestedKeysAndDropsEverythingElse(): void {
		$filtered = $this->tool->call(
			'only_args',
			[ 'title' => 'Hello', 'status' => 'draft', 'injected' => 'nope' ],
			[ 'title', 'status', 'absent' ]
		);

		$this->assertSame( [ 'title' => 'Hello', 'status' => 'draft' ], $filtered );
	}

	/**
	 * A null or false value is still a value the caller supplied, so it must
	 * survive filtering — dropping it would silently ignore "clear this field".
	 */
	public function testOnlyArgsPreservesFalsyValuesThatWereExplicitlyProvided(): void {
		$filtered = $this->tool->call(
			'only_args',
			[ 'title' => '', 'sticky' => false, 'parent' => 0, 'excerpt' => null ],
			[ 'title', 'sticky', 'parent', 'excerpt' ]
		);

		$this->assertSame(
			[ 'title' => '', 'sticky' => false, 'parent' => 0, 'excerpt' => null ],
			$filtered
		);
	}

	public function testMcpRouteUsesTheConfiguredNamespace(): void {
		global $wp_filter_values;

		$this->assertSame( '/saltus-framework/v1/models', $this->tool->call( 'mcp_route', '/models' ) );

		$wp_filter_values['saltus/framework/mcp/namespace'] = 'acme/v2';
		$this->assertSame( '/acme/v2/models', $this->tool->call( 'mcp_route', 'models' ) );
	}

	public function testRequestCarriesQueryParamsAndBodySeparately(): void {
		$request = $this->tool->call( 'request', 'POST', '/wp/v2/books', [ 'page' => 2 ], [ 'title' => 'Dune' ] );

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( 'POST', $request->get_method() );
		$this->assertSame( '/wp/v2/books', $request->get_route() );
		$this->assertSame( [ 'page' => 2 ], $request->get_params() );
		$this->assertSame( [ 'title' => 'Dune' ], $request->get_json_params() );
	}

	public function testBuiltInPostTypesBypassObjectLookup(): void {
		global $wp_post_type_objects;

		// A hostile or broken rest_base on a core type must not redirect the route.
		$wp_post_type_objects['posts'] = (object) [ 'rest_base' => 'hijacked' ];

		foreach ( [ 'posts', 'pages', 'media', 'users' ] as $post_type ) {
			$this->assertSame( $post_type, $this->tool->call( 'post_type_rest_base', $post_type ) );
		}
	}

	public function testPostTypeRestBaseResolvesFromTheRegisteredObject(): void {
		global $wp_post_type_objects;

		$wp_post_type_objects['book'] = (object) [ 'rest_base' => 'books' ];

		$this->assertSame( 'books', $this->tool->call( 'post_type_rest_base', 'book' ) );
	}

	/**
	 * An empty or non-string rest_base is not a usable route fragment, so the
	 * slug has to win — otherwise the tool would build '/wp/v2/'.
	 */
	public function testPostTypeRestBaseFallsBackToTheSlugWhenRestBaseIsUnusable(): void {
		global $wp_post_type_objects;

		$wp_post_type_objects['book']    = (object) [ 'rest_base' => '' ];
		$wp_post_type_objects['journal'] = (object) [ 'rest_base' => false ];
		$wp_post_type_objects['zine']    = (object) [ 'name' => 'zine' ];

		$this->assertSame( 'book', $this->tool->call( 'post_type_rest_base', 'book' ) );
		$this->assertSame( 'journal', $this->tool->call( 'post_type_rest_base', 'journal' ) );
		$this->assertSame( 'zine', $this->tool->call( 'post_type_rest_base', 'zine' ) );
	}

	public function testTaxonomyRestBaseResolvesFromTheRegisteredObject(): void {
		global $wp_taxonomy_objects;

		$wp_taxonomy_objects['genre'] = (object) [ 'rest_base' => 'genres' ];
		$wp_taxonomy_objects['mood']  = (object) [ 'rest_base' => '' ];

		$this->assertSame( 'genres', $this->tool->call( 'taxonomy_rest_base', 'genre' ) );
		$this->assertSame( 'mood', $this->tool->call( 'taxonomy_rest_base', 'mood' ) );
	}

	public function testAppendTermFiltersKeysResultsByRestBase(): void {
		global $wp_taxonomy_objects;

		$wp_taxonomy_objects['genre'] = (object) [ 'rest_base' => 'genres' ];

		$data = $this->tool->call( 'append_term_filters', [ 'page' => 1 ], [ 'genre' => [ 7, 9 ] ] );

		$this->assertSame( [ 'page' => 1, 'genres' => [ 7, 9 ] ], $data );
	}

	public function testAppendTermFiltersCoercesIdsAndDropsEmptySets(): void {
		$data = $this->tool->call(
			'append_term_filters',
			[],
			[
				'genre'  => [ '7', 9.4, 'not-a-number' ],
				'empty'  => [],
				'zeroes' => [ 0, '0' ],
			]
		);

		// Strings and floats become ints; unparseable and zero ids are discarded
		// because a term id of 0 would silently widen the query.
		$this->assertSame( [ 'genre' => [ 7, 9 ] ], $data );
		$this->assertArrayNotHasKey( 'empty', $data );
		$this->assertArrayNotHasKey( 'zeroes', $data );
	}

	public function testAppendTermFiltersIgnoresMalformedInput(): void {
		$this->assertSame( [ 'a' => 1 ], $this->tool->call( 'append_term_filters', [ 'a' => 1 ], 'not-an-array' ) );
		$this->assertSame( [], $this->tool->call( 'append_term_filters', [], [ 'genre' => 'not-a-list' ] ) );
		$this->assertSame( [], $this->tool->call( 'append_term_filters', [], [ 5 => [ 1, 2 ] ] ) );
	}

	public function testCanPostRejectsMissingOrNonPositiveIds(): void {
		global $wp_current_user_can;

		$wp_current_user_can = true;

		$this->assertFalse( $this->tool->call( 'can_post', 'edit_post', [] ) );
		$this->assertFalse( $this->tool->call( 'can_post', 'edit_post', [ 'post_id' => 0 ] ) );
		$this->assertFalse( $this->tool->call( 'can_post', 'edit_post', [ 'post_id' => -5 ] ) );
	}

	public function testCanPostChecksTheCapabilityAgainstThatPost(): void {
		global $wp_current_user_can;

		$wp_current_user_can = [ 'edit_post:42' => true, 'edit_post:99' => false ];

		$this->assertTrue( $this->tool->call( 'can_post', 'edit_post', [ 'post_id' => 42 ] ) );
		$this->assertFalse( $this->tool->call( 'can_post', 'edit_post', [ 'post_id' => 99 ] ) );
		$this->assertTrue( $this->tool->call( 'can_post', 'edit_post', [ 'post_id' => '42' ] ) );
	}

	public function testPostTypeCapabilityPrefersTheRegisteredCapability(): void {
		global $wp_post_type_objects;

		$cap                          = new \stdClass();
		$cap->create_posts            = 'create_books';
		$wp_post_type_objects['book'] = (object) [ 'cap' => $cap ];

		$this->assertSame(
			'create_books',
			$this->tool->call( 'post_type_capability', 'book', 'create_posts', 'edit_posts' )
		);
	}

	public function testPostTypeCapabilityFallsBackWhenTheCapabilityIsAbsent(): void {
		global $wp_post_type_objects;

		$wp_post_type_objects['book']    = (object) [ 'cap' => new \stdClass() ];
		$wp_post_type_objects['journal'] = (object) [ 'cap' => (object) [ 'create_posts' => [ 'not', 'a', 'string' ] ] ];

		$this->assertSame( 'edit_posts', $this->tool->call( 'post_type_capability', 'book', 'create_posts', 'edit_posts' ) );
		$this->assertSame( 'edit_posts', $this->tool->call( 'post_type_capability', 'journal', 'create_posts', 'edit_posts' ) );
	}
}
