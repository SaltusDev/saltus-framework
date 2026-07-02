<?php

namespace Saltus\WP\Framework\Tests\Unit\Infrastructure\Container;

use Saltus\WP\Framework\Infrastructure\Container\FailedToMakeInstance;
use Saltus\WP\Framework\Infrastructure\Container\GenericContainer;
use Saltus\WP\Framework\Infrastructure\Container\Invalid;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\Tests\TestCase;

class GenericContainerTest extends TestCase {
	public function testRegisterInstantiatesServiceWithDependencies(): void {
		$container = new GenericContainer();

		$container->register( 'dependent', GenericContainerDependentService::class, [ 'configured' ] );

		$service = $container->get( 'dependent' );

		$this->assertInstanceOf( GenericContainerDependentService::class, $service );
		$this->assertSame( 'configured', $service->value() );
	}

	public function testRegisterRejectsUnknownClass(): void {
		$container = new GenericContainer();

		$this->expectException( FailedToMakeInstance::class );
		$this->expectExceptionCode( FailedToMakeInstance::UNREFLECTABLE_CLASS );

		$container->register( 'missing', 'Saltus\\WP\\Framework\\Tests\\MissingService' );
	}

	public function testRegisterRejectsNonServiceObjects(): void {
		$container = new GenericContainer();

		$this->expectException( Invalid::class );

		$container->register( 'invalid', GenericContainerInvalidService::class );
	}
}

class GenericContainerDependentService implements Service {
	private string $value;

	public function __construct( string $value ) {
		$this->value = $value;
	}

	public function value(): string {
		return $this->value;
	}
}

class GenericContainerInvalidService {
}
