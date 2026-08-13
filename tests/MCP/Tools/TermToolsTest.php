<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\CreateTerm;
use Saltus\WP\Framework\MCP\Tools\GetPost;
use Saltus\WP\Framework\MCP\Tools\ListTerms;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * Taxonomy tools plus the single-post read.
 *
 * @covers \Saltus\WP\Framework\MCP\Tools\CreateTerm
 * @covers \Saltus\WP\Framework\MCP\Tools\ListTerms
 * @covers \Saltus\WP\Framework\MCP\Tools\GetPost
 */
class TermToolsTest extends TestCase {

	protected function setUp(): void {
		global $wp_taxonomy_objects, $wp_post_type_objects, $wp_current_user_can;

		$wp_taxonomy_objects  = [];
		$wp_post_type_objects = [];
		$wp_current_user_can  = true;
	}

	public function testToolIdentities(): void {
		$this->assertSame( 'create_term', ( new CreateTerm() )->get_name() );
		$this->assertSame( 'list_terms', ( new ListTerms() )->get_name() );
		$this->assertSame( 'get_post', ( new GetPost() )->get_name() );
	}

	public function testReadsAreCacheableAndWritesAreNot(): void {
		$this->assertTrue( ( new ListTerms() )->is_cacheable() );
		$this->assertTrue( ( new GetPost() )->is_cacheable() );
		$this->assertFalse( ( new CreateTerm() )->is_cacheable() );
	}

	public function testCreateTermRequiresTaxonomyAndName(): void {
		$params = ( new CreateTerm() )->get_parameters();

		$this->assertTrue( $params['taxonomy']['required'] );
		$this->assertTrue( $params['name']['required'] );
		$this->assertArrayHasKey( 'parent', $params );
		$this->assertArrayHasKey( 'slug', $params );
	}

	public function testCreateTermPostsToTheTaxonomyRestBase(): void {
		global $wp_taxonomy_objects;

		$wp_taxonomy_objects['genre'] = (object) [ 'rest_base' => 'genres' ];

		$request = ( new CreateTerm() )->build_rest_request(
			[
				'taxonomy'    => 'genre',
				'name'        => 'Science Fiction',
				'slug'        => 'sci-fi',
				'description' => 'Rockets.',
				'parent'      => 3,
				'extra'       => 'dropped',
			]
		);

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( 'POST', $request->get_method() );
		$this->assertSame( '/wp/v2/genres', $request->get_route() );

		$body = $request->get_json_params();
		$this->assertSame( 'Science Fiction', $body['name'] );
		$this->assertSame( 'sci-fi', $body['slug'] );
		$this->assertSame( 3, $body['parent'] );
		$this->assertArrayNotHasKey( 'extra', $body );
		$this->assertArrayNotHasKey( 'taxonomy', $body, 'The taxonomy selects the route, not a body field.' );
	}

	public function testCreateTermEncodesTheTaxonomyIntoTheRoute(): void {
		$request = ( new CreateTerm() )->build_rest_request( [ 'taxonomy' => 'a/b', 'name' => 'X' ] );

		$this->assertSame( '/wp/v2/a%2Fb', $request->get_route() );
	}

	public function testCreateTermDeniesWhenNoTaxonomyIsNamed(): void {
		global $wp_current_user_can;

		$wp_current_user_can = true;

		// An unnamed taxonomy cannot be capability-checked, so it must be refused
		// rather than fall through to a generic allow.
		$this->assertFalse( ( new CreateTerm() )->has_permission( [] ) );
		$this->assertFalse( ( new CreateTerm() )->has_permission( [ 'taxonomy' => '' ] ) );
	}

	public function testCreateTermUsesTheTaxonomyEditTermsCapability(): void {
		global $wp_taxonomy_objects, $wp_current_user_can;

		$wp_taxonomy_objects['genre'] = (object) [
			'rest_base' => 'genres',
			'cap'       => (object) [ 'edit_terms' => 'manage_genres' ],
		];

		$wp_current_user_can = [ 'manage_genres' => true ];
		$this->assertTrue( ( new CreateTerm() )->has_permission( [ 'taxonomy' => 'genre' ] ) );

		$wp_current_user_can = [ 'manage_genres' => false, 'manage_categories' => true ];
		$this->assertFalse(
			( new CreateTerm() )->has_permission( [ 'taxonomy' => 'genre' ] ),
			'The taxonomy capability must not fall back to manage_categories once it is declared.'
		);
	}

	public function testCreateTermFallsBackToManageCategories(): void {
		global $wp_taxonomy_objects, $wp_current_user_can;

		$wp_taxonomy_objects['genre'] = (object) [ 'rest_base' => 'genres' ];

		$wp_current_user_can = [ 'manage_categories' => true ];
		$this->assertTrue( ( new CreateTerm() )->has_permission( [ 'taxonomy' => 'genre' ] ) );

		$wp_current_user_can = [ 'manage_categories' => false ];
		$this->assertFalse( ( new CreateTerm() )->has_permission( [ 'taxonomy' => 'genre' ] ) );
	}

	public function testCreateTermDeniesWhenTheTaxonomyIsNotRegistered(): void {
		global $wp_taxonomy_objects, $wp_current_user_can;

		// get_taxonomy() returns null for a registered-but-null entry.
		$wp_taxonomy_objects['ghost'] = null;
		$wp_current_user_can          = true;

		$this->assertFalse( ( new CreateTerm() )->has_permission( [ 'taxonomy' => 'ghost' ] ) );
	}

	public function testListTermsForwardsOnlyQueryFilters(): void {
		global $wp_taxonomy_objects;

		$wp_taxonomy_objects['genre'] = (object) [ 'rest_base' => 'genres' ];

		$request = ( new ListTerms() )->build_rest_request(
			[
				'taxonomy'   => 'genre',
				'per_page'   => 10,
				'search'     => 'sci',
				'hide_empty' => true,
				'nonsense'   => 1,
			]
		);

		$this->assertSame( 'GET', $request->get_method() );
		$this->assertSame( '/wp/v2/genres', $request->get_route() );
		$this->assertSame(
			[ 'per_page' => 10, 'search' => 'sci', 'hide_empty' => true ],
			$request->get_params()
		);
	}

	public function testListTermsDefaultsToCategoriesWhenNoTaxonomyIsGiven(): void {
		$request = ( new ListTerms() )->build_rest_request( [] );

		$this->assertSame( '/wp/v2/categories', $request->get_route() );
	}

	public function testListTermsDeclaresItsDefaults(): void {
		$params = ( new ListTerms() )->get_parameters();

		$this->assertTrue( $params['taxonomy']['required'] );
		$this->assertSame( 50, $params['per_page']['default'] );
		$this->assertFalse( $params['hide_empty']['default'] );
	}

	public function testGetPostReadsASinglePostByRoute(): void {
		global $wp_post_type_objects;

		$wp_post_type_objects['book'] = (object) [ 'rest_base' => 'books' ];

		$request = ( new GetPost() )->build_rest_request( [ 'post_id' => 7, 'post_type' => 'book' ] );

		$this->assertSame( 'GET', $request->get_method() );
		$this->assertSame( '/wp/v2/books/7', $request->get_route() );
		$this->assertSame( [], $request->get_params() );
	}

	public function testGetPostRequiresReadPermissionOnThatPost(): void {
		global $wp_current_user_can;

		$wp_current_user_can = [ 'read_post:7' => true, 'read_post:8' => false ];

		$tool = new GetPost();
		$this->assertTrue( $tool->has_permission( [ 'post_id' => 7 ] ) );
		$this->assertFalse( $tool->has_permission( [ 'post_id' => 8 ] ) );
		$this->assertFalse( $tool->has_permission( [] ) );
	}

	public function testGetPostDeclaresPostIdRequired(): void {
		$params = ( new GetPost() )->get_parameters();

		$this->assertTrue( $params['post_id']['required'] );
		$this->assertSame( 'posts', $params['post_type']['default'] );
	}
}
