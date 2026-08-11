<?php

namespace Saltus\WP\Framework\Tests\Models;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\Models\ConfigError;
use Saltus\WP\Framework\Models\ConfigValidationResult;

/**
 * @covers \Saltus\WP\Framework\Models\ConfigError
 * @covers \Saltus\WP\Framework\Models\ConfigValidationResult
 */
class ConfigValidationTest extends TestCase {

	public function testConfigErrorCarriesPathRuleAndMessage(): void {
		$error = new ConfigError( 'fields.author.type', 'required', 'Type is required.' );

		$this->assertSame( 'fields.author.type', $error->get_path() );
		$this->assertSame( 'required', $error->get_rule() );
		$this->assertSame( 'Type is required.', $error->get_message() );
	}

	public function testConfigErrorSerializesToArray(): void {
		$error = new ConfigError( 'model', 'type_check', 'Expected string, got integer.' );

		$this->assertSame(
			[
				'path'    => 'model',
				'rule'    => 'type_check',
				'message' => 'Expected string, got integer.',
			],
			$error->to_array()
		);
	}

	public function testValidResultReportsValid(): void {
		$result = new ConfigValidationResult( 'movie', [] );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( 'movie', $result->get_model_name() );
		$this->assertSame( [], $result->get_errors() );
	}

	public function testInvalidResultReportsInvalid(): void {
		$errors = [
			new ConfigError( 'model', 'required', 'Model name required.' ),
			new ConfigError( 'fields.author.type', 'required', 'Type required.' ),
		];
		$result = new ConfigValidationResult( 'book', $errors );

		$this->assertFalse( $result->is_valid() );
		$this->assertSame( 'book', $result->get_model_name() );
		$this->assertCount( 2, $result->get_errors() );
	}

	public function testValidationResultSerializesToArray(): void {
		$errors = [
			new ConfigError( 'fields.title.type', 'enum', 'Invalid type.' ),
		];
		$result = new ConfigValidationResult( 'article', $errors );

		$array = $result->to_array();

		$this->assertSame( 'article', $array['model'] );
		$this->assertFalse( $array['valid'] );
		$this->assertCount( 1, $array['errors'] );
		$this->assertSame( 'fields.title.type', $array['errors'][0]['path'] );
		$this->assertSame( 'enum', $array['errors'][0]['rule'] );
		$this->assertSame( 'Invalid type.', $array['errors'][0]['message'] );
	}

	public function testEmptyErrorsArrayNormalizesToList(): void {
		$result = new ConfigValidationResult( 'test', [] );

		$this->assertSame( [], $result->get_errors() );
		$this->assertSame( [], $result->to_array()['errors'] );
	}

	public function testErrorsArrayReindexedToList(): void {
		$errors = [
			5 => new ConfigError( 'a', 'r1', 'm1' ),
			9 => new ConfigError( 'b', 'r2', 'm2' ),
		];
		$result = new ConfigValidationResult( 'test', $errors );

		$stored = $result->get_errors();
		$this->assertArrayHasKey( 0, $stored );
		$this->assertArrayHasKey( 1, $stored );
		$this->assertArrayNotHasKey( 5, $stored );
		$this->assertArrayNotHasKey( 9, $stored );
	}
}
