<?php

namespace Saltus\WP\Framework\Tests\Unit\Infrastructure\Container;

use Saltus\WP\Framework\Infrastructure\Container\FailedToMakeInstance;
use Saltus\WP\Framework\Infrastructure\Container\GenericContainer;
use Saltus\WP\Framework\Infrastructure\Container\ReflectionInstantiator;
use Saltus\WP\Framework\Infrastructure\Container\ServiceContainer;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\Tests\TestCase;

/** @covers \Saltus\WP\Framework\Infrastructure\Container\ReflectionInstantiator */
class ReflectionInstantiatorTest extends TestCase {

	public function testResolvesNamedAndTypedDependenciesAndUsesDefaults(): void {
		$dependency = new ReflectionDependency();
		$service    = ( new ReflectionInstantiator() )->instantiate(
			ReflectionAutowireTarget::class,
			[ 'unrelated' => $dependency, 'label' => 'named' ]
		);

		$this->assertInstanceOf( ReflectionAutowireTarget::class, $service );
		$this->assertSame( $dependency, $service->dependency );
		$this->assertSame( 'named', $service->label );
		$this->assertSame( 10, $service->limit );
	}

	public function testResolvesPositionalDependencies(): void {
		$service = ( new ReflectionInstantiator() )->instantiate(
			ReflectionPositionalTarget::class,
			[ 'first', 22 ]
		);

		$this->assertSame( 'first', $service->label );
		$this->assertSame( 22, $service->limit );
	}

	public function testResolvesNullableAndVariadicParameters(): void {
		$service = ( new ReflectionInstantiator() )->instantiate(
			ReflectionVariadicTarget::class,
			[ 'optional' => null, 1 => 'one', 2 => 'two' ]
		);

		$this->assertNull( $service->optional );
		$this->assertSame( [ 'one', 'two' ], $service->values );
	}

	public function testPreservesLegacyDependenciesArrayConstructor(): void {
		$container = new ServiceContainer();
		$container->register( 'bag', ReflectionBagService::class, [ 'project' => 'demo' ] );

		$this->assertSame( [ 'project' => 'demo' ], $container->get( 'bag' )->dependencies );
	}

	public function testGenericContainerStillSupportsPositionalConstructors(): void {
		$container = new GenericContainer();
		$container->register( 'positional', ReflectionPositionalService::class, [ 'configured', 3 ] );

		$this->assertSame( 'configured', $container->get( 'positional' )->label );
		$this->assertSame( 3, $container->get( 'positional' )->limit );
	}

	public function testThrowsDescriptiveExceptionForUnresolvedRequiredParameter(): void {
		$this->expectException( FailedToMakeInstance::class );
		$this->expectExceptionCode( FailedToMakeInstance::UNRESOLVED_ARGUMENT );
		$this->expectExceptionMessage( 'required' );

		( new ReflectionInstantiator() )->instantiate( ReflectionRequiredTarget::class );
	}
}

class ReflectionDependency {
}

class ReflectionAutowireTarget {
	public ReflectionDependency $dependency;
	public string $label;
	public int $limit;

	public function __construct( ReflectionDependency $dependency, string $label, int $limit = 10 ) {
		$this->dependency = $dependency;
		$this->label      = $label;
		$this->limit      = $limit;
	}
}

class ReflectionPositionalTarget {
	public string $label;
	public int $limit;

	public function __construct( string $label, int $limit ) {
		$this->label = $label;
		$this->limit = $limit;
	}
}

class ReflectionVariadicTarget {
	public ?ReflectionDependency $optional;
	/** @var list<string> */
	public array $values;

	public function __construct( ?ReflectionDependency $optional = null, string ...$values ) {
		$this->optional = $optional;
		$this->values   = $values;
	}
}

class ReflectionRequiredTarget {
	public function __construct( string $required ) {
	}
}

class ReflectionBagService implements Service {
	/** @var array<mixed> */
	public array $dependencies;

	/** @param array<mixed> $dependencies */
	public function __construct( array $dependencies ) {
		$this->dependencies = $dependencies;
	}
}

class ReflectionPositionalService implements Service {
	public string $label;
	public int $limit;

	public function __construct( string $label, int $limit ) {
		$this->label = $label;
		$this->limit = $limit;
	}
}
