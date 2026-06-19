<?php
namespace Saltus\WP\Framework\MCP;

use Saltus\WP\Framework\MCP\Client\WordPressClient;
use Saltus\WP\Framework\MCP\Config\Config;
use Saltus\WP\Framework\MCP\Prompts\PromptProvider;
use Saltus\WP\Framework\MCP\Resources\ResourceProvider;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;
use Saltus\WP\Framework\MCP\Tools\DuplicatePost;
use Saltus\WP\Framework\MCP\Tools\ExportPost;
use Saltus\WP\Framework\MCP\Tools\GetSettings;
use Saltus\WP\Framework\MCP\Tools\UpdateSettings;
use Saltus\WP\Framework\MCP\Tools\ReorderPosts;
use Saltus\WP\Framework\MCP\Tools\GetMetaFields;
use Saltus\WP\Framework\MCP\Validation\Validator;

class Server {

	private WordPressClient $client;
	private ToolProvider $toolProvider;
	private ResourceProvider $resourceProvider;
	private PromptProvider $promptProvider;

	public function __construct( Config $config ) {
		$this->client           = new WordPressClient( $config );
		$this->toolProvider     = new ToolProvider();
		$this->resourceProvider = new ResourceProvider( $this->client );
		$this->promptProvider   = new PromptProvider();

		$this->registerTools();
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

			$response = $this->handleRequest( $request );

			if ( $response !== null ) {
				echo json_encode( $response ) . "\n";
				fflush( STDOUT );
			}
		}
	}

	/**
	* @param array<string, mixed> $request
	* @return array<string, mixed>|null
	*/
	private function handleRequest( array $request ): ?array {
		$method = $request['method'] ?? '';
		$id     = $request['id'] ?? null;
		$params = $request['params'] ?? [];

		switch ( $method ) {
			case 'initialize':
				return $this->handleInitialize( $id );

			case 'initialized':
			case 'notifications/initialized':
				return null;

			case 'tools/list':
				return $this->handleToolsList( $id );

			case 'tools/call':
				return $this->handleToolsCall( $id, $params );

			case 'resources/list':
				return $this->handleResourcesList( $id );

			case 'resources/read':
				return $this->handleResourcesRead( $id, $params );

			case 'prompts/list':
				return $this->handlePromptsList( $id );

			case 'prompts/get':
				return $this->handlePromptsGet( $id, $params );

			default:
				return [
					'jsonrpc' => '2.0',
					'error'   => [
						'code'    => -32601,
						'message' => "Method not found: {$method}",
					],
					'id'      => $id,
				];
		}
	}

	/**
	* @return array<string, mixed>
	*/
	private function handleInitialize( mixed $id ): array {
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
	private function handleToolsList( mixed $id ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [
				'tools' => $this->toolProvider->getDefinitions(),
			],
		];
	}

	/**
	* @param array<string, mixed> $params
	* @return array<string, mixed>
	*/
	private function handleToolsCall( mixed $id, array $params ): array {
		$toolName  = $params['name'] ?? '';
		$arguments = $params['arguments'] ?? [];

		$tool = $this->toolProvider->get( $toolName );

		if ( ! $tool ) {
			return [
				'jsonrpc' => '2.0',
				'error'   => [
					'code'    => -32602,
					'message' => "Unknown tool: {$toolName}",
				],
				'id'      => $id,
			];
		}

		$schema   = $tool->getParameters();
		$valid    = Validator::validate( $arguments, $schema );
		if ( ! $valid['valid'] ) {
			return [
				'jsonrpc' => '2.0',
				'error'   => [
					'code'    => -32602,
					'message' => 'Invalid parameters: ' . implode( '; ', $valid['errors'] ),
				],
				'id'      => $id,
			];
		}

		try {
			$result = $tool->handle( $arguments, $this->client );

			if ( isset( $result['code'] ) && isset( $result['message'] ) ) {
				return [
					'jsonrpc' => '2.0',
					'isError' => true,
					'error'   => [
						'code'    => -32000,
						'message' => $result['message'],
					],
					'id'      => $id,
				];
			}

			return [
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => [
					'content' => [
						[
							'type' => 'text',
							'text' => json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
						],
					],
				],
			];
		} catch ( \Throwable $e ) {
			return [
				'jsonrpc' => '2.0',
				'error'   => [
					'code'    => -32000,
					'message' => $e->getMessage(),
				],
				'id'      => $id,
			];
		}
	}

	/**
	* @return array<string, mixed>
	*/
	private function handleResourcesList( mixed $id ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [
				'resources' => $this->resourceProvider->getDefinitions(),
			],
		];
	}

	/**
	* @param array<string, mixed> $params
	* @return array<string, mixed>
	*/
	private function handleResourcesRead( mixed $id, array $params ): array {
		$uri = $params['uri'] ?? '';

		$result = $this->resourceProvider->resolve( $uri );

		if ( ! $result ) {
			return [
				'jsonrpc' => '2.0',
				'error'   => [
					'code'    => -32602,
					'message' => "Resource not found: {$uri}",
				],
				'id'      => $id,
			];
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
	private function handlePromptsList( mixed $id ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [
				'prompts' => $this->promptProvider->list(),
			],
		];
	}

	/**
	* @param array<string, mixed> $params
	* @return array<string, mixed>
	*/
	private function handlePromptsGet( mixed $id, array $params ): array {
		$name      = $params['name'] ?? '';
		$arguments = $params['arguments'] ?? [];

		$result = $this->promptProvider->get( $name, $arguments );

		if ( ! $result ) {
			return [
				'jsonrpc' => '2.0',
				'error'   => [
					'code'    => -32602,
					'message' => "Prompt not found: {$name}",
				],
				'id'      => $id,
			];
		}

		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		];
	}

	private function registerTools(): void {
		$toolClasses = [
			Tools\ListModels::class,
			Tools\GetModel::class,
			Tools\ListPosts::class,
			Tools\GetPost::class,
			Tools\CreatePost::class,
			Tools\UpdatePost::class,
			Tools\DeletePost::class,
			Tools\ListTerms::class,
			Tools\CreateTerm::class,
			Tools\DuplicatePost::class,
			Tools\ExportPost::class,
			Tools\GetSettings::class,
			Tools\UpdateSettings::class,
			Tools\ReorderPosts::class,
			Tools\GetMetaFields::class,
		];

		foreach ( $toolClasses as $class ) {
			$this->toolProvider->register( new $class() );
		}
	}
}
