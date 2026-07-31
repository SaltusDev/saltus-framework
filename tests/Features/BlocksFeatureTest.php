<?php
namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Blocks\BlockRenderer;
use Saltus\WP\Framework\Features\Blocks\SaltusBlocks;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use WP_Post;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\Features\Blocks\SaltusBlocks
 * @covers \Saltus\WP\Framework\Features\Blocks\BlockRenderer
 */
class BlocksFeatureTest extends TestCase {

	protected function setUp(): void {
		global $wp_blocks_registered, $wp_scripts_enqueued, $wp_styles_enqueued, $wp_scripts_localized, $wp_query_posts, $wp_post_meta;
		$wp_blocks_registered = [];
		$wp_scripts_enqueued  = [];
		$wp_styles_enqueued   = [];
		$wp_scripts_localized = [];
		$wp_query_posts       = [];
		$wp_post_meta         = [];
	}

	public function testNormalizeConfigSupportsBooleanAndIndependentViews(): void {
		$this->assertSame( [ 'list' => true, 'single' => true, 'templates' => [] ], SaltusBlocks::normalize_config( true ) );
		$this->assertSame( [ 'list' => true, 'single' => false, 'templates' => [] ], SaltusBlocks::normalize_config( [ 'list' => true ] ) );
		$this->assertSame( [ 'list' => false, 'single' => false, 'templates' => [] ], SaltusBlocks::normalize_config( false ) );
	}

	public function testDefinitionsAndRegistrationUseApiVersionThree(): void {
		global $wp_blocks_registered, $wp_scripts_localized;
		$blocks = new SaltusBlocks( $this->modeler( [ $this->model( 'book', true ) ] ), [ 'root_url' => 'https://example.com/assets' ] );

		$definitions = $blocks->definitions();
		$this->assertCount( 1, $definitions );
		$this->assertSame( [ 'list', 'single' ], array_keys( $definitions[0]['blocks'] ) );

		$blocks->register();
		$this->assertArrayHasKey( 'saltus/book-list', $wp_blocks_registered );
		$this->assertArrayHasKey( 'saltus/book-single', $wp_blocks_registered );
		$this->assertSame( 3, $wp_blocks_registered['saltus/book-list']['api_version'] );
		$this->assertSame( [ 'isbn' ], $wp_blocks_registered['saltus/book-list']['attributes']['metaFields']['default'] );
		$this->assertSame( 'saltusBlockDefinitions', $wp_scripts_localized[0]['object_name'] );
	}

	public function testDisabledAndTaxonomyModelsDoNotRegisterBlocks(): void {
		$blocks = new SaltusBlocks( $this->modeler( [ $this->model( 'book', false ), $this->model( 'genre', true, 'taxonomy' ) ] ) );
		$this->assertSame( [], $blocks->definitions() );
	}

	public function testRendererClampsQueryAndEscapesSingleOutput(): void {
		global $wp_query_posts, $wp_post_meta, $wp_posts;
		$post              = new WP_Post( [ 'ID' => 7, 'post_type' => 'book', 'post_status' => 'publish', 'post_title' => '<Book>', 'post_content' => '<p>Body</p>', 'post_excerpt' => 'Excerpt' ] );
		$post->post_date   = '2026-07-31 12:00:00';
		$wp_query_posts    = [ $post ];
		$wp_posts[7]       = $post;
		$wp_post_meta[7]   = [ 'isbn' => [ '123' ] ];
		$definition        = ( new SaltusBlocks( $this->modeler( [ $this->model( 'book', true ) ] ) ) )->definitions()[0];
		$renderer          = new BlockRenderer();

		$list = $renderer->render( 'list', $definition, [ 'postsToShow' => 500, 'orderBy' => 'unsafe', 'metaFields' => [ 'isbn' ] ] );
		$this->assertStringContainsString( '&lt;Book&gt;', $list );
		$this->assertStringContainsString( '123', $list );

		$single = $renderer->render( 'single', $definition, [ 'postId' => 7, 'metaFields' => [ 'isbn' ] ] );
		$this->assertStringContainsString( '&lt;Book&gt;', $single );
		$this->assertStringContainsString( '<p>Body</p>', $single );
	}

	private function modeler( array $models ): Modeler {
		$modeler = $this->createStub( Modeler::class );
		$map = [];
		foreach ( $models as $model ) {
			$map[ $model->get_name() ] = $model;
		}
		$modeler->method( 'get_models' )->willReturn( $map );
		return $modeler;
	}

	private function model( string $name, bool $blocks, string $type = 'post_type' ): Model {
		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( $name );
		$model->method( 'get_type' )->willReturn( $type );
		$model->method( 'get_config' )->willReturn( [ 'blocks' => $blocks ] );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true, 'mcp_tools' => true ] );
		$model->method( 'get_args' )->willReturn( [
			'labels' => [ 'name' => 'Books', 'singular_name' => 'Book' ],
			'meta'   => [ 'details' => [ 'fields' => [ [ 'id' => 'isbn', 'type' => 'text', 'title' => 'ISBN' ] ] ] ],
		] );
		return $model;
	}
}
