<?php
namespace Saltus\WP\Framework\Tests\MCP\Tools;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Tools\ListBlockModels;
use Saltus\WP\Framework\Rest\ModelRestPolicy;

require_once dirname( __DIR__, 2 ) . '/Rest/functions.php';

/** @covers \Saltus\WP\Framework\MCP\Tools\ListBlockModels */
class ListBlockModelsTest extends TestCase {
	public function testToolMetadataAndRequest(): void {
		$tool = new ListBlockModels();
		$this->assertSame( 'list_block_models', $tool->get_name() );
		$this->assertSame( [], $tool->get_parameters() );
		$this->assertTrue( $tool->is_cacheable() );
		$this->assertSame( ModelRestPolicy::CAPABILITY_BLOCKS, $tool->get_rest_capability()->get_capability() );
		$request = $tool->build_rest_request( [] );
		$this->assertSame( 'GET', $request->get_method() );
		$this->assertSame( '/saltus-framework/v1/blocks', $request->get_route() );
	}
}
