# Current: Live Working State

## Working
- Refactor high-traffic legacy Features/ and Saltus*.php paths @since 2026-07-03
- Add unit/integration tests for refactored legacy paths @since 2026-07-03

## Next
- None

## Blocked
- None

## Recent Changes
- Audit/runtime hardening: MCP audit retention cleanup now runs through daily WP-Cron instead of after every audit write; settings updates safely sanitize object payloads; single-post REST export removes WordPress core download headers before returning through REST; health degradation now counts only server-side `error` and `exception` audit statuses while still reporting validation and rate-limit status counts @since 2026-07-04
- Service extraction: inline REST controller logic moved into shared service classes (SaltusSingleExport, MetaFieldProvider, ReorderPostsService, SettingsManager) and wired into both REST controllers and MCP tools; defensive guards added for null post, private property access, taxonomy object, and asset data types — 7 commits covering LegacyFeatureTest, FrameworkBootTest, RuntimeTest, RegistrarTest, and controller tests; 195 tests, 567 assertions @since 2026-07-03
- Added MCP client integration guide at `docs/MCP-CLIENTS.md`, covering recommended call flow, health-first checks, model and metadata discovery, safe reads/writes, permission and rate-limit handling, editor integration notes, prompt guidance, and anti-patterns @since 2026-07-03
- Added `composer docs:mcp` and `bin/generate-mcp-docs.php` to generate MCP ability documentation from `src/MCP/Tools`; generated output now refreshes `docs/MCP-ABILITIES.md` and the embedded ability table in `docs/MCP.md` @since 2026-07-03
- Added long-form WordPress-native MCP/Abilities documentation at `docs/MCP.md` as the source page for future Saltus site docs, covering setup, discovery, permissions, all 17 abilities, metadata discovery, health monitoring, runtime filters, audit/cache/rate-limit behavior, compatibility, and troubleshooting @since 2026-07-03
- MCP health ability added as `get_health`, backed by `GET /saltus-framework/v1/health`, cacheable for 60 seconds, and registered as `saltus/get-health`; WordPress-native MCP/Abilities surface now exposes 17 tools @since 2026-07-03
- Health monitoring REST endpoint added at `GET /saltus-framework/v1/health`; reports framework version, native ability availability, audit sample/error rate/status counts, latency average/p95/max, and cache/rate-limit enabled flags. Route is registered independently of model-level `saltus_rest` opt-in and is covered by HealthController and RestServer tests; verification is green with `composer test`, `composer phpstan`, `composer phpcs`, and `git diff --check` @since 2026-07-03
- Modeler ternary dispatch refactored into centralized `process_config()` method; WP test stubs enhanced with add_filter, apply_filters callback execution, post_meta, nonce, enqueue, esc*, WP_Query, and WP_Term stubs; LegacyFeatureTest and ModelerLegacyTest added covering deprecated filter paths, file-order processing, and multi-model configs — 4 files, 812 insertions @since 2026-07-02
- Code review hardening pass: single-post REST export now emits WXR only for the requested post; `Core` can register activation/deactivation hooks against the consuming plugin file; MCP mutating permission callbacks fail closed on missing target args; settings updates recursively preserve structured values; `AbilityRuntime` JSON fallback works outside WordPress; `AssetLoader` is covered by PHPStan via `AssetLoadingService` @since 2026-07-02
- Regression coverage added for export isolation, plugin-file lifecycle hook registration, fail-closed ability permissions, and nested settings payloads; verification is green with `composer test` (166 tests, 416 assertions), `composer phpstan`, `composer phpcs`, and `git diff --check` @since 2026-07-02
- Permission granularity: REST controllers and MCP abilities now delegate to per-post-type and per-post WordPress capabilities instead of coarse edit_posts gate — 8 commits covering DuplicateController, MetaController, ModelsController, ReorderController, SettingsController, and AbilityDefinitionFactory; ToolFactory removed in favor of ToolContributor-driven provider injection @since 2026-07-02
- MCP v1 refactoring: 14 commits — RestBackedToolInterface, RestCapabilityRequirement, RestTool, ToolContributor introduced; per-tool build_rest_request dispatch replaces monolithic AbilityRuntime switch; AbilityRegistrar gating via RestBackedToolInterface capability requirements; @phpstan-type AbilityDefinition added; all REST-backed tools migrated to RestBackedToolInterface; REST controllers updated for MCP v1 dispatch; ToolContributor wired into Modeler and all feature services @since 2026-07-02
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
- Health monitoring: Endpoint with version, error rate, latency stats
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
- `composer test` passes; Composer still prints a dependency deprecation notice from `justinrainbow/json-schema` under PHP 8.5.4.
- `composer phpcs` passes.
- PHPStan: Level 7 clean across the configured analysis set (new service classes ReorderPostsService, MetaFieldProvider, SettingsManager added).

## Handoff
- WP7 Abilities is the MCP direction. Local stdio server was removed; SSE transport and standalone packaging are skipped.
- Standalone stdio MCP server removed; WP7 Abilities now owns MCP execution with WordPress-native audit, rate limiting, and transient caching.
- Metadata discovery is implemented through `saltus/list-meta-fields` and `saltus/get-meta-fields`.
- `list_meta_fields` calls `GET /saltus-framework/v1/meta` and returns `post_types`.
- `get_meta_fields` calls `GET /saltus-framework/v1/meta/{post_type}` and returns one CPT's raw `meta` plus normalized field paths and REST meta keys.
- Service extraction completed 2026-07-03: SaltusSingleExport, MetaFieldProvider, ReorderPostsService, and SettingsManager are now shared between REST controllers and MCP tools via constructor injection. Feature classes (DragAndDrop, Meta, Settings, SingleExport) own the service instances and pass them to both paths, eliminating code duplication.
- Current verification: full `composer test` (195 tests, 567 assertions), `composer phpstan`, `composer phpcs`, and `git diff --check` pass after the service extraction pass.
