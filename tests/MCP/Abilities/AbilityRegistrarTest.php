<?php

namespace Saltus\WP\Framework\Tests\MCP\Abilities;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Abilities\AbilityRegistrar;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

class AbilityRegistrarTest extends TestCase {

	protected function setUp(): void {
		global $wp_abilities_registered, $wp_rest_request_log, $wp_current_user_can, $wp_taxonomy_objects;

		$wp_abilities_registered = [];
		$wp_rest_request_log     = [];
		$wp_current_user_can     = true;
		$wp_taxonomy_objects     = [];
	}

	public function testRegisterMapsAllMcpToolsToNativeAbilities(): void {
		global $wp_abilities_registered;

		$registered = ( new AbilityRegistrar() )->register();

		$this->assertCount( 16, $registered );
		$this->assertArrayHasKey( 'saltus/list-models', $wp_abilities_registered );
		$this->assertArrayHasKey( 'saltus/list-meta-fields', $wp_abilities_registered );
		$this->assertArrayHasKey( 'saltus/get-meta-fields', $wp_abilities_registered );
		$this->assertSame( 'List Models', $wp_abilities_registered['saltus/list-models']['label'] );
		$this->assertSame( 'list_models', $wp_abilities_registered['saltus/list-models']['meta']['mcp_tool'] );
		$this->assertArrayHasKey( 'input_schema', $wp_abilities_registered['saltus/list-models'] );
		$this->assertArrayHasKey( 'callback', $wp_abilities_registered['saltus/list-models'] );
		$this->assertArrayHasKey( 'execute_callback', $wp_abilities_registered['saltus/list-models'] );
		$this->assertArrayHasKey( 'permission_callback', $wp_abilities_registered['saltus/list-models'] );
	}

	public function testPermissionCallbackReusesWordPressCapabilityGate(): void {
		global $wp_abilities_registered, $wp_current_user_can;

		( new AbilityRegistrar() )->register();
		$permissionCallback = $wp_abilities_registered['saltus/list-models']['permission_callback'];

		$wp_current_user_can = true;
		$this->assertTrue( $permissionCallback() );

		$wp_current_user_can = false;
		$this->assertFalse( $permissionCallback() );
	}

	public function testCallbackDispatchesThroughRestRequest(): void {
		global $wp_abilities_registered, $wp_rest_request_log;

		( new AbilityRegistrar() )->register();

		$callback = $wp_abilities_registered['saltus/update-settings']['execute_callback'];
		$result   = $callback(
			[
				'post_type' => 'book',
				'settings'  => [ 'featured' => true ],
			]
		);

		$this->assertSame( [ 'ok' => true, 'route' => '/saltus-framework/v1/settings/book' ], $result );
		$this->assertSame( 'PUT', $wp_rest_request_log[0]['method'] );
		$this->assertSame( '/saltus-framework/v1/settings/book', $wp_rest_request_log[0]['route'] );
		$this->assertSame( [ 'featured' => true ], $wp_rest_request_log[0]['params'] );
	}

	public function testListPostsCallbackDispatchesTermFiltersThroughRestRequest(): void {
		global $wp_abilities_registered, $wp_rest_request_log, $wp_taxonomy_objects;

		$wp_taxonomy_objects['genre'] = (object) [
			'name'      => 'genre',
			'rest_base' => 'genres',
		];

		( new AbilityRegistrar() )->register();

		$callback = $wp_abilities_registered['saltus/list-posts']['execute_callback'];
		$result   = $callback(
			[
				'post_type' => 'movie',
				'per_page'  => 6,
				'orderby'   => 'date',
				'order'     => 'desc',
				'terms'     => [
					'genre' => [ 12 ],
				],
			]
		);

		$this->assertSame( [ 'ok' => true, 'route' => '/wp/v2/movie' ], $result );
		$this->assertSame( 'GET', $wp_rest_request_log[0]['method'] );
		$this->assertSame( '/wp/v2/movie', $wp_rest_request_log[0]['route'] );
		$this->assertSame( [ 12 ], $wp_rest_request_log[0]['query']['genres'] );
		$this->assertSame( 6, $wp_rest_request_log[0]['query']['per_page'] );
	}

	public function testListMetaFieldsCallbackDispatchesThroughRestRequest(): void {
		global $wp_abilities_registered, $wp_rest_request_log;

		( new AbilityRegistrar() )->register();

		$callback = $wp_abilities_registered['saltus/list-meta-fields']['execute_callback'];
		$result   = $callback();

		$this->assertSame( [ 'ok' => true, 'route' => '/saltus-framework/v1/meta' ], $result );
		$this->assertSame( 'GET', $wp_rest_request_log[0]['method'] );
		$this->assertSame( '/saltus-framework/v1/meta', $wp_rest_request_log[0]['route'] );
	}
}
