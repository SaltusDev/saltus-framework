<?php
namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;
use Saltus\WP\Framework\Features\Relationships\RelationshipStore;
use Saltus\WP\Framework\Features\WpCli\CommandCatalog;
use Saltus\WP\Framework\Features\WpCli\Commands\RelationshipCommand;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * @covers \Saltus\WP\Framework\Features\WpCli\Commands\RelationshipCommand
 * @covers \Saltus\WP\Framework\Features\WpCli\CommandCatalog
 */
class RelationshipCommandTest extends TestCase {

	private TestCliGateway $cli;
	private RelationshipManager $manager;

	protected function setUp(): void {
		global $wp_posts, $wp_current_user_can;
		$wp_posts            = [];
		$wp_current_user_can = true;
		$this->cli           = new TestCliGateway();

		$models        = [
			'movie'  => $this->model(
				'movie',
				[
					'actors'   => [
						'type'  => 'has_many',
						'model' => 'person',
						'meta'  => [ 'role' => [ 'type' => 'text' ] ],
					],
					'director' => [
						'type'  => 'has_one',
						'model' => 'person',
					],
				]
			),
			'person' => $this->model( 'person', [] ),
		];
		$modeler       = new CommandRelationshipModeler( $models );
		$this->manager = new RelationshipManager( new RelationshipRegistry( $modeler ), new RelationshipStore( null ) );
	}

	/** @param array<string, mixed> $relationships */
	private function model( string $name, array $relationships ): Model {
		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( $name );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true ] );
		$model->method( 'get_args' )->willReturn( [] );
		$model->method( 'get_config' )->willReturn( [ 'relationships' => $relationships ] );

		return $model;
	}

	private function command(): RelationshipCommand {
		return new RelationshipCommand(
			$this->cli,
			static function (): ?Modeler {
				return null;
			},
			$this->manager
		);
	}

	private function seed_post( int $post_id, string $post_type ): void {
		global $wp_posts;
		$post                 = new \WP_Post(
			[
				'post_type'  => $post_type,
				'post_title' => $post_type . '-' . $post_id,
			]
		);
		$post->ID             = $post_id;
		$wp_posts[ $post_id ] = $post;
	}

	public function testListPrintsEveryDeclaredRelationship(): void {
		$this->command()->list( [ 'movie' ], [ 'format' => 'json' ] );

		$this->assertSame( 'json', $this->cli->formats[0]['format'] );
		$this->assertSame( [ 'actors', 'director' ], array_column( $this->cli->formats[0]['items'], 'name' ) );
		$this->assertSame( 'yes', $this->cli->formats[0]['items'][0]['multiple'] );
		$this->assertSame( 'no', $this->cli->formats[0]['items'][1]['multiple'] );
	}

	public function testAttachThenGetPrintsTheRelatedRowWithItsPivot(): void {
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );

		$command = $this->command();
		$command->attach( [ '1', 'actors', '2' ], [ 'pivot' => '{"role":"Lead"}' ] );
		$command->get( [ '1', 'actors' ], [] );

		$this->assertStringContainsString( 'Attached post 2', $this->cli->messages[0] );
		$row = $this->cli->formats[0]['items'][0];
		$this->assertSame( 2, $row['post_id'] );
		$this->assertSame( 'person-2', $row['title'] );
		$this->assertSame( '{"role":"Lead"}', $row['pivot'] );
	}

	public function testSyncReplacesTheSetFromAJsonList(): void {
		$this->seed_post( 1, 'movie' );
		foreach ( [ 2, 3 ] as $person_id ) {
			$this->seed_post( $person_id, 'person' );
		}

		$command = $this->command();
		$command->attach( [ '1', 'actors', '2' ], [] );
		$command->sync( [ '1', 'actors', '[3,2]' ], [] );

		$this->assertSame( [ 3, 2 ], $this->manager->get_related_ids( 1, 'movie', 'actors' ) );
		$this->assertStringContainsString( 'Synced 2 related posts', end( $this->cli->messages ) );
	}

	public function testDetachReportsWhenNothingWasAttached(): void {
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );

		$this->command()->detach( [ '1', 'actors', '2' ], [] );

		$this->assertStringContainsString( 'was not attached', $this->cli->messages[0] );
	}

	public function testDetachConfirmsARemovedPair(): void {
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );

		$command = $this->command();
		$command->attach( [ '1', 'actors', '2' ], [] );
		$command->detach( [ '1', 'actors', '2' ], [] );

		$this->assertStringContainsString( 'Detached post 2', end( $this->cli->messages ) );
		$this->assertSame( [], $this->manager->get_related_ids( 1, 'movie', 'actors' ) );
	}

	public function testCommandsFailWhenTheOwningPostDoesNotExist(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Post not found: 999' );

		$this->command()->get( [ '999', 'actors' ], [] );
	}

	public function testAttachSurfacesManagerErrorsAsCommandFailures(): void {
		$this->seed_post( 1, 'movie' );
		$this->seed_post( 2, 'person' );
		$this->seed_post( 3, 'person' );

		$command = $this->command();
		$command->attach( [ '1', 'director', '2' ], [] );

		$this->expectException( \RuntimeException::class );
		$command->attach( [ '1', 'director', '3' ], [] );
	}

	public function testMissingArgumentsFailBeforeAnyWrite(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Missing required argument: post-type' );

		$this->command()->list( [], [] );
	}

	public function testCatalogDocumentsEveryRelationshipCommand(): void {
		$commands = [];
		foreach ( CommandCatalog::all() as $entry ) {
			$commands[ $entry['ability'] ] = $entry['command'];
		}

		$this->assertSame( 'wp saltus relationship list <post-type>', $commands['list_relationships'] );
		$this->assertSame( 'wp saltus relationship get <post-id> <relationship>', $commands['get_related'] );
		$this->assertSame( 'wp saltus relationship attach <post-id> <relationship> <related-id>', $commands['attach_related'] );
		$this->assertSame( 'wp saltus relationship detach <post-id> <relationship> <related-id>', $commands['detach_related'] );
		$this->assertSame( 'wp saltus relationship sync <post-id> <relationship> <json|@file>', $commands['sync_related'] );
	}
}

/** Modeler double returning a fixed model list. */
class CommandRelationshipModeler extends Modeler {

	/** @var array<string, Model> */
	private array $models;

	/** @param array<string, Model> $models */
	public function __construct( array $models ) {
		$this->models = $models;
	}

	/** @return array<string, Model> */
	public function get_models(): array {
		return $this->models;
	}
}
