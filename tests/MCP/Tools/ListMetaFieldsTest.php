<?php

namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Client\WordPressClient;
use Saltus\WP\Framework\MCP\Tools\ListMetaFields;

class ListMetaFieldsTest extends TestCase {
	private ListMetaFields $tool;

	protected function setUp(): void {
		$this->tool = new ListMetaFields();
	}

	public function testGetName(): void {
		$this->assertSame( 'list_meta_fields', $this->tool->getName() );
	}

	public function testGetParametersAreEmpty(): void {
		$this->assertSame( [], $this->tool->getParameters() );
	}

	public function testHandleListsAllPostTypeMetaFields(): void {
		$client = $this->createMock( WordPressClient::class );
		$client->expects( $this->once() )
			->method( 'get' )
			->with( 'saltus-framework/v1/meta' )
			->willReturn(
				[
					'post_types' => [
						[
							'post_type' => 'book',
							'meta'      => [
								'isbn' => [ 'type' => 'text' ],
							],
						],
						[
							'post_type' => 'movie',
							'meta'      => [],
						],
					],
				]
			);

		$result = $this->tool->handle( [], $client );

		$this->assertCount( 2, $result['post_types'] );
		$this->assertSame( 'book', $result['post_types'][0]['post_type'] );
		$this->assertSame( [], $result['post_types'][1]['meta'] );
	}

	public function testHandlePassesThroughApiError(): void {
		$client = $this->createStub( WordPressClient::class );
		$client->method( 'get' )->willReturn(
			[
				'code'    => 'rest_forbidden',
				'message' => 'Forbidden',
			]
		);

		$result = $this->tool->handle( [], $client );

		$this->assertSame( 'rest_forbidden', $result['code'] );
	}
}
