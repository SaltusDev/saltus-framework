<?php
namespace Saltus\WP\Framework\MCP\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Saltus\WP\Framework\MCP\Cache\CacheInterface;
use Saltus\WP\Framework\MCP\Config\Config;
use Saltus\WP\Framework\MCP\Support\Json;

class WordPressClient {

	private Client $client;
	private Config $config;
	private ?CacheInterface $cache;
	private int $default_ttl;

	public function __construct( Config $config, ?CacheInterface $cache = null ) {
		$this->config      = $config;
		$this->cache       = $cache;
		$this->default_ttl = $config->get_cache_ttl();

		$handler = HandlerStack::create();
		$handler->push( Middleware::retry(
			function ( int $retries, RequestInterface $request, ?ResponseInterface $response = null, ?\Throwable $exception = null ): bool {
				if ( $retries >= 4 ) {
					return false;
				}

				if ( $response !== null ) {
					$status = $response->getStatusCode();
					return in_array( $status, [ 429, 500, 502, 503, 504 ], true );
				}

				if ( $exception !== null ) {
					return $exception instanceof \GuzzleHttp\Exception\ConnectException;
				}

				return false;
			},
			function ( int $retries ): int {
				$delay = (int) ( 1000 * pow( 2, $retries - 1 ) );
				return min( $delay, 8000 );
			}
		) );

		$this->client = new Client([
			'handler'  => $handler,
			'base_uri' => $config->get_api_url(),
			'auth'     => [ $config->get_username(), $config->get_password() ],
			'timeout'  => 30,
			'headers'  => [
				'Accept'     => 'application/json',
				'User-Agent' => 'saltus-mcp-server/1.0',
			],
		]);
	}

	/**
	* @param array<string, mixed> $query
	* @return array<string, mixed>
	*/
	public function get( string $endpoint, array $query = [] ): array {
		if ( $this->cache !== null ) {
			$key    = $this->build_cache_key( 'GET', $endpoint, $query );
			$cached = $this->cache->get( $key );
			if ( $cached !== null ) {
				return $cached;
			}
		}

		try {
			$response = $this->client->get( $endpoint, [ 'query' => $query ] );
			$data     = $this->decode( $response->getBody()->getContents() );

			if ( $this->cache !== null && ! isset( $data['code'] ) ) {
				$this->cache->set( $key, $data, $this->default_ttl );
			}

			return $data;
		} catch ( GuzzleException $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	* @param array<string, mixed> $data
	* @return array<string, mixed>
	*/
	public function post( string $endpoint, array $data = [] ): array {
		try {
			$response = $this->client->post( $endpoint, [ 'json' => $data ] );
			$this->invalidate_cache();
			return $this->decode( $response->getBody()->getContents() );
		} catch ( GuzzleException $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	* @param array<string, mixed> $data
	* @return array<string, mixed>
	*/
	public function put( string $endpoint, array $data = [] ): array {
		try {
			$response = $this->client->put( $endpoint, [ 'json' => $data ] );
			$this->invalidate_cache();
			return $this->decode( $response->getBody()->getContents() );
		} catch ( GuzzleException $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	* @param array<string, mixed> $query
	* @return array<string, mixed>
	*/
	public function delete( string $endpoint, array $query = [] ): array {
		try {
			$response = $this->client->delete( $endpoint, [ 'query' => $query ] );
			$this->invalidate_cache();
			return $this->decode( $response->getBody()->getContents() );
		} catch ( GuzzleException $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	* @param array<string, mixed> $query
	*/
	private function build_cache_key( string $method, string $endpoint, array $query = [] ): string {
		return hash( 'sha256', strtoupper( $method ) . ':' . $endpoint . ':' . Json::encode( $query ) );
	}

	private function invalidate_cache(): void {
		$this->cache?->clear();
	}

	public function get_config(): Config {
		return $this->config;
	}

	/**
	* @return array<string, mixed>
	*/
	private function decode( string $body ): array {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return [];
		}
		return $data;
	}

	/**
	* @return array<string, mixed>
	*/
	private function handle_error( GuzzleException $e ): array {
		if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
			$body = $e->getResponse()->getBody()->getContents();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_decode -- Decoding API responses must work outside WordPress.
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
