# Build: Setup & Runtime Instructions

## Requirements
- PHP 7.4 or higher
- Composer

## Installation
Run `composer install` to install dependencies.

## Running Tests and Linting
The project defines several Composer scripts for quality assurance:

- **Tests:** `composer test`
- **Unit tests:** `composer test:unit`
- **Integration tests:** `composer test:integration`
- **Static Analysis (PHPStan):** `./vendor/bin/phpstan analyse --memory-limit=2G`
  Run via `composer phpstan`
- **Linting (PHPCS):** `./vendor/bin/phpcs --standard=phpcs.xml`
  Run via `composer phpcs`

## Patching Codestar Framework
If the Codestar Framework is updated, re-apply the custom patches:
```bash
for f in lib/codestar-framework/patches/*; do git apply "$f"; done
```

## Autoloading
The project has moved from 'files' to 'classmap' for specific libraries.
- If you add new classes to `lib/codestar-framework/`, you must regenerate the classmap by running `composer dump-autoload`.
