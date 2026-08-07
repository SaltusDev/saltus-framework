<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\WebMcp\ClientIdentity;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\WebMcp\ClientIdentity
 */
class WebMcpClientIdentityTest extends TestCase {

	/** @var array<string, mixed> */
	private array $server = [];

	protected function setUp(): void {
		global $wp_filter_values, $wp_current_user_id;
		$wp_filter_values   = [];
		$wp_current_user_id = 0;
		$this->server       = $_SERVER;
	}

	protected function tearDown(): void {
		global $wp_filter_values, $wp_current_user_id;
		$wp_filter_values   = [];
		$wp_current_user_id = null;
		$_SERVER            = $this->server;
	}

	public function testLoggedInCallerIsKeyedByUserId(): void {
		global $wp_current_user_id;
		$wp_current_user_id = 42;

		$this->assertSame( 'webmcp:user:42', ( new ClientIdentity() )->resolve() );
	}

	public function testAnonymousCallerIsKeyedByHashedAddress(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		$identifier = ( new ClientIdentity() )->resolve();

		$this->assertStringStartsWith( 'webmcp:ip:', $identifier );
		$this->assertStringNotContainsString( '198.51.100.7', $identifier, 'A raw visitor IP must not reach the audit table.' );
	}

	public function testDistinctAddressesGetDistinctIdentifiers(): void {
		$identity = new ClientIdentity();

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$first                  = $identity->resolve();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$second                 = $identity->resolve();

		$this->assertNotSame(
			$first,
			$second,
			'A shared key would let one visitor exhaust the window for everyone.'
		);
	}

	public function testSameAddressIsStableAcrossCalls(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		$identity = new ClientIdentity();

		$this->assertSame(
			$identity->resolve(),
			$identity->resolve(),
			'An unstable key would reset the window on every request.'
		);
	}

	public function testForwardedHeadersAreIgnored(): void {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';

		$identity = new ClientIdentity();
		$spoofed  = $identity->resolve();

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$plain = $identity->resolve();

		$this->assertSame(
			$plain,
			$spoofed,
			'Honoring a caller-supplied header would let an agent reset its own limit.'
		);
	}

	public function testMissingAddressFallsBackWithoutFailing(): void {
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertSame( 'webmcp:ip:unknown', ( new ClientIdentity() )->resolve() );
	}

	public function testUnparseableAddressIsDiscarded(): void {
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';

		$this->assertSame( 'webmcp:ip:unknown', ( new ClientIdentity() )->resolve() );
	}

	public function testIdentifierIsFilterableForProxiedSites(): void {
		global $wp_filter_values;

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		$wp_filter_values['saltus/framework/webmcp/client_identifier'] = 'webmcp:ip:cdn-resolved';

		$this->assertSame( 'webmcp:ip:cdn-resolved', ( new ClientIdentity() )->resolve() );
	}

	public function testUnusableFilterValueFallsBackToResolvedIdentifier(): void {
		global $wp_filter_values;

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		$expected = ( new ClientIdentity() )->resolve();

		$wp_filter_values['saltus/framework/webmcp/client_identifier'] = '   ';

		$this->assertSame( $expected, ( new ClientIdentity() )->resolve() );
	}

	public function testIdentifierFitsTheAuditColumn(): void {
		global $wp_filter_values;

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		$wp_filter_values['saltus/framework/webmcp/client_identifier'] = str_repeat( 'x', 500 );

		// The audit table stores identifier as varchar(191).
		$this->assertLessThanOrEqual( 191, strlen( ( new ClientIdentity() )->resolve() ) );
	}

	public function testIpv6AddressesAreAccepted(): void {
		$_SERVER['REMOTE_ADDR'] = '2001:db8::8a2e:370:7334';

		$identifier = ( new ClientIdentity() )->resolve();

		$this->assertStringStartsWith( 'webmcp:ip:', $identifier );
		$this->assertNotSame( 'webmcp:ip:unknown', $identifier );
	}
}
