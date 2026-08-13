<?php

namespace Saltus\WP\Framework\Tests\Unit\Infrastructure\Container;

use Saltus\WP\Framework\Exception\SaltusFrameworkThrowable;
use Saltus\WP\Framework\Infrastructure\Container\FailedToMakeInstance;
use Saltus\WP\Framework\Tests\TestCase;

/**
 * The exception codes are documented as public so callers can branch on why
 * instantiation failed, which makes both the code and the message part of the
 * contract rather than incidental text.
 *
 * @covers \Saltus\WP\Framework\Infrastructure\Container\FailedToMakeInstance
 */
class FailedToMakeInstanceTest extends TestCase {

	public function testCircularReferenceNamesTheClassAndCarriesItsCode(): void {
		$exception = FailedToMakeInstance::for_circular_reference( 'Acme\\Loop' );

		$this->assertSame( FailedToMakeInstance::CIRCULAR_REFERENCE, $exception->getCode() );
		$this->assertStringContainsString( 'Acme\\Loop', $exception->getMessage() );
		$this->assertStringContainsString( 'Circular reference', $exception->getMessage() );
	}

	public function testUnresolvedInterfaceNamesTheInterfaceAndCarriesItsCode(): void {
		$exception = FailedToMakeInstance::for_unresolved_interface( 'Acme\\Contract' );

		$this->assertSame( FailedToMakeInstance::UNRESOLVED_INTERFACE, $exception->getCode() );
		$this->assertStringContainsString( 'Acme\\Contract', $exception->getMessage() );
	}

	public function testUnreflectableClassNamesTheClassAndCarriesItsCode(): void {
		$exception = FailedToMakeInstance::for_unreflectable_class( 'Acme\\Ghost' );

		$this->assertSame( FailedToMakeInstance::UNREFLECTABLE_CLASS, $exception->getCode() );
		$this->assertStringContainsString( 'Acme\\Ghost', $exception->getMessage() );
	}

	public function testUnresolvedArgumentNamesBothTheArgumentAndTheClass(): void {
		$exception = FailedToMakeInstance::for_unresolved_argument( 'project', 'Acme\\Service' );

		$this->assertSame( FailedToMakeInstance::UNRESOLVED_ARGUMENT, $exception->getCode() );
		$this->assertStringContainsString( 'project', $exception->getMessage() );
		$this->assertStringContainsString( 'Acme\\Service', $exception->getMessage() );
	}

	public function testUninstantiatedSharedInstanceNamesTheClassAndCarriesItsCode(): void {
		$exception = FailedToMakeInstance::for_uninstantiated_shared_instance( 'Acme\\Shared' );

		$this->assertSame( FailedToMakeInstance::UNINSTANTIATED_SHARED_INSTANCE, $exception->getCode() );
		$this->assertStringContainsString( 'Acme\\Shared', $exception->getMessage() );
	}

	public function testInvalidDelegateNamesTheClassAndCarriesItsCode(): void {
		$exception = FailedToMakeInstance::for_invalid_delegate( 'Acme\\Delegated' );

		$this->assertSame( FailedToMakeInstance::INVALID_DELEGATE, $exception->getCode() );
		$this->assertStringContainsString( 'Acme\\Delegated', $exception->getMessage() );
	}

	public function testCodesAreDistinctSoCallersCanBranchOnThem(): void {
		$codes = [
			FailedToMakeInstance::CIRCULAR_REFERENCE,
			FailedToMakeInstance::UNRESOLVED_INTERFACE,
			FailedToMakeInstance::UNREFLECTABLE_CLASS,
			FailedToMakeInstance::UNRESOLVED_ARGUMENT,
			FailedToMakeInstance::UNINSTANTIATED_SHARED_INSTANCE,
			FailedToMakeInstance::INVALID_DELEGATE,
		];

		$this->assertSame( $codes, array_unique( $codes ) );
	}

	/**
	 * Consumers catch the framework's own throwable interface to separate
	 * framework failures from unrelated runtime errors.
	 */
	public function testTheExceptionIsCatchableAsAFrameworkThrowable(): void {
		$exception = FailedToMakeInstance::for_circular_reference( 'Acme\\Loop' );

		$this->assertInstanceOf( SaltusFrameworkThrowable::class, $exception );
		$this->assertInstanceOf( \RuntimeException::class, $exception );
	}
}
