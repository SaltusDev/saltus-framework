<?php

namespace Saltus\WP\Framework\Tests\Unit;

use Noodlehaus\AbstractConfig;
use Saltus\WP\Framework\MCP\Tools\CreatePost;
use Saltus\WP\Framework\MCP\Tools\ListModels;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Models\ModelFactory;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\ModelsController;
use Saltus\WP\Framework\Tests\TestCase;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

class ModelerLegacyTest extends TestCase {

	private string $tmp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp_dir = sys_get_temp_dir() . '/saltus-modeler-test-' . str_replace( '.', '', uniqid( '', true ) );
		mkdir( $this->tmp_dir, 0777, true );
		$this->resetWordPressFilters();
	}

	protected function tearDown(): void {
		$this->removeDirectory( $this->tmp_dir );
		$this->resetWordPressFilters();
		parent::tearDown();
	}

	public function testInitUsesCurrentModelPathFilterAfterDeprecatedFilter(): void {
		global $wp_filter_values;

		$deprecated_path = $this->tmp_dir . '/deprecated-models';
		$current_path    = $this->tmp_dir . '/current-models';
		mkdir( $deprecated_path, 0777, true );
		mkdir( $current_path, 0777, true );
		file_put_contents( $current_path . '/model.json', '{"type":"post_type","name":"book"}' );

		$wp_filter_values['saltus_models_path']              = $deprecated_path;
		$wp_filter_values['saltus/framework/models/path']    = $current_path;
		$created_names                                      = [];
		$modeler                                            = new ExposedModeler( $this->factoryRecordingNames( $created_names ) );

		$modeler->init( $this->tmp_dir );

		$this->assertSame( [ 'book' ], $created_names );
		$this->assertArrayHasKey( 'book', $modeler->get_models() );
	}

	public function testLoadProcessesFilesInFilenameOrder(): void {
		$models_path = $this->tmp_dir . '/models';
		mkdir( $models_path, 0777, true );
		file_put_contents( $models_path . '/b.json', '{"type":"post_type","name":"book"}' );
		file_put_contents( $models_path . '/a.json', '{"type":"post_type","name":"album"}' );
		file_put_contents( $models_path . '/ignored.txt', '{"type":"post_type","name":"ignored"}' );

		$created_names = [];
		$modeler       = new ExposedModeler( $this->factoryRecordingNames( $created_names ) );

		$modeler->loadFromPath( $models_path );

		$this->assertSame( [ 'album', 'book' ], $created_names );
		$this->assertSame( [ 'album', 'book' ], array_keys( $modeler->get_models() ) );
	}

	public function testLoadProcessesMultipleModelsFromOneConfigFile(): void {
		$models_path = $this->tmp_dir . '/models';
		mkdir( $models_path, 0777, true );
		file_put_contents(
			$models_path . '/models.json',
			'[{"type":"post_type","name":"book"},{"type":"taxonomy","name":"genre"}]'
		);

		$created_names = [];
		$modeler       = new ExposedModeler( $this->factoryRecordingNames( $created_names ) );

		$modeler->loadFromPath( $models_path );

		$this->assertSame( [ 'book', 'genre' ], $created_names );
		$this->assertSame( [ 'book', 'genre' ], array_keys( $modeler->get_models() ) );
	}

	public function testLoadProcessesCurrentExtraModelsFilterArray(): void {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/models/extra_models'] = [
			[
				'type' => 'post_type',
				'name' => 'book',
			],
			[
				'type' => 'taxonomy',
				'name' => 'genre',
			],
		];

		$created_names = [];
		$modeler       = new ExposedModeler( $this->factoryRecordingNames( $created_names ) );

		$modeler->loadFromPath( $this->tmp_dir . '/missing-models' );

		$this->assertSame( [ 'book', 'genre' ], $created_names );
		$this->assertSame( [ 'book', 'genre' ], array_keys( $modeler->get_models() ) );
	}

	public function testContributesModelRestRouteAndMcpTools(): void {
		$modeler = new Modeler( $this->createStub( ModelFactory::class ) );
		$policy  = new ModelRestPolicy( $modeler );

		$routes = $modeler->get_rest_routes( $modeler, $policy );
		$tools  = $modeler->get_mcp_tools( $modeler, $policy );

		$this->assertCount( 1, $routes );
		$this->assertSame( ModelRestPolicy::CAPABILITY_MODELS, $routes[0]->get_capability() );
		$this->assertNull( $routes[0]->get_model_type() );
		$this->assertStringContainsString( ModelsController::class, $this->describeRouteController( $routes[0] ) );
		$this->assertContainsOnlyInstancesOf( \Saltus\WP\Framework\MCP\Tools\ToolInterface::class, $tools );
		$this->assertInstanceOf( ListModels::class, $tools[0] );
		$this->assertInstanceOf( CreatePost::class, $tools[4] );
	}

	/**
	 * @param list<string> $created_names
	 */
	private function factoryRecordingNames( array &$created_names ): ModelFactory {
		$factory = $this->getMockBuilder( ModelFactory::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'create' ] )
			->getMock();

		$factory->expects( $this->atLeastOnce() )->method( 'create' )->willReturnCallback(
			function ( AbstractConfig $config ) use ( &$created_names ): ?Model {
				$name            = (string) $config->get( 'name' );
				$created_names[] = $name;

				return new NamedModel( $name, (string) $config->get( 'type' ) );
			}
		);

		return $factory;
	}

	private function describeRouteController( object $route ): string {
		$reflection = new \ReflectionClass( $route );
		$property   = $reflection->getProperty( 'controller' );

		return get_class( $property->getValue( $route ) );
	}

	private function resetWordPressFilters(): void {
		global $wp_filters_registered, $wp_filter_values;
		$wp_filters_registered = [];
		$wp_filter_values      = [];
	}

	private function removeDirectory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $path ) {
			$path->isDir() ? rmdir( $path->getPathname() ) : unlink( $path->getPathname() );
		}
		rmdir( $directory );
	}
}

class ExposedModeler extends Modeler {
	public function loadFromPath( string $path ): void {
		$this->load( $path );
	}
}

class NamedModel implements Model {
	private string $name;
	private string $type;

	public function __construct( string $name, string $type ) {
		$this->name = $name;
		$this->type = $type;
	}

	public function setup(): void {}

	public function get_name(): string {
		return $this->name;
	}

	public function get_type(): string {
		return $this->type;
	}

	public function get_options(): array {
		return [];
	}
}
