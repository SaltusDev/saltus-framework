<?php

namespace Saltus\WP\Framework\Tests\Unit\Models\Config;

use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Tests\TestCase;

class NoFileTest extends TestCase {
	public function testStoresArrayDataWithoutReadingAFile(): void {
		$config = new NoFile(
			[
				'type'     => 'post-type',
				'settings' => [
					'labels' => [
						'name' => 'Books',
					],
				],
			]
		);

		$this->assertTrue( $config->has( 'type' ) );
		$this->assertSame( 'post-type', $config->get( 'type' ) );
		$this->assertSame( 'Books', $config->get( 'settings.labels.name' ) );
	}

	public function testMissingValueReturnsDefault(): void {
		$config = new NoFile( [] );

		$this->assertFalse( $config->has( 'missing' ) );
		$this->assertSame( 'fallback', $config->get( 'missing', 'fallback' ) );
	}
}
