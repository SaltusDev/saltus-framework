<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\CreatePost;
use Saltus\WP\Framework\MCP\Tools\ListPosts;
use Saltus\WP\Framework\MCP\Tools\UpdatePost;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * The post-facing MCP tools: what route they hit, what they forward, and who
 * is allowed to call them.
 *
 * @covers \Saltus\WP\Framework\MCP\Tools\CreatePost
 * @covers \Saltus\WP\Framework\MCP\Tools\ListPosts
 * @covers \Saltus\WP\Framework\MCP\Tools\UpdatePost
 */
class PostToolsTest extends TestCase {

	protected function setUp(): void {
		global $wp_post_type_objects, $wp_taxonomy_objects, $wp_current_user_can;

		$wp_post_type_objects = [];
		$wp_taxonomy_objects  = [];
		$wp_current_user_can  = true;
	}

	public function testToolNamesAreStableIdentifiers(): void {
		// Ability names are generated from these, so a rename is a breaking change.
		$this->assertSame( 'create_post', ( new CreatePost() )->get_name() );
		$this->assertSame( 'list_posts', ( new ListPosts() )->get_name() );
		$this->assertSame( 'update_post', ( new UpdatePost() )->get_name() );
	}

	public function testDescriptionsAreNonEmpty(): void {
		foreach ( [ new CreatePost(), new ListPosts(), new UpdatePost() ] as $tool ) {
			$this->assertNotSame( '', trim( $tool->get_description() ), $tool->get_name() . ' needs a description for the agent.' );
		}
	}

	public function testOnlyReadsAreCacheable(): void {
		$this->assertTrue( ( new ListPosts() )->is_cacheable() );
		$this->assertFalse( ( new CreatePost() )->is_cacheable(), 'A write must never be served from cache.' );
		$this->assertFalse( ( new UpdatePost() )->is_cacheable(), 'A write must never be served from cache.' );
	}

	public function testCreatePostDeclaresTitleRequiredAndStatusEnumerated(): void {
		$params = ( new CreatePost() )->get_parameters();

		$this->assertTrue( $params['title']['required'] );
		$this->assertSame( 'draft', $params['status']['default'], 'Creating a published post by default would publish unreviewed content.' );
		$this->assertSame( [ 'publish', 'draft', 'pending', 'private' ], $params['status']['enum'] );
		$this->assertSame( 'posts', $params['post_type']['default'] );
	}

	public function testCreatePostBuildsAPostRequestWithOnlyWhitelistedBodyFields(): void {
		$request = ( new CreatePost() )->build_rest_request(
			[
				'post_type' => 'posts',
				'title'     => 'Dune',
				'content'   => '<p>Body</p>',
				'excerpt'   => 'Sand',
				'slug'      => 'dune',
				'status'    => 'draft',
				'meta'      => [ 'isbn' => '123' ],
				'attacker'  => 'ignored',
			]
		);

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( 'POST', $request->get_method() );
		$this->assertSame( '/wp/v2/posts', $request->get_route() );

		$body = $request->get_json_params();
		$this->assertSame( 'Dune', $body['title'] );
		$this->assertSame( [ 'isbn' => '123' ], $body['meta'] );
		$this->assertArrayNotHasKey( 'attacker', $body, 'Unknown keys must not be forwarded to the REST API.' );
		$this->assertArrayNotHasKey( 'post_type', $body, 'post_type selects the route; it is not a body field.' );
	}

	public function testCreatePostRoutesThroughTheRegisteredRestBase(): void {
		global $wp_post_type_objects;

		$wp_post_type_objects['book'] = (object) [ 'rest_base' => 'books' ];

		$request = ( new CreatePost() )->build_rest_request( [ 'post_type' => 'book', 'title' => 'Dune' ] );

		$this->assertSame( '/wp/v2/books', $request->get_route() );
	}

	public function testCreatePostDefaultsToPostsWhenNoTypeIsGiven(): void {
		$request = ( new CreatePost() )->build_rest_request( [ 'title' => 'Untyped' ] );

		$this->assertSame( '/wp/v2/posts', $request->get_route() );
	}

	/**
	 * A slug arriving from an agent is untrusted input that lands in a URL path.
	 */
	public function testCreatePostEncodesThePostTypeIntoTheRoute(): void {
		$request = ( new CreatePost() )->build_rest_request( [ 'post_type' => 'a b/../c', 'title' => 'X' ] );

		$this->assertSame( '/wp/v2/a%20b%2F..%2Fc', $request->get_route() );
	}

	public function testCreatePostSendsTermsUnderTheirRestBase(): void {
		global $wp_taxonomy_objects;

		$wp_taxonomy_objects['genre'] = (object) [ 'rest_base' => 'genres' ];

		$request = ( new CreatePost() )->build_rest_request(
			[ 'title' => 'Dune', 'terms' => [ 'genre' => [ 4, 5 ] ] ]
		);

		$this->assertSame( [ 4, 5 ], $request->get_json_params()['genres'] );
	}

	public function testCreatePostPermissionUsesThePostTypeCreateCapability(): void {
		global $wp_post_type_objects, $wp_current_user_can;

		$cap                          = new \stdClass();
		$cap->create_posts            = 'create_books';
		$wp_post_type_objects['book'] = (object) [ 'cap' => $cap ];

		$wp_current_user_can = [ 'create_books' => true ];
		$this->assertTrue( ( new CreatePost() )->has_permission( [ 'post_type' => 'book' ] ) );

		$wp_current_user_can = [ 'create_books' => false ];
		$this->assertFalse( ( new CreatePost() )->has_permission( [ 'post_type' => 'book' ] ) );
	}

	public function testCreatePostFallsBackToEditPostsWhenNoCreateCapabilityIsRegistered(): void {
		global $wp_post_type_objects, $wp_current_user_can;

		$wp_post_type_objects['book'] = (object) [ 'cap' => new \stdClass() ];
		$wp_current_user_can          = [ 'edit_posts' => false ];

		$this->assertFalse( ( new CreatePost() )->has_permission( [ 'post_type' => 'book' ] ) );
	}

	public function testListPostsBuildsAGetRequestWithFiltersAsQueryParams(): void {
		$request = ( new ListPosts() )->build_rest_request(
			[
				'post_type' => 'posts',
				'status'    => 'publish',
				'search'    => 'sand',
				'per_page'  => 5,
				'page'      => 2,
				'orderby'   => 'title',
				'order'     => 'asc',
				'ignored'   => 'nope',
			]
		);

		$this->assertSame( 'GET', $request->get_method() );
		$this->assertSame( '/wp/v2/posts', $request->get_route() );

		$query = $request->get_params();
		$this->assertSame( 'publish', $query['status'] );
		$this->assertSame( 'sand', $query['search'] );
		$this->assertSame( 5, $query['per_page'] );
		$this->assertSame( 2, $query['page'] );
		$this->assertSame( 'title', $query['orderby'] );
		$this->assertSame( 'asc', $query['order'] );
		$this->assertArrayNotHasKey( 'ignored', $query );
		$this->assertSame( [], $request->get_json_params(), 'A read must not carry a body.' );
	}

	public function testListPostsOmitsFiltersTheCallerDidNotSupply(): void {
		$request = ( new ListPosts() )->build_rest_request( [] );

		// Defaults live in the parameter schema, not in the request: sending
		// nothing lets the REST endpoint apply its own documented defaults.
		$this->assertSame( [], $request->get_params() );
	}

	public function testListPostsAppliesTermFiltersToTheQuery(): void {
		global $wp_taxonomy_objects;

		$wp_taxonomy_objects['genre'] = (object) [ 'rest_base' => 'genres' ];

		$request = ( new ListPosts() )->build_rest_request( [ 'terms' => [ 'genre' => [ 3 ] ] ] );

		$this->assertSame( [ 3 ], $request->get_params()['genres'] );
	}

	public function testListPostsDeclaresPaginationDefaults(): void {
		$params = ( new ListPosts() )->get_parameters();

		$this->assertSame( 20, $params['per_page']['default'] );
		$this->assertSame( 1, $params['page']['default'] );
		$this->assertSame( 'publish', $params['status']['default'] );
		$this->assertSame( [ 'asc', 'desc' ], $params['order']['enum'] );
	}

	/**
	 * The default read scope must stay at published content: a default of 'any'
	 * would expose drafts to any agent that omits the parameter.
	 */
	public function testListPostsDefaultStatusIsPublish(): void {
		$this->assertSame( 'publish', ( new ListPosts() )->get_parameters()['status']['default'] );
	}

	public function testUpdatePostTargetsTheIdInTheRoute(): void {
		global $wp_post_type_objects;

		$wp_post_type_objects['book'] = (object) [ 'rest_base' => 'books' ];

		$request = ( new UpdatePost() )->build_rest_request(
			[ 'post_id' => 42, 'post_type' => 'book', 'title' => 'New' ]
		);

		$this->assertSame( 'PUT', $request->get_method() );
		$this->assertSame( '/wp/v2/books/42', $request->get_route() );
		$this->assertSame( [ 'title' => 'New' ], $request->get_json_params() );
	}

	public function testUpdatePostCoercesTheIdToAnInteger(): void {
		$request = ( new UpdatePost() )->build_rest_request( [ 'post_id' => '42abc', 'title' => 'X' ] );

		$this->assertSame( '/wp/v2/posts/42', $request->get_route() );
	}

	public function testUpdatePostWithoutAnIdTargetsZeroRatherThanACollection(): void {
		// '/wp/v2/posts/0' 404s; '/wp/v2/posts' would create a post instead.
		$request = ( new UpdatePost() )->build_rest_request( [ 'title' => 'X' ] );

		$this->assertSame( '/wp/v2/posts/0', $request->get_route() );
	}

	public function testUpdatePostDoesNotAcceptTerms(): void {
		$this->assertArrayNotHasKey( 'terms', ( new UpdatePost() )->get_parameters() );

		$request = ( new UpdatePost() )->build_rest_request( [ 'post_id' => 1, 'terms' => [ 'genre' => [ 2 ] ] ] );

		$this->assertSame( [], $request->get_json_params() );
	}

	public function testUpdatePostRequiresEditPermissionOnThatSpecificPost(): void {
		global $wp_current_user_can;

		$wp_current_user_can = [ 'edit_post:42' => true, 'edit_post:99' => false ];

		$tool = new UpdatePost();
		$this->assertTrue( $tool->has_permission( [ 'post_id' => 42 ] ) );
		$this->assertFalse( $tool->has_permission( [ 'post_id' => 99 ] ) );
		$this->assertFalse( $tool->has_permission( [] ), 'A missing id must not pass the capability check.' );
	}

	public function testUpdatePostDeclaresPostIdRequired(): void {
		$params = ( new UpdatePost() )->get_parameters();

		$this->assertTrue( $params['post_id']['required'] );
		$this->assertArrayNotHasKey( 'default', $params['status'], 'Update must not silently change status.' );
	}
}
