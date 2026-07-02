<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\AdminCols\AdminCols;
use Saltus\WP\Framework\Features\AdminCols\SaltusAdminCols;
use Saltus\WP\Framework\Features\AdminFilters\AdminFilters;
use Saltus\WP\Framework\Features\AdminFilters\SaltusAdminFilters;
use Saltus\WP\Framework\Features\DragAndDrop\DragAndDrop;
use Saltus\WP\Framework\Features\DragAndDrop\SaltusDragAndDrop;
use Saltus\WP\Framework\Features\Duplicate\Duplicate;
use Saltus\WP\Framework\Features\Duplicate\SaltusDuplicate;
use Saltus\WP\Framework\Features\Meta\CodestarMeta;
use Saltus\WP\Framework\Features\Meta\Meta;
use Saltus\WP\Framework\Features\QuickEdit\QuickEdit;
use Saltus\WP\Framework\Features\QuickEdit\SaltusQuickEdit;
use Saltus\WP\Framework\Features\RememberTabs\RememberTabs;
use Saltus\WP\Framework\Features\RememberTabs\SaltusRememberTabs;
use Saltus\WP\Framework\Features\Settings\CodestarSettings;
use Saltus\WP\Framework\Features\Settings\Settings;
use Saltus\WP\Framework\Features\SingleExport\SaltusSingleExport;
use Saltus\WP\Framework\Features\SingleExport\SingleExport;
use Saltus\WP\Framework\MCP\Tools\DuplicatePost;
use Saltus\WP\Framework\MCP\Tools\ExportPost;
use Saltus\WP\Framework\MCP\Tools\GetMetaFields;
use Saltus\WP\Framework\MCP\Tools\GetSettings;
use Saltus\WP\Framework\MCP\Tools\ReorderPosts;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\ModelFactory;
use Saltus\WP\Framework\Rest\DuplicateController;
use Saltus\WP\Framework\Rest\ExportController;
use Saltus\WP\Framework\Rest\MetaController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\ReorderController;
use Saltus\WP\Framework\Rest\SettingsController;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

class LegacyFeatureTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->resetWordPressState();
	}

	protected function tearDown(): void {
		$this->resetWordPressState();
		parent::tearDown();
	}

	public function testFeatureFactoriesReturnLegacyImplementations(): void {
		$project = [ 'root_url' => 'http://example.com/assets' ];

		$this->assertInstanceOf( SaltusAdminCols::class, AdminCols::make( 'book', $project, [] ) );
		$this->assertInstanceOf( SaltusAdminFilters::class, AdminFilters::make( 'book', $project, [] ) );
		$this->assertInstanceOf( SaltusDragAndDrop::class, DragAndDrop::make( 'book', $project, [] ) );
		$this->assertInstanceOf( SaltusDuplicate::class, Duplicate::make( 'book', $project, [] ) );
		$this->assertInstanceOf( CodestarMeta::class, Meta::make( 'book', $project, [] ) );
		$this->assertInstanceOf( SaltusQuickEdit::class, QuickEdit::make( 'book', $project, [] ) );
		$this->assertInstanceOf( SaltusRememberTabs::class, RememberTabs::make( 'book', $project, [] ) );
		$this->assertInstanceOf( CodestarSettings::class, Settings::make( 'book', $project, [] ) );
		$this->assertInstanceOf( SaltusSingleExport::class, SingleExport::make( 'book', $project, [] ) );
	}

	public function testAdminConditionalFeaturesFollowAdminState(): void {
		global $wp_is_admin;

		$wp_is_admin = false;
		$this->assertFalse( AdminCols::is_needed() );
		$this->assertFalse( AdminFilters::is_needed() );
		$this->assertFalse( DragAndDrop::is_needed() );
		$this->assertFalse( Duplicate::is_needed() );
		$this->assertTrue( Meta::is_needed() );
		$this->assertFalse( QuickEdit::is_needed() );
		$this->assertFalse( RememberTabs::is_needed() );
		$this->assertFalse( Settings::is_needed() );
		$this->assertFalse( SingleExport::is_needed() );

		$wp_is_admin = true;
		$this->assertTrue( AdminCols::is_needed() );
		$this->assertTrue( AdminFilters::is_needed() );
		$this->assertTrue( DragAndDrop::is_needed() );
		$this->assertTrue( Duplicate::is_needed() );
		$this->assertTrue( QuickEdit::is_needed() );
		$this->assertTrue( RememberTabs::is_needed() );
		$this->assertTrue( Settings::is_needed() );
		$this->assertTrue( SingleExport::is_needed() );
	}

	public function testRestAndMcpFeatureContributorsExposeExpectedRoutesAndTools(): void {
		$modeler = new Modeler( $this->createStub( ModelFactory::class ) );
		$policy  = new ModelRestPolicy( $modeler );

		$this->assertRouteAndTool( new Duplicate(), $modeler, $policy, ModelRestPolicy::CAPABILITY_DUPLICATE, DuplicateController::class, DuplicatePost::class );
		$this->assertRouteAndTool( new SingleExport(), $modeler, $policy, ModelRestPolicy::CAPABILITY_EXPORT, ExportController::class, ExportPost::class );
		$this->assertRouteAndTool( new Settings(), $modeler, $policy, ModelRestPolicy::CAPABILITY_SETTINGS, SettingsController::class, GetSettings::class );
		$this->assertRouteAndTool( new Meta(), $modeler, $policy, ModelRestPolicy::CAPABILITY_META, MetaController::class, GetMetaFields::class );
		$this->assertRouteAndTool( new DragAndDrop(), $modeler, $policy, ModelRestPolicy::CAPABILITY_REORDER, ReorderController::class, ReorderPosts::class );
	}

	public function testProcessMethodsRegisterExpectedHooks(): void {
		global $wp_actions_registered, $wp_filters_registered;

		( new SaltusDuplicate( 'book', [] ) )->process();
		( new SaltusDragAndDrop( 'book', [ 'root_url' => 'http://example.com/assets' ] ) )->process();
		( new SaltusQuickEdit( 'book', [ 'subtitle' => [] ] ) )->process();
		( new SaltusSingleExport( 'book', [] ) )->process();

		$action_names = array_column( $wp_actions_registered, 'hook_name' );
		$this->assertContains( 'admin_action_saltus_framework_book_duplicate_post', $action_names );
		$this->assertContains( 'admin_enqueue_scripts', $action_names );
		$this->assertContains( 'save_post', $action_names );
		$this->assertContains( 'init', $action_names );
		$this->assertArrayHasKey( 'post_row_actions', $wp_filters_registered );
		$this->assertArrayHasKey( 'get_previous_post_where', $wp_filters_registered );
	}

	public function testAdminColumnsResolveSortableAndManagedColumns(): void {
		global $wp_current_user_can;

		$wp_current_user_can = [ 'manage_secret' => false ];
		$columns             = new SaltusAdminCols(
			'book',
			[
				'isbn'   => [
					'title'    => 'ISBN',
					'meta_key' => 'isbn',
				],
				'secret' => [
					'title'    => 'Secret',
					'meta_key' => 'secret',
					'cap'      => 'manage_secret',
				],
			]
		);

		$this->assertSame( [ 'isbn' => 'isbn', 'secret' => 'secret' ], $columns->sortables( [] ) );
		$columns->log_default_cols( [ 'cb' => '<input />', 'title' => 'Title', 'date' => 'Date' ] );
		$this->assertSame( [ 'cb' => '<input />', 'title' => 'Title', 'isbn' => 'ISBN' ], $columns->manage_columns( [ 'cb' => '<input />', 'title' => 'Title', 'date' => 'Date' ] ) );
	}

	public function testAdminColumnSortVarsMapMetaKeysAndPostFields(): void {
		$vars = SaltusAdminCols::get_sort_field_vars(
			[
				'orderby' => 'isbn',
				'order'   => 'desc',
			],
			[
				'isbn' => [
					'meta_key' => 'isbn',
					'orderby'  => 'meta_value_num',
				],
			]
		);

		$this->assertSame( 'isbn', $vars['meta_key'] );
		$this->assertSame( 'meta_value_num', $vars['orderby'] );
		$this->assertSame( 'desc', $vars['order'] );
	}

	public function testAdminFiltersBuildMetaAndDateQueryVars(): void {
		$vars = SaltusAdminFilters::get_filter_vars(
			[
				'status_filter' => 'published',
				'date_filter'   => '2026-07-02',
			],
			[
				'status_filter' => [
					'meta_key' => 'status',
					'compare'  => '=',
				],
				'date_filter'   => [
					'post_date' => 'after',
				],
			],
			'book'
		);

		$this->assertSame( 'status', $vars['meta_query'][0]['key'] );
		$this->assertSame( 'published', $vars['meta_query'][0]['value'] );
		$this->assertSame( '2026-07-02', $vars['date_query'][0]['after'] );
	}

	public function testAdminFilterHookCanOverrideQueryVars(): void {
		global $wp_filter_values;

		$wp_filter_values['saltus/framework/admin_filters/book/filter_query/status_filter'] = static function ( array $return ): array {
			$return['post_status'] = 'private';
			return $return;
		};

		$vars = SaltusAdminFilters::get_filter_vars(
			[ 'status_filter' => 'published' ],
			[ 'status_filter' => [ 'meta_key' => 'status' ] ],
			'book'
		);

		$this->assertSame( [ 'post_status' => 'private' ], $vars );
	}

	public function testDragAndDropAdjustsNavigationAndQueries(): void {
		global $post, $wp_is_admin;

		$post    = new \WP_Post(
			[
				'ID'         => 7,
				'post_type'  => 'book',
				'menu_order' => 5,
			]
		);
		$feature = new SaltusDragAndDrop( 'book', [ 'root_url' => 'http://example.com/assets' ] );

		$this->assertSame( "WHERE p.menu_order > '5'", $feature->previous_post_where( "WHERE p.post_date < '2026-07-02 00:00:00'" ) );
		$this->assertSame( 'ORDER BY p.menu_order ASC LIMIT 1', $feature->previous_post_sort( 'ORDER BY p.post_date DESC LIMIT 1' ) );
		$this->assertSame( "WHERE p.menu_order < '5'", $feature->next_post_where( "WHERE p.post_date > '2026-07-02 00:00:00'" ) );
		$this->assertSame( 'ORDER BY p.menu_order DESC LIMIT 1', $feature->next_post_sort( 'ORDER BY p.post_date ASC LIMIT 1' ) );

		$wp_is_admin = true;
		$query       = new \WP_Query( [ 'post_type' => 'book' ] );
		$feature->pre_get_posts( $query );
		$this->assertSame( 'menu_order', $query->get( 'orderby' ) );
		$this->assertSame( 'ASC', $query->get( 'order' ) );
	}

	public function testDuplicateRowLinkAndDuplication(): void {
		global $wp_posts;

		$wp_posts[7] = new \WP_Post(
			[
				'ID'           => 7,
				'post_type'    => 'book',
				'post_title'   => 'Original',
				'post_content' => 'Content',
			]
		);

		$feature = new SaltusDuplicate( 'book', [ 'label' => 'Clone' ] );
		$actions = $feature->row_link( [], $wp_posts[7] );
		$new_id  = $feature->perform_duplication( 7 );

		$this->assertArrayHasKey( 'duplicate', $actions );
		$this->assertStringContainsString( 'Clone', $actions['duplicate'] );
		$this->assertIsInt( $new_id );
		$this->assertSame( 'draft', $wp_posts[ $new_id ]->post_status );
		$this->assertSame( 'Original', $wp_posts[ $new_id ]->post_title );
	}

	public function testQuickEditSavesSanitizedPostedValues(): void {
		global $wp_meta_updates;

		$_POST['quick_edit_nonce_field'] = 'valid';
		$_POST['subtitle']               = '  New subtitle  ';

		$feature = new SaltusQuickEdit( 'book', [ 'subtitle' => [ 'title' => 'Subtitle' ] ] );
		$feature->save_quick_edit_data( 7 );

		$this->assertSame( 7, $wp_meta_updates[0]['post_id'] );
		$this->assertSame( 'subtitle', $wp_meta_updates[0]['meta_key'] );
		$this->assertSame( 'New subtitle', $wp_meta_updates[0]['meta_value'] );
	}

	public function testSingleExportOnlyRewritesMatchingExportQuery(): void {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! property_exists( $wpdb, 'posts' ) ) {
			$wpdb = new class implements \Saltus\WP\Framework\MCP\Audit\AuditDatabase {
				public string $prefix = 'wp_';
				public string $posts = 'wp_posts';
				/** @var list<array<string, mixed>> */
				public array $inserts = [];
				/** @var list<string> */
				public array $queries = [];

				public function prefix(): string {
					return $this->prefix;
				}

				public function insert( string $table, array $data, array $format = [] ): bool {
					$this->inserts[] = compact( 'table', 'data', 'format' );
					return true;
				}

				public function query( string $query ): bool {
					$this->queries[] = $query;
					return true;
				}

				public function prepare( string $query, mixed ...$args ): string {
					foreach ( $args as $arg ) {
						$query = preg_replace( '/%[dsf]/', (string) $arg, $query, 1 );
					}
					return $query;
				}

				public function get_results( string $query, mixed $output = null ): array {
					return array_reverse( array_map( static fn( array $insert ) => $insert['data'], $this->inserts ) );
				}

				public function get_charset_collate(): string {
					return '';
				}
			};
		}

		$_GET['export_single'] = '7';
		$_GET['_wpnonce']      = 'valid';

		$feature = new SaltusSingleExport( 'book', [] );
		$args    = $feature->export_args( [] );
		$query   = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}  WHERE {$wpdb->posts}.post_type = 'post' AND {$wpdb->posts}.post_status != 'auto-draft' AND {$wpdb->posts}.post_date >= %s AND {$wpdb->posts}.post_date < %s",
			'1970-01-05',
			'1970-02-05'
		);

		$this->assertSame( 'post', $args['content'] );
		$this->assertSame( SaltusSingleExport::FAKE_DATE, $args['start_date'] );
		$this->assertSame( "SELECT ID FROM {$wpdb->posts}  WHERE {$wpdb->posts}.ID = 7", $feature->query( $query ) );
		$this->assertSame( 'SELECT * FROM wp_posts', $feature->query( 'SELECT * FROM wp_posts' ) );
	}

	private function assertRouteAndTool( object $feature, Modeler $modeler, ModelRestPolicy $policy, string $capability, string $controller_class, string $tool_class ): void {
		$routes = $feature->get_rest_routes( $modeler, $policy );
		$tools  = $feature->get_mcp_tools( $modeler, $policy );

		$this->assertCount( 1, $routes );
		$this->assertSame( $capability, $routes[0]->get_capability() );
		$this->assertSame( 'post_type', $routes[0]->get_model_type() );
		$this->assertSame( $controller_class, $this->routeControllerClass( $routes[0] ) );
		$this->assertContainsOnlyInstancesOf( \Saltus\WP\Framework\MCP\Tools\ToolInterface::class, $tools );
		$this->assertContains( $tool_class, array_map( 'get_class', $tools ) );
	}

	private function routeControllerClass( object $route ): string {
		$reflection = new \ReflectionClass( $route );
		$property   = $reflection->getProperty( 'controller' );

		return get_class( $property->getValue( $route ) );
	}

	private function resetWordPressState(): void {
		global $wp_actions_registered, $wp_filters_registered, $wp_filter_values, $wp_current_user_can, $wp_is_admin, $wp_scripts_enqueued, $wp_styles_enqueued, $wp_scripts_localized, $wp_nonce_valid, $wp_meta_updates, $wp_posts, $wp_post_meta, $wpdb, $post;

		$wp_actions_registered = [];
		$wp_filters_registered = [];
		$wp_filter_values      = [];
		$wp_current_user_can   = true;
		$wp_is_admin           = false;
		$wp_scripts_enqueued   = [];
		$wp_styles_enqueued    = [];
		$wp_scripts_localized  = [];
		$wp_nonce_valid        = true;
		$wp_meta_updates       = [];
		$wp_posts              = [];
		$wp_post_meta          = [];
		$_GET                  = [];
		$_POST                 = [];
		$_SERVER['REQUEST_URI'] = '';
		if ( ! $wpdb instanceof \Saltus\WP\Framework\MCP\Audit\AuditDatabase ) {
			$wpdb = new class implements \Saltus\WP\Framework\MCP\Audit\AuditDatabase {
				public string $prefix = 'wp_';
				public string $posts = 'wp_posts';
				/** @var list<array<string, mixed>> */
				public array $inserts = [];
				/** @var list<string> */
				public array $queries = [];

				public function prefix(): string {
					return $this->prefix;
				}

				public function insert( string $table, array $data, array $format = [] ): bool {
					$this->inserts[] = compact( 'table', 'data', 'format' );
					return true;
				}

				public function query( string $query ): bool {
					$this->queries[] = $query;
					return true;
				}

				public function prepare( string $query, mixed ...$args ): string {
					foreach ( $args as $arg ) {
						$query = preg_replace( '/%[dsf]/', (string) $arg, $query, 1 );
					}
					return $query;
				}

				public function get_results( string $query, mixed $output = null ): array {
					return array_reverse( array_map( static fn( array $insert ) => $insert['data'], $this->inserts ) );
				}

				public function get_charset_collate(): string {
					return '';
				}
			};
		}
		$post                  = null;
	}
}
