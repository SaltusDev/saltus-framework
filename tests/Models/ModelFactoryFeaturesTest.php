<?php

namespace Saltus\WP\Framework\Tests\Models;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Infrastructure\Container\Container;
use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Models\ModelFactory;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * Feature dispatch must not crash on a misplaced config key.
 *
 * `features` maps a feature name to a registered service, and each such service
 * exposes a static `make()`. Not every container id is a per-model feature
 * though: `blocks` is a *top-level* key whose service has no `make()`, so writing
 * it under `features` used to raise a fatal `Call to undefined method` — a config
 * typo taking the whole site down.
 *
 * @covers \Saltus\WP\Framework\Models\ModelFactory
 */
class ModelFactoryFeaturesTest extends TestCase {

	protected function setUp(): void {
		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();
	}

	private function reset(): void {
		global $wp_post_types_registered, $wp_filters_registered;

		$wp_post_types_registered = [];
		$wp_filters_registered    = [];
	}

	/**
	 * A container whose ids resolve to the given service objects.
	 *
	 * @param array<string, object> $services id => service instance.
	 */
	private function container( array $services ): Container {
		$container = $this->createStub( Container::class );
		$container->method( 'has' )->willReturnCallback(
			static fn( string $id ): bool => isset( $services[ $id ] )
		);
		$container->method( 'get' )->willReturnCallback(
			static fn( string $id ) => $services[ $id ] ?? null
		);

		return $container;
	}

	/** Registers a post type with the given `features` section. */
	private function create_with_features( Container $container, array $features ): void {
		( new ModelFactory( $container, [] ) )->create(
			new NoFile(
				[
					'type'     => 'cpt',
					'name'     => 'movie',
					'features' => $features,
				]
			)
		);
	}

	/**
	 * The bug: `blocks` is a registered id with no `make()`, so this raised
	 * `Error: Call to undefined method`. It must now be skipped like any other
	 * unusable feature.
	 */
	public function testAServiceWithoutMakeIsSkippedRatherThanFatal(): void {
		$without_make = new class() {
			// Deliberately empty: this is what `Blocks` looks like to feature dispatch.
		};

		$this->create_with_features( $this->container( [ 'blocks' => $without_make ] ), [ 'blocks' => true ] );

		$this->assertTrue( true, 'Reaching this line without a fatal is the assertion.' );
	}

	/** A real feature still dispatches — the guard must not skip everything. */
	public function testAServiceWithMakeStillDispatches(): void {
		$with_make = new class() {
			public static bool $made = false;

			public static function make( string $name, array $project, array $args ): object {
				self::$made = true;

				return new \stdClass();
			}
		};

		$with_make::$made = false;

		$this->create_with_features( $this->container( [ 'admin_cols' => $with_make ] ), [ 'admin_cols' => [ 'title' => 'title' ] ] );

		$this->assertTrue( $with_make::$made, 'A feature exposing make() must still be dispatched.' );
	}

	/**
	 * A misplaced key must not stop the features declared after it. Ordering
	 * matters here: if the guard threw or returned instead of continuing, the
	 * second feature would never be reached.
	 */
	public function testAMisplacedKeyDoesNotBlockLaterFeatures(): void {
		$without_make = new class() {};

		$with_make = new class() {
			public static bool $made = false;

			public static function make( string $name, array $project, array $args ): object {
				self::$made = true;

				return new \stdClass();
			}
		};

		$with_make::$made = false;

		$this->create_with_features(
			$this->container(
				[
					'blocks'     => $without_make,
					'admin_cols' => $with_make,
				]
			),
			[
				'blocks'     => true,
				'admin_cols' => [ 'title' => 'title' ],
			]
		);

		$this->assertTrue( $with_make::$made, 'A feature after the misplaced key must still dispatch.' );
	}

	/** An unregistered feature name was already skipped; that must still hold. */
	public function testAnUnknownFeatureNameIsSkipped(): void {
		$this->create_with_features( $this->container( [] ), [ 'not_a_feature' => true ] );

		$this->assertTrue( true, 'An unknown feature name is ignored, not fatal.' );
	}
}
