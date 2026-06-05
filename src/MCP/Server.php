<?php
namespace Saltus\WP\Framework\MCP;

use Saltus\WP\Framework\MCP\Client\WordPressClient;
use Saltus\WP\Framework\MCP\Config\Config;
use Saltus\WP\Framework\MCP\Config\ConfigManager;
use Saltus\WP\Framework\MCP\Resources\ResourceProvider;
use Saltus\WP\Framework\MCP\Tools\ToolProvider;

class Server {

	private Config $config;
	private WordPressClient $client;
	private ToolProvider $toolProvider;
	private ResourceProvider $resourceProvider;

	private bool $initialized = false;

	public function __construct( Config $config ) {
		$this->config           = $config;
		$this->client           = new WordPressClient( $config );
		$this->toolProvider     = new ToolProvider();
		$this->resourceProvider = new ResourceProvider();

		$this->registerTools();
	}

	public static function fromConfigManager( ConfigManager $configManager ): self {
		$config = $configManager->load();

		if ( ! $config ) {
			echo json_encode([
				'jsonrpc' => '2.0',
				'error'   => [
					'code'    => -32000,
					'message' => 'No configuration found. Run the setup wizard first.',
				],
				'id'      => null,
			]) . "\n";
			exit( 1 );
		}

		return new self( $config );
	}

	/**
	* Run the MCP server: listen on stdin for JSON-RPC requests.
	*/
	public function run(): void {
		while ( true ) {
			$line = fgets( STDIN );

			if ( $line === false || $line === null ) {
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

	private function handleRequest( array $request ): ?array {
		$method = $request['method'] ?? '';
		$id     = $request['id'] ?? null;
		$params = $request['params'] ?? [];

		switch ( $method ) {
			case 'initialize':
				return $this->handleInitialize( $id );

			case 'initialized':
				return null;

			case 'tools/list':
				return $this->handleToolsList( $id );

			case 'tools/call':
				return $this->handleToolsCall( $id, $params );

			case 'resources/list':
				return $this->handleResourcesList( $id );

			case 'resources/read':
				return $this->handleResourcesRead( $id, $params );

			case 'notifications/initialized':
				$this->initialized = true;
				return null;

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

	private function handleInitialize( $id ): array {
		$this->initialized = true;

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

	private function handleToolsList( $id ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [
				'tools' => $this->toolProvider->getDefinitions(),
			],
		];
	}

	private function handleToolsCall( $id, array $params ): array {
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

	private function handleResourcesList( $id ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [
				'resources' => $this->resourceProvider->getDefinitions(),
			],
		];
	}

	private function handleResourcesRead( $id, array $params ): array {
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
		];

		foreach ( $toolClasses as $class ) {
			$this->toolProvider->register( new $class() );
		}
	}
}
