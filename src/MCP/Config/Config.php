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

	public function toArray(): array {
		return [
			'site_url' => $this->siteUrl,
			'username' => $this->username,
			'password' => $this->password,
		];
	}

	public static function fromArray( array $data ): self {
		return new self(
			$data['site_url'] ?? '',
			$data['username'] ?? '',
			$data['password'] ?? ''
		);
	}
}
