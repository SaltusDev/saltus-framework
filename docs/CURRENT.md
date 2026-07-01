# Current: Live Working State

## Working
- Refactor high-traffic legacy files (Modeler.php, Features/, Saltus*.php) @since 2026-07-02
- MCP v1 dispatch refactoring: RestBackedToolInterface, per-tool build_rest_request, ToolContributor integration @since 2026-07-02

## Next
- Add unit/integration tests for refactored legacy paths

## Blocked
- None

## Recent Changes
- MCP v1 refactoring: 14 commits — RestBackedToolInterface, RestCapabilityRequirement, RestTool, ToolContributor introduced; per-tool build_rest_request dispatch replaces monolithic AbilityRuntime switch; AbilityRegistrar gating via RestBackedToolInterface capability requirements; @phpstan-type AbilityDefinition added; all 16 tools migrated to RestBackedToolInterface; REST controllers updated for MCP v1 dispatch; ToolContributor wired into Modeler and all feature services @since 2026-07-02
- Capability-gated REST routes: ModelRestPolicy, RestRouteDefinition, and RestRouteProvider infrastructure — per-model opt-in via `saltus_rest` config key; all 9 REST controllers enforce policy at request time; MCP abilities respect same policy gates @since 2026-07-01
- Audit trail: insert validation and sanitization — null-byte stripping, column-length truncation, status whitelist, and WordPress sanitize_text_field applied to all string fields before persistence @since 2026-07-01
- Fixed 2 pre-existing PHPStan errors in ResourceProvider — docblock param name mismatch (@param $context → $_context) @since 2026-07-01
- Added unit tests for `BaseModel::get_name()` and `Modeler` add/get_models (5 test methods, 248 total)
- Added `tests/Integration/.gitkeep` to preserve the integration test directory
- `composer phpcs` is clean — all MCP module renamed to snake_case (14 commits)
- WordPress naming conventions enforced across MCP module: methods, properties, variables renamed from camelCase to snake_case
- Added `Json` helper class for safe JSON encoding (wp_json_encode with json_encode fallback)
- Removed phpcs.xml exclusion rules for MCP and REST paths
- Fixed ReflectionClass::getName() regression from rename
- Full `composer phpstan` is clean at PHPStan Level 7 across the configured analysis set
- Added `Model::get_name()` and `BaseModel::get_name()` so `Modeler` keys models through the model contract instead of concrete public properties
- Tightened `Modeler::add()` parameter and return types and removed redundant nullable fallback from `get_models()`
- Updated REST controllers to register routes with non-empty namespace constants for PHPStan-safe WordPress route registration
- Removed redundant taxonomy slug type narrowing in `ListPosts`
- v2.0.0 released 2026-06-30 — merged feature/mcp-v0 to main, tagged v2.0.0
- Added strict phpunit.xml config with random execution order, failOn* and beStrictAbout* flags
- Added phpunit.xml.dist as distribution configuration
- Created tests/TestCase.php base class for all framework tests
- Added Unit test suite: Container, Asset, Model config tests (5 test files)
- Added Integration test suite: FrameworkBootTest for service container verification
- Added is_admin() stub in test functions for environment compatibility
- Added PHPUnit test job to GitHub Actions CI workflow
- Bumped version to 1.4.0
- WordPress 7.0 MCP/Abilities connector: `MCP` injects feature-contributed tools into `AbilityRegistrar` when `wp_register_ability()` exists
- Feature-owned `ToolContributor` services keep MCP tools aligned with their REST routes and capabilities
- Native ability callbacks dispatch through `rest_do_request()` so existing REST permission callbacks remain authoritative
- Added compatibility tests covering native ability registration, capability gating, and REST-backed dispatch
- README documents WordPress-native MCP/Abilities as the selected path
- Removed standalone local stdio MCP server and related setup docs
- WP7 clients use `list_meta_fields` for discovering model-defined meta fields across registered CPTs
- Added `list_meta_fields` for WordPress-native metadata discovery across registered CPTs
- Added aggregate `GET /saltus-framework/v1/meta` for all post type meta definitions
- WP7 ability runtime resolves metadata discovery through the aggregate `/meta` endpoint
- MCP clients currently see raw Saltus/Codestar meta config: metabox IDs, sections, field definitions, dynamic option callback names, and `register_rest_api` hints
- Example for `itt_globe_point`: clients discover `points_info` as serialized meta with nested `coordinates`, `tooltipContent`, and `content`; `relationship_point` exposes `globe_id` and `globe_id_select`
- Metadata responses now preserve raw `meta` config and add `normalized.fields` plus `normalized.rest_meta_keys`
- Normalized field discovery flattens nested Codestar fields into explicit paths such as `points_info.coordinates.latitude`
- Normalized REST roots expose writable REST meta keys, serialized status, and JSON-schema-like types for MCP clients
- Added MCP ability tests for meta field aggregation, empty fields, REST errors, and no-model cases
- Phase 3 progress: 5 items completed, 5 items skipped
- Removed local stdio MCP server: WordPress 7.0 Abilities is the adopted MCP integration path
- Skipped SSE transport: Serve MCP over HTTP for remote connections
- Skipped Multi-site management: Named site profiles, switchable at runtime
- Skipped Role-based access: Map MCP tool access to WP user roles
- Skipped Health monitoring: Endpoint with version, error rate, latency stats
- Skipped Configuration profiles: `--profile=high-volume`, `--profile=strict`
- WP7 ability errors now return `WP_Error` directly from the WordPress-native runtime
- Caching layer: CacheInterface + TransientCache integrated into WP7 ability execution
- Rate limiting: Sliding-window RateLimiter throttles WP7 ability calls (default 60/60s)
- Audit trail: AuditLogger writes WP7 ability records to the Saltus MCP audit table
- Config: WordPress filters control cache, rate limit, and audit behavior
- 38 new PHPUnit tests (243 total, 696 assertions) covering all 4 Phase 3 features
- PHPStan Level 7 clean across all new MCP code
- MCP stdio-only error wrapper removed with the standalone server path
- Code review: Config constructor refactored to array bag pattern (#49 — medium)
- Code review: stdio-only MCP error wrapper removed with old server path
- Code review: RateLimitResult split into own file (#49 — low)
- Code review: Unused getDefaultMessage() removed (#49 — low)

## Known Issues
- `composer phpcs` passes — MCP module renamed to snake_case, exclusions removed.
- `composer test` passes, but PHPUnit reports 8 deprecations and 49 notices under PHP 8.5.4/PHPUnit 12.5.30.
- PHPStan: Level 7 clean — ResourceProvider docblock mismatch resolved.

## Handoff
- WP7 Abilities is the MCP direction. Local stdio server was removed; SSE transport and standalone packaging are skipped.
- Standalone stdio MCP server removed; WP7 Abilities now owns MCP execution with WordPress-native audit, rate limiting, and transient caching.
- Metadata discovery is implemented through `saltus/list-meta-fields` and `saltus/get-meta-fields`.
- `list_meta_fields` calls `GET /saltus-framework/v1/meta` and returns `post_types`.
- `get_meta_fields` calls `GET /saltus-framework/v1/meta/{post_type}` and returns one CPT's raw `meta` plus normalized field paths and REST meta keys.
- Current verification: full `composer phpstan` passes; full `composer test` passes with existing PHPUnit deprecations/notices; project-wide `composer phpcs` still fails on pre-existing style/complexity findings.
