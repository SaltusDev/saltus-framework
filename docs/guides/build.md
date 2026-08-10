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

Two published pages are generated and must not be hand-edited: `docs/mcp/abilities.md` and `docs/guides/wp-cli.md`. `composer docs:mcp` also refreshes the ability-count block inside `docs/mcp/index.md`, and `composer docs:webmcp` the tool table inside `docs/guides/webmcp.md`. Re-run `composer docs:all` whenever a tool or command is added, renamed, or has its schema changed — CI fails the docs build if the generated output differs from what is committed.

## Documentation Layout

Docs are split into three tiers, and only one of them is authored by hand.

| Tier | Location | Committed | Published |
|---|---|---|---|
| Private working notes | `notes/` | No (gitignored) | Never |
| Authored source | `docs/` | Yes | Yes |
| Generated output | `build/docs/` | No (gitignored) | Yes |

`docs/` is the only tier a human edits. `build/docs/` is disposable and reproducible:

```bash
# Site -> build/docs/site via docs/.vitepress/dist, wiki -> build/docs/wiki
npm run docs:build

# Site only, including the private-content leak check
npm run docs:site

# Flattened GitHub Wiki mirror only
npm run docs:wiki

# Assert no private page or text reached the built site
npm run docs:check
```

Private pages are listed in `srcExclude` in `docs/.vitepress/config.mjs`. VitePress publishes every
`.md` under `docs/` unless excluded, so anything private belongs in `notes/` — not in `docs/` with a
note asking people not to publish it. `bin/check-docs-leak.mjs` enforces this by asserting the built
output contains no private route and no private text, including in the local search index. It runs as
part of `npm run docs:site`, so a leak fails the build before anything is deployed.

The class-level API reference is phpDocumentor HTML, generated to `build/docs/api/` and staged into
the site at `/api/reference/`. The authored landing page at `docs/api/index.md` owns `/api/`.

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
