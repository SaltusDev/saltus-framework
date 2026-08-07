<?php

namespace Saltus\WP\Framework\WebMcp;

use Saltus\WP\Framework\Infrastructure\Services\FilterAwareTrait;

/**
 * Resolves who is calling a WebMCP tool, for rate limiting and audit.
 *
 * `/webmcp/execute` is reachable by unauthenticated visitors, so a single
 * shared counter lets one agent working through a multi-step task exhaust the
 * window for every other visitor on the site. Keying the window per client
 * confines that to the client causing it.
 *
 * Logged-in callers are keyed by user id, which survives a changing IP.
 * Everyone else is keyed by a salted hash of the remote address: enough to
 * separate clients, while keeping a raw visitor IP out of the audit table.
 *
 * Forwarded headers are deliberately not trusted. `X-Forwarded-For` is
 * caller-supplied, so honoring it by default would let an agent reset its own
 * limit by varying one header. Sites behind a proxy or CDN that terminates
 * traffic should resolve the real client through the filter below.
 * @api
 */
final class ClientIdentity {

	use FilterAwareTrait;

	/** Identifier prefix, so WebMCP rows stay separable from ability rows. */
	public const PREFIX = 'webmcp';

	/** Characters of the address hash kept in the identifier. */
	private const HASH_LENGTH = 32;

	/** Identifier used when no remote address is available. */
	private const UNKNOWN = 'unknown';

	/**
	 * Resolve the rate-limit and audit identifier for the current caller.
	 *
	 * The returned value is stable for one client across a window and carries
	 * no raw address, so it is safe to persist in the audit table.
	 *
	 * @return string Identifier of the form `webmcp:user:12` or `webmcp:ip:<hash>`.
	 */
	public function resolve(): string {
		$user_id = $this->current_user_id();
		if ( $user_id > 0 ) {
			$identifier = self::PREFIX . ':user:' . $user_id;
		} else {
			$identifier = self::PREFIX . ':ip:' . $this->address_hash();
		}

		/**
		 * Filter the resolved WebMCP client identifier.
		 *
		 * Use this behind a reverse proxy or CDN to key the window on the real
		 * client address, which the framework will not read from
		 * caller-supplied forwarding headers on its own.
		 *
		 * @param string $identifier Resolved identifier.
		 */
		$filtered = $this->filter( 'saltus/framework/webmcp/client_identifier', $identifier );

		if ( ! is_string( $filtered ) || trim( $filtered ) === '' ) {
			return $identifier;
		}

		return substr( trim( $filtered ), 0, 191 );
	}

	/**
	 * Resolve the current user id, tolerating a non-WordPress context.
	 */
	private function current_user_id(): int {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return 0;
		}

		return max( 0, (int) get_current_user_id() );
	}

	/**
	 * Hash the remote address so no raw visitor IP is stored.
	 *
	 * Salted with the site's auth salt where available, so identifiers cannot
	 * be reversed by hashing candidate addresses against a known site.
	 */
	private function address_hash(): string {
		$address = $this->remote_address();
		if ( $address === '' ) {
			return self::UNKNOWN;
		}

		return substr( hash( 'sha256', $this->salt() . '|' . $address ), 0, self::HASH_LENGTH );
	}

	/**
	 * Read and validate the remote address.
	 *
	 * Only `REMOTE_ADDR` is consulted: it is set by the web server rather than
	 * the caller. An unparseable value is discarded instead of being keyed on.
	 */
	private function remote_address(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$address = trim( function_exists( 'wp_unslash' ) ? (string) wp_unslash( $address ) : $address );

		if ( $address === '' ) {
			return '';
		}

		return filter_var( $address, FILTER_VALIDATE_IP ) === false ? '' : $address;
	}

	/**
	 * Resolve a hashing salt, falling back to a per-site constant.
	 */
	private function salt(): string {
		if ( defined( 'AUTH_SALT' ) && is_string( constant( 'AUTH_SALT' ) ) && constant( 'AUTH_SALT' ) !== '' ) {
			return (string) constant( 'AUTH_SALT' );
		}

		if ( function_exists( 'wp_salt' ) ) {
			$salt = wp_salt( 'nonce' );
			if ( $salt !== '' ) {
				return $salt;
			}
		}

		return 'saltus-webmcp';
	}
}
