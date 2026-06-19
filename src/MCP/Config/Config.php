<?php
namespace Saltus\WP\Framework\MCP\Config;

class Config {

	private string $siteUrl;
	private string $username;
	private string $password;
	private bool $cacheEnabled;
	private int $cacheTtl;
	private int $cacheTtlModels;
	private bool $rateLimitEnabled;
	private int $rateLimitMax;
	private int $rateLimitWindow;
	private bool $auditEnabled;
	private ?string $auditLogFile;

	public function __construct(
		string $siteUrl,
		string $username,
		string $password,
		bool $cacheEnabled = true,
		int $cacheTtl = 300,
		int $cacheTtlModels = 600,
		bool $rateLimitEnabled = true,
		int $rateLimitMax = 60,
		int $rateLimitWindow = 60,
		bool $auditEnabled = true,
		?string $auditLogFile = null
	) {
		$this->siteUrl          = rtrim( $siteUrl, '/' );
		$this->username         = $username;
		$this->password         = $password;
		$this->cacheEnabled     = $cacheEnabled;
		$this->cacheTtl         = $cacheTtl;
		$this->cacheTtlModels   = $cacheTtlModels;
		$this->rateLimitEnabled = $rateLimitEnabled;
		$this->rateLimitMax     = $rateLimitMax;
		$this->rateLimitWindow  = $rateLimitWindow;
		$this->auditEnabled     = $auditEnabled;
		$this->auditLogFile     = $auditLogFile;
	}

	public function getSiteUrl(): string {
		return $this->siteUrl;
	}

	public function getApiUrl(): string {
		return $this->siteUrl . '/wp-json/';
	}

	public function getUsername(): string {
		return $this->username;
	}

	public function getPassword(): string {
		return $this->password;
	}

	public function isCacheEnabled(): bool {
		return $this->cacheEnabled;
	}

	public function getCacheTtl(): int {
		return $this->cacheTtl;
	}

	public function getCacheTtlModels(): int {
		return $this->cacheTtlModels;
	}

	public function isRateLimitEnabled(): bool {
		return $this->rateLimitEnabled;
	}

	public function getRateLimitMax(): int {
		return $this->rateLimitMax;
	}

	public function getRateLimitWindow(): int {
		return $this->rateLimitWindow;
	}

	public function isAuditEnabled(): bool {
		return $this->auditEnabled;
	}

	public function getAuditLogFile(): ?string {
		return $this->auditLogFile;
	}

	/**
	* @return array<string, mixed>
	*/
	public function toArray(): array {
		return [
			'site_url'            => $this->siteUrl,
			'username'            => $this->username,
			'password'            => $this->password,
			'cache_enabled'       => $this->cacheEnabled,
			'cache_ttl'           => $this->cacheTtl,
			'cache_ttl_models'    => $this->cacheTtlModels,
			'rate_limit_enabled'  => $this->rateLimitEnabled,
			'rate_limit_max'      => $this->rateLimitMax,
			'rate_limit_window'   => $this->rateLimitWindow,
			'audit_enabled'       => $this->auditEnabled,
			'audit_log_file'      => $this->auditLogFile,
		];
	}

	/**
	* @param array<string, mixed> $data
	*/
	public static function fromArray( array $data ): self {
		return new self(
			$data['site_url'] ?? '',
			$data['username'] ?? '',
			$data['password'] ?? '',
			(bool) ( $data['cache_enabled'] ?? true ),
			(int) ( $data['cache_ttl'] ?? 300 ),
			(int) ( $data['cache_ttl_models'] ?? 600 ),
			(bool) ( $data['rate_limit_enabled'] ?? true ),
			(int) ( $data['rate_limit_max'] ?? 60 ),
			(int) ( $data['rate_limit_window'] ?? 60 ),
			(bool) ( $data['audit_enabled'] ?? true ),
			isset( $data['audit_log_file'] ) ? (string) $data['audit_log_file'] : null
		);
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

		return new self(
			$siteUrl,
			$username,
			$password,
			filter_var( getenv( 'SALTUS_CACHE_ENABLED' ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true,
			(int) ( getenv( 'SALTUS_CACHE_TTL' ) ?: 300 ),
			(int) ( getenv( 'SALTUS_CACHE_TTL_MODELS' ) ?: 600 ),
			filter_var( getenv( 'SALTUS_RATE_LIMIT_ENABLED' ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true,
			(int) ( getenv( 'SALTUS_RATE_LIMIT_MAX' ) ?: 60 ),
			(int) ( getenv( 'SALTUS_RATE_LIMIT_WINDOW' ) ?: 60 ),
			filter_var( getenv( 'SALTUS_AUDIT_ENABLED' ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true,
			$auditLogFile
		);
	}
}
