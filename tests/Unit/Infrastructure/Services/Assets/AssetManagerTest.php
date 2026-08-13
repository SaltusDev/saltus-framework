<?php

namespace Saltus\WP\Framework\Tests\Unit\Infrastructure\Services\Assets;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Infrastructure\Container\Invalid;
use Saltus\WP\Framework\Infrastructure\Plugin\Project;
use Saltus\WP\Framework\Infrastructure\Services\Assets\Asset;
use Saltus\WP\Framework\Infrastructure\Services\Assets\AssetManager;
use Saltus\WP\Framework\Infrastructure\Services\Assets\AssetsContainer;

require_once dirname( __DIR__, 4 ) . '/Rest/functions.php';

/**
 * AssetManager turns a relative source path into a registered WordPress handle.
 *
 * Handles are derived, never passed in, so the naming scheme is the contract:
 * every enqueue, dependency reference, and localize call has to arrive at the
 * same string from the same source path.
 *
 * SALTUS_ENV is a constant defined on first construction, so these tests assert
 * the development-mode behaviour that the suite runs under.
 *
 * @covers \Saltus\WP\Framework\Infrastructure\Services\Assets\AssetManager
 * @covers \Saltus\WP\Framework\Infrastructure\Services\Assets\Asset
 * @covers \Saltus\WP\Framework\Infrastructure\Services\Assets\AssetsContainer
 */
class AssetManagerTest extends TestCase {

	private AssetManager $manager;

	protected function setUp(): void {
		global $wp_scripts_registered, $wp_styles_registered, $wp_scripts_enqueued, $wp_styles_enqueued, $wp_scripts_localized, $wp_blocks_registered, $wp_actions_registered;

		$wp_scripts_registered = [];
		$wp_styles_registered  = [];
		$wp_scripts_enqueued   = [];
		$wp_styles_enqueued    = [];
		$wp_scripts_localized  = [];
		$wp_blocks_registered  = [];
		$wp_actions_registered = [];

		$this->manager = new AssetManager(
			[ 'project' => new Project( 'saltus', '1.8.4', '/plugins/saltus/saltus.php' ) ]
		);
	}

	public function testConstructionRequiresAProject(): void {
		$this->expectException( Invalid::class );

		new AssetManager( [] );
	}

	public function testConstructionRejectsAProjectOfTheWrongType(): void {
		$this->expectException( Invalid::class );

		new AssetManager( [ 'project' => 'not-a-project' ] );
	}

	public function testDevelopmentModeUsesUnminifiedPathsWithoutADistDirectory(): void {
		// The suite runs with SALTUS_ENV defaulting to 'development'.
		$this->assertSame( 'development', SALTUS_ENV );
		$this->assertSame( '', $this->manager->dir );
		$this->assertSame( '', $this->manager->suffix );
	}

	public function testRootFilePathComesFromTheProject(): void {
		$this->assertSame( '/plugins/saltus/saltus.php', $this->manager->root_file_path );
	}

	/**
	 * The handle format is <project>_<ext>_<filename>, which is what makes a
	 * handle predictable from a source path alone.
	 */
	public function testStyleHandleIsDerivedFromProjectExtensionAndFilename(): void {
		$this->assertSame( 'saltus_css_admin', $this->manager->register_style( 'assets/css/admin.css' ) );
	}

	public function testScriptHandleIsDerivedTheSameWay(): void {
		$this->assertSame( 'saltus_js_admin', $this->manager->register_script( 'assets/js/admin.js' ) );
	}

	public function testRegisteringAStyleResolvesTheSourceToAPluginUrl(): void {
		global $wp_styles_registered;

		$handle = $this->manager->register_style( 'assets/css/admin.css' );

		$this->assertArrayHasKey( $handle, $wp_styles_registered );
		$this->assertSame(
			'http://example.com/wp-content/plugins/assets/css/admin.css',
			$wp_styles_registered[ $handle ]['src']
		);
		$this->assertSame( '1.8.4', $wp_styles_registered[ $handle ]['ver'], 'The project version busts the asset cache.' );
	}

	public function testRegisteringAScriptForwardsTheFooterFlag(): void {
		global $wp_scripts_registered;

		$handle = $this->manager->register_script( 'assets/js/admin.js', [], true );

		$this->assertTrue( $wp_scripts_registered[ $handle ]['in_footer'] );
		$this->assertFalse(
			$wp_scripts_registered[ $this->manager->register_script( 'assets/js/other.js' ) ]['in_footer']
		);
	}

	public function testFullPathRegistrationDoesNotRewriteTheSource(): void {
		global $wp_scripts_registered;

		$handle = $this->manager->register_fullpath_script( 'https://cdn.example.com/lib.js' );

		$this->assertSame( 'saltus_js_lib', $handle );
		$this->assertSame( 'https://cdn.example.com/lib.js', $wp_scripts_registered[ $handle ]['src'] );
	}

	/**
	 * An empty src registers a handle with no file, which WordPress uses for
	 * dependency-only or inline-script handles.
	 */
	public function testAnEmptyScriptSourceIsRegisteredAsFalse(): void {
		global $wp_scripts_registered;

		$handle = $this->manager->register_fullpath_script( '' );

		$this->assertFalse( $wp_scripts_registered[ $handle ]['src'] );
	}

	public function testListedDependenciesAreConvertedToDerivedHandles(): void {
		global $wp_scripts_registered;

		$handle = $this->manager->register_script( 'assets/js/admin.js', [ 'assets/js/vendor.js' ] );

		$this->assertSame( [ 'saltus_js_vendor' ], $wp_scripts_registered[ $handle ]['deps'] );
	}

	/**
	 * A dependency marked 'skip' or 'external' is a handle WordPress already
	 * knows (jquery, wp-element), so its key must pass through untransformed.
	 */
	public function testSkipAndExternalDependenciesPassThroughByKey(): void {
		global $wp_scripts_registered;

		$handle = $this->manager->register_script(
			'assets/js/admin.js',
			[ 'jquery' => 'external', 'wp-element' => 'skip', 'assets/js/local.js' ]
		);

		$this->assertSame( [ 'jquery', 'wp-element', 'saltus_js_local' ], $wp_scripts_registered[ $handle ]['deps'] );
	}

	public function testRegisterAssetStoresAStyleInTheContainer(): void {
		$container = new AssetsContainer();

		$handle = $this->manager->register_asset( $container, new Asset( 'assets/css/admin.css' ) );

		$this->assertSame( 'saltus_css_admin', $handle );
		$this->assertTrue( $container->has( $handle ) );
	}

	public function testRegisterAssetStoresAScriptInTheContainer(): void {
		$container = new AssetsContainer();

		$handle = $this->manager->register_asset( $container, new Asset( 'assets/js/admin.js' ) );

		$this->assertSame( 'saltus_js_admin', $handle );
		$this->assertTrue( $container->has( $handle ) );
	}

	/**
	 * An unrecognised extension yields type 'unknown', which must not be
	 * registered — enqueuing it later would emit a handle with no kind.
	 */
	public function testRegisterAssetIgnoresAnUnknownAssetType(): void {
		$container = new AssetsContainer();

		$this->assertSame( '', $this->manager->register_asset( $container, new Asset( 'assets/data.json' ) ) );
		$this->assertSame( [], $container->getAll() );
	}

	public function testRegisterAssetsRegistersEveryAssetInTheList(): void {
		$container = new AssetsContainer();

		$this->manager->register_assets(
			[ new Asset( 'assets/css/admin.css' ), new Asset( 'assets/js/admin.js' ) ],
			$container
		);

		$this->assertSame( [ 'saltus_css_admin', 'saltus_js_admin' ], array_keys( $container->getAll() ) );
	}

	public function testEnqueueAssetsEnqueuesEachRegisteredHandleByKind(): void {
		global $wp_scripts_enqueued, $wp_styles_enqueued;

		$container = new AssetsContainer();
		$this->manager->register_assets(
			[ new Asset( 'assets/css/admin.css' ), new Asset( 'assets/js/admin.js' ) ],
			$container
		);

		$this->manager->enqueue_assets( $container );

		$this->assertSame( [ 'saltus_js_admin' ], array_column( $wp_scripts_enqueued, 'handle' ) );
		$this->assertSame( [ 'saltus_css_admin' ], array_column( $wp_styles_enqueued, 'handle' ) );
	}

	public function testEnqueueAssetsOnAnEmptyContainerDoesNothing(): void {
		global $wp_scripts_enqueued, $wp_styles_enqueued;

		$this->manager->enqueue_assets( new AssetsContainer() );

		$this->assertSame( [], $wp_scripts_enqueued );
		$this->assertSame( [], $wp_styles_enqueued );
	}

	/**
	 * add_data() takes the source path, not the handle, and derives the handle
	 * itself — so the localized object lands on the same handle as the script.
	 */
	public function testAddDataLocalizesAgainstTheDerivedHandle(): void {
		global $wp_scripts_localized;

		$this->manager->add_data( 'assets/js/admin.js', 'SaltusAdmin', [ 'nonce' => 'abc' ] );

		$this->assertSame( 'saltus_js_admin', $wp_scripts_localized[0]['handle'] );
		$this->assertSame( 'SaltusAdmin', $wp_scripts_localized[0]['object_name'] );
		$this->assertSame( [ 'nonce' => 'abc' ], $wp_scripts_localized[0]['l10n'] );
	}

	public function testGutenbergBlockRegistrationAttachesDerivedEditorHandles(): void {
		global $wp_blocks_registered;

		$this->manager->register_gutenberg_block(
			'saltus/card',
			'assets/js/block.js',
			'assets/css/block.css',
			[ 'render_callback' => null ]
		);

		$this->assertArrayHasKey( 'saltus/card', $wp_blocks_registered );
		$this->assertSame( 'saltus_js_block', $wp_blocks_registered['saltus/card']['editor_script'] );
		$this->assertSame( 'saltus_css_block', $wp_blocks_registered['saltus/card']['editor_style'] );
	}

	public function testGutenbergBlockOmitsHandlesThatWereNotSupplied(): void {
		global $wp_blocks_registered;

		$this->manager->register_gutenberg_block( 'saltus/plain', '', '', [] );

		$this->assertArrayNotHasKey( 'editor_script', $wp_blocks_registered['saltus/plain'] );
		$this->assertArrayNotHasKey( 'editor_style', $wp_blocks_registered['saltus/plain'] );
	}

	public function testLoadAdminStylesDefersToTheAdminEnqueueHook(): void {
		global $wp_actions_registered, $wp_styles_enqueued;

		$this->manager->load_admin_styles( 'assets/css/admin.css' );

		$this->assertSame( 'admin_enqueue_scripts', $wp_actions_registered[0]['hook_name'] );
		$this->assertSame( [], $wp_styles_enqueued, 'Nothing may enqueue before the hook fires.' );
	}

	public function testAssetTypeIsDetectedFromTheExtension(): void {
		$this->assertSame( 'script', ( new Asset( 'a/b.js' ) )->get_type() );
		$this->assertSame( 'style', ( new Asset( 'a/b.css' ) )->get_type() );
		$this->assertSame( 'unknown', ( new Asset( 'a/b.txt' ) )->get_type() );
	}

	public function testAssetTypeIgnoresAQueryString(): void {
		$this->assertSame( 'script', ( new Asset( 'a/b.js?ver=2' ) )->get_type() );
		$this->assertSame( 'style', ( new Asset( 'a/b.css?ver=2' ) )->get_type() );
	}

	public function testAnExplicitAssetTypeWinsOverDetection(): void {
		$this->assertSame( 'style', ( new Asset( 'a/b.js', [], false, 'style' ) )->get_type() );
	}

	public function testAssetExposesItsConfiguration(): void {
		$asset = new Asset( 'a/b.js', [ 'jquery' ], true );

		$this->assertSame( 'a/b.js', $asset->get_source() );
		$this->assertSame( [ 'jquery' ], $asset->get_dependencies() );
		$this->assertTrue( $asset->get_in_footer() );
	}

	public function testAssetDefaultsToNoDependenciesInTheHeader(): void {
		$asset = new Asset( 'a/b.js' );

		$this->assertSame( [], $asset->get_dependencies() );
		$this->assertFalse( $asset->get_in_footer() );
	}
}
