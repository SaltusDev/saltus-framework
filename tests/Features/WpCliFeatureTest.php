<?php
namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\WpCli\CliGateway;
use Saltus\WP\Framework\Features\WpCli\CommandCatalog;
use Saltus\WP\Framework\Features\WpCli\Commands\BlockCommand;
use Saltus\WP\Framework\Features\WpCli\Commands\ModelCommand;
use Saltus\WP\Framework\Features\WpCli\Commands\PostCommand;
use Saltus\WP\Framework\Features\WpCli\Commands\ReorderCommand;
use Saltus\WP\Framework\Features\WpCli\Commands\SettingsCommand;
use Saltus\WP\Framework\Features\WpCli\WpCli;
use Saltus\WP\Framework\MCP\Tools\ToolInterface;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\Features\WpCli\WpCli
 * @covers \Saltus\WP\Framework\Features\WpCli\CommandCatalog
 * @covers \Saltus\WP\Framework\Features\WpCli\Commands\AbstractCommand
 * @covers \Saltus\WP\Framework\Features\WpCli\Commands\ModelCommand
 * @covers \Saltus\WP\Framework\Features\WpCli\Commands\PostCommand
 * @covers \Saltus\WP\Framework\Features\WpCli\Commands\SettingsCommand
 * @covers \Saltus\WP\Framework\Features\WpCli\Commands\ReorderCommand
 * @covers \Saltus\WP\Framework\Features\WpCli\Commands\BlockCommand
 */
class WpCliFeatureTest extends TestCase {
	private TestCliGateway $cli;
	private Modeler $modeler;

	protected function setUp(): void {
		global $wp_posts, $wp_options, $wp_query_posts, $wp_current_user_can;
		$this->cli           = new TestCliGateway();
		$this->modeler       = $this->modeler();
		$wp_posts            = [];
		$wp_options          = [];
		$wp_query_posts      = [];
		$wp_current_user_can = true;
	}

	public function testRegistersEveryCommandGroup(): void {
		$service = new WpCli( [ 'modeler_resolver' => function (): Modeler { return $this->modeler; } ], $this->cli );
		$service->register_commands();

		$this->assertSame( [
			'saltus',
			'saltus model',
			'saltus post',
			'saltus term',
			'saltus settings',
			'saltus meta',
			'saltus reorder',
			'saltus block',
			'saltus relationship',
			'saltus context',
			'saltus webmcp',
			'saltus config',
			'saltus metrics',
		], array_keys( $this->cli->commands ) );
	}

	/**
	 * WP_CLI's CommandFactory reflects on __invoke() to choose a command's kind: a
	 * class that has one becomes a Subcommand, and Subcommands cannot accept
	 * children. Registering anything beneath such a command throws from inside
	 * cli_init, which aborts WordPress bootstrap and breaks every wp command on
	 * the site. Nothing below exercises the real factory, so this is the guard.
	 */
	public function testNoParentCommandDefinesInvoke(): void {
		$service = new WpCli( [ 'modeler_resolver' => function (): Modeler { return $this->modeler; } ], $this->cli );
		$service->register_commands();

		$names   = array_keys( $this->cli->commands );
		$parents = [];
		foreach ( $names as $name ) {
			$parent = substr( $name, 0, (int) strrpos( $name, ' ' ) );
			if ( strpos( $name, ' ' ) !== false && in_array( $parent, $names, true ) ) {
				$parents[ $parent ] = true;
			}
		}

		$this->assertArrayHasKey( 'saltus', $parents, 'saltus must still be a parent for this test to mean anything' );

		foreach ( array_keys( $parents ) as $parent ) {
			$handler = $this->cli->commands[ $parent ];
			$this->assertFalse(
				( new \ReflectionClass( $handler ) )->hasMethod( '__invoke' ),
				sprintf( '"wp %s" has children, so its class must not define __invoke().', $parent )
			);
		}
	}

	/**
	 * The same reflection rule silently hides methods: a Subcommand's own public
	 * methods are never registered, so `wp saltus webmcp validate` would parse
	 * "validate" as a positional argument and run __invoke() instead.
	 */
	public function testCommandsWithInvokeExposeNoOtherActions(): void {
		$service = new WpCli( [ 'modeler_resolver' => function (): Modeler { return $this->modeler; } ], $this->cli );
		$service->register_commands();

		foreach ( $this->cli->commands as $name => $handler ) {
			$reflection = new \ReflectionClass( $handler );
			if ( ! $reflection->hasMethod( '__invoke' ) ) {
				continue;
			}

			$actions = [];
			foreach ( $reflection->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
				// Mirrors WP_CLI\Dispatcher\CommandFactory::is_good_method().
				if ( ! $method->isStatic() && strpos( $method->getName(), '__' ) !== 0 ) {
					$actions[] = $method->getName();
				}
			}

			$this->assertSame(
				[],
				$actions,
				sprintf(
					'"wp %s" defines __invoke(), so WP_CLI registers it as a Subcommand and these methods are unreachable: %s.',
					$name,
					implode( ', ', $actions )
				)
			);
		}
	}

	public function testCatalogMatchesEveryInstantiableMcpTool(): void {
		$tool_names = [];
		foreach ( glob( dirname( __DIR__, 2 ) . '/src/MCP/Tools/*.php' ) ?: [] as $file ) {
			$class = 'Saltus\\WP\\Framework\\MCP\\Tools\\' . basename( $file, '.php' );
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$reflection = new \ReflectionClass( $class );
			$constructor = $reflection->getConstructor();
			if ( ! $reflection->isInstantiable() || ! $reflection->implementsInterface( ToolInterface::class ) || ( $constructor && $constructor->getNumberOfRequiredParameters() > 0 ) ) {
				continue;
			}
			$tool = $reflection->newInstance();
			if ( $tool instanceof ToolInterface ) {
				$tool_names[] = $tool->get_name();
			}
		}
		sort( $tool_names );
		$catalog = CommandCatalog::abilities();
		sort( $catalog );

		$this->assertCount( 25, $catalog );
		$this->assertSame( $tool_names, $catalog );
	}

	public function testModelAndBlockCommandsProduceStructuredRows(): void {
		$resolver = function (): Modeler { return $this->modeler; };
		( new ModelCommand( $this->cli, $resolver ) )->list( [], [ 'format' => 'json' ] );
		$this->assertSame( 'json', $this->cli->formats[0]['format'] );
		$this->assertSame( 'book', $this->cli->formats[0]['items'][0]['name'] );

		( new BlockCommand( $this->cli, $resolver ) )->list( [], [] );
		$this->assertSame( 'saltus/book-list', $this->cli->formats[1]['items'][0]['block'] );
	}

	public function testPostCreateAndSettingsUpdateUseJsonPayloads(): void {
		global $wp_posts, $wp_options;
		$resolver = function (): Modeler { return $this->modeler; };
		( new PostCommand( $this->cli, $resolver ) )->create( [ 'book', 'New Book' ], [ 'meta' => '{"isbn":"123"}' ] );
		$this->assertSame( 'New Book', end( $wp_posts )->post_title );

		( new SettingsCommand( $this->cli, $resolver ) )->update( [ 'book', '{"perPage":12}' ], [] );
		$this->assertSame( 12, $wp_options['saltus_framework_settings_book']['perPage'] );
	}

	public function testReorderRejectsObjectPayloadBeforeWriting(): void {
		$this->expectException( \RuntimeException::class );
		( new ReorderCommand( $this->cli, function (): Modeler { return $this->modeler; } ) )( [ '{"id":1}' ], [] );
	}

	private function modeler(): Modeler {
		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( 'book' );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true, 'mcp_tools' => true ] );
		$model->method( 'get_config' )->willReturn( [ 'blocks' => [ 'list' => true ], 'meta' => [] ] );
		$model->method( 'get_args' )->willReturn( [ 'labels' => [ 'name' => 'Books', 'singular_name' => 'Book' ], 'meta' => [] ] );
		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );
		return $modeler;
	}
}

final class TestCliGateway implements CliGateway {
	/** @var array<string, object|string> */
	public array $commands = [];
	/** @var list<array<string, mixed>> */
	public array $formats = [];
	/** @var list<string> */
	public array $messages = [];
	/** Exit status, or null while the command is still considered successful. */
	public ?int $halted = null;

	public function add_command( string $name, $command_handler ): void {
		$this->commands[ $name ] = $command_handler;
	}

	public function format_items( string $format, array $items, array $fields ): void {
		$this->formats[] = compact( 'format', 'items', 'fields' );
	}

	public function line( string $message ): void {
		$this->messages[] = $message;
	}

	public function success( string $message ): void {
		$this->messages[] = $message;
	}

	/**
	 * Throws, mirroring the real gateway's exit.
	 *
	 * `WP_CLI::error()` terminates the process, so a command must not carry on
	 * afterwards. Throwing is how that non-return shows up in a test.
	 */
	public function error( string $message ): void {
		$this->messages[] = $message;
		throw new \RuntimeException( $message );
	}

	public function warning( string $message ): void {
		$this->messages[] = $message;
	}

	public function halt( int $code ): void {
		$this->halted = $code;
	}

	/** Everything written, in order, as one string. */
	public function output(): string {
		return implode( "\n", $this->messages );
	}

	/**
	 * The status the command would exit with.
	 *
	 * Zero unless something halted, so a test can assert success without the
	 * gateway having to model a process exit.
	 */
	public function exit_code(): int {
		return $this->halted ?? 0;
	}

	/** The format passed to the most recent `format_items()` call. */
	public function last_format(): ?string {
		$last = end( $this->formats );

		return is_array( $last ) ? (string) $last['format'] : null;
	}
}
