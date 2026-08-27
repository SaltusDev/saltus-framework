<?php

namespace Saltus\WP\Framework\Tests\MCP\Validation;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Validation\Validator;

/**
 * @covers \Saltus\WP\Framework\MCP\Validation\Validator
 */
class ValidatorTest extends TestCase
{
    public function testValidPasses(): void
    {
        $schema = [
            'name' => ['type' => 'string', 'required' => true],
            'age'  => ['type' => 'number'],
        ];
        $args = ['name' => 'Alice', 'age' => 30];
        $result = Validator::validate($args, $schema);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testMissingRequiredField(): void
    {
        $schema = [
            'name' => ['type' => 'string', 'required' => true],
        ];
        $args = [];
        $result = Validator::validate($args, $schema);
        $this->assertFalse($result['valid']);
        $this->assertContains("'name' is required", $result['errors']);
    }

    public function testTypeMismatchString(): void
    {
        $schema = [
            'title' => ['type' => 'string'],
        ];
        $args = ['title' => 42];
        $result = Validator::validate($args, $schema);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be of type string', $result['errors'][0]);
    }

    public function testTypeMismatchNumber(): void
    {
        $schema = [
            'count' => ['type' => 'number'],
        ];
        $args = ['count' => 'not-a-number'];
        $result = Validator::validate($args, $schema);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be of type number', $result['errors'][0]);
    }

    public function testTypeMismatchBoolean(): void
    {
        $schema = [
            'active' => ['type' => 'boolean'],
        ];
        $args = ['active' => 'yes'];
        $result = Validator::validate($args, $schema);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be of type boolean', $result['errors'][0]);
    }

    public function testTypeMismatchObject(): void
    {
        $schema = [
            'meta' => ['type' => 'object'],
        ];
        $args = ['meta' => 'string-instead'];
        $result = Validator::validate($args, $schema);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be of type object', $result['errors'][0]);
    }

    public function testValidObjectType(): void
    {
        $schema = [
            'meta' => ['type' => 'object'],
        ];
        $args = ['meta' => ['key' => 'value']];
        $result = Validator::validate($args, $schema);
        $this->assertTrue($result['valid']);
    }

    public function testEnumValidation(): void
    {
        $schema = [
            'status' => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending']],
        ];
        $args = ['status' => 'draft'];
        $result = Validator::validate($args, $schema);
        $this->assertTrue($result['valid']);
    }

    public function testEnumValidationFails(): void
    {
        $schema = [
            'status' => ['type' => 'string', 'enum' => ['draft', 'publish']],
        ];
        $args = ['status' => 'trash'];
        $result = Validator::validate($args, $schema);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be one of', $result['errors'][0]);
    }

    public function testMultipleErrors(): void
    {
        $schema = [
            'name'   => ['type' => 'string', 'required' => true],
            'status' => ['type' => 'string', 'enum' => ['active']],
        ];
        $args = ['status' => 123];
        $result = Validator::validate($args, $schema);
        $this->assertFalse($result['valid']);
        $this->assertContains("'name' is required", $result['errors']);
    }

    public function testEmptySchema(): void
    {
        $result = Validator::validate(['anything' => 1], []);
        $this->assertTrue($result['valid']);
    }

    public function testOptionalFieldOmitted(): void
    {
        $schema = [
            'name' => ['type' => 'string'],
            'desc' => ['type' => 'string'],
        ];
        $result = Validator::validate(['name' => 'test'], $schema);
        $this->assertTrue($result['valid']);
    }

    /**
     * A value core accepts must not be rejected here.
     *
     * Core validates an ability's input before the tool runs, so this validator
     * always runs second. Over REST the request sanitizer coerces first and
     * hides any disagreement, but a direct execute() from WP-CLI or another
     * plugin passes the raw value through, and a stricter check there fails a
     * call core deliberately allowed.
     *
     * @dataProvider losslessScalarSpellings
     *
     * @param mixed $value
     */
    public function testALosslessScalarSpellingIsAccepted(string $type, $value): void
    {
        $result = Validator::validate(['f' => $value], ['f' => ['type' => $type]]);
        $this->assertTrue($result['valid'], var_export($value, true) . " must satisfy {$type}");
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function losslessScalarSpellings(): array
    {
        return [
            'integer string'      => ['integer', '12'],
            'negative integer'    => ['integer', '-3'],
            'whole float string'  => ['integer', '12.0'],
            'number string'       => ['number', '1.5'],
            'integer as number'   => ['number', '12'],
            'boolean true string' => ['boolean', 'true'],
            'boolean one string'  => ['boolean', '1'],
            'boolean zero int'    => ['boolean', 0],
        ];
    }

    /**
     * @dataProvider valuesCoreAlsoRejects
     *
     * @param mixed $value
     */
    public function testAValueCoreRejectsIsStillRejected(string $type, $value): void
    {
        $result = Validator::validate(['f' => $value], ['f' => ['type' => $type]]);
        $this->assertFalse($result['valid'], var_export($value, true) . " must not satisfy {$type}");
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function valuesCoreAlsoRejects(): array
    {
        return [
            'fractional integer' => ['integer', '12.5'],
            'word as integer'    => ['integer', 'abc'],
            'word as number'     => ['number', 'abc'],
            'word as boolean'    => ['boolean', 'yes'],
            'int as string'      => ['string', 12],
            'list as object'     => ['object', ['a', 'b']],
            'map as array'       => ['array', ['a' => 1]],
        ];
    }

    /**
     * Core sanitizes to the declared type before comparing an enum, so a
     * numeric string satisfies a numeric enum.
     */
    public function testANumericStringSatisfiesANumericEnum(): void
    {
        $result = Validator::validate(['f' => '12'], ['f' => ['type' => 'integer', 'enum' => [12, 13]]]);
        $this->assertTrue($result['valid']);
    }

    /**
     * Normalizing for the enum comparison must not become loose comparison:
     * '1' is not the boolean true.
     */
    public function testAStringDoesNotSatisfyABooleanEnum(): void
    {
        $result = Validator::validate(['f' => '1'], ['f' => ['type' => 'string', 'enum' => [true]]]);
        $this->assertFalse($result['valid']);
    }

    public function testAValueOutsideAStringEnumIsRejected(): void
    {
        $result = Validator::validate(['f' => 'draft'], ['f' => ['type' => 'string', 'enum' => ['publish', 'pending']]]);
        $this->assertFalse($result['valid']);
    }

    /**
     * The string 'false' is a valid boolean and a truthy string.
     *
     * A tool reading it with `! empty()` would do the opposite of what the
     * caller asked, so accepting the spelling obliges us to convert it. This is
     * the case that turns `delete_post` with `force => 'false'` from a trash
     * into a permanent delete.
     */
    public function testTheStringFalseCoercesToBooleanFalse(): void
    {
        $coerced = Validator::coerce(['force' => 'false'], ['force' => ['type' => 'boolean']]);

        $this->assertFalse($coerced['force']);
        $this->assertNotSame('false', $coerced['force']);
    }

    /**
     * @dataProvider coercions
     *
     * @param mixed $sent
     * @param mixed $expected
     */
    public function testAnAcceptedSpellingBecomesItsDeclaredType(string $type, $sent, $expected): void
    {
        $coerced = Validator::coerce(['f' => $sent], ['f' => ['type' => $type]]);
        $this->assertSame($expected, $coerced['f']);
    }

    /** @return array<string, array{0: string, 1: mixed, 2: mixed}> */
    public static function coercions(): array
    {
        return [
            'true string'      => ['boolean', 'true', true],
            'one string'       => ['boolean', '1', true],
            'zero string'      => ['boolean', '0', false],
            'zero int'         => ['boolean', 0, false],
            'one int'          => ['boolean', 1, true],
            'integer string'   => ['integer', '42', 42],
            'negative integer' => ['integer', '-7', -7],
            'number string'    => ['number', '1.5', 1.5],
            'real bool kept'   => ['boolean', true, true],
            'real int kept'    => ['integer', 42, 42],
        ];
    }

    /** A value of a type the rules do not name is handed through untouched. */
    public function testAnUnrecognisedTypeIsLeftAlone(): void
    {
        $args    = ['f' => ['a', 'b'], 'g' => 'text'];
        $coerced = Validator::coerce($args, ['f' => ['type' => 'array'], 'g' => ['type' => 'string']]);

        $this->assertSame($args, $coerced);
    }

    /** An argument the rules do not mention is not invented. */
    public function testAnAbsentArgumentIsNotAdded(): void
    {
        $coerced = Validator::coerce([], ['force' => ['type' => 'boolean']]);

        $this->assertSame([], $coerced);
    }
}
