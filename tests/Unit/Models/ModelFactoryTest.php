<?php

namespace Saltus\WP\Framework\Tests\Unit\Models;

use Saltus\WP\Framework\Infrastructure\Container\SimpleContainer;
use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Models\ModelFactory;
use Saltus\WP\Framework\Tests\TestCase;

class ModelFactoryTest extends TestCase {
	public function testCreateReturnsNullWhenTypeIsMissing(): void {
		$factory = new ModelFactory( new SimpleContainer(), [] );

		$this->assertNull( $factory->create( new NoFile( [] ) ) );
	}

	public function testCreateReturnsNullForUnknownType(): void {
		$factory = new ModelFactory( new SimpleContainer(), [] );

		$this->assertNull( $factory->create( new NoFile( [ 'type' => 'unknown' ] ) ) );
	}
}
