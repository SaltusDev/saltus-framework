<?php

namespace Saltus\WP\Framework\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Rest\ExportController;
use WP_REST_Request;
use WP_Error;

require_once __DIR__ . '/functions.php';

class ExportControllerTest extends TestCase {
	private ExportController $controller;

	protected function setUp(): void {
		global $wpdb, $wp_rest_routes_registered, $wp_current_user_can, $wp_posts;
		$wp_rest_routes_registered = [];
		$wp_current_user_can       = true;
		$wp_posts                  = [];

		if ( ! isset( $GLOBALS['wpdb'] ) ) {
			$wpdb = new class implements \Saltus\WP\Framework\MCP\Audit\AuditDatabase {
				public string $prefix = 'wp_';
				public string $posts = 'wp_posts';
				/** @var list<array<string, mixed>> */
				public array $inserts = [];
				/** @var list<string> */
				public array $queries = [];

				public function prefix(): string {
					return $this->prefix;
				}

				public function insert( string $table, array $data, array $format = [] ): bool {
					$this->inserts[] = compact( 'table', 'data', 'format' );
					return true;
				}

				public function query( string $query ): bool {
					$this->queries[] = $query;
					return true;
				}

				public function prepare( string $query, ...$args ): string {
					foreach ( $args as $arg ) {
						$query = preg_replace( '/%[dsf]/', (string) $arg, $query, 1 );
					}
					return $query;
				}

				public function get_results( string $query, $output = null ) {
					return array_reverse( array_map( fn( array $insert ) => $insert['data'], $this->inserts ) );
				}

				public function get_charset_collate(): string {
					return '';
				}
			};
		}

		$this->controller = new ExportController();
	}

	public function testConstructorSetsNamespaceAndRestBase(): void {
		$this->assertSame( 'saltus-framework/v1', $this->getProtectedProperty( $this->controller, 'namespace' ) );
		$this->assertSame( 'export', $this->getProtectedProperty( $this->controller, 'rest_base' ) );
	}

	private function getProtectedProperty( object $object, string $property ) {
		$reflection = new \ReflectionProperty( $object, $property );
		$reflection->setAccessible( true );
		return $reflection->getValue( $object );
	}

	public function testRegisterRoutes(): void {
		global $wp_rest_routes_registered;

		$this->controller->register_routes();

		$this->assertCount( 1, $wp_rest_routes_registered );
		$this->assertSame( 'saltus-framework/v1', $wp_rest_routes_registered[0]['namespace'] );
		$this->assertStringContainsString( 'export', $wp_rest_routes_registered[0]['route'] );
		$this->assertSame( 'GET', $wp_rest_routes_registered[0]['args']['methods'] );
	}

	public function testGetItemPermissionsCheckReturnsTrueWhenAuthorized(): void {
		global $wp_current_user_can;
		$wp_current_user_can = true;

		$result = $this->controller->get_item_permissions_check( new WP_REST_Request() );
		$this->assertTrue( $result );
	}

	public function testGetItemPermissionsCheckReturnsErrorWhenUnauthorized(): void {
		global $wp_current_user_can;
		$wp_current_user_can = false;

		$result = $this->controller->get_item_permissions_check( new WP_REST_Request() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function testGetItemReturnsErrorWhenPostNotFound(): void {
		$request = new WP_REST_Request( [ 'post_id' => 999 ] );

		$result = $this->controller->get_item( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	public function testGetItemReturnsExportData(): void {
		global $wp_posts;
		$wp_posts[42] = new \WP_Post( [
			'ID'         => 42,
			'post_type'  => 'post',
			'post_title' => 'Exportable Post',
			'post_content' => 'Selected content',
		] );
		$wp_posts[43] = new \WP_Post( [
			'ID'         => 43,
			'post_type'  => 'post',
			'post_title' => 'Other Post',
			'post_content' => 'Other content',
		] );

		$request = new WP_REST_Request( [ 'post_id' => 42 ] );
		$result  = $this->controller->get_item( $request );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$response = rest_ensure_response( $result );
		$data     = $response->get_data();

		if ( is_array( $data ) ) {
			$this->assertArrayHasKey( 'post_id', $data );
			$this->assertArrayHasKey( 'post_type', $data );
			$this->assertArrayHasKey( 'post_title', $data );
			$this->assertArrayHasKey( 'wxr', $data );
			$this->assertSame( 42, $data['post_id'] );
			$this->assertSame( 'post', $data['post_type'] );
			$this->assertSame( 'Exportable Post', $data['post_title'] );
			$this->assertStringContainsString( 'WXR', $data['wxr'] );
			$this->assertStringContainsString( '<wp:post_id>42</wp:post_id>', $data['wxr'] );
			$this->assertStringContainsString( 'Selected content', $data['wxr'] );
			$this->assertStringNotContainsString( 'Other Post', $data['wxr'] );
			$this->assertStringNotContainsString( 'Other content', $data['wxr'] );
		}
	}

	public function testExportPostRemovesWordPressDownloadHeaders(): void {
		global $wp_posts;
		$wp_posts[42] = new \WP_Post( [
			'ID'           => 42,
			'post_type'    => 'post',
			'post_title'   => 'Exportable Post',
			'post_content' => 'Selected content',
		] );

		$exporter = new \Saltus\WP\Framework\Features\SingleExport\SaltusSingleExport( 'post' );
		$result   = $exporter->export_post( 42 );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( '<wp:post_id>42</wp:post_id>', $result['wxr'] );
		$this->assertSame( [], array_values( array_filter( headers_list(), static fn( string $header ): bool => preg_match( '/^Content-(Description|Disposition|Type):/i', $header ) === 1 ) ) );
	}
}
