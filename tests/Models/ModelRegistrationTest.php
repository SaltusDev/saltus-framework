<?php

namespace Saltus\WP\Framework\Tests\Models;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Models\PostType;
use Saltus\WP\Framework\Models\Taxonomy;
use WP_Post;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * What setup() hands to register_post_type() and register_taxonomy().
 *
 * @covers \Saltus\WP\Framework\Models\PostType
 * @covers \Saltus\WP\Framework\Models\Taxonomy
 */
class ModelRegistrationTest extends TestCase {

	protected function setUp(): void {
		global $wp_post_types_registered, $wp_taxonomies_registered, $wp_filters_registered, $wp_actions_registered;

		$wp_post_types_registered = [];
		$wp_taxonomies_registered = [];
		$wp_filters_registered    = [];
		$wp_actions_registered    = [];
	}

	private function post_type( array $config ): PostType {
		$model = new PostType( new NoFile( $config + [ 'name' => 'movie', 'type' => 'post_type' ] ) );
		$model->setup();

		return $model;
	}

	private function taxonomy( array $config ): Taxonomy {
		$model = new Taxonomy( new NoFile( $config + [ 'name' => 'genre', 'type' => 'tag' ] ) );
		$model->setup();

		return $model;
	}

	/** @return array<string, mixed> */
	private function registered_post_type( string $name = 'movie' ): array {
		global $wp_post_types_registered;

		$this->assertArrayHasKey( $name, $wp_post_types_registered, 'Post type was never registered.' );

		return $wp_post_types_registered[ $name ]['args'];
	}

	public function testPostTypeIsRegisteredUnderItsSanitizedName(): void {
		global $wp_post_types_registered;

		$model = new PostType( new NoFile( [ 'name' => 'My Movie', 'type' => 'post_type' ] ) );
		$model->setup();

		$this->assertArrayHasKey( 'mymovie', $wp_post_types_registered );
	}

	public function testPostTypeDefaultsArePublicAndRestEnabled(): void {
		$this->post_type( [] );
		$args = $this->registered_post_type();

		$this->assertTrue( $args['public'] );
		$this->assertTrue( $args['show_in_rest'] );
		$this->assertSame( 5, $args['menu_position'] );
	}

	public function testPostTypeGetTypeIdentifiesTheModelKind(): void {
		$this->assertSame( 'post_type', $this->post_type( [] )->get_type() );
		$this->assertSame( 'taxonomy', $this->taxonomy( [] )->get_type() );
	}

	public function testSupportsIsForwardedFromConfig(): void {
		$this->post_type( [ 'supports' => [ 'title', 'editor', 'thumbnail' ] ] );

		$this->assertSame( [ 'title', 'editor', 'thumbnail' ], $this->registered_post_type()['supports'] );
	}

	/**
	 * Meta fields are stored as custom fields, so declaring meta has to pull in
	 * the 'custom-fields' support or the values never persist.
	 */
	public function testDeclaringMetaAddsCustomFieldsSupport(): void {
		$this->post_type( [ 'supports' => [ 'title' ], 'meta' => [ 'box' => [ 'fields' => [] ] ] ] );

		$this->assertSame( [ 'title', 'custom-fields' ], $this->registered_post_type()['supports'] );
	}

	public function testMetaWithoutExplicitSupportsStillGetsCustomFields(): void {
		$this->post_type( [ 'meta' => [ 'box' => [ 'fields' => [] ] ] ] );

		$this->assertSame( [ 'custom-fields' ], $this->registered_post_type()['supports'] );
	}

	public function testMetaIsCarriedOnTheRegistrationArgs(): void {
		$meta = [ 'box' => [ 'fields' => [ 'isbn' => [ 'type' => 'text' ] ] ] ];

		$model = $this->post_type( [ 'meta' => $meta ] );

		$this->assertSame( $meta, $model->get_args()['meta'] );
		$this->assertTrue( $model->has_meta() );
	}

	public function testHasMetaIsFalseWithoutConfiguredMeta(): void {
		$this->assertFalse( $this->post_type( [] )->has_meta() );
	}

	public function testUserOptionsOverrideRegistrationDefaults(): void {
		$this->post_type( [ 'options' => [ 'public' => false, 'show_in_rest' => false, 'menu_icon' => 'dashicons-video' ] ] );
		$args = $this->registered_post_type();

		$this->assertFalse( $args['public'] );
		$this->assertFalse( $args['show_in_rest'] );
		$this->assertSame( 'dashicons-video', $args['menu_icon'] );
	}

	public function testDisabledPostTypeIsNeverRegistered(): void {
		global $wp_post_types_registered;

		$this->post_type( [ 'active' => false ] );

		$this->assertSame( [], $wp_post_types_registered );
	}

	public function testEnterTitleHereFilterIsRegisteredOnlyWhenConfigured(): void {
		global $wp_filters_registered;

		$this->post_type( [] );
		$this->assertArrayNotHasKey( 'enter_title_here', $wp_filters_registered );

		$wp_filters_registered = [];
		$this->post_type( [ 'labels' => [ 'overrides' => [ 'ui' => [ 'enter_title_here' => 'Movie title' ] ] ] ] );
		$this->assertArrayHasKey( 'enter_title_here', $wp_filters_registered );
	}

	public function testEnterTitleHereAppliesOnlyToThisPostType(): void {
		$model = $this->post_type( [ 'labels' => [ 'overrides' => [ 'ui' => [ 'enter_title_here' => 'Movie title' ] ] ] ] );

		$mine    = new WP_Post( [ 'ID' => 1, 'post_type' => 'movie' ] );
		$foreign = new WP_Post( [ 'ID' => 2, 'post_type' => 'page' ] );

		$this->assertSame( 'Movie title', $model->enter_title_here( 'Add title', $mine ) );
		$this->assertSame( 'Add title', $model->enter_title_here( 'Add title', $foreign ) );
	}

	public function testDisableBlockEditorOnlyTargetsThisPostType(): void {
		$model = $this->post_type( [] );

		$this->assertFalse( $model->disable_block_editor( true, 'movie' ) );
		$this->assertTrue( $model->disable_block_editor( true, 'page' ) );
		$this->assertFalse( $model->disable_block_editor( false, 'page' ), 'The incoming status must pass through untouched.' );
	}

	public function testTaxonomyIsRegisteredWithRestEnabled(): void {
		global $wp_taxonomies_registered;

		$this->taxonomy( [] );

		$this->assertArrayHasKey( 'genre', $wp_taxonomies_registered );
		$this->assertTrue( $wp_taxonomies_registered['genre']['args']['show_in_rest'] );
	}

	/**
	 * A 'tag' taxonomy is flat and a 'cat' one is nested; getting this backwards
	 * changes the term UI and how terms can be assigned.
	 */
	public function testTaxonomyTypeDecidesHierarchy(): void {
		$this->assertFalse( $this->taxonomy( [ 'type' => 'tag' ] )->is_hierarchical() );

		foreach ( [ 'cat', 'category' ] as $type ) {
			$model = new Taxonomy( new NoFile( [ 'name' => 'section', 'type' => $type ] ) );
			$model->setup();
			$this->assertTrue( $model->is_hierarchical(), sprintf( 'type: %s must be hierarchical.', $type ) );
		}
	}

	/**
	 * Without a type there is nothing to derive hierarchy from, so the model
	 * registers with no options rather than guessing.
	 */
	public function testTaxonomyWithoutATypeRegistersWithoutOptions(): void {
		$model = new Taxonomy( new NoFile( [ 'name' => 'genre' ] ) );
		$model->setup();

		$this->assertSame( [], $model->get_options() );
		$this->assertFalse( $model->is_hierarchical() );
	}

	public function testTaxonomyAssociationsDefaultToEmpty(): void {
		$this->assertSame( [], $this->taxonomy( [] )->get_associations() );
	}

	public function testTaxonomyAssociationsComeFromConfig(): void {
		global $wp_taxonomies_registered;

		$model = $this->taxonomy( [ 'associations' => [ 'movie', 'book' ] ] );

		$this->assertSame( [ 'movie', 'book' ], $model->get_associations() );
		$this->assertSame( [ 'movie', 'book' ], $wp_taxonomies_registered['genre']['object_type'] );
	}

	public function testTaxonomyAssociationsAcceptASingleString(): void {
		$this->assertSame( [ 'movie' ], $this->taxonomy( [ 'associations' => 'movie' ] )->get_associations() );
	}

	public function testTaxonomyAssociationsDropEmptyEntries(): void {
		$this->assertSame(
			[ 'movie' ],
			$this->taxonomy( [ 'associations' => [ 'movie', '', '  ' => '' ] ] )->get_associations()
		);
	}

	public function testTaxonomyRegistersItsAssociationsOnInit(): void {
		global $wp_actions_registered;

		$this->taxonomy( [ 'associations' => [ 'movie' ] ] );

		$hooks = array_column( $wp_actions_registered, 'hook_name' );
		$this->assertContains( 'init', $hooks );
	}

	public function testRegisterAssociationsRunsWithoutErrorForEachObjectType(): void {
		$model = $this->taxonomy( [ 'associations' => [ 'movie', 'book' ] ] );

		$model->register_associations();

		// register_taxonomy_for_object_type() is a no-op stub; the assertion is
		// that iterating a string-or-array association list does not fatal.
		$this->assertSame( [ 'movie', 'book' ], $model->get_associations() );
	}

	public function testTaxonomyMetaIsCarriedOnTheArgs(): void {
		$meta = [ 'box' => [ 'fields' => [ 'colour' => [ 'type' => 'text' ] ] ] ];

		$this->assertSame( $meta, $this->taxonomy( [ 'meta' => $meta ] )->get_args()['meta'] );
	}

	public function testDisabledTaxonomyIsNeverRegistered(): void {
		global $wp_taxonomies_registered;

		$this->taxonomy( [ 'active' => false ] );

		$this->assertSame( [], $wp_taxonomies_registered );
	}

	public function testTaxonomyLabelsAreDerivedFromTheName(): void {
		$labels = $this->taxonomy( [] )->get_args()['labels'];

		$this->assertSame( 'Genres', $labels['name'] );
		$this->assertSame( 'Genre', $labels['singular_name'] );
		$this->assertSame( 'Separate genres with commas', $labels['separate_items_with_commas'] );
		$this->assertSame( 'No genres', $labels['no_terms'] );
	}
}
