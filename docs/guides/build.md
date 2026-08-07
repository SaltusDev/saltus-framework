# Build & Setup

## Requirements

- PHP 7.4 or higher
- Composer

## Installation

```bash
composer install
```

The Composer dev platform is pinned to PHP 8.4, which `phpdocumentor/phpdocumentor` requires for API doc generation. Runtime code still targets PHP 7.4+.

## Quality Checks

```bash
# Run the full suite: tests, static analysis, coding standards
composer tests

# Tests only
composer test
composer test:unit
composer test:integration

# Static analysis (PHPStan level 7)
composer test:phpstan

# Coding standards (WordPress via phpcs.xml)
composer test:phpcs

# Auto-fix coding standards
composer fix:phpcbf

# Validate composer
composer validate --strict
```

## Documentation

```bash
# Generate MCP ability docs from src/MCP/Tools
composer docs:mcp

# Generate WP-CLI command docs from CommandCatalog
composer docs:wpcli

# Build API docs (requires phpDocumentor)
composer docs:api

# All generated docs
composer docs:all

# Build the VitePress site
npm run docs:build
```

Two published pages are generated and must not be hand-edited: `docs/mcp/abilities.md` and `docs/guides/wp-cli.md`. `composer docs:mcp` also refreshes the ability-count block inside `docs/MCP.md`. Re-run `composer docs:all` whenever a tool or command is added, renamed, or has its schema changed.

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
