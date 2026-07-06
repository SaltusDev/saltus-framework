<?php
namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\UpdateMetaFields;
use Saltus\WP\Framework\Features\Meta\MetaFieldProvider;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use WP_REST_Request;
use WP_Error;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\Tools\UpdateMetaFields
 */
class UpdateMetaFieldsTest extends TestCase {

	private UpdateMetaFields $tool;
	private MetaFieldProvider $meta_field_provider;
	private Modeler $modeler;

	protected function setUp(): void {
		global $wp_posts, $wp_post_meta;
		$wp_posts     = [];
		$wp_post_meta = [];

		$this->meta_field_provider = $this->createMock( MetaFieldProvider::class );
		$this->modeler             = $this->createMock( Modeler::class );
		$this->tool                = new UpdateMetaFields( $this->meta_field_provider );
	}

	public function testBasicGetters(): void {
		$this->assertSame( 'update_meta_fields', $this->tool->get_name() );
		$this->assertStringContainsString( 'Update meta fields', $this->tool->get_description() );
		$this->assertArrayHasKey( 'post_id', $this->tool->get_parameters() );
		$this->assertArrayHasKey( 'post_type', $this->tool->get_parameters() );
		$this->assertArrayHasKey( 'meta', $this->tool->get_parameters() );
	}

	public function testBuildRestRequest(): void {
		$request = $this->tool->build_rest_request(
			[
				'post_type' => 'book',
				'post_id'   => 123,
				'meta'      => [ 'isbn' => '123-456' ],
			]
		);

		$this->assertInstanceOf( WP_REST_Request::class, $request );
		$this->assertSame( 'PUT', $request->get_method() );
		$this->assertSame( '/saltus-framework/v1/meta/book/123', $request->get_route() );
		$this->assertSame( [ 'meta' => [ 'isbn' => '123-456' ] ], $request->get_json_params() );
	}

	public function testUpdateMetaFieldsDirectlyReturnsErrorWhenPostNotFound(): void {
		global $wp_posts;
		$wp_posts = [];

		$result = $this->tool->update_meta_fields( $this->modeler, null, 'book', 123, [] );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
	}

	public function testUpdateMetaFieldsDirectlyUpdatesUnserializedFields(): void {
		global $wp_posts, $wp_post_meta;
		$wp_posts[123] = new \WP_Post( [ 'ID' => 123, 'post_type' => 'book' ] );

		$this->meta_field_provider->method( 'post_type_meta' )->willReturn(
			[
				'normalized' => [
					'rest_meta_keys' => [
						[ 'meta_key' => 'isbn', 'serialized' => false ],
					]
				]
			]
		);

		$result = $this->tool->update_meta_fields( $this->modeler, null, 'book', 123, [ 'isbn' => '111-222', 'invalid_key' => 'bad' ] );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( '111-222', get_post_meta( 123, 'isbn', true ) );
		$this->assertSame( '', get_post_meta( 123, 'invalid_key', true ) );
	}

	public function testUpdateMetaFieldsDirectlyMergesSerializedFields(): void {
		global $wp_posts, $wp_post_meta;
		$wp_posts[123] = new \WP_Post( [ 'ID' => 123, 'post_type' => 'point' ] );
		$wp_post_meta[123] = [
			'location_data' => [ [ 'latitude' => 50, 'longitude' => 60 ] ],
		];

		$this->meta_field_provider->method( 'post_type_meta' )->willReturn(
			[
				'normalized' => [
					'rest_meta_keys' => [
						[ 'meta_key' => 'location_data', 'serialized' => true ],
					]
				]
			]
		);

		$result = $this->tool->update_meta_fields( $this->modeler, null, 'point', 123, [ 'location_data' => [ 'latitude' => 55 ] ] );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( [ 'latitude' => 55, 'longitude' => 60 ], get_post_meta( 123, 'location_data', true ) );
	}
}
