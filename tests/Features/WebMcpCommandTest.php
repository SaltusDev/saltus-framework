<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\WebMcp\AdminScreen;
use Saltus\WP\Framework\Features\WpCli\Commands\WebMcpCommand;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

require_once dirname( __DIR__ ) . '/Rest/functions.php';
require_once __DIR__ . '/WpCliFeatureTest.php';

/**
 * Offline inspection of the WebMCP surface.
 *
 * The surface is otherwise only observable inside a browser implementing an API
 * Chrome ships no earlier than 157, which makes a misconfiguration and an
 * unsupported browser look identical. These commands are the only way to tell
 * them apart, so they are covered as a diagnostic tool: the failure cases matter
 * more than the happy path.
 *
 * @covers \Saltus\WP\Framework\Features\WpCli\Commands\WebMcpCommand
 */
class WebMcpCommandTest extends TestCase {

	private TestCliGateway $cli;

	protected function setUp(): void {
		global $wp_post_type_objects, $wp_current_user_can, $wp_filter_values;
		$this->cli            = new TestCliGateway();
		$wp_post_type_objects = [];
		$wp_current_user_can  = true;
		$wp_filter_values     = [];
	}

	protected function tearDown(): void {
		global $wp_current_user_can, $wp_filter_values;
		$wp_current_user_can = null;
		$wp_filter_values    = [];
	}

	public function testManifestRendersFrontendDescriptors(): void {
		$this->command( [ 'webmcp' => true ] )->manifest( [], [ 'format' => 'json' ] );

		$rows = $this->cli->formats[0]['items'];
		$this->assertSame( 'json', $this->cli->formats[0]['format'] );
		$this->assertContains( 'search_content', array_column( $rows, 'tool' ) );

		$search = $this->row( $rows, 'search_content' );
		$this->assertSame( 'yes', $search['read_only'], 'Public read tools carry readOnlyHint.' );
		$this->assertStringContainsString( 'query', $search['required'] );
	}

	public function testManifestRendersAdminDescriptorsWithWritesMarkedNotReadOnly(): void {
		$this->command( [ 'webmcp' => [ 'enabled' => true, 'admin' => true ] ] )
			->manifest( [], [ 'surface' => 'admin' ] );

		$rows = $this->cli->formats[0]['items'];
		$this->assertContains( 'update_post', array_column( $rows, 'tool' ) );
		$this->assertSame( 'no', $this->row( $rows, 'update_post' )['read_only'] );
	}

	public function testManifestScopesToAnAdminScreen(): void {
		$this->command( [ 'webmcp' => [ 'enabled' => true, 'admin' => true ] ] )
			->manifest( [], [ 'surface' => 'admin', 'screen' => AdminScreen::SETTINGS ] );

		$tools = array_column( $this->cli->formats[0]['items'], 'tool' );

		$this->assertNotContains( 'update_post', $tools, 'The settings screen does not offer entry writes.' );
	}

	public function testManifestReportsAnEmptySurfaceWithoutFailing(): void {
		$this->command( [] )->manifest( [], [] );

		$this->assertSame( [], $this->cli->formats );
		$this->assertStringContainsString( 'No WebMCP tools', $this->cli->messages[0] );
	}

	public function testUnknownSurfaceAndScreenAreRejected(): void {
		$command = $this->command( [ 'webmcp' => true ] );

		$this->expectException( \RuntimeException::class );
		$command->manifest( [], [ 'surface' => 'sideways' ] );
	}

	public function testValidateAcceptsACoherentSurface(): void {
		$this->command( [ 'webmcp' => [ 'enabled' => true, 'frontend' => true ] ] )->validate( [], [] );

		$this->assertStringContainsString( 'valid', $this->cli->messages[0] );
		$this->assertStringContainsString( '1 model(s) enabled', $this->cli->messages[0] );
	}

	public function testValidateFlagsAFrontendModelThatIsNotPubliclyQueryable(): void {
		global $wp_post_type_objects;

		$wp_post_type_objects['book'] = (object) [
			'name'               => 'book',
			'publicly_queryable' => false,
		];

		$command = $this->command( [ 'webmcp' => [ 'enabled' => true, 'frontend' => true ] ], false );

		try {
			$command->validate( [], [] );
			$this->fail( 'An incoherent surface must exit non-zero.' );
		} catch ( \RuntimeException $error ) {
			$issues = $this->cli->formats[0]['items'];
			$this->assertSame( 'warning', $issues[0]['severity'] );
			$this->assertStringContainsString( 'not publicly queryable', $issues[0]['issue'] );
		}
	}

	public function testValidateFlagsAnAllowlistTypo(): void {
		$command = $this->command(
			[
				'webmcp' => [
					'enabled'  => true,
					'frontend' => true,
					'tools'    => [ 'search_content', 'serch_content' ],
				],
			]
		);

		try {
			$command->validate( [], [] );
			$this->fail( 'A typo that silently narrows nothing must be reported.' );
		} catch ( \RuntimeException $error ) {
			$issues = $this->cli->formats[0]['items'];
			$this->assertSame( 'error', $issues[0]['severity'] );
			$this->assertStringContainsString( 'serch_content', $issues[0]['issue'] );
		}
	}

	public function testValidateFlagsAModelEnabledForNoSurface(): void {
		$command = $this->command( [ 'webmcp' => [ 'enabled' => true, 'frontend' => false, 'admin' => false ] ] );

		try {
			$command->validate( [], [] );
			$this->fail( 'A model enabled for neither surface registers nothing.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString(
				'neither frontend nor admin',
				$this->cli->formats[0]['items'][0]['issue']
			);
		}
	}

	/**
	 * Find one rendered row by tool name.
	 *
	 * @param list<array<string, mixed>> $rows Rendered rows.
	 * @param string                     $tool Tool name.
	 * @return array<string, mixed>
	 */
	private function row( array $rows, string $tool ): array {
		foreach ( $rows as $row ) {
			if ( $row['tool'] === $tool ) {
				return $row;
			}
		}

		$this->fail( 'Expected a row for ' . $tool . '.' );
	}

	/**
	 * @param array<string, mixed> $config          Model configuration.
	 * @param bool                 $seed_post_type  Whether to seed the post type object.
	 */
	private function command( array $config, bool $seed_post_type = true ): WebMcpCommand {
		$modeler = $this->modeler( $config, $seed_post_type );

		return new WebMcpCommand(
			$this->cli,
			function () use ( $modeler ): Modeler {
				return $modeler;
			}
		);
	}

	/**
	 * @param array<string, mixed> $config         Model configuration.
	 * @param bool                 $seed_post_type Whether to seed the post type object.
	 */
	private function modeler( array $config, bool $seed_post_type = true ): Modeler {
		global $wp_post_type_objects;

		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( 'book' );
		$model->method( 'get_type' )->willReturn( 'post_type' );
		$model->method( 'get_config' )->willReturn( $config );
		$model->method( 'get_options' )->willReturn( [ 'show_in_rest' => true ] );
		$model->method( 'get_args' )->willReturn( [ 'public' => true, 'publicly_queryable' => true ] );

		if ( $seed_post_type ) {
			$wp_post_type_objects['book'] = (object) [
				'name'               => 'book',
				'publicly_queryable' => true,
				'public'             => true,
				'label'              => 'Book',
				'labels'             => (object) [ 'name' => 'Books' ],
			];
		}

		$modeler = $this->createStub( Modeler::class );
		$modeler->method( 'get_models' )->willReturn( [ 'book' => $model ] );
		$modeler->method( 'get_mcp_tools' )->willReturn(
			[
				new \Saltus\WP\Framework\MCP\Tools\UpdatePost(),
				new \Saltus\WP\Framework\MCP\Tools\GetPost(),
				new \Saltus\WP\Framework\MCP\Tools\UpdateSettings(),
				new \Saltus\WP\Framework\MCP\Tools\GetSettings(),
			]
		);

		return $modeler;
	}
}
