# Build & Setup

## Requirements

- PHP 7.4 or higher
- Composer

## Installation

```bash
composer install
```

## Quality Checks

```bash
# Run tests
composer test

# Static analysis
composer phpstan

# Coding standards
composer phpcs

# Validate composer
composer validate --strict
```

## Documentation

```bash
# Generate MCP ability docs
composer docs:mcp

# Build API docs (requires phpDocumentor)
composer docs:api

# Build full docs site
npm run docs:build
```

## Patching Codestar Framework

If the Codestar Framework is updated, re-apply custom patches:

```bash
for f in lib/codestar-framework/patches/*; do git apply "$f"; done
```

## Autoloading

If you add new classes to `lib/codestar-framework/`, regenerate the classmap:

```bash
composer dump-autoload
```
