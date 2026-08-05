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
			'saltus context',
		], array_keys( $this->cli->commands ) );
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

		$this->assertCount( 20, $catalog );
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

	public function error( string $message ): void {
		throw new \RuntimeException( $message );
	}
}
