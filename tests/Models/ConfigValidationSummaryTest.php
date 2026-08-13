<?php

namespace Saltus\WP\Framework\Tests\Models;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Models\ConfigError;
use Saltus\WP\Framework\Models\ConfigValidationResult;
use Saltus\WP\Framework\Models\ConfigValidationSummary;

/**
 * @covers \Saltus\WP\Framework\Models\ConfigValidationSummary
 */
class ConfigValidationSummaryTest extends TestCase {

	public function testEmptySummaryIsValidAndCountsZero(): void {
		$summary = new ConfigValidationSummary();

		$this->assertTrue( $summary->is_valid() );
		$this->assertFalse( $summary->has_errors() );
		$this->assertFalse( $summary->has_warnings() );
		$this->assertSame( 0, $summary->total_count() );
		$this->assertSame( 0, $summary->valid_count() );
		$this->assertSame( 0, $summary->error_count() );
		$this->assertSame( 0, $summary->warning_count() );
	}

	public function testWithReturnsANewInstanceAndLeavesTheOriginalAlone(): void {
		$original = new ConfigValidationSummary();
		$extended = $original->with( $this->valid_result( 'book' ) );

		$this->assertNotSame( $original, $extended, 'with() must not mutate in place.' );
		$this->assertSame( 0, $original->total_count(), 'The original summary must be unchanged.' );
		$this->assertSame( 1, $extended->total_count() );
	}

	public function testCountsSumConsistentlyAcrossValidAndFailingModels(): void {
		$summary = ( new ConfigValidationSummary() )
			->with( $this->valid_result( 'book' ) )
			->with( $this->valid_result( 'author' ) )
			->with( $this->error_result( 'movie' ) );

		// total must equal valid + error, or the health payload contradicts itself.
		$this->assertSame( 3, $summary->total_count() );
		$this->assertSame( 2, $summary->valid_count() );
		$this->assertSame( 1, $summary->error_count() );
		$this->assertSame(
			$summary->total_count(),
			$summary->valid_count() + $summary->error_count(),
			'total must equal valid + error.'
		);
	}

	public function testErrorCountCountsFailingModelsNotIndividualErrors(): void {
		$result  = new ConfigValidationResult(
			'book',
			[
				ConfigError::error( 'book', 'type', 'type.unknown', 'Unknown type' ),
				ConfigError::error( 'book', 'name', 'name.too_long', 'Name too long' ),
				ConfigError::error( 'book', 'meta', 'meta.conflict', 'Both fields and sections' ),
			]
		);
		$summary = ( new ConfigValidationSummary() )->with( $result );

		// Three errors on one model is one failing model. Counting errors here would
		// make error_count exceed total_count and break the sum above.
		$this->assertSame( 1, $summary->total_count() );
		$this->assertSame( 1, $summary->error_count() );
		$this->assertCount( 3, $summary->all_errors() );
	}

	public function testWarningsDoNotMakeAModelInvalid(): void {
		$result  = new ConfigValidationResult(
			'book',
			[ ConfigError::warning( 'book', 'taxonomies', 'unknown_key', 'No such key' ) ]
		);
		$summary = ( new ConfigValidationSummary() )->with( $result );

		$this->assertTrue( $summary->is_valid(), 'A warning must not invalidate the site.' );
		$this->assertSame( 1, $summary->valid_count() );
		$this->assertSame( 0, $summary->error_count() );
		$this->assertSame( 1, $summary->warning_count() );
		$this->assertTrue( $summary->has_warnings() );
	}

	public function testAllErrorsAndAllWarningsFlattenInDiscoveryOrder(): void {
		$first  = new ConfigValidationResult(
			'book',
			[
				ConfigError::error( 'book', 'type', 'type.unknown', 'first error' ),
				ConfigError::warning( 'book', 'active', 'active.truthy', 'first warning' ),
			]
		);
		$second = new ConfigValidationResult(
			'movie',
			[ ConfigError::error( 'movie', 'name', 'name.too_long', 'second error' ) ]
		);

		$summary = ( new ConfigValidationSummary() )->with( $first )->with( $second );

		$this->assertSame(
			[ 'first error', 'second error' ],
			array_map( static fn( ConfigError $e ): string => $e->get_message(), $summary->all_errors() )
		);
		$this->assertSame(
			[ 'first warning' ],
			array_map( static fn( ConfigError $e ): string => $e->get_message(), $summary->all_warnings() )
		);
	}

	public function testToArrayReportsCountsAndPerModelDetail(): void {
		$summary = ( new ConfigValidationSummary() )
			->with( $this->valid_result( 'book' ) )
			->with( $this->error_result( 'movie' ) );

		$data = $summary->to_array();

		$this->assertSame( 2, $data['total'] );
		$this->assertSame( 1, $data['valid'] );
		$this->assertSame( 1, $data['errors'] );
		$this->assertSame( 0, $data['warnings'] );
		$this->assertCount( 2, $data['models'] );
		$this->assertSame( 'book', $data['models'][0]['model'] );
		$this->assertTrue( $data['models'][0]['valid'] );
		$this->assertSame( 'movie', $data['models'][1]['model'] );
		$this->assertFalse( $data['models'][1]['valid'] );
	}

	public function testConstructorReindexesASparseResultArray(): void {
		$summary = new ConfigValidationSummary(
			[ 5 => $this->valid_result( 'book' ), 9 => $this->valid_result( 'movie' ) ]
		);

		$this->assertSame( [ 0, 1 ], array_keys( $summary->get_results() ) );
	}

	private function valid_result( string $model ): ConfigValidationResult {
		return new ConfigValidationResult( $model, [] );
	}

	private function error_result( string $model ): ConfigValidationResult {
		return new ConfigValidationResult(
			$model,
			[ ConfigError::error( $model, 'type', 'type.unknown', 'Unknown type alias' ) ]
		);
	}
}
