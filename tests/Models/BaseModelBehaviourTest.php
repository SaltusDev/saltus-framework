<?php

namespace Saltus\WP\Framework\Tests\Models;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Models\PostType;
use Saltus\WP\Framework\Models\Taxonomy;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * BaseModel: label derivation, config merging, and registration-name validation.
 *
 * These are exercised through PostType because BaseModel is abstract, but the
 * behaviour under test belongs to the base class.
 *
 * @covers \Saltus\WP\Framework\Models\BaseModel
 */
class BaseModelBehaviourTest extends TestCase {

	protected function setUp(): void {
		global $wp_posts, $wp_post_type_objects, $wp_filters_registered;

		$wp_posts              = [];
		$wp_post_type_objects  = [];
		$wp_filters_registered = [];
		unset( $_GET['revision'] );
	}

	protected function tearDown(): void {
		unset( $_GET['revision'] );
	}

	/** Build a PostType without running setup(), so no registration happens. */
	private function model( array $config ): PostType {
		return new PostType( new NoFile( $config + [ 'name' => 'movie', 'type' => 'post_type' ] ) );
	}

	public function testSingularAndPluralLabelsDefaultFromTheName(): void {
		$model = $this->model( [] );

		$this->assertSame( 'Movie', $model->get_label_singular() );
		$this->assertSame( 'Movies', $model->get_label_plural() );
	}

	public function testExplicitLabelsOverrideTheDerivedOnes(): void {
		$model = $this->model(
			[ 'labels' => [ 'has_one' => 'Film', 'has_many' => 'Films' ] ]
		);

		$this->assertSame( 'Film', $model->get_label_singular() );
		$this->assertSame( 'Films', $model->get_label_plural() );
	}

	/**
	 * An initialism must keep its capitals: "FAQ" lower-cased inside a sentence
	 * reads as a typo, so the lower-casing is skipped for 2+ consecutive capitals.
	 */
	public function testInitialismsAreNotLowerCased(): void {
		$model  = $this->model( [ 'labels' => [ 'has_one' => 'FAQ', 'has_many' => 'FAQs' ] ] );
		$labels = $this->labels( $model );

		$this->assertSame( 'No FAQs found.', $labels['not_found'] );
		$this->assertSame( 'Insert into FAQ', $labels['insert_into_item'] );
	}

	public function testOrdinaryLabelsAreLowerCasedInSentences(): void {
		$labels = $this->labels( $this->model( [ 'labels' => [ 'has_one' => 'Film', 'has_many' => 'Films' ] ] ) );

		$this->assertSame( 'No films found.', $labels['not_found'] );
		$this->assertSame( 'Insert into film', $labels['insert_into_item'] );
	}

	public function testFeaturedImageLabelsAppearOnlyWhenConfigured(): void {
		$without = $this->labels( $this->model( [] ) );
		$this->assertArrayNotHasKey( 'featured_image', $without );

		$with = $this->labels( $this->model( [ 'labels' => [ 'featured_image' => 'Poster' ] ] ) );
		$this->assertSame( 'Poster', $with['featured_image'] );
		$this->assertSame( 'Set poster', $with['set_featured_image'] );
		$this->assertSame( 'Remove poster', $with['remove_featured_image'] );
		$this->assertSame( 'Use as poster', $with['use_featured_image'] );
	}

	public function testFeaturedImageLabelIsExposedOnTheModel(): void {
		$this->assertSame( 'Poster', $this->model( [ 'labels' => [ 'featured_image' => 'Poster' ] ] )->get_featured_image_label() );
		$this->assertSame( '', $this->model( [] )->get_featured_image_label() );
	}

	public function testLabelOverridesAreMergedOverTheDefaults(): void {
		$model = $this->model( [ 'labels' => [ 'overrides' => [ 'labels' => [ 'not_found' => 'Nothing here.' ] ] ] ] );

		$labels = $this->labels( $model );

		$this->assertSame( 'Nothing here.', $labels['not_found'] );
		$this->assertSame( 'All Movies', $labels['all_items'], 'Unlisted labels must keep their defaults.' );
	}

	public function testDisabledModelsSkipAllSetup(): void {
		$model = $this->model( [ 'active' => false ] );

		// The constructor returns early, so nothing was derived.
		$this->assertSame( '', $model->get_name() );
		$this->assertSame( '', $model->get_label_singular() );
	}

	public function testTruthyNonTrueValuesDisableTheModel(): void {
		$this->assertSame( '', $this->model( [ 'active' => 1 ] )->get_name() );
		$this->assertSame( '', $this->model( [ 'active' => 'yes' ] )->get_name() );
	}

	public function testActiveTrueIsTreatedAsEnabled(): void {
		$this->assertSame( 'movie', $this->model( [ 'active' => true ] )->get_name() );
	}

	/**
	 * An absent 'active' key means "not disabled" — the framework opts models in
	 * by default, and only an explicit non-true value turns one off.
	 */
	public function testMissingActiveKeyIsTreatedAsEnabled(): void {
		$this->assertSame( 'movie', $this->model( [] )->get_name() );
	}

	/**
	 * `active: false` is the obvious way to switch a model off, and what "whether
	 * to register this model" in docs/api/config-reference.md implies.
	 *
	 * It needs its own check because `is_disabled()` also tests
	 * `empty( $this->data['active'] )`, and `empty( false )` is true — so without
	 * the explicit comparison a false reads as "no value set" and the model
	 * registers regardless.
	 */
	public function testActiveFalseDisablesTheModel(): void {
		$model = $this->model( [ 'active' => false ] );

		$this->assertSame( '', $model->get_name() );
		$this->assertSame( '', $model->get_label_singular() );
	}

	/**
	 * Only a real false disables. Other falsy values stay enabled: an absent key
	 * means "no opinion", and 0 or '' are config mistakes rather than an intent
	 * to disable — treating them as off would silently drop a model.
	 */
	public function testOtherFalsyActiveValuesStillRegisterTheModel(): void {
		foreach ( [ 0, '' ] as $value ) {
			$this->assertSame(
				'movie',
				$this->model( [ 'active' => $value ] )->get_name(),
				sprintf( 'active: %s must not disable the model.', var_export( $value, true ) )
			);
		}
	}

	public function testOptionsMergeUserValuesOverFrameworkDefaults(): void {
		$model = $this->model( [ 'options' => [ 'public' => false, 'menu_icon' => 'dashicons-video' ] ] );
		$model->setup();

		$options = $model->get_options();

		$this->assertFalse( $options['public'], 'A user option must win over the default.' );
		$this->assertSame( 'dashicons-video', $options['menu_icon'] );
		$this->assertSame( 5, $options['menu_position'], 'Untouched defaults must survive the merge.' );
	}

	public function testRestBaseFallsBackToTheRegistrationName(): void {
		$plain = $this->model( [] );
		$plain->setup();
		$this->assertSame( 'movie', $plain->get_rest_base() );

		$custom = $this->model( [ 'options' => [ 'rest_base' => 'films' ] ] );
		$custom->setup();
		$this->assertSame( 'films', $custom->get_rest_base() );
	}

	public function testRegistrationNameIsSanitized(): void {
		$model = new PostType( new NoFile( [ 'name' => 'My Movie!', 'type' => 'post_type' ] ) );

		$this->assertSame( 'mymovie', $model->get_registration_name() );
	}

	public function testEmptyRegistrationNameIsRejected(): void {
		$model = new PostType( new NoFile( [ 'name' => '!!!', 'type' => 'post_type' ] ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Model name cannot be empty.' );

		$model->get_registration_name();
	}

	/**
	 * WordPress silently truncates over-long keys, which produces a post type
	 * that cannot be queried by the name in the config. Failing loudly is better.
	 */
	public function testPostTypeNamesOverTwentyCharactersAreRejected(): void {
		$model = new PostType( new NoFile( [ 'name' => str_repeat( 'a', 21 ), 'type' => 'post_type' ] ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'exceeds the maximum 20 character limit for post_type' );

		$model->get_registration_name();
	}

	public function testPostTypeNamesAtExactlyTwentyCharactersAreAccepted(): void {
		$name  = str_repeat( 'a', 20 );
		$model = new PostType( new NoFile( [ 'name' => $name, 'type' => 'post_type' ] ) );

		$this->assertSame( $name, $model->get_registration_name() );
	}

	public function testTaxonomyNamesGetTheLongerThirtyTwoCharacterLimit(): void {
		$name  = str_repeat( 'a', 32 );
		$model = new Taxonomy( new NoFile( [ 'name' => $name, 'type' => 'taxonomy' ] ) );

		$this->assertSame( $name, $model->get_registration_name() );

		$too_long = new Taxonomy( new NoFile( [ 'name' => str_repeat( 'a', 33 ), 'type' => 'taxonomy' ] ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'exceeds the maximum 32 character limit for taxonomy' );

		$too_long->get_registration_name();
	}

	public function testConfigIsExposedVerbatim(): void {
		$config = [ 'name' => 'movie', 'type' => 'post_type', 'meta' => [ 'box' => [] ] ];

		$this->assertSame( $config, ( new PostType( new NoFile( $config ) ) )->get_config() );
	}

	/** Read the labels the model prepared for registration. */
	private function labels( PostType $model ): array {
		$model->setup();

		return $model->get_args()['labels'];
	}
}
