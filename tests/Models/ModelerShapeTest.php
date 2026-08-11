<?php

namespace Saltus\WP\Framework\Tests\Models;

use Noodlehaus\AbstractConfig;
use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\ModelFactory;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * Single-vs-multi model detection.
 *
 * A model config can be one model or a map of model-name => config. Getting that
 * wrong is silent: a misread single model sends each of its top-level keys to
 * `ModelFactory::create()`, which soft-fails on the missing `type` and registers
 * nothing at all. So these assert on *what reaches create()*, which is the only
 * observable difference between the two readings.
 *
 * @covers \Saltus\WP\Framework\Modeler
 */
class ModelerShapeTest extends TestCase {

	/**
	 * Every config that reached `create()`, in order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function created_from( array $config ): array {
		$modeler = new class( $this->createStub( ModelFactory::class ) ) extends Modeler {
			/** @var list<array<string, mixed>> */
			public array $created = [];

			public function feed( array $config ): void {
				$this->process_config( $config );
			}

			protected function create( AbstractConfig $config ): void {
				$this->created[] = $config->all();
			}
		};

		$modeler->feed( $config );

		return $modeler->created;
	}

	// --- Single models ---

	public function testSingleModelWithTypeFirstIsOneModel(): void {
		$created = $this->created_from(
			[
				'type'   => 'cpt',
				'name'   => 'movie',
				'labels' => [ 'singular' => 'Movie' ],
			]
		);

		$this->assertCount( 1, $created );
		$this->assertSame( 'movie', $created[0]['name'] );
	}

	/**
	 * The bug. Identical config, `labels` written before `type`, was read as a list
	 * of three models — none of which had a `type` — so the post type never
	 * registered and nothing said so.
	 */
	public function testSingleModelWithAnArrayKeyFirstIsStillOneModel(): void {
		$created = $this->created_from(
			[
				'labels' => [ 'singular' => 'Movie' ],
				'type'   => 'cpt',
				'name'   => 'movie',
			]
		);

		$this->assertCount( 1, $created, 'Key order must not change how many models a config declares.' );
		$this->assertSame( 'movie', $created[0]['name'] );
	}

	/** Order must not matter at all, not merely for the one reported arrangement. */
	public function testKeyOrderNeverChangesTheParse(): void {
		$orders = [
			[ 'type' => 'cpt', 'name' => 'movie', 'labels' => [ 'singular' => 'Movie' ] ],
			[ 'labels' => [ 'singular' => 'Movie' ], 'name' => 'movie', 'type' => 'cpt' ],
			[ 'name' => 'movie', 'labels' => [ 'singular' => 'Movie' ], 'type' => 'cpt' ],
		];

		foreach ( $orders as $index => $config ) {
			$created = $this->created_from( $config );

			$this->assertCount( 1, $created, 'Arrangement ' . $index . ' must parse as one model.' );
			$this->assertSame( 'movie', $created[0]['name'] );
		}
	}

	// --- Multi-model files ---

	public function testMapOfModelsIsIteratedAsSeparateModels(): void {
		$created = $this->created_from(
			[
				'movie' => [ 'type' => 'cpt', 'name' => 'movie' ],
				'book'  => [ 'type' => 'cpt', 'name' => 'book' ],
			]
		);

		$this->assertCount( 2, $created );
		$this->assertSame( [ 'movie', 'book' ], array_column( $created, 'name' ) );
	}

	/**
	 * The case sorting alone would have broken. Hoisting scalars ahead of arrays
	 * puts `version` first, so a first-key test would call this a single model and
	 * register nothing. Detection keys on `type` instead, which this file lacks.
	 */
	public function testMultiModelFileWithAStrayScalarIsStillMultiple(): void {
		$created = $this->created_from(
			[
				'version' => 2,
				'movie'   => [ 'type' => 'cpt', 'name' => 'movie' ],
				'book'    => [ 'type' => 'cpt', 'name' => 'book' ],
			]
		);

		$this->assertCount( 2, $created, 'A stray scalar is not a model and must not change the shape.' );
		$this->assertSame( [ 'movie', 'book' ], array_column( $created, 'name' ) );
	}

	public function testChildConfigsAreAlsoOrderNormalized(): void {
		$created = $this->created_from(
			[
				'movie' => [ 'labels' => [ 'singular' => 'Movie' ], 'type' => 'cpt', 'name' => 'movie' ],
			]
		);

		$this->assertCount( 1, $created );
		$this->assertSame( 'type', array_key_first( $created[0] ), 'A child config gets the same normalization as a top-level one.' );
	}

	// --- Edge cases ---

	public function testEmptyConfigCreatesNothing(): void {
		$this->assertSame( [], $this->created_from( [] ) );
	}

	/**
	 * No `type` anywhere, so nothing is registerable either way. It must not throw
	 * on the way to finding that out.
	 */
	public function testConfigWithNoTypeAnywhereIsHandledWithoutError(): void {
		$created = $this->created_from(
			[
				'labels'  => [ 'singular' => 'Movie' ],
				'options' => [ 'public' => true ],
			]
		);

		$this->assertCount( 2, $created );
	}
}
