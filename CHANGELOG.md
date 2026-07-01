# Changelog


## [Unreleased]

### Fixed
	- Cleared the remaining PHPStan Level 7 issues in `Modeler`, REST route registration, and MCP taxonomy REST-base handling.
	- Added an explicit model name accessor contract so `Modeler` no longer depends on concrete model properties.

### Changed
	- Removed the standalone stdio MCP server path; WP7 MCP/Abilities is now the only supported MCP transport.
	- Migrated MCP audit logging, rate limiting, and caching to WordPress-native storage around WP7 ability execution.

## [2.0.0] - 2026-06-30

### Added
	- MCP/Abilities integration: WordPress 7.0 native ability definitions through AbilityRegistrar
	- MCP protocol server with stdio transport: initialize, tools/list, tools/call, resources/list, resources/read, prompts/list, prompts/get
	- REST API namespace `saltus-framework/v1/` with 9 routes: models, duplicate, export, settings (GET/PUT), meta (aggregate + per-CPT), reorder
	- 16 MCP tools (9 Phase 1 CRUD + 7 Phase 2): models, posts, terms, duplicate, export, settings, reorder, meta fields
	- 3 MCP prompt templates for post/content generation workflows
	- Meta field normalization: nested Codestar fields flattened to explicit paths with JSON-schema-like types
	- ToolFactory for shared tool definitions between MCP and WordPress-native abilities
	- Caching layer (CacheInterface + InMemoryCache) for WordPressClient GET requests
	- Sliding-window rate limiter (default 60 req/60s) for tool calls
	- Audit trail logger with JSON records to STDERR and optional file
	- Structured error codes (ErrorCode constants + McpError value object with resolution hints)
	- Config::fromEnv for environment-variable-based configuration (15 env vars)
	- 243 PHPUnit tests across MCP, REST, and framework core

### Fixed
	- Rate limiter race condition in concurrent test scenarios
	- ModelFactory static make method call operator
	- WalkerTaxonomyDropdown incompatible void return type
	- WordPress key length validation in get_registration_name()
	- Admin columns non-string return from get_edit_term_link
	- Various type safety issues across services, models, assets, admin columns, meta, drag-drop, and container

### Changed
	- Version bumped to 2.0.0
	- Type safety improvements across the entire codebase
	- MCP Server uses ToolFactory for unified tool definitions
	- Config constructor refactored to array bag pattern
	- PHP 8.3+ required (str_starts_with in McpError)
	- PHPStan Level 7 compliance for all src/MCP/ and src/Rest/ code
	- PHPUnit configuration with strict flags, random execution order, and failOn* rules

## [1.3.5] - 2026-06-20
	- Chore: Package it for packagist

## [1.3.4] - 2026-04-07
	- New: Add h3 to meta sections
	- Fix: typo in drag and drop filter
	- Docs: Add docs for patching codestar
	- Docs: Add docs for existing hooks
	- Improvement: Reduce complexity and improve typing safety
	- Chore: Update dependencies
	- Test: Test phpstan level 7

## [1.3.3] - 2026-03-20
	- Rework remember tabs
	- Allow models to pass schema for meta fields
	- Fix schema for other types of meta
	- Wrap registering gutenberger block
	- Allow assets to skip prefixing
	- Allow admin cols to get called on rest api endpoints
	- Allow duplicate to run on rest api endpoints
	- Fix: Patch codestar framework

## [1.3.2] - 2025-09-21
	- Fix: Add Assets data container to allow add_data

## [1.3.1] - 2025-07-06
	- Feature: Quick edit

## [1.3.0] - 2025-05-26
	- Breaking changes: Allow Injection of container type to Assembler
	- Feature: Manage HasAssets to manage assets ( css, js )
	- Feature: Provide Project class
	- Feature: Provide Service Factory
	- Fix: Correct types to be compatible with php84 without warnings
	- Fix: Escape exception messages

## [1.2.1] - 2025-04-16

### Added
	- Allow filter to have a key property to be used when filtering
	- Allow filter to force to use key as value in meta filter
### Changed
	- Update filters to reflect that

## [1.2.0] - 2025-04-08

### Added
	- New hook in Admin Filters
	- Allow meta query params to be passed by model
	- Allow models to overwrite sort type
	- CS and analyzers dependencies
	- Set possible prefixes

### Changed
	- Fix admin filters
	- Improve naming of filters and actions
	- CS, Docs, and optimizations
	- Update admin cols from ext-cpts and small optimizations
	- Change action name for drag and drop to be more descriptive
	- Improve naming of Duplicate nonce and action
	- Simplify parsing of meta box setttings for registering in rest api

### Removed
	- CMB2Meta
	- Demo feature

## [1.1.4] - 2025-02-18

### Changed
	- Move loading codestar from files to classmap

## [1.1.3] - 2025-01-28

### Added
	- Add changelog

## [1.1.2] - 2024-12-10

### Fixed
	- Load models by ascending filename order

## [1.1.1] - 2024-12-10

### Fixed
	- Correct Media meta field type
	- Rename drag and drop action
	- Allow settings meta boxes to have any number of sections

## [1.1.0] - 2024-11-28

### Added
	- Items as CPTs
### Changed
	- Updated Codestar
	- Updated dependencies
### Fixed
	- Save linebreaks on save meta
	- interfaces usage
### Removed
	- p2p integration


## [1.0.2] - 2023-10-20

### Added
	- Remember tabs
### Fixed
	- Fix missing properties
	- Update features to be Processable


## [1.0.1] - 2022-04-20

### Added
	- Meta feature
	- Settings feature
	- Drag and drop feature
	- Duplicate feature
	- Export feature
	- Admin Cols and Admin Filters features

## [1.0.0] - 2019-04-23
	- Initial Release


The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
