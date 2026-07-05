<?php

namespace Saltus\WP\Framework\Tests\Integration;

use Saltus\WP\Framework\Infrastructure\Container\ContainerAssembler;
use Saltus\WP\Framework\Infrastructure\Container\GenericContainer;
use Saltus\WP\Framework\Infrastructure\Container\ServiceContainer;
use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Conditional;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\Tests\TestCase;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

class ContainerIntegrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		global $wp_is_admin;
		$wp_is_admin = true;
	}

	protected function tearDown(): void {
		global $wp_is_admin;
		$wp_is_admin = false;

		parent::tearDown();
	}

	public function testAssemblerCreatesGenericContainer(): void {
		$assembler = new ContainerAssembler();
		$container = $assembler->create( GenericContainer::class );

		$this->assertInstanceOf( GenericContainer::class, $container );
	}

	public function testAssemblerCreatesServiceContainer(): void {
		$assembler = new ContainerAssembler();
		$container = $assembler->create( ServiceContainer::class );

		$this->assertInstanceOf( ServiceContainer::class, $container );
	}

	public function testAssemblerThrowsForNonExistentClass(): void {
		$assembler = new ContainerAssembler();

		$this->expectException( \InvalidArgumentException::class );
		$assembler->create( 'NonExistentContainerClass' );
	}

	public function testGenericContainerPutAndCount(): void {
		$assembler = new ContainerAssembler();
		$container = $assembler->create( GenericContainer::class );

		$container->put( 'key_a', new GenericContainerTestService() );
		$container->put( 'key_b', new GenericContainerTestService() );

		$this->assertCount( 2, $container );
		$this->assertTrue( $container->has( 'key_a' ) );
		$this->assertTrue( $container->has( 'key_b' ) );
	}

	public function testServiceContainerRegistersFeatures(): void {
		$assembler = new ContainerAssembler();
		$container = $assembler->create( ServiceContainer::class );

		$container->register( 'simple', SimpleService::class, [] );
		$container->register( 'registerable', RegisterableService::class, [] );

		$this->assertCount( 2, $container );
		$this->assertInstanceOf( SimpleService::class, $container->get( 'simple' ) );
		$this->assertInstanceOf( RegisterableService::class, $container->get( 'registerable' ) );
	}

	public function testServiceContainerSkipsUnneededConditionalServices(): void {
		global $wp_is_admin;
		$wp_is_admin = false;

		$assembler = new ContainerAssembler();
		$container = $assembler->create( ServiceContainer::class );

		$container->register( 'admin_only', ConditionalService::class, [] );

		$this->assertCount( 0, $container );
	}

	public function testServiceContainerTriggersRegisterOnRegisterableServices(): void {
		$assembler = new ContainerAssembler();
		$container = $assembler->create( ServiceContainer::class );

		$container->register( 'with_register', RegisterableService::class, [] );

		global $wp_registered_items;
		$this->assertContains( 'with_register', $wp_registered_items );
	}
}

class GenericContainerTestService implements Service {
}

class SimpleService implements Service {
}

class RegisterableService implements Service, Registerable {
	public function register() {
		global $wp_registered_items;
		$wp_registered_items[] = 'with_register';
	}
}

class ConditionalService implements Service, Conditional {
	public static function is_needed(): bool {
		return \is_admin();
	}
}
