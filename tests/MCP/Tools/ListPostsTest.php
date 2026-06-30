<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Client\WordPressClient;
use Saltus\WP\Framework\MCP\Tools\ListPosts;

class ListPostsTest extends TestCase {

	private ListPosts $tool;

	protected function setUp(): void {
		$this->tool = new ListPosts();
	}

	public function testGetParametersIncludesTermFilters(): void {
		$params = $this->tool->getParameters();

		$this->assertArrayHasKey( 'terms', $params );
		$this->assertSame( 'object', $params['terms']['type'] );
	}

	public function testHandlePassesTaxonomyTermsToRestQuery(): void {
		$client = $this->createMock( WordPressClient::class );
		$client->expects( $this->exactly( 3 ) )
			->method( 'get' )
			->willReturnCallback(
				function ( string $endpoint, array $query = [] ): array {
					if ( $endpoint === 'wp/v2/types' ) {
						return [
							'movie' => [
								'rest_base' => 'movies',
							],
						];
					}

					if ( $endpoint === 'wp/v2/taxonomies' ) {
						return [
							'genre' => [
								'rest_base' => 'genres',
							],
						];
					}

					$this->assertSame( 'wp/v2/movies', $endpoint );
					$this->assertSame( [ 12 ], $query['genres'] );
					$this->assertSame( 6, $query['per_page'] );
					$this->assertSame( 'date', $query['orderby'] );
					$this->assertSame( 'desc', $query['order'] );

					return [
						[
							'id'     => 42,
							'title'  => [ 'rendered' => 'Solar Drift' ],
							'type'   => 'movie',
							'status' => 'publish',
						],
					];
				}
			);

		$result = $this->tool->handle(
			[
				'post_type' => 'movie',
				'per_page'  => 6,
				'orderby'   => 'date',
				'order'     => 'desc',
				'terms'     => [
					'genre' => [ 12 ],
				],
			],
			$client
		);

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'Solar Drift', $result['posts'][0]['title'] );
	}
}
