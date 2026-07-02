<?php

namespace Saltus\WP\Framework\Tests\Unit;

use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;
use Saltus\WP\Framework\Models\ModelFactory;
use Saltus\WP\Framework\Tests\TestCase;

class ModelerTest extends TestCase {
	private function callAdd( Modeler $modeler, Model $model ): void {
		( function () use ( $model ): void {
			$this->add( $model );
		} )->call( $modeler );
	}

	public function testGetModelsReturnsEmptyArrayInitially(): void {
		$factory = $this->createStub( ModelFactory::class );
		$modeler = new Modeler( $factory );

		$this->assertSame( [], $modeler->get_models() );
	}

	public function testAddStoresModelKeyedByName(): void {
		$factory = $this->createStub( ModelFactory::class );
		$modeler = new Modeler( $factory );

		$model = $this->createStub( Model::class );
		$model->method( 'get_name' )->willReturn( 'movie' );

		$this->callAdd( $modeler, $model );

		$models = $modeler->get_models();
		$this->assertArrayHasKey( 'movie', $models );
		$this->assertSame( $model, $models['movie'] );
	}

	public function testAddStoresMultipleModels(): void {
		$factory = $this->createStub( ModelFactory::class );
		$modeler = new Modeler( $factory );

		$movie = $this->createStub( Model::class );
		$movie->method( 'get_name' )->willReturn( 'movie' );

		$book = $this->createStub( Model::class );
		$book->method( 'get_name' )->willReturn( 'book' );

		$this->callAdd( $modeler, $movie );
		$this->callAdd( $modeler, $book );

		$this->assertCount( 2, $modeler->get_models() );
	}

	public function testAddWithSameNameOverwrites(): void {
		$factory = $this->createStub( ModelFactory::class );
		$modeler = new Modeler( $factory );

		$first = $this->createStub( Model::class );
		$first->method( 'get_name' )->willReturn( 'movie' );

		$second = $this->createStub( Model::class );
		$second->method( 'get_name' )->willReturn( 'movie' );

		$this->callAdd( $modeler, $first );
		$this->callAdd( $modeler, $second );

		$this->assertCount( 1, $modeler->get_models() );
		$this->assertSame( $second, $modeler->get_models()['movie'] );
	}
}
