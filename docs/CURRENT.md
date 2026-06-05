# Current: Live Working State

## Working
- Implement `src/Rest/` namespace with REST controllers exposing framework features as `saltus-framework/v1/` routes @since 2026-06-06

## Next
- Wire new REST controllers to MCP tools (duplicate_post, export_post, get/update_settings, reorder_posts, get_meta_fields)
- Update MCP resources to call framework REST endpoints for live data
- Plan Phase 2 release (v1.0)

## Blocked
- (none)

## Recent Changes
- Initial MCP server (v0.1) with 9 CRUD tools: `list_models`, `get_model`, `list_posts`, `get_post`, `create_post`, `update_post`, `delete_post`, `list_terms`, `create_term`.
- MCP protocol implementation: `initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read`.
- 3 static resources: `saltus://models`, `saltus://features`, `saltus://status`.
- Added `guzzlehttp/guzzle` dependency.
- Published comprehensive roadmap in `docs/ROADMAP.md`.
- Added PHPUnit test suite with 44 tests (112 assertions) covering Config, ToolProvider, tool implementations, and ResourceProvider.
- Grew test suite to 66 tests (160 assertions) — added ValidatorTest (12 tests) and PromptProviderTest (9 tests).
- Implemented `sodium_crypto_secretbox` credential encryption for stored passwords (later replaced by env-var config).
- Added `--help` flag with complete usage reference in `bin/mcp-server`.
- Improved error handling: standardized tool error returns to `['code', 'message']` format, added `file_get_contents`/`mkdir` return checks, try/catch guards around `save()` and `load()`.
- Cleaned up dead code: removed identical `if/else` branches in 4 tool files, unused `$this->requestId` property, redundant `$argv ?? []`.
- Updated `README.md` with MCP usage guide and client configuration examples.
- Added `phpcs.xml` path-specific exclusions for MCP camelCase naming conventions.
- Replaced file-based ConfigManager with env-var-only Config::fromEnv(). Removed all filesystem I/O, encryption key management, and interactive wizard.
- Reached PHPStan Level 7 compliance across all `src/MCP/` code (added type annotations to 12 files).
- Implemented PromptProvider with 3 prompt templates (create_content, analyze_content, site_overview) wired to prompts/list and prompts/get.
- Implemented input validation via Validator class — JSON Schema checks (required, type, enum) before REST API calls.
- Added Guzzle retry middleware with exponential backoff (1s→2s→4s→8s, max 4) on 429/5xx and ConnectException.

## Known Issues
- Reference `phpstan_errors.txt` for current static analysis warnings/errors (Level 7 clean).
