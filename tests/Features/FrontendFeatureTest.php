<?php
namespace Saltus\WP\Framework\Tests\Features;

use Saltus\WP\Framework\Features\Frontend\SaltusFrontend;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Tests\TestCase;
use WP_Post;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/** @covers \Saltus\WP\Framework\Features\Frontend\SaltusFrontend */
class FrontendFeatureTest extends TestCase {

	protected function setUp(): void {
		global $wp_shortcodes_registered, $wp_query_posts, $wp_posts, $wp_post_meta, $wp_taxonomy_objects;
		SaltusFrontend::reset();
		$wp_shortcodes_registered = [];
		$wp_query_posts          = [];
		$wp_posts                = [];
		$wp_post_meta            = [];
		$wp_taxonomy_objects     = [];
	}

	public function testConfigAndShortcodeRegistration(): void {
		$this->assertSame( [ 'shortcode' => true, 'shortcode_alias' => '', 'templates' => [] ], SaltusFrontend::normalize_config( true ) );
		$this->assertSame( [ 'shortcode' => false, 'shortcode_alias' => '', 'templates' => [] ], SaltusFrontend::normalize_config( [ 'shortcode' => false ] ) );

		$frontend = new SaltusFrontend( 'book', [], [ 'shortcode_alias' => 'Books!' ] );
		$frontend->set_model( $this->model( 'book' ) );
		$frontend->process();

		global $wp_shortcodes_registered;
		$this->assertArrayHasKey( 'saltus_cpt', $wp_shortcodes_registered );
		$this->assertArrayHasKey( 'books', $wp_shortcodes_registered );
	}

	public function testListShortcodeSanitizesQueryAndRendersMeta(): void {
		global $wp_query_posts, $wp_post_meta, $wp_taxonomy_objects;
		$post                   = new WP_Post( [ 'ID' => 7, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => '<Book>', 'post_excerpt' => 'Summary' ] );
		$post->post_date        = '2026-07-31 12:00:00';
		$wp_query_posts         = [ $post ];
		$wp_post_meta[7]        = [ 'isbn' => [ '123' ] ];
		$wp_taxonomy_objects['genre'] = (object) [ 'object_type' => [ 'book' ] ];
		$frontend               = new SaltusFrontend( 'book' );
		$frontend->set_model( $this->model( 'book' ) );
		$frontend->process();

		$output = SaltusFrontend::shortcode( [ 'type' => 'book', 'limit' => 500, 'orderby' => 'unsafe', 'taxonomy' => 'genre', 'terms' => 'action, comedy' ] );
		$this->assertStringContainsString( '&lt;Book&gt;', $output );
		$this->assertStringContainsString( '123', $output );
		$this->assertStringContainsString( 'saltus-frontend__list', $output );
	}

	public function testSingleShortcodeRejectsWrongTypeAndUnpublishedPosts(): void {
		global $wp_posts;
		$wp_posts[7] = new WP_Post( [ 'ID' => 7, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => 'Book' ] );
		$wp_posts[8] = new WP_Post( [ 'ID' => 8, 'post_type' => 'book', 'post_status' => 'draft', 'post_title' => 'Draft' ] );
		$frontend    = new SaltusFrontend( 'book' );
		$frontend->set_model( $this->model( 'book' ) );
		$frontend->process();

		$this->assertStringContainsString( 'Book', SaltusFrontend::shortcode( [ 'type' => 'book', 'view' => 'single', 'id' => 7 ] ) );
		$this->assertSame( '', SaltusFrontend::shortcode( [ 'type' => 'movie', 'view' => 'single', 'id' => 7 ] ) );
		$this->assertSame( '', SaltusFrontend::shortcode( [ 'type' => 'book', 'view' => 'single', 'id' => 8 ] ) );
	}

	public function testUnknownTypeReturnsEmptyOutput(): void {
		$this->assertSame( '', SaltusFrontend::shortcode( [ 'type' => 'missing' ] ) );
	}

	private function model( string $name ): Model {
		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( $name );
		$model->method( 'get_args' )->willReturn( [
			'labels' => [ 'name' => 'Books', 'singular_name' => 'Book' ],
			'meta'   => [ 'details' => [ 'fields' => [ [ 'id' => 'isbn', 'type' => 'text', 'title' => 'ISBN' ] ] ] ],
		] );
		return $model;
	}
}
