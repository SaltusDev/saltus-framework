<?php

namespace Saltus\WP\Framework\Tests\Unit\Infrastructure\Container;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Infrastructure\Container\FailedToMakeInstance;
use Saltus\WP\Framework\Infrastructure\Container\Invalid;
use Saltus\WP\Framework\Infrastructure\Container\ServiceContainer;
use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Actionable;
use Saltus\WP\Framework\Infrastructure\Service\Conditional;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\Infrastructure\Services\Assets\HasAssets;

require_once dirname( __DIR__, 3 ) . '/Rest/functions.php';
require_once __DIR__ . '/service-doubles.php';

/**
 * ServiceContainer instantiates services and wires them into WordPress based on
 * the interfaces they implement.
 *
 * The interface dispatch is the whole point of the class: a service that
 * implements Registerable must have register() called, an Actionable one must be
 * deferred to a hook, and a Conditional one must not be built at all when it
 * says it is not needed.
 *
 * @covers \Saltus\WP\Framework\Infrastructure\Container\ServiceContainer
 * @covers \Saltus\WP\Framework\Infrastructure\Container\Invalid
 */
class ServiceContainerTest extends TestCase {

	private ServiceContainer $container;

	protected function setUp(): void {
		global $wp_actions_registered;

		$wp_actions_registered = [];
		$this->container       = new ServiceContainer();

		Doubles\CountingService::$registered  = 0;
		Doubles\CountingService::$actions_run = 0;
		Doubles\UnneededService::$built       = 0;
		Doubles\AssetService::$lists_set      = 0;
	}

	public function testPutAndGetRoundTripAService(): void {
		$service = new Doubles\PlainService();

		$this->container->put( 'plain', $service );

		$this->assertTrue( $this->container->has( 'plain' ) );
		$this->assertSame( $service, $this->container->get( 'plain' ) );
	}

	public function testHasIsFalseForAnUnknownId(): void {
		$this->assertFalse( $this->container->has( 'nope' ) );
	}

	public function testGettingAnUnknownIdThrows(): void {
		$this->expectException( Invalid::class );
		$this->expectExceptionMessage( 'The item ID "nope" is not recognized and cannot be retrieved.' );

		$this->container->get( 'nope' );
	}

	public function testRegisterInstantiatesAndStoresTheService(): void {
		$this->container->register( 'plain', Doubles\PlainService::class, [] );

		$this->assertInstanceOf( Doubles\PlainService::class, $this->container->get( 'plain' ) );
	}

	public function testRegisterPassesConstructorDependencies(): void {
		$this->container->register( 'needs', Doubles\NeedsDependencies::class, [ 'project' => 'saltus' ] );

		$this->assertSame( [ 'project' => 'saltus' ], $this->container->get( 'needs' )->dependencies );
	}

	public function testRegisterableServicesAreRegisteredExactlyOnce(): void {
		$this->container->register( 'counting', Doubles\CountingService::class, [] );

		$this->assertSame( 1, Doubles\CountingService::$registered );
	}

	/**
	 * A Conditional service that reports it is not needed must never be built:
	 * the point is to avoid loading admin-only code on the front end.
	 */
	public function testConditionalServicesThatAreNotNeededAreSkipped(): void {
		$this->container->register( 'unneeded', Doubles\UnneededService::class, [] );

		$this->assertFalse( $this->container->has( 'unneeded' ) );
		$this->assertSame( 0, Doubles\UnneededService::$built );
	}

	public function testConditionalServicesThatAreNeededAreBuilt(): void {
		$this->container->register( 'needed', Doubles\NeededService::class, [] );

		$this->assertTrue( $this->container->has( 'needed' ) );
	}

	/**
	 * Actionable services are deferred to a hook rather than run immediately, so
	 * they observe WordPress state that only exists after init.
	 */
	public function testActionableServicesAreDeferredToAHook(): void {
		global $wp_actions_registered;

		$this->container->register( 'counting', Doubles\CountingService::class, [] );

		$this->assertSame( 0, Doubles\CountingService::$actions_run, 'add_action must not run during registration.' );

		$hook = $this->find_action( 'init' );
		$this->assertNotNull( $hook, 'An Actionable service must be hooked.' );
		$this->assertSame( 1, $hook['priority'], 'The default priority is 1.' );

		( $hook['callback'] )();
		$this->assertSame( 1, Doubles\CountingService::$actions_run );
	}

	public function testAServiceCanOverrideItsHookAndPriority(): void {
		$this->container->register( 'custom', Doubles\CustomHookService::class, [] );

		$hook = $this->find_action( 'admin_init' );

		$this->assertNotNull( $hook, 'The service filter() must select the hook.' );
		$this->assertSame( 25, $hook['priority'] );
	}

	public function testAssetBearingServicesAreWiredToBothEnqueueHooks(): void {
		$this->container->register( 'assets', Doubles\AssetService::class, [] );

		$this->assertSame( 1, Doubles\AssetService::$lists_set, 'set_assets_list() runs at registration.' );
		$this->assertNotNull( $this->find_action( 'admin_enqueue_scripts' ) );
		$this->assertNotNull( $this->find_action( 'wp_enqueue_scripts' ), 'Assets must reach the front end too.' );
	}

	public function testRegisteringAMissingClassThrows(): void {
		$this->expectException( FailedToMakeInstance::class );
		$this->expectExceptionCode( FailedToMakeInstance::UNREFLECTABLE_CLASS );

		$this->container->register( 'ghost', '\\Saltus\\Nope\\NotAClass', [] );
	}

	/**
	 * The container only manages Services. Something that is merely a class must
	 * be rejected rather than stored, or get() would return a non-Service.
	 */
	public function testRegisteringANonServiceThrows(): void {
		$this->expectException( Invalid::class );

		$this->container->register( 'not-a-service', Doubles\NotAService::class, [] );
	}

	public function testRegisteringAnAbstractClassThrows(): void {
		$this->expectException( FailedToMakeInstance::class );
		$this->expectExceptionCode( FailedToMakeInstance::UNRESOLVED_INTERFACE );

		$this->container->register( 'abstract', Doubles\AbstractService::class, [] );
	}

	/**
	 * instantiate_unconditionally() exists so the framework can inspect a service
	 * for interfaces (RestRouteProvider, ToolContributor) without honouring
	 * is_needed() and without storing it.
	 */
	public function testInstantiateUnconditionallyBypassesTheConditionalGate(): void {
		$service = $this->container->instantiate_unconditionally( Doubles\UnneededService::class, [] );

		$this->assertInstanceOf( Doubles\UnneededService::class, $service );
		$this->assertSame( 1, Doubles\UnneededService::$built );
	}

	public function testInstantiateUnconditionallyDoesNotStoreOrRegisterTheService(): void {
		$this->container->instantiate_unconditionally( Doubles\CountingService::class, [] );

		$this->assertFalse( $this->container->has( Doubles\CountingService::class ) );
		$this->assertSame( 0, Doubles\CountingService::$registered, 'Inspection must not trigger side effects.' );
	}

	public function testTheContainerIsIterableAndCountable(): void {
		$this->container->register( 'a', Doubles\PlainService::class, [] );
		$this->container->register( 'b', Doubles\CountingService::class, [] );

		$this->assertCount( 2, $this->container );
		$this->assertSame( [ 'a', 'b' ], array_keys( $this->container->getArrayCopy() ) );
	}

	public function testInvalidReportsTheClassNameForAnObject(): void {
		$exception = Invalid::from( new Doubles\NotAService() );

		$this->assertStringContainsString( Doubles\NotAService::class, $exception->getMessage() );
	}

	public function testInvalidReportsTheStringForAScalar(): void {
		$this->assertStringContainsString( 'project', Invalid::from( 'project' )->getMessage() );
	}

	/** @return array<string, mixed>|null */
	private function find_action( string $hook_name ): ?array {
		global $wp_actions_registered;

		foreach ( (array) $wp_actions_registered as $action ) {
			if ( $action['hook_name'] === $hook_name ) {
				return $action;
			}
		}

		return null;
	}
}
