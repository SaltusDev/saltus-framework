<?php

namespace Saltus\WP\Framework\Tests\Unit\Infrastructure\Container;

use Saltus\WP\Framework\Infrastructure\Container\Invalid;
use Saltus\WP\Framework\Infrastructure\Container\SimpleContainer;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\Tests\TestCase;

class SimpleContainerTest extends TestCase {
	public function testPutStoresServiceForLaterRetrieval(): void {
		$container = new SimpleContainer();
		$service   = new SimpleContainerTestService();

		$container->put( 'example', $service );

		$this->assertTrue( $container->has( 'example' ) );
		$this->assertSame( $service, $container->get( 'example' ) );
	}

	public function testGetThrowsForMissingService(): void {
		$container = new SimpleContainer();

		$this->expectException( Invalid::class );
		$this->expectExceptionMessage( 'missing' );

		$container->get( 'missing' );
	}
}

class SimpleContainerTestService implements Service {
}
