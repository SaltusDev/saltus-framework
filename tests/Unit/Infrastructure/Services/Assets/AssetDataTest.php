<?php

namespace Saltus\WP\Framework\Tests\Unit\Infrastructure\Services\Assets;

use Saltus\WP\Framework\Infrastructure\Services\Assets\AssetData;
use Saltus\WP\Framework\Tests\TestCase;

class AssetDataTest extends TestCase {
	public function testExposesConfiguredAssetData(): void {
		$data = new AssetData(
			'assets/js/admin.js',
			'SaltusAdmin',
			[
				'nonce' => 'abc123',
				'rest'  => '/wp-json/saltus-framework/v1',
			]
		);

		$this->assertSame( 'assets/js/admin.js', $data->get_source() );
		$this->assertSame( 'SaltusAdmin', $data->get_identifier() );
		$this->assertSame(
			[
				'nonce' => 'abc123',
				'rest'  => '/wp-json/saltus-framework/v1',
			],
			$data->get_data()
		);
	}

	public function testDataDefaultsToEmptyArray(): void {
		$data = new AssetData( 'assets/js/admin.js', 'SaltusAdmin' );

		$this->assertSame( [], $data->get_data() );
	}
}
