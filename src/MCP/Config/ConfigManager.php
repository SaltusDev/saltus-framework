<?php
namespace Saltus\WP\Framework\MCP\Config;

class ConfigManager {

	private const CONFIG_DIR  = '.saltus-mcp';
	private const CONFIG_FILE = 'config.json';

	private ?Config $config = null;

	public function load(): ?Config {
		$path = $this->getConfigPath();
		if ( ! file_exists( $path ) ) {
			return null;
		}

		$content = file_get_contents( $path );
		if ( $content === false ) {
			return null;
		}

		$data = json_decode( $content, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		// Handle encrypted password format
		if ( isset( $data['password_encrypted'] ) ) {
			$key     = $this->getEncryptionKey();
			$decoded = base64_decode( $data['password_encrypted'], true );
			if ( $decoded === false || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return null;
			}
			$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$password   = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
			sodium_memzero( $key );
			if ( $password === false ) {
				return null;
			}
			$data['password'] = $password;
		}

		// Legacy plaintext format support
		if ( ! isset( $data['password'] ) ) {
			return null;
		}

		$this->config = Config::fromArray( $data );
		return $this->config;
	}

	public function save( Config $config ): void {
		$dir = $this->getConfigDir();
		if ( ! is_dir( $dir ) ) {
			if ( ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
				throw new \RuntimeException( "Failed to create config directory: {$dir}" );
			}
		}

		$key        = $this->getEncryptionKey();
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $config->getPassword(), $nonce, $key );
		sodium_memzero( $key );

		$data = $config->toArray();
		unset( $data['password'] );
		$data['password_encrypted'] = base64_encode( $nonce . $ciphertext );

		$payload = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( file_put_contents( $this->getConfigPath(), $payload ) === false ) {
			throw new \RuntimeException( "Failed to write config to {$this->getConfigPath()}" );
		}

		chmod( $this->getConfigPath(), 0600 );
		$this->config = $config;
	}

	public function runWizard(): Config {
		echo "\n=== Saltus Framework MCP Server Setup ===\n\n";

		$siteUrl  = $this->prompt( 'WordPress site URL', 'https://example.com' );
		$username = $this->prompt( 'WordPress username (with Application Password permission)' );
		$password = $this->prompt( 'Application Password' );

		$config = new Config( $siteUrl, $username, $password );

		echo "\n[~] Testing connection...\n";

		try {
			$testUrl = rtrim( $siteUrl, '/' ) . '/wp-json/wp/v2/';
			$context = stream_context_create([
				'http' => [
					'method'  => 'GET',
					'header'  => 'Authorization: Basic ' . base64_encode( "{$username}:{$password}" ) . "\r\n",
					'timeout' => 10,
				],
			]);

			$result = @file_get_contents( $testUrl, false, $context );

			if ( $result === false ) {
				$error   = error_get_last();
				$message = $error['message'] ?? 'Unknown error';
				echo "[!] Warning: {$message}\n";
				echo "    Check the URL and credentials, then run: php bin/mcp-server --reconfigure\n";
			} else {
				echo "[✓] Connection successful!\n";
			}
		} catch ( \Throwable $e ) {
			echo "[!] Warning: Connection test failed: {$e->getMessage()}\n";
			echo "    Run: php bin/mcp-server --reconfigure\n";
		}

		try {
			$this->save( $config );
		} catch ( \RuntimeException $e ) {
			echo "[!] Error: {$e->getMessage()}\n";
			exit( 1 );
		}

		echo "[✓] Configuration saved to ~/{$this->getRelativePath()}\n\n";

		return $config;
	}

	public function getConfig(): ?Config {
		return $this->config;
	}

	private function prompt( string $label, string $default = '' ): string {
		if ( $default ) {
			echo "{$label} [{$default}]: ";
		} else {
			echo "{$label}: ";
		}

		$input = trim( fgets( STDIN ) );

		if ( $input === '' && $default !== '' ) {
			return $default;
		}

		return $input;
	}

	private function getConfigDir(): string {
		$home = getenv( 'HOME' ) ?: getenv( 'USERPROFILE' );
		if ( ! $home ) {
			$home = sys_get_temp_dir();
		}
		return $home . DIRECTORY_SEPARATOR . self::CONFIG_DIR;
	}

	private function getConfigPath(): string {
		return $this->getConfigDir() . DIRECTORY_SEPARATOR . self::CONFIG_FILE;
	}

	private function getRelativePath(): string {
		return self::CONFIG_DIR . '/' . self::CONFIG_FILE;
	}

	private function getEncryptionKey(): string {
		$dir     = $this->getConfigDir();
		$keyFile = $dir . DIRECTORY_SEPARATOR . 'key';

		if ( file_exists( $keyFile ) ) {
			return file_get_contents( $keyFile );
		}

		if ( ! is_dir( $dir ) ) {
			if ( ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
				throw new \RuntimeException( "Failed to create config directory: {$dir}" );
			}
		}

		$key = sodium_crypto_secretbox_keygen();
		file_put_contents( $keyFile, $key );
		chmod( $keyFile, 0600 );
		return $key;
	}
}
