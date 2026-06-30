# Current: Live Working State

## Working
- Prepare v1.0 release tag @since 2026-06-30

## Next

## Recent Changes
- Added strict phpunit.xml config with random execution order, failOn* and beStrictAbout* flags
- Added phpunit.xml.dist as distribution configuration
- Created tests/TestCase.php base class for all framework tests
- Added Unit test suite: Container, Asset, Model config tests (5 test files)
- Added Integration test suite: FrameworkBootTest for service container verification
- Added is_admin() stub in test functions for environment compatibility
- Added PHPUnit test job to GitHub Actions CI workflow
- Bumped version to 1.4.0
- WordPress 7.0 MCP/Abilities connector: `AbilityRegistrar` registers 16 Saltus tool definitions when `wp_register_ability()` exists
- Shared `ToolFactory` keeps MCP tool definitions aligned with WordPress-native abilities
- Native ability callbacks dispatch through `rest_do_request()` so existing REST permission callbacks remain authoritative
- Added compatibility tests covering native ability registration, capability gating, and REST-backed dispatch
- README documents WordPress-native MCP/Abilities as the selected path
- Skipped standalone local stdio MCP server and related setup docs
- MCP resources now document `saltus://meta-fields` for discovering model-defined meta fields across registered CPTs
- Added `list_meta_fields` for WordPress-native metadata discovery across registered CPTs
- Added aggregate `GET /saltus-framework/v1/meta` for all post type meta definitions
- `ResourceProvider` resolves `saltus://meta-fields` through the aggregate `/meta` endpoint as a legacy resource path
- MCP clients currently see raw Saltus/Codestar meta config: metabox IDs, sections, field definitions, dynamic option callback names, and `register_rest_api` hints
- Example for `itt_globe_point`: clients discover `points_info` as serialized meta with nested `coordinates`, `tooltipContent`, and `content`; `relationship_point` exposes `globe_id` and `globe_id_select`
- Metadata responses now preserve raw `meta` config and add `normalized.fields` plus `normalized.rest_meta_keys`
- Normalized field discovery flattens nested Codestar fields into explicit paths such as `points_info.coordinates.latitude`
- Normalized REST roots expose writable REST meta keys, serialized status, and JSON-schema-like types for MCP clients
- Added MCP resource tests for meta field aggregation, empty fields, REST errors, and no-model cases
- Phase 3 progress: 5 items completed, 5 items skipped
- Skipped local stdio MCP server: WordPress 7.0 Abilities is the adopted MCP integration path
- Skipped SSE transport: Serve MCP over HTTP for remote connections
- Skipped Multi-site management: Named site profiles, switchable at runtime
- Skipped Role-based access: Map MCP tool access to WP user roles
- Skipped Health monitoring: Endpoint with version, error rate, latency stats
- Skipped Configuration profiles: `--profile=high-volume`, `--profile=strict`
- Structured error codes: ErrorCode constants + McpError value object with resolution hints
- Caching layer: CacheInterface + InMemoryCache integrated into WordPressClient GET
- Rate limiting: Sliding-window RateLimiter throttles tool calls (default 60/60s)
- Audit trail: AuditLogger writes JSON tool call records to STDERR and optional file
- Config: 9 new env vars for cache, rate limit, and audit configuration
- 38 new PHPUnit tests (243 total, 696 assertions) covering all 4 Phase 3 features
- PHPStan Level 7 clean across all new MCP code
- PHP 8.3+ required for `str_starts_with()` in McpError
- Code review: Config constructor refactored to array bag pattern (#49 — medium)
- Code review: McpError dead ternary removed (#49 — low)
- Code review: RateLimitResult split into own file (#49 — low)
- Code review: Unused getDefaultMessage() removed (#49 — low)

## Known Issues
- Reference `phpstan_errors.txt` for current static analysis warnings/errors (Level 7 clean for MCP + Rest, 318 pre-existing errors in legacy core code).
- Code review flagged RateLimitResultTest.php as added (3 new tests, 559 assertions).
- Code review 4/4 findings resolved.

## Handoff
- WP7 Abilities is the MCP direction. Local stdio server, SSE transport, and standalone packaging are skipped.
- Metadata discovery is implemented through `saltus/list-meta-fields` and `saltus/get-meta-fields`.
- `list_meta_fields` calls `GET /saltus-framework/v1/meta` and returns `post_types`.
- `get_meta_fields` calls `GET /saltus-framework/v1/meta/{post_type}` and returns one CPT's raw `meta` plus normalized field paths and REST meta keys.
- Current verification: full `composer test` passes; touched-file PHPStan passes; full `composer phpstan` still reports 10 pre-existing errors outside the metadata changes.
