# Current: Live Working State

## Working
- Docs accuracy review: counts, config keys, and route pages reconciled against source @since 2026-08-07
- Reconcile the historical `v1.4.2`/`v2.0.0` tag relationship with the current version line (package.json now at 1.8.0) @since 2026-08-08
- Phase 8B implementation: admin surface and proposal-queue-governed writes @since 2026-08-07

## Next
- Optional 8A follow-up: move the bridge enqueue onto `AssetLoadingService` instead of a direct `wp_enqueue_scripts` call

## Blocked
- None

## Recent Changes
- Version bumped 1.7.0 → 1.8.0 (minor) via the automated version cycle; annotated `v1.8.0` tag created. The cycle committed the Phase 8A hardening and docs already recorded above (per-client `ClientIdentity` rate limiting, `ResultBudget` output clamp, `bridge.js` JS suite, and the generated WebMCP tool reference) as atomic commits, each passing the repo commit gate. Full suite green: 394 tests, 1072 assertions, plus 13 JS tests; PHPStan Level 7 and PHPCS clean. @since 2026-08-08
- Phase 8A hardening and docs, closing the three checklist rows that had been ticked prematurely. `/webmcp/execute` is now rate-limited **per client** rather than on one shared key — `ClientIdentity` keys logged-in callers by user id and everyone else by a salted hash of `REMOTE_ADDR`, so one visitor's agent can no longer exhaust the window for every other visitor; forwarded headers are deliberately ignored, with `saltus/framework/webmcp/client_identifier` for sites behind a proxy or CDN. `ResultBudget` clamps a serialized result to the 1500-character agent output budget, dropping entries from the longest list before clipping strings, correcting sibling `count` values, and setting `truncated` only when something was actually removed. `docs/guides/webmcp.md` added with a generated tool reference (`composer docs:webmcp` → `bin/generate-webmcp-docs.php`), registered in the VitePress nav and sidebar. First JS test suite added: 13 `node:test` cases over `bridge.js`, covering the absent-API silent no-op, the deprecated-`navigator` fallback and probe order, error and network paths, and `pagehide` teardown — wired to `npm test` and a `bridge` CI job. Both new guards were mutation-tested: reverting to the shared key or removing the clamp fails 3 tests, and making the bridge log on unsupported browsers fails 2. Full suite green: 394 tests, 1072 assertions, plus 13 JS tests; PHPStan Level 7 and PHPCS clean. @since 2026-08-08
- Phase 8A delivered: WebMCP frontend read-only tool surface. `WebMcp` feature service, `WebMcpPolicy` per-model gating, `ManifestBuilder` projecting existing `ToolInterface` definitions into JSON Schema descriptors, `PublicFieldFilter` for public meta field resolution, five read tools (`search_content`, `get_content`, `list_content_models`, `list_taxonomy_terms`, `filter_content`), `WebMcpController` manifest/execute routes, and the `bridge.js` single-point namespace probe. Every invocation re-validates server-side, is rate-limited, and audit-logged; only published posts of publicly-queryable models surface. Component-based PHPUnit coverage added for policy, manifest, each tool, execute permissions, and the absent-API no-op. Full suite green: 372 tests, 1015 assertions. @since 2026-08-07
- Version bumped 1.6.0 → 1.7.0 (minor) via the automated version cycle; annotated `v1.7.0` tag created. This cycle delivered Phase 8A (see above). @since 2026-08-07
- Phase 8 scoped: WebMCP browser surface. Research recorded in `docs/discovery/webmcp.md` (standards status pulled from the Chrome Status API, Cloudflare's dual consumer/producer implementation, Shopify's 11-tool storefront rollout, the WebMCP Bridge plugin's cache/leakage findings, and the 0%-adoption survey data). Roadmap Phase 8 added with 8A frontend read-only tools and 8B admin surface plus proposal-queue-governed writes. Three scope decisions: project tool descriptors from the existing `ToolInterface` registry rather than hand-authoring a parallel set, ship read-only first because all 20 current abilities gate on `edit_posts` and give an anonymous visitor nothing, and route all future writes through Phase 6B's `ProposalService::should_queue()` since WebMCP has no settled confirmation or auth model. @since 2026-08-07
- Version bumped 1.5.0 → 1.6.0 (minor) via the automated version cycle; annotated `v1.6.0` tag created. `AiAssistantProvider` now generates unhandled assistant actions through the WordPress AI Client (`AiClient` + `ActionPrompts`), composes model `ai_context` into the system instruction, fills missing title/content/excerpt from the post, and adds the `saltus/framework/ai/prompt_builder` filter. Health (`ai.client_available`, `ai.connectors_available`) and `wp saltus` report AI availability. Regression coverage added across AiAssistantTest + functions.php stubs and a health-payload assertion. Full suite green: 355 tests, 956 assertions. @since 2026-08-07
- Version bumped 1.4.2 → 1.5.0 (minor) via the automated version cycle; annotated `v1.5.0` tag created. `DuplicateControllerTest` flake fix (storage flag now reset in a `finally` block) included in the cycle commit. @since 2026-08-07
- Documented three REST/MCP gating behaviors that the docs previously described incorrectly: capability config sections live at the top level of the model array (not under a `config` key), an array section without a `show_in_rest`/`show_in_mcp` key resolves to **enabled** and overrides the master option rather than falling back to it, and the reorder capability is gated from `features.drag_and_drop` while the admin UI is enabled via `features.draganddrop`. Verified against `ModelRestPolicy`/`McpPolicy`. @since 2026-08-07
- Docs accuracy pass: ability count corrected to 20 across index.md, mcp/index.md, MCP.md, architecture.md, and skill.md; `get_context` added to the generated ability reference via `composer docs:mcp`; REST route count corrected from 9 to 17 with a full route table in architecture.md; feature service count corrected from 10 to 16; Phase 6 AI governance services documented in architecture.md; dead `saltus_rest` config key replaced with `options.show_in_rest` / `options.mcp_tools` in README; wrong `composer phpstan`/`phpcs` script names corrected to `test:phpstan`/`test:phpcs`; missing `docs/public/logo.png` and `favicon.ico` generated from brand icon; duplicate BUILD.md and MCP-CLIENTS.md reduced to pointers; stale TESTING_HANDOFF.md and HANDOFF.md rewritten to record delivered state @since 2026-08-07
- Phase 6B delivered: persistent proposals for all eight mutating Saltus abilities, permission-checked MCP queueing, before/after review payloads, REST approval/rejection endpoints, admin review dashboard, proposal audit events, and lifecycle tests. Full suite green: 336 tests, 904 assertions. @since 2026-08-06
- Phase 6C delivered: model-scoped admin assistant actions, provider filter contract, authenticated REST endpoint, post editor controls, field-rule validation output, and PHPUnit coverage. @since 2026-08-06
- Phase 7 delivered: ReflectionInstantiator autowiring for named, positional, typed, default, nullable, variadic, and legacy dependency-bag constructor parameters, with container tests and documentation. @since 2026-08-06
- Version bumped to 1.2.1. `get_context` MCP tool + `GET /saltus-framework/v1/context/{post_type}` + `wp saltus context get` command added; AI governance now enforced at runtime via `AiContextProvider` in `AbilityRuntime`; command catalog is the authoritative 20-ability parity map. Frontend and AiContext features documented in CONTEXT.md. Full suite green: 329 tests, 872 assertions. @since 2026-08-06
- Phase 6A delivered: normalized per-model ai_context, defaults filter, get_context MCP/REST discovery, and hard mutation governance for statuses and forbidden actions @since 2026-08-06
- Phase 5C delivered: model-driven frontend shortcodes, list/single templates, safe query attribute parsing, taxonomy filtering, meta field exposure, config/theme/default template overrides, tests, and frontend feature documentation @since 2026-08-06
- Phase 5A delivered: runtime Block API v3 list/single blocks, shared editor assets, dynamic render templates, REST discovery, and `list_block_models` MCP ability @since 2026-07-31
- Phase 5B delivered: eight `wp saltus` command groups provide parity with all 19 abilities, direct shared-service execution, table/JSON/YAML output, JSON file input, tests, and generated command docs @since 2026-07-31
- Phase 5D delivered for implemented features: README placeholders replaced, model/feature references corrected, Blocks, Frontend, and WP-CLI guides added to VitePress, and MCP/WP-CLI docs regenerated for 19 tools @since 2026-07-31
- Docs infrastructure: VitePress site scaffolded at `docs/.vitepress/`, phpDocumentor config at `phpdoc.dist.xml`, `.github/workflows/docs.yml` for auto-build + GH Pages deploy to `docs.saltus.dev`, `docs/public/CNAME` for custom domain, 6 doc content pages (getting-started, features, architecture, build, MCP overview, API index), `composer docs:all` script (`docs:mcp` + `docs:api`), @api annotations on 84 public classes/interfaces across all namespaces, README updated with docs.saltus.dev links, ROADMAP.md Phase 5D progress tracked @since 2026-07-21
- AbilityRuntime middleware pipeline integration: backward-compatible `execute_via_pipeline()` delegated from `execute()`, `execute_legacy()` preserved as fallback, optional `MiddlewarePipeline` constructor injection — resolves Phase 4F/4G strip-cache/strip-rate-limit items @since 2026-07-21
- Code review feedback: replaced wp_die with RuntimeException in single_export_query to avoid HTML death pages in REST/MCP contexts; added catch clause in export_post to return structured WP_Error; replaced unsafe property_exists with get_object_vars in ModelRestPolicy to avoid fatal errors on non-public properties; added explicit edit_posts permission checks for list_models/get_model/list_meta_fields/get_meta_fields in AbilityDefinitionFactory; changed AuditLogger created_at column from varchar(32) to datetime(3) — 2 commits @since 2026-07-04
- Export query hardening: replaced fragile string-equality check in single_export_query with structural regex detection via is_fake_date_export_query; added wp_die fallback for unrecognized fake-date query shapes; added esc_html__ and wp_die test stubs — 1 commit @since 2026-07-04
- Code review feedback: deferred RestServer instantiation inside rest_api_init to avoid overhead on non-REST requests; replaced wp_next_scheduled/wp_unschedule_event with wp_clear_scheduled_hook in MCP deactivation; gated ensure_table() behind DB version option to avoid unnecessary CREATE TABLE queries on every audit write; added wp_clear_scheduled_hook test stub — 3 commits @since 2026-07-04
- PHP 7.4 test compatibility: removed `mixed` type hints from 6 test file anonymous classes implementing AuditDatabase to avoid fatal errors on PHP 7.4 (parse error) and PHP 8.x (interface signature mismatch) @since 2026-07-04
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

- Defensive code review fix pass: 6 files updated — invalid item payload guard in ReorderPostsService, WP_Error handling after rest_do_request in AbilityRuntime, OBJECT output format support in WpdbAuditDatabase, explicit get_settings/reorder_posts permission checks in AbilityDefinitionFactory, repeater sub-field schema exposure in MetaFieldProvider, and cutoff timestamp millisecond fix in AuditLogger @since 2026-07-04
- Code review follow-up: replaced unsafe property_exists with get_object_vars in MetaFieldProvider::get_model_args to align with ModelRestPolicy pattern; widened catch from \Exception to \Throwable in SaltusSingleExport::export_post for PHP 7+ Error type resilience — 2 commits @since 2026-07-05
- Phase 5 planned and added to ROADMAP.md — 4 sub-tracks: 5A (Blocks), 5B (WP-CLI), 5C (Frontend), 5D (Docs). Implementation starting with 5A + 5D. @since 2026-07-05
- Code review fixes: cache only cleared on non-GET requests in AbilityRuntime; added integer type support in Validator; moved null check before try block in AssetLoader — 3 commits @since 2026-07-05
- Model interface hardening: added get_options(): array and get_args(): array to Model interface; removed fragile method_exists + get_object_vars fallbacks from ModelRestPolicy, ModelsController, and MetaFieldProvider; updated all anonymous Model implementations in test files — 5 commits, 13 files @since 2026-07-05
- Replaced hardcoded tester.php with proper PHPUnit container integration tests in tests/Integration/ContainerIntegrationTest.php — 1 commit @since 2026-07-05
- Code review feedback round 2: removed static clear guard from TransientCache to avoid stale cache in long-running processes; replaced fragile strict array comparison in SettingsManager with database re-read to prevent false-positive rest_update_failed errors; switched export SQL detection from regex to WP_Query var inspection via posts_request filter; set explicit UTC timezone in AuditEntry DateTimeImmutable to fix incorrect timestamps — 5 commits @since 2026-07-06
- Code review gemini-code-assist round 1: replaced direct $model->name access with check_method() helper in ModelsController to avoid fatal errors on private/protected properties; added added_option and deleted_option hooks to MCP cache-clearing list to flush caches on option creation and deletion; replaced sanitize_key() with case-preserving preg_replace() in SettingsManager to avoid breaking camelCase settings keys; replaced ISO 8601 datetime format with MySQL-compatible format in AuditEntry and AuditLogger to prevent "Truncated incorrect datetime value" warnings — 4 commits, 8 files @since 2026-07-06

- FilterAwareTrait extracted from 5 identical `filter()` private methods in RateLimiter, HealthController, AbilityRuntime, AuditLogger, TransientCache — shared trait applied across MCP infrastructure @since 2026-07-06
- REST route registration bug fix: `is_needed()` gate bypassed for RestRouteProvider/ToolContributor registries via two-pass approach in `Core::register_services()` — REST routes now appear in WP-REST index even when `REST_REQUEST` is undefined during plugin boot @since 2026-07-06
- ServiceContainer::instantiate_unconditionally() added for bypassing Conditional gates @since 2026-07-06
- MCP contributors() fallback preserves backward compatibility when MCP is instantiated outside Core @since 2026-07-06
- AuditDatabase::prepare() added to interface and WpdbAuditDatabase for safe SQL parameterization @since 2026-07-06
- Validator::is_list() helper added for type checks @since 2026-07-06
- Test suite fixed and passing: 214 tests, 605 assertions — AuditLogger DAY_IN_SECONDS fallback, ExportController export_wp echo fix, wpdb prepare %s quoting, MCPFeatureTest contributor fallback, RestRegistrationTest modeler mock injection @since 2026-07-06
- @covers annotations added to all test classes (26 test files) @since 2026-07-06
- MCP error hints: actionable `hint` keys added to every WP_Error `$data` array across 7 REST controllers (HealthController, ExportController, DuplicateController, ModelsController, MetaController, ReorderController, SettingsController) and MetaFieldProvider — 5 commits @since 2026-07-07
- Permission delegation: ToolInterface::has_permission() added; RestTool default + 13 per-tool overrides; AbilityDefinitionFactory::can_use_tool refactored to delegate to per-tool has_permission — 2 commits @since 2026-07-07
- MetaController PUT route: `update_item` + `update_item_permissions_check` at `PUT /saltus-framework/v1/meta/{post_type}/{post_id}` with serialized meta merging; UpdateMetaFields MCP tool and MetaControllerTest + UpdateMetaFieldsTest added — 1 commit @since 2026-07-07
- Test suite: 226 tests, 639 assertions (bumped from 214/605 by +12 tests, +34 assertions for new MCP tool, MetaController PUT, and count updates) @since 2026-07-07
- ModelsController: use `check_method` for description property access to prevent PHP 8.2+ dynamic property deprecation notices @since 2026-07-07
- MCP namespace config filterability: added MCPConfig utility class with 3 WordPress filters (saltus/framework/mcp/namespace, saltus/framework/mcp/ability_category, saltus/framework/mcp/ability_prefix); added mcp_route() helper to RestTool base; refactored 21 source files from hardcoded strings to MCPConfig calls; added MCPConfigTest with 13 test cases — 6 commits, 236 tests, 655 assertions @since 2026-07-08
- MCP/REST capability gating refactored: added get_config() to Model interface; added McpPolicy class with 14 test cases for MCP-specific mcp_tools/show_in_mcp gating; refactored ModelRestPolicy from saltus_rest array to per-feature config-section model; updated REST controller error hints; updated all existing tests for new config-section pattern — 7 commits, 250 tests, 669 assertions @since 2026-07-08

- Version bump to v1.1.0: `phpdocumentor/phpdocumentor` added to require-dev, composer dev platform pinned to PHP 8.4 (runtime still targets 7.4+), brand assets added to `docs/assets/`, BUILD.md notes the docs tooling @since 2026-07-31

## Known Issues
- `composer test` passes (394 tests, 1072 assertions); Composer still prints a dependency deprecation notice from `justinrainbow/json-schema` under PHP 8.5.4.
- `npm test` runs the `bridge.js` suite (13 tests) on Node's built-in test runner. No npm dependencies are needed for it; `npm ci` is only required for the docs build.
- `composer test:phpcs` passes. Note the script names are `test:phpstan` and `test:phpcs` — bare `composer phpstan` / `composer phpcs` do not exist.
- PHPStan: Level 7 clean across the configured analysis set; use `--debug` when the sandbox prevents PHPStan's local TCP worker server from binding.
- Version numbering is partially resolved: `package.json` is now 1.8.0 with a matching `v1.8.0` tag, but `docs/ROADMAP.md` historically referenced 2.0.0 and `v1.4.2`/`v2.0.0` tags still exist. `CHANGELOG.md` now carries a 1.8.0 release section.

## Handoff
- WP7 Abilities is the MCP direction. Local stdio server was removed; SSE transport and standalone packaging are skipped.
- Standalone stdio MCP server removed; WP7 Abilities now owns MCP execution with WordPress-native audit, rate limiting, and transient caching.
- Metadata discovery is implemented through `saltus/list-meta-fields` and `saltus/get-meta-fields`.
- `list_meta_fields` calls `GET /saltus-framework/v1/meta` and returns `post_types`.
- `get_meta_fields` calls `GET /saltus-framework/v1/meta/{post_type}` and returns one CPT's raw `meta` plus normalized field paths and REST meta keys.
- Service extraction completed 2026-07-03: SaltusSingleExport, MetaFieldProvider, ReorderPostsService, and SettingsManager are now shared between REST controllers and MCP tools via constructor injection. Feature classes (DragAndDrop, Meta, Settings, SingleExport) own the service instances and pass them to both paths, eliminating code duplication.
- Current verification: full `composer test` (394 tests, 1072 assertions), `npm test` (13 `bridge.js` tests), PHPStan Level 7 (`--debug` in the sandbox), `composer test:phpcs`, `npm run docs:build`, and `git diff --check`.
- Generated docs must be refreshed with `composer docs:all` whenever a tool, WP-CLI command, or WebMCP tool is added, renamed, or has its schema changed. `docs/mcp/abilities.md` and `docs/guides/wp-cli.md` are generated in full; do not hand-edit them. In `docs/guides/webmcp.md` only the section between the `AUTO-GENERATED WEBMCP TOOLS` markers is generated — the prose around it is hand-written and is preserved by `composer docs:webmcp`.
