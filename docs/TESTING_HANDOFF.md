# Testing Suite Handoff

## Goal

Bring this framework up to a `wp_mock`-style testing setup: PHPUnit suites for unit and integration coverage, strict PHPUnit configuration, and CI jobs that run tests across the supported PHP range.

Reference workflow and test layout:

- `wp_mock` CI: <https://github.com/10up/wp_mock/blob/221736aeed1df0fd343e37fa91b47c2e17ab7663/.github/workflows/ci.yml>
- `wp_mock` PHPUnit config: <https://github.com/10up/wp_mock/blob/221736aeed1df0fd343e37fa91b47c2e17ab7663/phpunit.xml.dist>
- `wp_mock` tests: <https://github.com/10up/wp_mock/tree/221736aeed1df0fd343e37fa91b47c2e17ab7663/tests>

## Current State

The project currently has:

- Composer package metadata in `composer.json`.
- Runtime PHP support declared as `>=7.4`.
- PHPStan configured in `phpstan.neon`.
- PHPCS and PHPCompatibility configured in `phpcs.xml`.
- CI jobs for Composer validation, coding standards, static analysis, and runtime compatibility.
- PHPUnit listed in `require-dev`, currently locked to PHPUnit 12, which requires PHP `>=8.3`.

The project does not currently have:

- `phpunit.xml.dist`.
- A `tests/` directory.
- Test bootstrap logic.
- Unit or integration tests.
- CI jobs that execute PHPUnit.

## Important Constraint

Do not assume one PHPUnit major can cover PHP `7.4` through `8.4`.

The framework supports PHP `>=7.4`, but modern PHPUnit versions do not. To test the full PHP range, the project needs either:

1. A PHPUnit version matrix, similar to `wp_mock`, where Composer resolves the correct PHPUnit major per PHP version.
2. A constrained PHPUnit version that supports old PHP, accepting less modern PHPUnit features.

Recommended direction: use a matrix. It preserves runtime support while still allowing modern PHPUnit on modern PHP.

Likely mapping:

- PHP 7.4: PHPUnit 9
- PHP 8.0: PHPUnit 9
- PHP 8.1: PHPUnit 10
- PHP 8.2: PHPUnit 11
- PHP 8.3: PHPUnit 12
- PHP 8.4: PHPUnit 12 or 13, depending on package availability and constraints

Confirm exact constraints with Composer before committing the final matrix.

## Target Test Layout

Create this structure:

```text
tests/
  TestCase.php
  bootstrap.php
  Unit/
    Infrastructure/
    Models/
    Features/
  Integration/
    FrameworkBootTest.php
```

Use `tests/Unit` for isolated class behavior and `tests/Integration` for tests that exercise multiple framework components together.

## Target PHPUnit Config

Add `phpunit.xml.dist` at the repo root.

Recommended starting point:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="./tests/bootstrap.php"
         colors="true"
         executionOrder="random"
         cacheDirectory=".phpunit.cache"
         beStrictAboutOutputDuringTests="true"
         beStrictAboutTestsThatDoNotTestAnything="true"
         failOnRisky="true"
         failOnWarning="true">

    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">./tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory suffix="Test.php">./tests/Integration</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory suffix=".php">./src</directory>
        </include>
    </source>
</phpunit>
```

Notes:

- `failOnDeprecation` is useful, but may be noisy while supporting PHP 7.4 and WordPress compatibility. Add it after the first useful suite is passing.
- PHPUnit XML schema differs across major versions. If the matrix uses PHPUnit 9 through 12, keep the XML conservative or maintain version-specific configs.

## Bootstrap

Add `tests/bootstrap.php`:

```php
<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';
```

If tests need WordPress functions, prefer explicit stubs or WP Mock-style expectations over loading a full WordPress install for unit tests.

## Base Test Case

Add `tests/TestCase.php`:

```php
<?php

namespace Saltus\WP\Framework\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
}
```

If Mockery or WP Mock is introduced later, close expectations in `tearDown()`.

## Composer Changes

Add scripts:

```json
"test": "./vendor/bin/phpunit",
"test:unit": "./vendor/bin/phpunit --testsuite Unit",
"test:integration": "./vendor/bin/phpunit --testsuite Integration"
```

Revisit `require-dev` for PHPUnit. Current `^12.5.15` prevents testing on PHP below 8.3. For a PHP-version matrix, Composer may need conditional update commands in CI rather than one fixed lock-file PHPUnit version.

## First Unit Tests To Add

Start with framework code that has minimal WordPress coupling:

1. `src/Infrastructure/Container/*`
   - Registering and resolving services.
   - Handling missing services.
   - Preventing invalid registrations.
   - Instantiator behavior.

2. `src/Models/Config/NoFile.php`
   - Missing config file behavior.
   - Returned defaults or exception behavior.

3. `src/Models/ModelFactory.php`
   - Correct model class selection.
   - Invalid model type handling.

4. `src/Infrastructure/Services/Assets/AssetData.php`
   - Normalization of asset metadata.
   - Default values.
   - Invalid input handling.

These tests should not require WordPress to be loaded.

## First Integration Tests To Add

Start with integration tests that still avoid a full WordPress install:

1. Framework bootstrapping
   - Instantiate the core framework object.
   - Register a small set of services.
   - Assert expected services are available.

2. Model registration pipeline
   - Feed a representative config array.
   - Assert the correct model objects are created.

3. Asset pipeline
   - Create asset definitions.
   - Assert resulting asset objects and dependency data.

If integration tests need WordPress functions such as `add_action`, `add_filter`, `register_post_type`, or `wp_enqueue_script`, use WP Mock or thin local test stubs.

## Whether To Use WP Mock

WP Mock is appropriate if this framework needs to verify interactions with WordPress functions without booting WordPress.

Use it for tests like:

- `register_post_type()` is called with expected args.
- `register_taxonomy()` is called with expected args.
- `add_action()` hooks are registered.
- `add_filter()` hooks are registered.
- enqueue functions receive expected handles and dependencies.

Do not use WP Mock for pure data classes or framework container tests. Plain PHPUnit is simpler there.

## CI Changes After Tests Exist

Once `phpunit.xml.dist` and initial tests are committed, add a `php-tests` job before or alongside the current quality jobs.

Target shape:

```yaml
php-tests:
  runs-on: ubuntu-latest
  name: PHP ${{ matrix.php }} tests
  strategy:
    fail-fast: false
    matrix:
      include:
        - php: "7.4"
          composer_flags: "--with phpunit/phpunit:^9"
        - php: "8.0"
          composer_flags: "--with phpunit/phpunit:^9"
        - php: "8.1"
          composer_flags: "--with phpunit/phpunit:^10"
        - php: "8.2"
          composer_flags: "--with phpunit/phpunit:^11"
        - php: "8.3"
          composer_flags: "--with phpunit/phpunit:^12"
        - php: "8.4"
          composer_flags: "--with phpunit/phpunit:^12"
```

Then:

```yaml
- run: composer update ${{ matrix.composer_flags }} --no-ansi --no-interaction --no-progress --prefer-dist
- run: vendor/bin/phpunit --configuration phpunit.xml.dist --order-by=random
```

Validate this matrix in a branch. Composer may need adjustments depending on transitive constraints.

## Coverage

Do not add coverage upload immediately. First make the test suite meaningful and stable.

After enough tests exist:

- Enable `pcov` in one CI matrix leg.
- Generate `clover.xml`.
- Upload to Codecov, Coveralls, or GitHub artifact storage.

Use coverage as a trend signal, not as a blocking threshold at the start.

## Definition Of Done

The first testing milestone is complete when:

- `phpunit.xml.dist` exists.
- `tests/bootstrap.php` exists.
- `tests/TestCase.php` exists.
- At least one unit test exists for container behavior.
- At least one integration test exists for a framework-level workflow.
- `composer test`, `composer test:unit`, and `composer test:integration` pass locally.
- CI runs PHPUnit on at least PHP 8.3.
- The wide PHP matrix is expanded to PHPUnit once Composer constraints are proven.

