<?php

namespace Saltus\WP\Framework\Tests\Features;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Features\Relationships\RelationshipBulkActions;
use Saltus\WP\Framework\Features\Relationships\RelationshipColumn;
use Saltus\WP\Framework\Features\Relationships\RelationshipManager;
use Saltus\WP\Framework\Features\Relationships\RelationshipRegistry;
use Saltus\WP\Framework\Features\Relationships\RelationshipStore;
use Saltus\WP\Framework\Models\ModelFactory;

require_once dirname( __DIR__ ) . '/Rest/functions.php';
require_once __DIR__ . '/RelationshipsTest.php';

/**
 * @covers \Saltus\WP\Framework\Features\Relationships\RelationshipColumn
 * @covers \Saltus\WP\Framework\Features\Relationships\RelationshipBulkActions
 */
class RelationshipColumnTest extends TestCase {

	protected function setUp(): void {
		global $wp_posts, $wp_current_user_can, $wp_query_count;

		$wp_posts            = [];
		$wp_current_user_can = true;
		$wp_query_count      = 0;
	}

	protected function tearDown(): void {
		global $wp_posts, $wp_current_user_can;

		// `$wp_posts` is shared with every other test class and not every class
		// resets it. `AiContextFeatureTest` in particular asserts on a post id
		// that resolves to nothing, so a post this class left behind at that id
		// changes its result — the failure surfaces in that class, under a random
		// ordering, with nothing pointing back here. Clean up after ourselves.
		$wp_posts            = [];
		$wp_current_user_can = true;
	}

	private function seed_post( int $post_id, string $post_type, string $title = '' ): \WP_Post {
		global $wp_posts;
		$post                 = new \WP_Post(
			[
				'post_type'  => $post_type,
				'post_title' => $title === '' ? $post_type . '-' . $post_id : $title,
			]
		);
		$post->ID             = $post_id;
		$wp_posts[ $post_id ] = $post;

		return $post;
	}

	private function manager( ?RelationshipStore $store = null ): RelationshipManager {
		$models = [
			'movie'  => new RelationshipModel(
				'movie',
				[
					'actors' => [
						'type'       => 'has_many',
						'model'      => 'person',
						'reciprocal' => 'acted_in',
					],
				]
			),
			'person' => new RelationshipModel( 'person', [] ),
		];

		return new RelationshipManager(
			new RelationshipRegistry( new RelationshipModeler( $this->createStub( ModelFactory::class ), $models ) ),
			$store ?? new RelationshipStore( null )
		);
	}

	public function testColumnIsAddedPerRelationship(): void {
		$column = new RelationshipColumn( $this->manager() );

		$columns = $column->add_columns( [ 'title' => 'Title' ], 'movie' );

		$this->assertArrayHasKey( 'saltus_rel_actors', $columns );
		$this->assertSame( 'Actors', $columns['saltus_rel_actors'] );
		$this->assertArrayHasKey( 'title', $columns, 'Existing columns must survive.' );
	}

	public function testColumnKeyIsNamespaced(): void {
		$column = new RelationshipColumn( $this->manager() );

		$this->assertSame( 'saltus_rel_actors', $column->column_key( 'actors' ) );
	}

	public function testNoColumnForPostTypeOutsideTheGraph(): void {
		$column = new RelationshipColumn( $this->manager() );

		$this->assertSame( [ 'title' => 'Title' ], $column->add_columns( [ 'title' => 'Title' ], 'unrelated' ) );
	}

	/**
	 * The whole point of priming: a page of posts costs one query per
	 * relationship, not one per row. This asserts the store is queried once for a
	 * three-post page rather than three times.
	 */
	public function testPrimingResolvesAWholePageInOneQueryPerRelationship(): void {
		$database = new CountingDatabase();
		$manager  = $this->manager( new RelationshipStore( $database ) );
		$column   = new RelationshipColumn( $manager );

		$movies = [];
		foreach ( [ 10, 11, 12 ] as $id ) {
			$movies[] = $this->seed_post( $id, 'movie' );
		}
		$this->seed_post( 20, 'person', 'Ripley' );

		foreach ( [ 10, 11, 12 ] as $id ) {
			$manager->attach( $id, 'movie', 'actors', 20 );
		}

		$database->reset();
		$column->prime( $movies, 'movie' );

		$this->assertSame(
			1,
			$database->reads,
			'A three-row page must cost one read for the relationship, not one per row.'
		);

		// Rendering must add nothing on top of that single read.
		$before = $database->reads;
		foreach ( [ 10, 11, 12 ] as $id ) {
			ob_start();
			$column->render( 'saltus_rel_actors', $id );
			ob_end_clean();
		}

		$this->assertSame( $before, $database->reads, 'Rendering a primed column must issue no query.' );
	}

	public function testRenderLinksEachRelatedPostToItsEditor(): void {
		$manager = $this->manager();
		$column  = new RelationshipColumn( $manager );

		$movie = $this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person', 'Sigourney Weaver' );
		$manager->attach( 10, 'movie', 'actors', 20 );

		$column->prime( [ $movie ], 'movie' );

		ob_start();
		$column->render( 'saltus_rel_actors', 10 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Sigourney Weaver', $html );
		$this->assertStringContainsString( 'post=20', $html );
		$this->assertStringContainsString( '<a href=', $html );
	}

	public function testRenderSummarizesBeyondThreeRelated(): void {
		$manager = $this->manager();
		$column  = new RelationshipColumn( $manager );

		$movie = $this->seed_post( 10, 'movie' );
		foreach ( [ 20, 21, 22, 23, 24 ] as $id ) {
			$this->seed_post( $id, 'person', 'Person ' . $id );
			$manager->attach( 10, 'movie', 'actors', $id );
		}

		$column->prime( [ $movie ], 'movie' );

		ob_start();
		$column->render( 'saltus_rel_actors', 10 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Person 20', $html );
		$this->assertStringContainsString( 'Person 22', $html );
		$this->assertStringNotContainsString( 'Person 23', $html, 'Only three titles are shown inline.' );
		$this->assertStringContainsString( '+2 more', $html );
	}

	public function testRenderShowsAnAccessibleEmptyStateWhenNothingIsRelated(): void {
		$manager = $this->manager();
		$column  = new RelationshipColumn( $manager );

		$movie = $this->seed_post( 10, 'movie' );
		$column->prime( [ $movie ], 'movie' );

		ob_start();
		$column->render( 'saltus_rel_actors', 10 );
		$html = (string) ob_get_clean();

		// A bare dash is meaningless to a screen reader, so it is hidden and paired
		// with text.
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
		$this->assertStringContainsString( 'screen-reader-text', $html );
		$this->assertStringContainsString( 'None', $html );
	}

	/**
	 * If priming never ran, rendering must not silently fall back to a per-row
	 * query — that would turn a missed hook into an N+1 nobody notices.
	 */
	public function testRenderWithoutPrimingIssuesNoQuery(): void {
		$database = new CountingDatabase();
		$manager  = $this->manager( new RelationshipStore( $database ) );
		$column   = new RelationshipColumn( $manager );

		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person', 'Ripley' );
		$manager->attach( 10, 'movie', 'actors', 20 );

		$database->reset();

		ob_start();
		$column->render( 'saltus_rel_actors', 10 );
		$html = (string) ob_get_clean();

		$this->assertSame( 0, $database->reads, 'An unprimed render must not query.' );
		$this->assertSame( '', $html );
	}

	public function testRenderIgnoresColumnsThatAreNotOurs(): void {
		$manager = $this->manager();
		$column  = new RelationshipColumn( $manager );

		$movie = $this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person', 'Ripley' );
		$manager->attach( 10, 'movie', 'actors', 20 );
		$column->prime( [ $movie ], 'movie' );

		ob_start();
		$column->render( 'title', 10 );
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html, 'Another plugin\'s column must not be written into.' );
	}

	public function testPrimingIgnoresNonPostEntries(): void {
		$column = new RelationshipColumn( $this->manager() );

		$this->assertSame( [ 'not-a-post' ], $column->prime( [ 'not-a-post' ], 'movie' ) );
	}

	public function testPrimingReturnsPostsUnchanged(): void {
		$manager = $this->manager();
		$column  = new RelationshipColumn( $manager );

		$movie = $this->seed_post( 10, 'movie' );

		$this->assertSame( [ $movie ], $column->prime( [ $movie ], 'movie' ), 'the_posts must pass its input through.' );
	}

	// --- Bulk actions ---

	public function testBulkActionsOfferAttachAndDetachPerRelationship(): void {
		$bulk = new RelationshipBulkActions( $this->manager() );

		$actions = $bulk->add_actions( [ 'trash' => 'Move to Trash' ], 'movie' );

		$this->assertArrayHasKey( 'saltus_attach_actors', $actions );
		$this->assertArrayHasKey( 'saltus_detach_actors', $actions );
		$this->assertArrayHasKey( 'trash', $actions, 'Core bulk actions must survive.' );
	}

	public function testBulkAttachAppliesToEverySelectedPost(): void {
		$manager = $this->manager();
		$bulk    = new RelationshipBulkActions( $manager );

		foreach ( [ 10, 11, 12 ] as $id ) {
			$this->seed_post( $id, 'movie' );
		}
		$this->seed_post( 20, 'person', 'Ripley' );

		$redirect = $bulk->handle(
			'https://example.test/wp-admin/edit.php',
			'saltus_attach_actors',
			[ 10, 11, 12 ],
			'movie',
			[ RelationshipBulkActions::ARG_RELATED => '20' ]
		);

		foreach ( [ 10, 11, 12 ] as $id ) {
			$this->assertSame( [ 20 ], $manager->get_related_ids( $id, 'movie', 'actors' ) );
		}
		$this->assertStringContainsString( 'saltus_rel_ok=3', $redirect );
	}

	public function testBulkDetachRemovesFromEverySelectedPost(): void {
		$manager = $this->manager();
		$bulk    = new RelationshipBulkActions( $manager );

		foreach ( [ 10, 11 ] as $id ) {
			$this->seed_post( $id, 'movie' );
		}
		$this->seed_post( 20, 'person', 'Ripley' );

		foreach ( [ 10, 11 ] as $id ) {
			$manager->attach( $id, 'movie', 'actors', 20 );
		}

		$bulk->handle(
			'https://example.test/wp-admin/edit.php',
			'saltus_detach_actors',
			[ 10, 11 ],
			'movie',
			[ RelationshipBulkActions::ARG_RELATED => '20' ]
		);

		foreach ( [ 10, 11 ] as $id ) {
			$this->assertSame( [], $manager->get_related_ids( $id, 'movie', 'actors' ) );
		}
	}

	/**
	 * Bulk must not have a fast path that skips the checks a single attach makes.
	 * `has_one` is capped at one, and a bulk attach of a second target has to be
	 * refused per post rather than overwriting.
	 */
	public function testBulkAttachRespectsCardinality(): void {
		$models = [
			'movie'  => new RelationshipModel(
				'movie',
				[
					'director' => [
						'type'       => 'has_one',
						'model'      => 'person',
						'reciprocal' => 'directed',
					],
				]
			),
			'person' => new RelationshipModel( 'person', [] ),
		];

		$manager = new RelationshipManager(
			new RelationshipRegistry( new RelationshipModeler( $this->createStub( ModelFactory::class ), $models ) ),
			new RelationshipStore( null )
		);
		$bulk    = new RelationshipBulkActions( $manager );

		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person', 'Cameron' );
		$this->seed_post( 21, 'person', 'Scott' );

		$manager->attach( 10, 'movie', 'director', 20 );

		$redirect = $bulk->handle(
			'https://example.test/wp-admin/edit.php',
			'saltus_attach_director',
			[ 10 ],
			'movie',
			[ RelationshipBulkActions::ARG_RELATED => '21' ]
		);

		$this->assertSame( [ 20 ], $manager->get_related_ids( 10, 'movie', 'director' ), 'A has_one must not be overwritten by a bulk attach.' );
		$this->assertStringContainsString( 'saltus_rel_bad=1', $redirect );
	}

	public function testBulkActionWithoutATargetAsksForOne(): void {
		$manager = $this->manager();
		$bulk    = new RelationshipBulkActions( $manager );

		$this->seed_post( 10, 'movie' );

		$redirect = $bulk->handle(
			'https://example.test/wp-admin/edit.php',
			'saltus_attach_actors',
			[ 10 ],
			'movie',
			[]
		);

		$this->assertStringContainsString( 'needs_target', $redirect );
		$this->assertSame( [], $manager->get_related_ids( 10, 'movie', 'actors' ) );
	}

	public function testUnrelatedBulkActionPassesTheRedirectThrough(): void {
		$bulk = new RelationshipBulkActions( $this->manager() );

		$url = 'https://example.test/wp-admin/edit.php?post_type=movie';

		$this->assertSame( $url, $bulk->handle( $url, 'trash', [ 10 ], 'movie', [] ) );
	}

	public function testBulkSkipsPostsTheUserCannotEdit(): void {
		global $wp_current_user_can;

		$manager = $this->manager();
		$bulk    = new RelationshipBulkActions( $manager );

		$this->seed_post( 10, 'movie' );
		$this->seed_post( 20, 'person', 'Ripley' );

		$wp_current_user_can = [ 'edit_post' => false ];

		$redirect = $bulk->handle(
			'https://example.test/wp-admin/edit.php',
			'saltus_attach_actors',
			[ 10 ],
			'movie',
			[ RelationshipBulkActions::ARG_RELATED => '20' ]
		);

		$this->assertSame( [], $manager->get_related_ids( 10, 'movie', 'actors' ) );
		$this->assertStringContainsString( 'saltus_rel_bad=1', $redirect );
	}

	public function testNoticeReportsAppliedAndFailedCounts(): void {
		$bulk = new RelationshipBulkActions( $this->manager() );

		ob_start();
		$bulk->render_notice(
			[
				RelationshipBulkActions::ARG_RESULT => 'attach',
				'saltus_rel_ok'                     => '3',
				'saltus_rel_bad'                    => '1',
			]
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Attached 3 posts.', $html );
		$this->assertStringContainsString( 'could not be changed', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
	}

	public function testNoticeIsSilentWithoutAResult(): void {
		$bulk = new RelationshipBulkActions( $this->manager() );

		ob_start();
		$bulk->render_notice( [] );

		$this->assertSame( '', (string) ob_get_clean() );
	}
}

/**
 * Database double that counts SELECTs, so the eager-loading claim is measured at
 * the real query boundary rather than at a wrapper method.
 *
 * `RelationshipStore` is final, so it cannot be subclassed to intercept reads;
 * it does accept an `AuditDatabase`, which is where the queries actually go.
 */
class CountingDatabase implements \Saltus\WP\Framework\MCP\Audit\AuditDatabase {

	public int $reads = 0;

	/** @var list<array<string, mixed>> */
	public array $rows = [];

	private int $next_id = 1;

	public function reset(): void {
		$this->reads = 0;
	}

	public function prefix(): string {
		return 'wp_';
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string> $format
	 */
	public function insert( string $table, array $data, array $format = [] ) {
		$data['id']   = $this->next_id++;
		$this->rows[] = $data;

		return 1;
	}

	public function query( string $query ) {
		// DDL and DELETE. Deletes are applied so detach is observable.
		if ( stripos( $query, 'DELETE' ) === 0 ) {
			$this->apply_delete( $query );
		}

		return true;
	}

	public function get_charset_collate(): string {
		return '';
	}

	/**
	 * Answer a SELECT from the in-memory rows.
	 *
	 * Only the shapes `RelationshipStore` issues are understood: a filter on one
	 * key plus an `IN` list of owner ids.
	 *
	 * @param mixed $output
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $query, $output = null ) {
		++$this->reads;

		$key    = $this->captured( $query, "/relationship_key = '([^']+)'/" );
		$column = $this->captured( $query, '/AND (from_post_id|to_post_id) IN/' );
		$ids    = [];
		if ( preg_match( '/IN \(([0-9, ]+)\)/', $query, $matches ) === 1 ) {
			$ids = array_map( 'intval', array_map( 'trim', explode( ',', $matches[1] ) ) );
		}

		$found = [];
		foreach ( $this->rows as $row ) {
			if ( $key !== '' && (string) ( $row['relationship_key'] ?? '' ) !== $key ) {
				continue;
			}
			if ( $column !== '' && $ids !== [] && ! in_array( (int) ( $row[ $column ] ?? 0 ), $ids, true ) ) {
				continue;
			}
			$found[] = $row;
		}

		usort(
			$found,
			static fn( array $a, array $b ): int => ( (int) ( $a['order_index'] ?? 0 ) ) <=> ( (int) ( $b['order_index'] ?? 0 ) )
		);

		return $found;
	}

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_string( $arg ) ? "'" . $arg . "'" : (string) $arg;
			$query       = preg_replace( '/%[dsf]/', $replacement, $query, 1 );
		}

		return (string) $query;
	}

	/** Drop rows a DELETE targets, matching on key and owner id. */
	private function apply_delete( string $query ): void {
		$key    = $this->captured( $query, "/relationship_key = '([^']+)'/" );
		$column = $this->captured( $query, '/AND (from_post_id|to_post_id) =/' );
		$owner  = (int) $this->captured( $query, '/= ([0-9]+)/' );
		$other  = $column === 'from_post_id' ? 'to_post_id' : 'from_post_id';
		$target = (int) $this->captured( $query, '/' . $other . ' = ([0-9]+)/' );

		$this->rows = array_values(
			array_filter(
				$this->rows,
				static function ( array $row ) use ( $key, $column, $owner, $other, $target ): bool {
					if ( $key !== '' && (string) ( $row['relationship_key'] ?? '' ) !== $key ) {
						return true;
					}
					if ( $column !== '' && (int) ( $row[ $column ] ?? 0 ) !== $owner ) {
						return true;
					}

					return $target > 0 && (int) ( $row[ $other ] ?? 0 ) !== $target;
				}
			)
		);
	}

	private function captured( string $subject, string $pattern ): string {
		return preg_match( $pattern, $subject, $matches ) === 1 ? (string) $matches[1] : '';
	}
}
