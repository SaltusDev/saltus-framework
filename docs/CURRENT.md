# Current: Live Working State

## Active Focus
- **Phase 1: MCP Foundation Hardening** — remove file I/O, env-var-only config, tests, PHPStan, prompts.
- **Phase 2: Framework REST API** — expose framework features as `saltus-framework/v1/` routes.

## Recent Changes
- Initial MCP server (v0.1) with 9 CRUD tools: `list_models`, `get_model`, `list_posts`, `get_post`, `create_post`, `update_post`, `delete_post`, `list_terms`, `create_term`.
- MCP protocol implementation: `initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read`.
- 3 static resources: `saltus://models`, `saltus://features`, `saltus://status`.
- Added `guzzlehttp/guzzle` dependency.
- Published comprehensive roadmap in `docs/ROADMAP.md`.
- Added PHPUnit test suite with 44 tests (112 assertions) covering Config, ToolProvider, tool implementations, and ResourceProvider.
- Implemented `sodium_crypto_secretbox` credential encryption for stored passwords.
- Added `--help` flag with complete usage reference in `bin/mcp-server`.
- Improved error handling: standardized tool error returns to `['code', 'message']` format, added `file_get_contents`/`mkdir` return checks, try/catch guards around `save()` and `load()`.
- Cleaned up dead code: removed identical `if/else` branches in 4 tool files, unused `$this->requestId` property, redundant `$argv ?? []`.
- Updated `README.md` with MCP usage guide and client configuration examples.
- Added `phpcs.xml` path-specific exclusions for MCP camelCase naming conventions.

## Next Up
- Remove `ConfigManager.php` and all filesystem writes.
- Replace with `Config::fromEnv()` — env vars only.
- Add REST API namespace `saltus-framework/v1/` in `src/Rest/`.
- Build controllers for duplicate, export, settings, meta, reorder, models.

## Known Issues
- Reference `phpstan_errors.txt` for current static analysis warnings/errors.
- `src/MCP/` code needs PHPStan Level 7 compliance.
