<?php
namespace Saltus\WP\Framework\MCP;

use Saltus\WP\Framework\MCP\Audit\AuditEntry;
use Saltus\WP\Framework\MCP\Audit\AuditLogger;
use Saltus\WP\Framework\MCP\Cache\InMemoryCache;
use Saltus\WP\Framework\MCP\Client\WordPressClient;
use Saltus\WP\Framework\MCP\Config\Config;
use Saltus\WP\Framework\MCP\Error\McpError;
use Saltus\WP\Framework\MCP\Prompts\PromptProvider;
use Saltus\WP\Framework\MCP\RateLimiter\RateLimiter;
use Saltus\WP\Framework\MCP\Resources\ResourceProvider;
use Saltus\WP\Framework\MCP\Support\Json;
use Saltus\WP\Framework\MCP\Tools\ToolFactory;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;
use Saltus\WP\Framework\MCP\Validation\Validator;

class Server {

	private WordPressClient $client;
	private ToolProvider $tool_provider;
	private ResourceProvider $resource_provider;
	private PromptProvider $prompt_provider;
	private ?RateLimiter $rate_limiter;
	private ?AuditLogger $audit_logger;

	public function __construct( Config $config ) {
		$cache = $config->is_cache_enabled() ? new InMemoryCache() : null;

		$this->client            = new WordPressClient( $config, $cache );
		$this->tool_provider     = ToolFactory::create_default_provider();
		$this->resource_provider = new ResourceProvider( $this->client );
		$this->prompt_provider   = new PromptProvider();
		$this->rate_limiter      = $config->is_rate_limit_enabled()
			? new RateLimiter( $config->get_rate_limit_max(), $config->get_rate_limit_window() )
			: null;
		$this->audit_logger      = $config->is_audit_enabled()
			? new AuditLogger( true, true, $config->get_audit_log_file() )
			: null;
	}

	/**
	* Run the MCP server: listen on stdin for JSON-RPC requests.
	*/
	public function run(): void {
		while ( true ) {
			$line = fgets( STDIN );

			if ( $line === false ) {
				break;
			}

			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}

			$request = json_decode( $line, true );
			if ( ! is_array( $request ) ) {
				continue;
			}

			$response = $this->handle_request( $request );

			if ( $response !== null ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-RPC responses must be emitted as raw JSON.
				echo Json::encode( $response ) . "\n";
				fflush( STDOUT );
			}
		}
	}

	/**
	* @param array<string, mixed> $request
	* @return array<string, mixed>|null
	*/
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- JSON-RPC method dispatch is intentionally explicit.
	private function handle_request( array $request ): ?array {
		$method = $request['method'] ?? '';
		$id     = $request['id'] ?? null;
		$params = $request['params'] ?? [];

		switch ( $method ) {
			case 'initialize':
				return $this->handle_initialize( $id );

			case 'initialized':
			case 'notifications/initialized':
				return null;

			case 'tools/list':
				return $this->handle_tools_list( $id );

			case 'tools/call':
				return $this->handle_tools_call( $id, $params );

			case 'resources/list':
				return $this->handle_resources_list( $id );

			case 'resources/read':
				return $this->handle_resources_read( $id, $params );

			case 'prompts/list':
				return $this->handle_prompts_list( $id );

			case 'prompts/get':
				return $this->handle_prompts_get( $id, $params );

			default:
				return $this->build_error(
					McpError::not_found( 'method', "{$method}" ),
					$id
				);
		}
	}

	/**
	* @return array<string, mixed>
	*/
	private function handle_initialize( mixed $id ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [
				'protocolVersion' => '2024-11-05',
				'capabilities'    => [
					'tools'     => [],
					'resources' => [],
				],
				'serverInfo'      => [
					'name'    => 'saltus-mcp-server',
					'version' => '0.1.0',
				],
			],
		];
	}

	/**
	* @return array<string, mixed>
	*/
	private function handle_tools_list( mixed $id ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [
				'tools' => $this->tool_provider->get_definitions(),
			],
		];
	}

	/**
	* @param array<string, mixed> $params
	* @return array<string, mixed>
	*/
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded -- Tool call orchestration keeps audit, rate-limit, validation, and execution in one flow.
	private function handle_tools_call( mixed $id, array $params ): array {
		$tool_name = $params['name'] ?? '';
		$arguments = $params['arguments'] ?? [];

		$entry = $this->audit_logger !== null ? new AuditEntry( $tool_name, $arguments ) : null;

		if ( $this->rate_limiter !== null ) {
			$rate_result = $this->rate_limiter->check( 'default' );
			if ( ! $rate_result->allowed ) {
				$entry?->complete( 'rate_limited', 'rate_limited', 'Rate limit exceeded' );
				$this->audit_logger?->record( $entry );
				return $this->build_error(
					McpError::from_rate_limit( $rate_result->retry_after ?? 1, $rate_result->remaining ),
					$id
				);
			}
		}

		$tool = $this->tool_provider->get( $tool_name );

		if ( ! $tool ) {
			$entry?->complete( 'error', 'tool_not_found', "Unknown tool: {$tool_name}" );
			$this->audit_logger?->record( $entry );
			return $this->build_error( McpError::not_found( 'tool', $tool_name ), $id );
		}

		$schema = $tool->get_parameters();
		$valid  = Validator::validate( $arguments, $schema );
		if ( ! $valid['valid'] ) {
			$entry?->complete( 'validation_error', 'invalid_params', implode( '; ', $valid['errors'] ) );
			$this->audit_logger?->record( $entry );
			return $this->build_error( McpError::from_validation( $valid['errors'] ), $id );
		}

		try {
			$result = $tool->handle( $arguments, $this->client );

			if ( isset( $result['code'] ) && isset( $result['message'] ) ) {
				$entry?->complete( 'error', $result['code'], $result['message'] );
				$this->audit_logger?->record( $entry );
				return $this->build_error( McpError::from_api_error( $result ), $id );
			}

			$entry?->complete( 'success' );
			$this->audit_logger?->record( $entry );

			return [
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => [
					'content' => [
						[
							'type' => 'text',
							'text' => Json::encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
						],
					],
				],
			];
		} catch ( \Throwable $e ) {
			$entry?->complete( 'exception', 'tool_exception', $e->getMessage() );
			$this->audit_logger?->record( $entry );
			return $this->build_error( McpError::from_throwable( $e ), $id );
		}
	}

	/**
	* @return array<string, mixed>
	*/
	private function handle_resources_list( mixed $id ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [
				'resources' => $this->resource_provider->get_definitions(),
			],
		];
	}

	/**
	* @param array<string, mixed> $params
	* @return array<string, mixed>
	*/
	private function handle_resources_read( mixed $id, array $params ): array {
		$uri = $params['uri'] ?? '';

		$result = $this->resource_provider->resolve( $uri );

		if ( ! $result ) {
			return $this->build_error( McpError::not_found( 'resource', $uri ), $id );
		}

		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		];
	}

	/**
	* @return array<string, mixed>
	*/
	private function handle_prompts_list( mixed $id ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [
				'prompts' => $this->prompt_provider->list(),
			],
		];
	}

	/**
	* @param array<string, mixed> $params
	* @return array<string, mixed>
	*/
	private function handle_prompts_get( mixed $id, array $params ): array {
		$name      = $params['name'] ?? '';
		$arguments = $params['arguments'] ?? [];

		$result = $this->prompt_provider->get( $name, $arguments );

		if ( ! $result ) {
			return $this->build_error( McpError::not_found( 'prompt', $name ), $id );
		}

		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		];
	}

	/**
	* @return array<string, mixed>
	*/
	private function build_error( McpError $error, mixed $id ): array {
		return [
			'jsonrpc' => '2.0',
			'isError' => true,
			'error'   => $error->to_array(),
			'id'      => $id,
		];
	}
}
