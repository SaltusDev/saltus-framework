# Current: Live Working State

## Working
- (none)

## Next
- Tag v1.0 release
- Plan Phase 3: Premium Polish (SSE transport, caching, audit trail, rate limiting)

## Blocked
- (none)

## Recent Changes
- Phase 2 complete: All 8 REST routes registered in `saltus-framework/v1/` namespace
- REST controllers implemented: ModelsController, DuplicateController, ExportController, SettingsController, MetaController, ReorderController
- All 6 Phase 2 MCP tools registered in Server.php: duplicate_post, export_post, get_settings, update_settings, reorder_posts, get_meta_fields
- `saltus://models` MCP resource now fetches live data from `GET /saltus-framework/v1/models`
- 39 new PHPUnit tests (105 total, 252 assertions) covering all 6 Phase 2 MCP tools
- Updated ResourceProviderTest with WordPressClient mock for live data testing
- PHPStan Level 7 clean on both `src/MCP/` and `src/Rest/`
- Added `src/Rest/` to phpunit.xml source coverage include
- Updated phpcs.xml with `src/Rest/` naming convention exemptions

## Known Issues
- Reference `phpstan_errors.txt` for current static analysis warnings/errors (Level 7 clean for MCP + Rest, 318 pre-existing errors in legacy core code).
