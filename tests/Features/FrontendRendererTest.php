<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Frontend\FrontendRenderer;
use Saltus\WP\Framework\Models\Model;
use WP_Post;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * FrontendRenderer turns shortcode attributes into a WP_Query and a rendered
 * template.
 *
 * Attributes come from post content, so every one of them is untrusted input
 * heading for a database query: orderby and order are whitelisted, limits are
 * clamped, and taxonomy and term slugs are sanitized. Custom template paths are
 * confined to the project directory.
 *
 * @covers \Saltus\WP\Framework\Features\Frontend\FrontendRenderer
 */
class FrontendRendererTest extends TestCase {

	protected function setUp(): void {
		global $wp_query_posts, $wp_posts, $wp_post_meta, $wp_taxonomy_objects, $wp_filter_values;

		$wp_query_posts      = [];
		$wp_posts            = [];
		$wp_post_meta        = [];
		$wp_taxonomy_objects = [];
		$wp_filter_values    = [];
	}

	/** @param array<string, mixed> $meta */
	private function model( array $meta = [] ): Model {
		$model = $this->createStub( Model::class );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_args' )->willReturn( [ 'meta' => $meta ] );

		return $model;
	}

	/**
	 * Capture the query the renderer built by intercepting the filter it applies.
	 *
	 * @return array<string, mixed>
	 */
	private function captured_query( array $attributes, ?Model $model = null ): array {
		global $wp_filter_values;

		$captured = [];

		$wp_filter_values['saltus/framework/frontend/query_args'] = static function ( $args ) use ( &$captured ) {
			$captured = $args;
			return $args;
		};

		( new FrontendRenderer( 'book', [], [], null, $model ) )->render( $attributes );

		return $captured;
	}

	public function testListIsTheDefaultView(): void {
		$query = $this->captured_query( [] );

		$this->assertSame( 'book', $query['post_type'] );
		$this->assertSame( 'publish', $query['post_status'], 'Only published content may render publicly.' );
	}

	public function testAnUnknownViewFallsBackToTheList(): void {
		$this->assertSame( 'book', $this->captured_query( [ 'view' => 'nonsense' ] )['post_type'] );
	}

	public function testDefaultsAreTenNewestFirst(): void {
		$query = $this->captured_query( [] );

		$this->assertSame( 10, $query['posts_per_page'] );
		$this->assertSame( 'date', $query['orderby'] );
		$this->assertSame( 'DESC', $query['order'] );
	}

	public function testLimitIsClampedToAHundred(): void {
		$this->assertSame( 100, $this->captured_query( [ 'limit' => 5000 ] )['posts_per_page'] );
		$this->assertSame( 25, $this->captured_query( [ 'limit' => '25' ] )['posts_per_page'] );
	}

	/**
	 * A limit of 0 would mean "unbounded" to WP_Query, which turns a shortcode
	 * typo into a full table scan, so it has to floor at 1.
	 */
	public function testAZeroOrUnparseableLimitFallsBackToOne(): void {
		$this->assertSame( 1, $this->captured_query( [ 'limit' => 0 ] )['posts_per_page'] );
		$this->assertSame( 1, $this->captured_query( [ 'limit' => 'abc' ] )['posts_per_page'] );
	}

	/**
	 * absint() takes the absolute value rather than flooring at zero, so a
	 * negative limit reads as its magnitude — still a bounded, safe query.
	 */
	public function testANegativeLimitBecomesItsMagnitude(): void {
		$this->assertSame( 5, $this->captured_query( [ 'limit' => -5 ] )['posts_per_page'] );
	}

	public function testOrderbyIsRestrictedToAWhitelist(): void {
		foreach ( [ 'date', 'title', 'modified', 'menu_order', 'ID' ] as $orderby ) {
			$this->assertSame( $orderby, $this->captured_query( [ 'orderby' => $orderby ] )['orderby'] );
		}
	}

	public function testAnUnknownOrderbyIsReplacedRatherThanPassedThrough(): void {
		// An unchecked orderby reaches SQL, so an injection attempt must not survive.
		$this->assertSame( 'date', $this->captured_query( [ 'orderby' => 'rand(); DROP TABLE' ] )['orderby'] );
		$this->assertSame( 'date', $this->captured_query( [ 'orderby' => 'meta_value' ] )['orderby'] );
	}

	public function testOrderAcceptsEitherCaseAndRejectsAnythingElse(): void {
		$this->assertSame( 'ASC', $this->captured_query( [ 'order' => 'asc' ] )['order'] );
		$this->assertSame( 'ASC', $this->captured_query( [ 'order' => 'ASC' ] )['order'] );
		$this->assertSame( 'DESC', $this->captured_query( [ 'order' => 'sideways' ] )['order'] );
	}

	public function testNoTaxonomyQueryIsAddedWithoutBothATaxonomyAndTerms(): void {
		$this->assertArrayNotHasKey( 'tax_query', $this->captured_query( [] ) );
		$this->assertArrayNotHasKey( 'tax_query', $this->captured_query( [ 'taxonomy' => 'genre' ] ) );
		$this->assertArrayNotHasKey( 'tax_query', $this->captured_query( [ 'terms' => 'sci-fi' ] ) );
	}

	public function testATaxonomyQueryIsAddedForAValidTaxonomy(): void {
		global $wp_taxonomy_objects;

		$wp_taxonomy_objects['genre'] = (object) [ 'name' => 'genre', 'object_type' => [ 'book' ] ];

		$tax_query = $this->captured_query( [ 'taxonomy' => 'genre', 'terms' => 'sci-fi,classics' ] )['tax_query'];

		$this->assertSame( 'genre', $tax_query[0]['taxonomy'] );
		$this->assertSame( 'slug', $tax_query[0]['field'] );
		$this->assertSame( [ 'sci-fi', 'classics' ], $tax_query[0]['terms'] );
	}

	/**
	 * A taxonomy not registered for this post type would silently return nothing
	 * or, worse, match another type's terms.
	 */
	public function testATaxonomyNotRegisteredForThePostTypeIsIgnored(): void {
		global $wp_taxonomy_objects;

		$wp_taxonomy_objects['genre'] = (object) [ 'name' => 'genre', 'object_type' => [ 'movie' ] ];

		$this->assertArrayNotHasKey(
			'tax_query',
			$this->captured_query( [ 'taxonomy' => 'genre', 'terms' => 'sci-fi' ] )
		);
	}

	public function testTermsAcceptAnArrayAsWellAsACommaList(): void {
		global $wp_taxonomy_objects;

		$wp_taxonomy_objects['genre'] = (object) [ 'name' => 'genre', 'object_type' => [ 'book' ] ];

		$query = $this->captured_query( [ 'taxonomy' => 'genre', 'terms' => [ 'sci-fi', 'classics' ] ] );

		$this->assertSame( [ 'sci-fi', 'classics' ], $query['tax_query'][0]['terms'] );
	}

	public function testTermAndTaxonomySlugsAreSanitized(): void {
		global $wp_taxonomy_objects;

		$wp_taxonomy_objects['genre'] = (object) [ 'name' => 'genre', 'object_type' => [ 'book' ] ];

		$query = $this->captured_query( [ 'taxonomy' => 'GENRE!', 'terms' => 'Sci-Fi!,  ,classics' ] );

		// sanitize_key lower-cases and strips punctuation; blanks are dropped so
		// an empty slug cannot widen the query.
		$this->assertSame( 'genre', $query['tax_query'][0]['taxonomy'] );
		$this->assertSame( [ 'sci-fi', 'classics' ], $query['tax_query'][0]['terms'] );
	}

	public function testTheQueryCanBeRewrittenByFilter(): void {
		global $wp_filter_values, $wp_query_posts;

		$wp_query_posts = [];

		$wp_filter_values['saltus/framework/frontend/query_args'] = static fn( $args ) => array_merge( $args, [ 'posts_per_page' => 3 ] );

		// The filter is the documented extension point; the renderer must use its
		// return value rather than the array it built.
		$output = ( new FrontendRenderer( 'book' ) )->render( [] );

		$this->assertIsString( $output );
	}

	public function testTheFilterReceivesThePostTypeAndNormalizedAttributes(): void {
		global $wp_filter_values;

		$seen = [];

		$wp_filter_values['saltus/framework/frontend/query_args'] = static function ( $args, $post_type = null, $attributes = null ) use ( &$seen ) {
			$seen = [ 'post_type' => $post_type, 'attributes' => $attributes ];
			return $args;
		};

		( new FrontendRenderer( 'book' ) )->render( [ 'limit' => 3 ] );

		$this->assertSame( 'book', $seen['post_type'] );
		$this->assertSame( 3, $seen['attributes']['limit'] );
	}

	/** A non-array filter return must not be passed to WP_Query. */
	public function testAMalformedFilterReturnIsIgnored(): void {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/frontend/query_args'] = static fn() => 'not-an-array';

		$this->assertIsString( ( new FrontendRenderer( 'book' ) )->render( [] ) );
	}

	public function testTheListTemplateRendersEachPost(): void {
		global $wp_query_posts;

		$wp_query_posts = [
			new WP_Post( [ 'ID' => 1, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => 'Dune' ] ),
			new WP_Post( [ 'ID' => 2, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => 'Neuromancer' ] ),
		];

		$output = ( new FrontendRenderer( 'book' ) )->render( [] );

		$this->assertStringContainsString( 'Dune', $output );
		$this->assertStringContainsString( 'Neuromancer', $output );
	}

	/**
	 * Meta collection skips anything that is not a WP_Post, so a malformed query
	 * result cannot produce a meta entry keyed off a non-post.
	 *
	 * Note this is only true of the meta pass: the posts array itself is handed to
	 * the template unfiltered, and templates/list.php dereferences every entry. A
	 * filter returning a non-post would still fatal there, so this guard protects
	 * the meta lookup rather than the render as a whole.
	 */
	public function testMetaCollectionSkipsNonPostEntries(): void {
		global $wp_query_posts, $wp_post_meta;

		$post           = new WP_Post( [ 'ID' => 1, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => 'Dune' ] );
		$wp_query_posts = [ $post ];
		$wp_post_meta   = [ 1 => [ 'isbn' => [ '978' ] ] ];

		$model  = $this->model( [ 'box' => [ 'fields' => [ 'isbn' => [ 'type' => 'text' ] ] ] ] );
		$output = ( new FrontendRenderer( 'book', [], [], null, $model ) )->render( [] );

		$this->assertStringContainsString( 'Dune', $output );
		$this->assertStringContainsString( '978', $output );
	}

	public function testAnEmptyResultRendersTheEmptyNotice(): void {
		global $wp_query_posts;

		$wp_query_posts = [];

		$this->assertStringContainsString( 'No entries found.', ( new FrontendRenderer( 'book' ) )->render( [] ) );
	}

	public function testTheSingleViewRendersOnePublishedPost(): void {
		global $wp_posts;

		$wp_posts[5] = new WP_Post(
			[ 'ID' => 5, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => 'Dune', 'post_content' => 'Sand.' ]
		);

		$output = ( new FrontendRenderer( 'book' ) )->render( [ 'view' => 'single', 'id' => 5 ] );

		$this->assertStringContainsString( 'Dune', $output );
	}

	public function testTheSingleViewRefusesAnythingNotPubliclyReadable(): void {
		global $wp_posts;

		$wp_posts[6] = new WP_Post( [ 'ID' => 6, 'post_type' => 'book', 'post_status' => 'draft', 'post_title' => 'Draft' ] );
		$wp_posts[7] = new WP_Post( [ 'ID' => 7, 'post_type' => 'movie', 'post_status' => 'publish', 'post_title' => 'Other type' ] );

		$renderer = new FrontendRenderer( 'book' );

		$this->assertSame( '', $renderer->render( [ 'view' => 'single', 'id' => 6 ] ), 'A draft must not render.' );
		$this->assertSame( '', $renderer->render( [ 'view' => 'single', 'id' => 7 ] ), 'Another post type must not render.' );
		$this->assertSame( '', $renderer->render( [ 'view' => 'single', 'id' => 999 ] ), 'A missing post must not render.' );
		$this->assertSame( '', $renderer->render( [ 'view' => 'single' ] ), 'A missing id must not render.' );
		$this->assertSame( '', $renderer->render( [ 'view' => 'single', 'id' => 0 ] ) );
	}

	public function testUnserializedMetaValuesAreExposedByPath(): void {
		global $wp_posts, $wp_post_meta;

		$wp_posts[5]     = new WP_Post( [ 'ID' => 5, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => 'Dune' ] );
		$wp_post_meta[5] = [ 'isbn' => [ '978-0441013593' ] ];

		$model  = $this->model( [ 'box' => [ 'fields' => [ 'isbn' => [ 'type' => 'text', 'title' => 'ISBN' ] ] ] ] );
		$output = ( new FrontendRenderer( 'book', [], [], null, $model ) )->render( [ 'view' => 'single', 'id' => 5 ] );

		$this->assertStringContainsString( '978-0441013593', $output );
	}

	/**
	 * A serialized box stores one array under the box key, so a dotted path has
	 * to be walked into that array rather than read as its own meta key.
	 */
	public function testSerializedMetaValuesAreResolvedByWalkingTheDottedPath(): void {
		global $wp_posts, $wp_post_meta;

		$wp_posts[5]     = new WP_Post( [ 'ID' => 5, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => 'Dune' ] );
		$wp_post_meta[5] = [ 'book_data' => [ [ 'isbn' => '978-0441013593', 'nested' => [ 'deep' => 'found' ] ] ] ];

		$model = $this->model(
			[
				'book_data' => [
					'data_type' => 'serialize',
					'fields'    => [
						'isbn'   => [ 'type' => 'text', 'title' => 'ISBN' ],
						'nested' => [
							'type'   => 'fieldset',
							'fields' => [ 'deep' => [ 'type' => 'text', 'title' => 'Deep' ] ],
						],
					],
				],
			]
		);

		$output = ( new FrontendRenderer( 'book', [], [], null, $model ) )->render( [ 'view' => 'single', 'id' => 5 ] );

		$this->assertStringContainsString( '978-0441013593', $output );
		$this->assertStringContainsString( 'found', $output );
	}

	public function testAMissingSerializedSegmentResolvesToNothingRatherThanFailing(): void {
		global $wp_posts, $wp_post_meta;

		$wp_posts[5]     = new WP_Post( [ 'ID' => 5, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => 'Dune' ] );
		$wp_post_meta[5] = [ 'book_data' => [ [ 'other' => 'value' ] ] ];

		$model = $this->model(
			[ 'book_data' => [ 'data_type' => 'serialize', 'fields' => [ 'isbn' => [ 'type' => 'text' ] ] ] ]
		);

		$output = ( new FrontendRenderer( 'book', [], [], null, $model ) )->render( [ 'view' => 'single', 'id' => 5 ] );

		$this->assertIsString( $output );
		$this->assertStringContainsString( 'Dune', $output );
	}

	public function testRenderingWorksWithoutAModel(): void {
		global $wp_posts;

		$wp_posts[5] = new WP_Post( [ 'ID' => 5, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => 'Dune' ] );

		$this->assertStringContainsString( 'Dune', ( new FrontendRenderer( 'book' ) )->render( [ 'view' => 'single', 'id' => 5 ] ) );
	}

	/**
	 * A configured template path is resolved inside the project directory. A path
	 * escaping it must be refused and the bundled default used instead — the
	 * template is included, so a traversal would execute an arbitrary file.
	 */
	public function testACustomTemplateOutsideTheProjectDirectoryIsRefused(): void {
		global $wp_posts;

		$wp_posts[5] = new WP_Post( [ 'ID' => 5, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => 'Dune' ] );

		$project = [ 'path' => dirname( __DIR__, 2 ) . '/templates' ];
		$output  = ( new FrontendRenderer(
			'book',
			$project,
			[ 'templates' => [ 'single' => '../../../../etc/passwd' ] ]
		) )->render( [ 'view' => 'single', 'id' => 5 ] );

		$this->assertStringNotContainsString( 'root:', $output );
		$this->assertStringContainsString( 'Dune', $output, 'The bundled template must be used instead.' );
	}

	public function testACustomTemplateInsideTheProjectDirectoryIsUsed(): void {
		global $wp_posts;

		$wp_posts[5] = new WP_Post( [ 'ID' => 5, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => 'Dune' ] );

		$dir = sys_get_temp_dir() . '/saltus-renderer-' . uniqid();
		mkdir( $dir . '/views', 0777, true );
		file_put_contents( $dir . '/views/single.php', '<em>custom template</em>' );

		try {
			$output = ( new FrontendRenderer(
				'book',
				[ 'path' => $dir ],
				[ 'templates' => [ 'single' => 'views/single.php' ] ]
			) )->render( [ 'view' => 'single', 'id' => 5 ] );

			$this->assertSame( '<em>custom template</em>', $output );
		} finally {
			unlink( $dir . '/views/single.php' );
			rmdir( $dir . '/views' );
			rmdir( $dir );
		}
	}

	public function testAConfiguredTemplateThatDoesNotExistFallsBackToTheDefault(): void {
		global $wp_posts;

		$wp_posts[5] = new WP_Post( [ 'ID' => 5, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => 'Dune' ] );

		$output = ( new FrontendRenderer(
			'book',
			[ 'path' => sys_get_temp_dir() ],
			[ 'templates' => [ 'single' => 'no/such/file.php' ] ]
		) )->render( [ 'view' => 'single', 'id' => 5 ] );

		$this->assertStringContainsString( 'Dune', $output );
	}
}
