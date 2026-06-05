<?php
namespace Saltus\WP\Framework\MCP\Config;

class Config {

	private string $siteUrl;
	private string $username;
	private string $password;

	public function __construct( string $siteUrl, string $username, string $password ) {
		$this->siteUrl  = rtrim( $siteUrl, '/' );
		$this->username = $username;
		$this->password = $password;
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

	/**
	* @return array<string, string>
	*/
	public function toArray(): array {
		return [
			'site_url' => $this->siteUrl,
			'username' => $this->username,
			'password' => $this->password,
		];
	}

	/**
	* @param array<string, string> $data
	*/
	public static function fromArray( array $data ): self {
		return new self(
			$data['site_url'] ?? '',
			$data['username'] ?? '',
			$data['password'] ?? ''
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

		return new self( $siteUrl, $username, $password );
	}
}
