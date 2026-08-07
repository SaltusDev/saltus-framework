# Testing

The testing milestone described by the original handoff is complete. This page records the delivered setup.

## Current State

- 53 test files, 347 tests, 934 assertions passing.
- `phpunit.xml` and `phpunit.xml.dist` at the repo root, with `executionOrder="random"`, `failOnRisky`, `failOnWarning`, and the `beStrictAbout*` flags enabled.
- `tests/bootstrap.php` loads the Composer autoloader and WordPress function stubs. No WordPress install is required.
- `tests/TestCase.php` is the base class for all framework tests.
- `@covers` annotations on every test class.
- CI runs PHPUnit, PHPStan, and PHPCS across PHP 7.4, 8.0, 8.1, 8.2, and 8.3.

## Suites

| Suite | Directory | Scope |
|---|---|---|
| `Unit` | `tests/Unit/` | Container, models, config, assets — no WordPress coupling |
| `Integration` | `tests/Integration/` | Framework boot and container wiring across components |
| `MCP` | `tests/MCP/` | Abilities, tools, middleware, audit, cache, rate limiter, validation |
| `Rest` | `tests/Rest/` | REST controllers and route registration |
| `Features` | `tests/Features/` | Feature services and legacy feature paths |

Run them with `composer test`, or one at a time:

```bash
composer test:unit
composer test:integration
./vendor/bin/phpunit -c phpunit.xml --testsuite MCP
```

`composer tests` runs the suite plus PHPStan and PHPCS.

## PHP Version Range

The framework supports PHP 7.4+ while modern PHPUnit does not, so `require-dev` accepts a range — `^9.6 || ^10.5 || ^11.5 || ^12.0` — and Composer resolves the appropriate major per PHP version. The Composer dev platform is pinned to PHP 8.4 for `phpdocumentor/phpdocumentor`; this does not affect the runtime target.

Because the suite must run on PHP 7.4, avoid `mixed` type hints and other 8.x-only syntax in test files, including in anonymous classes implementing framework interfaces.

## WordPress Functions

Unit and integration tests use thin local stubs in `tests/` rather than booting WordPress or pulling in WP Mock. Stubs cover `add_action`, `add_filter` with callback execution, post meta, nonces, enqueues, escaping helpers, `WP_Query`, `WP_Term`, and WP-CLI. Add new stubs there when a tested path reaches a WordPress function that is not yet covered.

## Not Yet Done

- Coverage reporting is configured in `phpunit.xml` but not uploaded anywhere. If it is added later, enable `pcov` in one CI leg and treat coverage as a trend signal rather than a blocking threshold.
- `failOnDeprecation` is not enabled; it is noisy across the supported PHP range.
