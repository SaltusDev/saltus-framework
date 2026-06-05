<?php
namespace Saltus\WP\Framework\MCP\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Saltus\WP\Framework\MCP\Config\Config;

class WordPressClient {

	private Client $client;
	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
		$this->client = new Client([
			'base_uri' => $config->getApiUrl(),
			'auth'     => [ $config->getUsername(), $config->getPassword() ],
			'timeout'  => 30,
			'headers'  => [
				'Accept'     => 'application/json',
				'User-Agent' => 'saltus-mcp-server/1.0',
			],
		]);
	}

	public function get( string $endpoint, array $query = [] ): array {
		try {
			$response = $this->client->get( $endpoint, [ 'query' => $query ] );
			return $this->decode( $response->getBody()->getContents() );
		} catch ( GuzzleException $e ) {
			return $this->handleError( $e );
		}
	}

	public function post( string $endpoint, array $data = [] ): array {
		try {
			$response = $this->client->post( $endpoint, [ 'json' => $data ] );
			return $this->decode( $response->getBody()->getContents() );
		} catch ( GuzzleException $e ) {
			return $this->handleError( $e );
		}
	}

	public function put( string $endpoint, array $data = [] ): array {
		try {
			$response = $this->client->put( $endpoint, [ 'json' => $data ] );
			return $this->decode( $response->getBody()->getContents() );
		} catch ( GuzzleException $e ) {
			return $this->handleError( $e );
		}
	}

	public function delete( string $endpoint, array $query = [] ): array {
		try {
			$response = $this->client->delete( $endpoint, [ 'query' => $query ] );
			return $this->decode( $response->getBody()->getContents() );
		} catch ( GuzzleException $e ) {
			return $this->handleError( $e );
		}
	}

	public function getConfig(): Config {
		return $this->config;
	}

	private function decode( string $body ): array {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			trigger_error( 'WordPress API returned invalid JSON: ' . substr( $body, 0, 200 ), E_USER_WARNING );
			return [];
		}
		return $data;
	}

	private function handleError( GuzzleException $e ): array {
		if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
			$body = $e->getResponse()->getBody()->getContents();
			$data = json_decode( $body, true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		return [
			'code'    => 'mcp_error',
			'message' => $e->getMessage(),
		];
	}
}
