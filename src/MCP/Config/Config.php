<?php
namespace Saltus\WP\Framework\MCP\Config;

class Config {

	private const DEFAULTS = [
		'cache_enabled'       => true,
		'cache_ttl'           => 300,
		'cache_ttl_models'    => 600,
		'rate_limit_enabled'  => true,
		'rate_limit_max'      => 60,
		'rate_limit_window'   => 60,
		'audit_enabled'       => true,
		'audit_log_file'      => null,
	];

	/** @var array<string, mixed> */
	private array $values;

	/**
	 * @param array<string, mixed> $values
	 */
	public function __construct( array $values ) {
		$values['site_url'] = isset( $values['site_url'] )
			? rtrim( (string) $values['site_url'], '/' )
			: '';
		$values['username'] ??= '';
		$values['password'] ??= '';

		$this->values = array_merge( self::DEFAULTS, $values );
	}

	public function getSiteUrl(): string {
		return (string) ( $this->values['site_url'] ?? '' );
	}

	public function getApiUrl(): string {
		return $this->getSiteUrl() . '/wp-json/';
	}

	public function getUsername(): string {
		return (string) ( $this->values['username'] ?? '' );
	}

	public function getPassword(): string {
		return (string) ( $this->values['password'] ?? '' );
	}

	public function isCacheEnabled(): bool {
		return (bool) ( $this->values['cache_enabled'] ?? true );
	}

	public function getCacheTtl(): int {
		return (int) ( $this->values['cache_ttl'] ?? 300 );
	}

	public function getCacheTtlModels(): int {
		return (int) ( $this->values['cache_ttl_models'] ?? 600 );
	}

	public function isRateLimitEnabled(): bool {
		return (bool) ( $this->values['rate_limit_enabled'] ?? true );
	}

	public function getRateLimitMax(): int {
		return (int) ( $this->values['rate_limit_max'] ?? 60 );
	}

	public function getRateLimitWindow(): int {
		return (int) ( $this->values['rate_limit_window'] ?? 60 );
	}

	public function isAuditEnabled(): bool {
		return (bool) ( $this->values['audit_enabled'] ?? true );
	}

	public function getAuditLogFile(): ?string {
		$val = $this->values['audit_log_file'] ?? null;
		return $val !== null ? (string) $val : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return $this->values;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self {
		return new self( $data );
	}

	public static function fromEnv(): self {
		$siteUrl  = getenv( 'SALTUS_WP_URL' );
		$username = getenv( 'SALTUS_WP_USERNAME' );
		$password = getenv( 'SALTUS_WP_PASSWORD' );

		if ( $siteUrl === false || $username === false || $password === false ) {
			$missing = [];
			if ( $siteUrl === false ) {
				$missing[] = 'SALTUS_WP_URL';
			}
			if ( $username === false ) {
				$missing[] = 'SALTUS_WP_USERNAME';
			}
			if ( $password === false ) {
				$missing[] = 'SALTUS_WP_PASSWORD';
			}
			throw new \RuntimeException(
				'Missing required environment variable(s): '
				. implode( ', ', $missing )
				. '. Set them before running the MCP server.'
			);
		}

		$auditLogFile = getenv( 'SALTUS_AUDIT_LOG_FILE' );
		if ( $auditLogFile === false || $auditLogFile === '' ) {
			$auditLogFile = null;
		}

		return new self([
			'site_url'            => $siteUrl,
			'username'            => $username,
			'password'            => $password,
			'cache_enabled'       => filter_var( getenv( 'SALTUS_CACHE_ENABLED' ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true,
			'cache_ttl'           => (int) ( getenv( 'SALTUS_CACHE_TTL' ) ?: 300 ),
			'cache_ttl_models'    => (int) ( getenv( 'SALTUS_CACHE_TTL_MODELS' ) ?: 600 ),
			'rate_limit_enabled'  => filter_var( getenv( 'SALTUS_RATE_LIMIT_ENABLED' ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true,
			'rate_limit_max'      => (int) ( getenv( 'SALTUS_RATE_LIMIT_MAX' ) ?: 60 ),
			'rate_limit_window'   => (int) ( getenv( 'SALTUS_RATE_LIMIT_WINDOW' ) ?: 60 ),
			'audit_enabled'       => filter_var( getenv( 'SALTUS_AUDIT_ENABLED' ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true,
			'audit_log_file'      => $auditLogFile,
		]);
	}
}
