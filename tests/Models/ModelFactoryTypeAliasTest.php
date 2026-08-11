<?php

namespace Saltus\WP\Framework\Tests\Models;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Infrastructure\Container\Container;
use Saltus\WP\Framework\Models\Config\NoFile;
use Saltus\WP\Framework\Models\Config\SchemaBuilder;
use Saltus\WP\Framework\Models\ModelFactory;

require_once dirname( __DIR__ ) . '/Rest/functions.php';

/**
 * The schema's type list must match what ModelFactory actually accepts.
 *
 * `$type_map` is a local variable inside `ModelFactory::create()`, so the schema
 * cannot read it and has to keep its own copy. That copy is exactly the kind of
 * transcription that drifts, so this asserts the two agree by *behaviour*: every
 * type the schema advertises must build a model, and anything outside that list
 * must not.
 *
 * @covers \Saltus\WP\Framework\Models\Config\SchemaBuilder
 */
class ModelFactoryTypeAliasTest extends TestCase {

	protected function setUp(): void {
		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();
	}

	private function reset(): void {
		global $wp_post_types_registered, $wp_taxonomies_registered, $wp_filters_registered;

		$wp_post_types_registered = [];
		$wp_taxonomies_registered = [];
		$wp_filters_registered    = [];
	}

	private function factory(): ModelFactory {
		$container = $this->createStub( Container::class );
		$container->method( 'has' )->willReturn( false );

		return new ModelFactory( $container, [] );
	}

	/** Whether ModelFactory builds a model for a given `type` value. */
	private function builds( string $type ): bool {
		$model = $this->factory()->create(
			new NoFile(
				[
					'type' => $type,
					'name' => 'thing',
				]
			)
		);

		return $model !== null;
	}

	public function testEveryAdvertisedTypeAliasActuallyBuildsAModel(): void {
		$advertised = ( new SchemaBuilder() )->build()['type']['accepted'];

		$this->assertNotSame( [], $advertised );

		foreach ( $advertised as $alias ) {
			$this->assertTrue(
				$this->builds( $alias ),
				sprintf( 'Schema advertises type "%s" but ModelFactory does not accept it.', $alias )
			);
		}
	}

	/**
	 * The other direction: the schema must not be missing an accepted alias, or
	 * validation would reject config the framework happily registers.
	 */
	public function testNoAcceptedAliasIsMissingFromTheSchema(): void {
		$advertised = ( new SchemaBuilder() )->build()['type']['accepted'];

		// Every plausible alias spelling the framework might accept. Any that
		// builds a model must be advertised.
		$candidates = [
			'post-type',
			'cpt',
			'posttype',
			'post_type',
			'posts',
			'post',
			'taxonomy',
			'tax',
			'category',
			'cat',
			'tag',
			'tags',
			'categories',
			'term',
		];

		foreach ( $candidates as $candidate ) {
			if ( ! $this->builds( $candidate ) ) {
				continue;
			}

			$this->assertContains(
				$candidate,
				$advertised,
				sprintf( 'ModelFactory accepts type "%s" but the schema does not advertise it.', $candidate )
			);
		}
	}

	public function testAnUnrecognizedTypeBuildsNothing(): void {
		$this->assertFalse( $this->builds( 'not_a_type' ) );
		$this->assertFalse( $this->builds( '' ) );
	}
}
