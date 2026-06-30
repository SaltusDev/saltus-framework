<?php
namespace Saltus\WP\Framework\MCP\Config;

class Config {

	private const DEFAULTS = [
		'cache_enabled'      => true,
		'cache_ttl'          => 300,
		'cache_ttl_models'   => 600,
		'rate_limit_enabled' => true,
		'rate_limit_max'     => 60,
		'rate_limit_window'  => 60,
		'audit_enabled'      => true,
		'audit_log_file'     => null,
	];

	/** @var array<string, mixed> */
	private array $values;

	/**
	 * @param array<string, mixed> $values
	 */
	public function __construct( array $values ) {
		$values['site_url']   = isset( $values['site_url'] )
			? rtrim( (string) $values['site_url'], '/' )
			: '';
		$values['username'] ??= '';
		$values['password'] ??= '';

		$this->values = array_merge( self::DEFAULTS, $values );
	}

	public function get_site_url(): string {
		return (string) ( $this->values['site_url'] ?? '' );
	}

	public function get_api_url(): string {
		return $this->get_site_url() . '/wp-json/';
	}

	public function get_username(): string {
		return (string) ( $this->values['username'] ?? '' );
	}

	public function get_password(): string {
		return (string) ( $this->values['password'] ?? '' );
	}

	public function is_cache_enabled(): bool {
		return (bool) ( $this->values['cache_enabled'] ?? true );
	}

	public function get_cache_ttl(): int {
		return (int) ( $this->values['cache_ttl'] ?? 300 );
	}

	public function get_cache_ttl_models(): int {
		return (int) ( $this->values['cache_ttl_models'] ?? 600 );
	}

	public function is_rate_limit_enabled(): bool {
		return (bool) ( $this->values['rate_limit_enabled'] ?? true );
	}

	public function get_rate_limit_max(): int {
		return (int) ( $this->values['rate_limit_max'] ?? 60 );
	}

	public function get_rate_limit_window(): int {
		return (int) ( $this->values['rate_limit_window'] ?? 60 );
	}

	public function is_audit_enabled(): bool {
		return (bool) ( $this->values['audit_enabled'] ?? true );
	}

	public function get_audit_log_file(): ?string {
		$val = $this->values['audit_log_file'] ?? null;
		return $val !== null ? (string) $val : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->values;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self( $data );
	}

	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Environment parsing is kept in one place for clarity.
	public static function from_env(): self {
		$site_url = getenv( 'SALTUS_WP_URL' );
		$username = getenv( 'SALTUS_WP_USERNAME' );
		$password = getenv( 'SALTUS_WP_PASSWORD' );

		if ( $site_url === false || $username === false || $password === false ) {
			$missing = [];
			if ( $site_url === false ) {
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
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is CLI diagnostics, not rendered output.
				. implode( ', ', $missing )
				. '. Set them before running the MCP server.'
			);
		}

		$audit_log_file = getenv( 'SALTUS_AUDIT_LOG_FILE' );
		if ( $audit_log_file === false || $audit_log_file === '' ) {
			$audit_log_file = null;
		}

		return new self([
			'site_url'           => $site_url,
			'username'           => $username,
			'password'           => $password,
			'cache_enabled'      => filter_var( getenv( 'SALTUS_CACHE_ENABLED' ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true,
			'cache_ttl'          => (int) ( getenv( 'SALTUS_CACHE_TTL' ) ?: 300 ),
			'cache_ttl_models'   => (int) ( getenv( 'SALTUS_CACHE_TTL_MODELS' ) ?: 600 ),
			'rate_limit_enabled' => filter_var( getenv( 'SALTUS_RATE_LIMIT_ENABLED' ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true,
			'rate_limit_max'     => (int) ( getenv( 'SALTUS_RATE_LIMIT_MAX' ) ?: 60 ),
			'rate_limit_window'  => (int) ( getenv( 'SALTUS_RATE_LIMIT_WINDOW' ) ?: 60 ),
			'audit_enabled'      => filter_var( getenv( 'SALTUS_AUDIT_ENABLED' ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true,
			'audit_log_file'     => $audit_log_file,
		]);
	}
}
