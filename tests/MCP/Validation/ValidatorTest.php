<?php

namespace Saltus\WP\Framework\Tests\MCP\Validation;

use PHPUnit\Framework\TestCase;
use Saltus\WP\Framework\MCP\Validation\Validator;

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
}
