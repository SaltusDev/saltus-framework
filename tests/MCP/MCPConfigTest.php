<?php

namespace Saltus\WP\Framework\Tests\MCP;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\MCPConfig;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\MCP\MCPConfig
 */
class MCPConfigTest extends TestCase {

	protected function setUp(): void {
		global $wp_filter_values, $wp_filters_registered;
		$wp_filter_values    = [];
		$wp_filters_registered = [];
	}

	protected function tearDown(): void {
		global $wp_filter_values, $wp_filters_registered;
		$wp_filter_values    = [];
		$wp_filters_registered = [];
	}

	public function testGetNamespaceReturnsDefault(): void {
		$this->assertSame( 'saltus-framework/v1', MCPConfig::get_namespace() );
	}

	public function testGetNamespaceReturnsFilteredValueViaWpFilterValues(): void {
		global $wp_filter_values;
		$wp_filter_values['saltus/framework/mcp/namespace'] = 'my-plugin/v2';

		$this->assertSame( 'my-plugin/v2', MCPConfig::get_namespace() );
	}

	public function testGetNamespaceReturnsFilteredValueViaAddFilter(): void {
		global $wp_filters_registered;
		$wp_filters_registered = [];

		add_filter(
			'saltus/framework/mcp/namespace',
			function (): string {
				return 'override/v3';
			}
		);

		$this->assertSame( 'override/v3', MCPConfig::get_namespace() );
	}

	public function testGetNamespaceFilterCallbackReceivesDefaultValue(): void {
		add_filter(
			'saltus/framework/mcp/namespace',
			function ( string $value ): string {
				return strtoupper( $value );
			}
		);

		$this->assertSame( 'SALTUS-FRAMEWORK/V1', MCPConfig::get_namespace() );
	}

	public function testGetAbilityCategoryReturnsDefault(): void {
		$category = MCPConfig::get_ability_category();

		$this->assertIsArray( $category );
		$this->assertSame( 'saltus-framework', $category['id'] );
		$this->assertSame( 'Saltus Framework', $category['label'] );
		$this->assertSame( 'Saltus Framework content modeling and administration abilities.', $category['description'] );
	}

	public function testGetAbilityCategoryReturnsFilteredValue(): void {
		global $wp_filter_values;
		$wp_filter_values['saltus/framework/mcp/ability_category'] = [
			'id'          => 'custom-plugin',
			'label'       => 'Custom Plugin',
			'description' => 'Custom abilities.',
		];

		$category = MCPConfig::get_ability_category();
		$this->assertSame( 'custom-plugin', $category['id'] );
		$this->assertSame( 'Custom Plugin', $category['label'] );
		$this->assertSame( 'Custom abilities.', $category['description'] );
	}

	public function testGetAbilityCategoryFilterReceivesDefault(): void {
		add_filter(
			'saltus/framework/mcp/ability_category',
			function ( array $category ): array {
				$category['label'] = 'Overridden Label';
				return $category;
			}
		);

		$category = MCPConfig::get_ability_category();
		$this->assertSame( 'saltus-framework', $category['id'] );
		$this->assertSame( 'Overridden Label', $category['label'] );
	}

	public function testGetAbilityCategoryFilterReturnsIncompleteArrayMergesWithDefaults(): void {
		global $wp_filter_values;
		$wp_filter_values['saltus/framework/mcp/ability_category'] = [
			'id' => 'custom-id',
		];

		$category = MCPConfig::get_ability_category();
		$this->assertSame( 'custom-id', $category['id'] );
		$this->assertSame( 'Saltus Framework', $category['label'] );
		$this->assertSame( 'Saltus Framework content modeling and administration abilities.', $category['description'] );
	}

	public function testGetAbilityPrefixReturnsDefault(): void {
		$this->assertSame( 'saltus/', MCPConfig::get_ability_prefix() );
	}

	public function testGetAbilityPrefixReturnsFilteredValue(): void {
		global $wp_filter_values;
		$wp_filter_values['saltus/framework/mcp/ability_prefix'] = 'custom/';

		$this->assertSame( 'custom/', MCPConfig::get_ability_prefix() );
	}

	public function testGetAbilityPrefixFilterReceivesDefault(): void {
		add_filter(
			'saltus/framework/mcp/ability_prefix',
			function ( string $prefix ): string {
				return 'new-' . $prefix;
			}
		);

		$this->assertSame( 'new-saltus/', MCPConfig::get_ability_prefix() );
	}
}
