# Saltus Framework Roadmap

## Current Status
- Version: `package.json` is at 2.1.1, released 2026-08-19 with an annotated `v2.1.1` tag; `CHANGELOG.md` now cuts a `[2.1.0]` section, but `[Unreleased]` is empty and no `[2.1.1]` section exists yet — see finding 17.27 in [Phase 17](#phase-17--v211-review--2527); relationship to the historical `v1.4.2`/`v2.0.0` tags still pending
- Phases 1–8 delivered. Phase 8A (WebMCP frontend browser surface) delivered 2026-08-07; Phase 8B (admin surface and governed writes) delivered 2026-08-08.
- Phase 10A (content relationships) delivered 2026-08-08, without its metabox UI, query-builder facade, or migration scripts — see [Phase 10 Remainder](#phase-10-remainder). Phases 10B and 10C are scoped only in the internal RFC.
- **Phase 14 delivered 2026-08-16** (daily rollups, retention ordering, aggregate and per-client metrics, admin dashboard, `wp saltus metrics`, audit health states, error hand-off, sampling, slow-call logging, rollup freshness, and coverage). There is no Phase 9 — see [Phase Numbering](#phase-numbering).
- Release maintenance: v1.8.3 findings resolved 2026-08-10, v1.8.4 finding resolved 2026-08-11. Review backlogs after the 2026-08-19 fix pass: [Phase 15 — v1.8.5 Review](#phase-15--v185-review--45) (4/5 — only the 15.3 changelog note is left, deferred to finding 17.27), [Phase 16 — v2.1.0 Review](#phase-16--v210-review--79) (7/9 — 16.3 reciprocal permission reconciliation and 16.4 privacy cascade read path are held open pending a design decision), and [Phase 17 — v2.1.1 Review](#phase-17--v211-review--2527) (25/27 — 17.5 static-analysis strictness and 17.27 changelog remain).
- Features implemented: CPT creation, taxonomies, settings pages, metaboxes, cloning, export, drag&drop reordering, model-driven blocks, frontend shortcodes, WP-CLI parity, AI governance, WebMCP frontend read surface, audit rollups and the observability metrics dashboard
- WordPress-native MCP/Abilities surface with 25 tools
- REST API: 23 routes registered in `saltus-framework/v1/` across 13 controllers
- Phase 3 hardening complete: caching, rate limiting, audit trail, structured error codes, health monitoring
- MCP v1 refactoring complete: per-tool REST dispatch, RestBackedToolInterface, ToolContributor, @phpstan-type AbilityDefinition
- MCP namespace/category/prefix now filterable via MCPConfig utility class (saltus/framework/mcp/namespace, saltus/framework/mcp/ability_category, saltus/framework/mcp/ability_prefix)
- MCP/REST capability gating refactored: McpPolicy class with mcp_tools/show_in_mcp gating; ModelRestPolicy switched from the old saltus_rest array to a per-feature config-section model (using show_in_rest and show_in_mcp gates)
- Legacy refactoring: inline REST controller logic extracted into shared service classes (SaltusSingleExport, MetaFieldProvider, ReorderPostsService, SettingsManager) wired into both REST controllers and MCP tools — resolved 2026-07-03
- Conditional registration fix: `is_needed()` gate bypass for RestRouteProvider/ToolContributor registries via two-pass approach in `Core`, ensuring REST routes always appear in WP-REST index even before `REST_REQUEST` is defined — resolved 2026-07-06
- 1369 PHPUnit tests passing (3560 assertions) and PHPStan Level 7 clean, plus 51 `node --test` JS tests across three suites, verified 2026-08-19 after the fix pass. `phpstan.neon` still sets `treatPhpDocTypesAsCertain: false` globally — see finding 17.5.
- WebMCP Phase 8A delivers a third consumer of the tool registry (alongside MCP/Abilities and WP-CLI): read-only tools projected into the visitor's browser for models that opt in with `webmcp: { enabled: true, frontend: true }`.

## Top Priority: WordPress 7.0 MCP/Abilities Integration

**Theme:** Make Saltus MCP tools discoverable and usable through WordPress-native MCP/Abilities infrastructure in WordPress 7.0. The standalone local stdio MCP server path has been removed.

| Item | Status |
|------|--------|
| Track WordPress 7.0 MCP/Abilities API shape and naming as it stabilizes | ✓ Done |
| Map each existing Saltus MCP tool to a WordPress-native ability definition | ✓ Done |
| Register Saltus abilities from WordPress when the native API is present | ✓ Done |
| Standalone local stdio MCP fallback | Removed |
| Reuse existing REST permission checks so abilities honor `current_user_can()` gates | ✓ Done |
| Add compatibility tests for native abilities and REST-backed dispatch | ✓ Done |
| Document WordPress-native MCP client discovery | ✓ Done |

**Exit criteria:** On WordPress 7.0+, Saltus capabilities are exposed through the native MCP/Abilities layer. Older WordPress versions skip native ability registration.

---

## MCP/Abilities Roadmap

### Vision
Expose Saltus Framework capabilities through WordPress-native MCP/Abilities. Saltus keeps its REST controllers as the authoritative execution layer and registers ability definitions when WordPress provides the Abilities API.

---

### Phase 1: Foundation Hardening (v0.1 → v0.5)

**Theme:** Make it reliable, secure, and spec-compliant.

| Item | Status |
|------|--------|
| MCP core protocol (initialize, tools, resources) | ✓ Done |
| 9 Phase 1 CRUD tools (models, posts, terms) | ✓ Done |
| Interactive setup wizard | ✓ Removed |
| WordPress-native MCP runtime configuration filters | ✓ Done |
| WP7 ability audit, cache, and rate-limit runtime | ✓ Done |
| PHPUnit tests for ability registration and runtime behavior | ✓ Done |
| PHPStan Level 7 compliance for all `src/MCP/` code | ✓ Done |
| MCP Prompts support (`prompts/list`, `prompts/get`) — 3 prompt templates | Removed with stdio server |
| Input validation — JSON Schema validation on tool args before REST API call | ✓ Done |
| WordPress transient caching and invalidation for WP7 abilities | ✓ Done |
| `--help` flag with complete usage reference | Removed with stdio server |
| Update `README.md` with WP7 MCP usage and runtime filters | ✓ Done |

**Exit criteria:** Full test suite green, PHPStan level 7, prompts working, zero file I/O, no wizard.

---

### Phase 2: Framework REST API (v0.5 → v1.0)

**Theme:** Expose every framework feature as a registered REST API route. Consume them from MCP tools.

**Framework REST namespace:** `saltus-framework/v1/`

#### REST Controllers (new `src/Rest/` namespace)

| Route | Method | Controller | Status | Wraps |
|-------|--------|------------|--------|-------|
| `/models` | GET | `ModelsController` | ✓ Done | `Modeler` — list loaded models with full config |
| `/models/{post_type}` | GET | `ModelsController` | ✓ Done | Model config, features, meta, settings |
| `/duplicate/{post_id}` | POST | `DuplicateController` | ✓ Done | `SaltusDuplicate::perform_duplication()` |
| `/export/{post_id}` | GET | `ExportController` | ✓ Done | Single-post WXR export scoped to the requested post |
| `/settings/{post_type}` | GET | `SettingsController` | ✓ Done | `get_option($settings_id)` |
| `/settings/{post_type}` | PUT | `SettingsController` | ✓ Done | `update_option($settings_id, $data)` |
| `/meta` | GET | `MetaController` | ✓ Done | Aggregate meta field definitions for all post type models |
| `/meta/{post_type}` | GET | `MetaController` | ✓ Done | List meta field definitions from model config |
| `/reorder` | POST | `ReorderController` | ✓ Done | Batch `menu_order` update |

**Registration:** `Core::register()` adds `add_action('rest_api_init', [$restServer, 'register_routes'])`.

**Permission callback:** `current_user_can('edit_posts')` by default.

#### New MCP Tools

| Tool | Calls | Status |
|------|-------|--------|
| `duplicate_post` | `POST /saltus-framework/v1/duplicate/{id}` | ✓ Done |
| `export_post` | `GET /saltus-framework/v1/export/{id}` | ✓ Done |
| `get_settings` | `GET /saltus-framework/v1/settings/{post_type}` | ✓ Done |
| `update_settings` | `PUT /saltus-framework/v1/settings/{post_type}` | ✓ Done |
| `reorder_posts` | `POST /saltus-framework/v1/reorder` | ✓ Done |
| `list_meta_fields` | `GET /saltus-framework/v1/meta` | ✓ Done |
| `get_meta_fields` | `GET /saltus-framework/v1/meta/{post_type}` | ✓ Done |

#### Updated MCP Resources

| Resource | Status |
|----------|--------|
| `saltus://models` | ✓ Returns live data from `GET /saltus-framework/v1/models` |
| `saltus://meta-fields` | ✓ Legacy MCP resource backed by `GET /saltus-framework/v1/meta`; WP7 clients use `list_meta_fields` |
| `saltus://features` | ○ Still static — no dedicated REST endpoint for features list |

#### Metadata Normalization

| Item | Status |
|------|--------|
| Raw Saltus/Codestar meta config exposed to WP7 MCP clients | ✓ Done |
| Flatten nested meta fields into client-friendly paths and JSON-schema-like types | ✓ Done |

**Exit criteria:** All 9 REST routes registered and tested ✓; all 7 new MCP tools operational ✓; v2.0.0 release tag ✓.

---

### Phase 3: Premium Polish (v1.0 → v2.0)

**Theme:** Production hardening — caching, audit, errors, and request controls.

| Feature | Description | Status |
|---------|-------------|--------|
| **WordPress 7.0 MCP/Abilities integration** | Register Saltus MCP tools as WordPress-native abilities when available | ✓ |
| **Local stdio MCP server** | Run Saltus as a standalone local MCP server process | Removed |
| **SSE transport** | Serve MCP over HTTP for remote connections | Skipped |
| **Multi-site management** | Named site profiles, switchable at runtime | Skipped |
| **Role-based access** | Map MCP tool access to WP user roles | Skipped |
| **Audit trail** | Every tool call logged with timestamp, user, args, result | ✓ |
| **Rate limiting** | Throttle requests per client | ✓ |
| **Caching layer** | Cache `list_models`, `list_posts` with TTL | ✓ |
| **Structured error codes** | Machine-readable error codes + resolution hints | ✓ |
| **Security hardening review** | Export isolation, fail-closed ability permissions, structured settings sanitization, lifecycle hook registration | ✓ |
| **Health monitoring** | Endpoint with version, error rate, latency stats | ✓ |
| **Configuration profiles** | `--profile=high-volume`, `--profile=strict` | Skipped |

**Exit criteria:** Caching reduces REST calls by 60%+, audit log operational, health endpoint available, v2.0 release ✓. SSE, multi-site, role mapping, and configuration profiles are skipped for this track.

---

### Phase 4: Ecosystem & Distribution (v2.0+)

**Theme:** WordPress-native MCP/Abilities distribution.

| Item | Target |
|------|--------|
| **Composer package** (`saltus/mcp-server`) | Skipped with standalone server path |
| **PHAR distribution** | Skipped with standalone server path |
| **Docker image** | Skipped with standalone server path |
| **GitHub Action** | Skipped with standalone server path |
| **VS Code extension** | Future WordPress-native MCP client integration |
| **Documentation site** | VitePress site live at `docs.saltus.dev`, phpDocumentor API docs, GitHub Actions auto-deploy, MCP docs integrated |
| **MCP Registry listing** | Reassess for WordPress-native abilities |
| **Support & SLA model** | Paid support contracts, custom tool development |

**Exit criteria:** WordPress-native MCP docs published; standalone server packaging remains skipped.

---

### Phase 5: Block Editor, WP-CLI, Frontend & Docs (v2.1+)

**Theme:** Expand the framework beyond admin-only — block editor integration, CLI tooling, frontend rendering, and complete documentation.

**Design constraints:**
- Plugin scaffolding is out of scope — keep framework lean
- PHP 7.4 minimum is required by WordPress and unchanged
- New features follow existing patterns: `Service`, `Conditional`, `Assembly`, `Processable`, `RestRouteProvider`, `ToolContributor`
- Shared service classes reused across REST, MCP, and WP-CLI paths

---

#### 5A — Block Editor Integration (runtime metadata tied to models)

**Goal:** Auto-register Gutenberg blocks from CPT model config, using the model's meta fields as block attributes. One named block per CPT (e.g., `saltus/movie-list`, `saltus/book-list`).

**Config shape:**
```yaml
blocks: true
# or
blocks:
  list: true    # saltus/{cpt_name}-list
  single: true  # saltus/{cpt_name}-single
```

**Files:**

| File | Purpose |
|------|---------|
| `src/Features/Blocks/Blocks.php` | Service class (Service, Conditional, Assembly, ToolContributor) |
| `src/Features/Blocks/SaltusBlocks.php` | Processable — iterates models, register_block_type() per CPT |
| `src/Features/Blocks/BlockRenderer.php` | Shared render_callback for list and single blocks |
| `templates/blocks/list.php` | Default list block template |
| `templates/blocks/single.php` | Default single block template |
| `assets/Feature/Blocks/editor.js` | Editor script (InspectorControls) |
| `assets/Feature/Blocks/style.css` | Block styles |

**How it wires in:**
- `Core::get_service_classes()` adds `'blocks' => Blocks::class`
- `Blocks` reads all post type models from `Modeler` and registers enabled definitions on `init`
- Each call to `register_block_type()` uses a metadata array (no static block.json needed)
- Meta fields from model `meta` config auto-mapped as block attributes via `MetaFieldProvider`
- Dedicated MCP tool `list_block_models` contributed by `Blocks::get_mcp_tools()`
- Filter: `saltus/framework/blocks/attributes` to customize auto-generated attributes

| Item | Status |
|------|--------|
| Blocks feature service + SaltusBlocks implementation | ✓ Done |
| BlockRenderer with default render callbacks | ✓ Done |
| Default list/single block templates | ✓ Done |
| Editor script and styles | ✓ Done |
| MCP tool for block model discovery | ✓ Done |
| PHPUnit tests for block registration | ✓ Done |
| Integration with existing ModelRestPolicy | ✓ Done |

**Exit criteria:** `saltus/{cpt_name}-list` and `saltus/{cpt_name}-single` blocks are registered for every CPT with `blocks: true`. Block attributes reflect the model's meta field config. List block queries and renders posts; single block renders a post with all meta.

---

#### 5B — WP-CLI Tools

**Goal:** `wp saltus <command>` mapping every MCP tool to a WP-CLI command, using the same shared service classes.

**Command tree (8 grouped command classes):**

| Command | MCP Tool | Shared Service |
|---------|----------|----------------|
| `wp saltus health` | health | `GetHealth` |
| `wp saltus model list [--type]` | `list_models` | `Modeler::get_models()` |
| `wp saltus model get <slug>` | `get_model` | `Modeler::get_models()` |
| `wp saltus post list <post_type> [--status] [--search] ...` | `list_posts` | `WP_Query` |
| `wp saltus post get <id>` | `get_post` | `get_post()` |
| `wp saltus post create <post_type> <title> [--content] ...` | `create_post` | `wp_insert_post()` |
| `wp saltus post update <id> [--title] ...` | `update_post` | `wp_update_post()` |
| `wp saltus post delete <id> [--force]` | `delete_post` | `wp_delete_post()` |
| `wp saltus post duplicate <id>` | `duplicate_post` | `SaltusDuplicate` |
| `wp saltus post export <id>` | `export_post` | `SaltusSingleExport` |
| `wp saltus term list <taxonomy> [--search] ...` | `list_terms` | `get_terms()` |
| `wp saltus term create <taxonomy> <name> [--slug] ...` | `create_term` | `wp_insert_term()` |
| `wp saltus settings get <post_type>` | `get_settings` | `SettingsManager` |
| `wp saltus settings update <post_type> <json>` | `update_settings` | `SettingsManager` |
| `wp saltus reorder <json>` | `reorder_posts` | `ReorderPostsService` |
| `wp saltus meta list [<post_type>]` | `list_meta_fields` / `get_meta_fields` | `MetaFieldProvider` |
| `wp saltus meta update <post_type> <post_id> <json>` | `update_meta_fields` | `MetaFieldProvider` |
| `wp saltus block list` | `list_block_models` | `SaltusBlocks` |

**Files:**

| File | Purpose |
|------|---------|
| `src/Features/WpCli/WpCli.php` | Service class — hooks `WP_CLI::add_command()` on `cli_init` |
| `src/Features/WpCli/Commands/SaltusCommand.php` | `wp saltus health` — health summary; parent of the command tree, so it defines no `__invoke()` |
| `src/Features/WpCli/Commands/ModelCommand.php` | `wp saltus model {list\|get}` |
| `src/Features/WpCli/Commands/PostCommand.php` | `wp saltus post {list\|get\|create\|update\|delete\|duplicate\|export}` |
| `src/Features/WpCli/Commands/TermCommand.php` | `wp saltus term {list\|create}` |
| `src/Features/WpCli/Commands/SettingsCommand.php` | `wp saltus settings {get\|update}` |
| `src/Features/WpCli/Commands/MetaCommand.php` | `wp saltus meta {list\|get}` |
| `src/Features/WpCli/Commands/ReorderCommand.php` | `wp saltus reorder` |
| `src/Features/WpCli/Commands/BlockCommand.php` | `wp saltus block list` |

**Output formatting:** `--format=table|json|yaml` flag on all list/detail commands. Table is default for interactive, JSON for scripting.

**Generated docs:** `composer docs:wpcli` auto-generates command reference from command classes (parallel to `bin/generate-mcp-docs.php`).

| Item | Status |
|------|--------|
| WpCli feature service | ✓ Done |
| SaltusCommand (health + help) | ✓ Done |
| ModelCommand (list, get) | ✓ Done |
| PostCommand (list, get, create, update, delete, duplicate, export) | ✓ Done |
| TermCommand (list, create) | ✓ Done |
| SettingsCommand (get, update) | ✓ Done |
| MetaCommand (list, get, update) | ✓ Done |
| ReorderCommand | ✓ Done |
| BlockCommand | ✓ Done |
| `composer docs:wpcli` script | ✓ Done |
| PHPUnit tests with WP_CLI stubs | ✓ Done |

**Exit criteria:** Every MCP tool has a corresponding `wp saltus` subcommand. Commands use shared service classes (not REST dispatch). Output formatting supports `--format=table|json|yaml`. Test suite covers all command groups.

---

#### 5C — Frontend Rendering

**Goal:** Shortcodes and rendering templates for CPT content on the frontend, with meta field exposure. Default templates ship with the framework; plugins can override.

**Config shape:**
```yaml
frontend:
  shortcode: true            # auto-register [saltus_cpt type="movie"]
  shortcode_alias: 'movies'  # optional: [movies]
  templates:
    list: 'plugin/templates/list.php'
    single: 'plugin/templates/single.php'
```

**Shortcode API:**
```
[saltus_cpt type="movie" view="list" limit="10" orderby="date" order="desc" taxonomy="genre" terms="action,comedy"]
[saltus_cpt type="movie" view="single" id="123"]
```

**Template variables:**

| Template | Variables |
|----------|-----------|
| `list` | `$posts` (array of `WP_Post`), `$model` (PostType instance), `$attributes` (shortcode args) |
| `single` | `$post` (WP_Post), `$meta` (assoc array of all registered meta fields), `$model`, `$attributes` |

**Default templates:** Shipped in `templates/` — list renders a styled post table with title, excerpt, date, and all meta fields; single renders the full post with meta grouped into sections.

**Files:**

| File | Purpose |
|------|---------|
| `src/Features/Frontend/Frontend.php` | Service class (Service, Conditional, Assembly) |
| `src/Features/Frontend/SaltusFrontend.php` | Processable — registers shortcodes on `init` |
| `templates/list.php` | Default list template |
| `templates/single.php` | Default single template |

**How it wires in:** `Core::get_service_classes()` adds `'frontend' => Frontend::class`. `ModelFactory::process_services()` reads `config['frontend']` → `Frontend::make()` → `SaltusFrontend::process()` calls `add_shortcode()` per CPT.

| Item | Status |
|------|--------|
| Frontend feature service | ✓ Done |
| SaltusFrontend shortcode registration | ✓ Done |
| Default list template | ✓ Done |
| Default single template | ✓ Done |
| Shortcode attribute parsing (limit, orderby, taxonomy, terms, etc.) | ✓ Done |
| Template override resolution (config → theme → default) | ✓ Done |
| PHPUnit tests for shortcode rendering | ✓ Done |

**Exit criteria:** `[saltus_cpt type="movie"]` renders a styled list of posts. `[saltus_cpt type="movie" view="single" id="123"]` renders a single post with meta. Templates are overridable per model. Output is escaped and safe.

---

#### 5D — Documentation

**Goal:** Fill all README placeholders, add docs for new features, provide complete examples.

**README.md changes:**

| Section | Current State | Target |
|---------|---------------|--------|
| `features` parameter table | Complete | Table of all 7 features with config keys and one-liners |
| `labels` parameter table | Complete | Full reference: `has_one`, `has_many`, `text_domain`, `featured_image`, and overrides |
| `meta` parameter table | Complete | Metabox structure: sections, fields, Codestar field types, REST API registration |
| `settings` parameter table | Complete | Settings page structure: page args, sections, fields, parent menu, tabs |
| CPT example file | Complete | Complete PHP model with all common parameters |
| Taxonomy example file | Complete | Complete PHP taxonomy model with associations |

**New doc files:**

| File | Content |
|------|---------|
| `docs/guides/blocks.md` | Block config reference, template customization, attributes guide, editor integration |
| `docs/guides/wp-cli.md` | Full command reference with examples, auto-generated by `composer docs:wpcli` |
| `docs/guides/frontend.md` | Shortcode API, template variables, and customization guide |
| `docs/guides/features.md` | Deep feature and model reference |

**Auto-generation:** `composer docs:wpcli` script in `bin/generate-wpcli-docs.php` to generate WP-CLI command tables (parallel to `bin/generate-mcp-docs.php`).

**Docs site infrastructure (new):** VitePress static site at `docs.saltus.dev` + phpDocumentor API docs + GitHub Actions auto-deploy.

| Item | Status |
|------|--------|
| VitePress site config + landing page | ✓ Done |
| phpDocumentor config (phpdoc.dist.xml) | ✓ Done |
| @api annotations on 84 public classes/interfaces | ✓ Done |
| GitHub Actions workflow (build + deploy to Pages) | ✓ Done |
| Docs content: getting-started, architecture, build, features, MCP | ✓ Done |
| `composer docs:all` script (MCP + WP-CLI + API) | ✓ Done |
| README features table | ✓ Done |
| README labels reference | ✓ Done |
| README meta structure | ✓ Done |
| README settings structure | ✓ Done |
| README CPT example | ✓ Done |
| README taxonomy example | ✓ Done |
| docs/guides/blocks.md | ✓ Done |
| docs/guides/wp-cli.md (auto-generated) | ✓ Done |
| docs/guides/frontend.md | ✓ Done |
| docs/guides/features.md | ✓ Done |
| bin/generate-wpcli-docs.php | ✓ Done |

**Exit criteria:** README placeholders filled, docs.saltus.dev backed by VitePress + API docs and GitHub Actions deployment, Blocks and Features guides published, and WP-CLI docs auto-generated. Frontend documentation ships with 5C.

---

*Plugin Generator moved to its own repository — see the [framework-demo repository](https://github.com/SaltusDev/framework-demo).*

## Framework Core Roadmap

### Short-term Goals
- ✓ Address remaining PHPStan errors (2 pre-existing in ResourceProvider) — resolved 2026-07-01.
- ✓ Code-review hardening pass — export isolation, lifecycle hook file registration, fail-closed MCP permissions, structured settings sanitization, JSON fallback, and AssetLoader PHPStan coverage resolved 2026-07-02.
- ✓ Service extraction — inline REST controller logic (WXR export, meta field normalization, post reorder, settings CRUD) moved into dedicated shared service classes and wired into both REST controllers and MCP tools; defensive guards for null post, private property access, taxonomy object, and asset data types — resolved 2026-07-03.
- Continue maintaining automated testing suites (1293 tests, 3360 assertions as of 2026-08-19).
- WordPress-native MCP/Abilities integration shipped in v2.0.0.
- ✓ **Phase 5 implementation** — Block Editor integration, WP-CLI tools, Frontend rendering, and documentation completion — delivered 2026-07-31.
- ✓ **Phase 6C AI client generation** — unhandled assistant actions generate through the WordPress AI Client; `saltus/framework/ai/prompt_builder` filter; AI availability reported in health + `wp saltus` — delivered 2026-08-07.
- Reconcile version numbering across `package.json` (now 2.1.1), `docs/ROADMAP.md`, `CHANGELOG.md`, and the `v1.4.2`/`v2.0.0` tags.
- ✓ **Phase 8B implementation** — admin WebMCP surface and proposal-queue-governed writes: `AdminScreen`/`AdminToolSet` per-screen scoping, `AdminTool` decorating existing abilities with the review-queue write posture, nonce-authenticated execute + refresh route, `saltus-webmcp-toolchange` re-registration in the bridge, `wp saltus webmcp manifest|validate`, health/`wp saltus health` WebMCP stats, and the declarative forms no-go evaluation — delivered 2026-08-08.
- ✓ **Phase 8 scope defined** — WebMCP browser surface: frontend read-only tools in 8A, admin surface and proposal-queue-governed writes in 8B — scoped 2026-08-07.
- ✓ **Phase 8A implementation** — `WebMcp` feature service, `WebMcpPolicy` gating, `ManifestBuilder` projection from the existing tool registry, five public read tools (`search_content`, `get_content`, `list_content_models`, `list_taxonomy_terms`, `filter_content`), `PublicFieldFilter`, `WebMcpController` manifest/execute routes, and the `bridge.js` single-point namespace probe — delivered 2026-08-07.
- ✓ **Phase 8A hardening and docs** — per-client rate limiting via `ClientIdentity`, `ResultBudget` output clamping, `bridge.js` test coverage, and the generated `docs/guides/webmcp.md` reference — delivered 2026-08-08.
- ✓ **Phase 8B implementation** — admin surface and proposal-queue-governed writes: `WebMcpTool` contract, `AdminTool` ability projection, `AdminScreen`/`AdminToolSet` per-screen scoping, `/webmcp/nonce` route with silent bridge refresh-and-retry, `saltus-webmcp-toolchange` re-registration, WebMCP state in health output, and `wp saltus webmcp manifest|validate` — delivered 2026-08-08.
- ✓ **Declarative forms evaluation** — no-go for 8B; Codestar emits `<h4>` titles, no input `id`, and no ARIA across 45 field types, so a derived schema would carry no property descriptions. Four accessibility defects documented for separate scoped work — evaluated 2026-08-08.
- ✓ **Bug-fix pass 1.8.3** — shortcode alias bound to its own model (bare `[books]` renders), `__invoke()` removed from `wp saltus` parent commands so subcommands register, audit table created before reads, WebMCP manifest includes admin models via `enabled_models()`, and `ResultBudget` guarantees fit at the pass bound — delivered 2026-08-10.
- ✓ **Maintenance pass 1.8.4** — `AuditLogger::ensure_table()` returns whether the DDL succeeded, so a failed create no longer marks the table verified and hides a missing audit table for the full TTL — delivered 2026-08-11.
- ✓ **Phases 11–14 scoped** — Security & Compliance (field-level permissions, per-field encryption, GDPR hooks), Developer Experience (config-time validation), Enhanced UX (relationship picker plus the four documented Codestar accessibility defects), Observability (audit rollups and a metrics surface) — scoped 2026-08-11. The phase-numbering collision with the retired bug-fix buckets is resolved and recorded.
- ✓ **Phase 14 implementation** — Observability: `RollupStore`/`DailyRollup` daily audit rollups with a portable 1.2.0 schema migration and an atomic upsert, the `Observability` feature (metrics dashboard plus `MetricsApi`), `wp saltus metrics`, rollup-freshness reporting on the health endpoint, audit sampling, and slow-call logging — delivered 2026-08-16, released in v2.1.1 on 2026-08-19.
- ✓ **Per-relationship capability enforcement** — `RelationshipPermissionPolicy` resolves a relationship's declared `capabilities` at the `RelationshipManager` choke-point, so REST, MCP, WP-CLI, and the metabox cannot disagree; reciprocals inherit the declaring side's rules, cascade cleanup is exempt, and write-denied pickers render read-only — delivered 2026-08-14, released in v2.1.1.
- Next code work: the [Phase 16](#phase-16--v210-review--09) review backlog, approved 2026-08-17 as the next work, then the 25 open [Phase 17](#phase-17--v211-review--227) findings. The remaining Phase 10A follow-ups — the query-builder facade, then the ACF/Toolset/Pods migration scripts — are still open; the metabox picker and the Codestar accessibility fixes shipped with [Phase 13](#phase-13-enhanced-ux-v29).

### Long-term Vision
- Continued improvements for WordPress CPT-based plugin development.
- Further refine the Codestar Framework integration.
- Establish WordPress-native MCP/Abilities as the standard AI interface for WordPress CPT plugins.
- Complete frontend-to-admin coverage: blocks, CLI, shortcodes, and documentation for every framework feature.

## Tracking
- Check GitHub Issues for active sprint items.
- Active development on `feature/mcp-v1` branch.



### Phase 6: AI Governance & Editorial Review (v2.2+)

**Theme:** Add a first-class AI governance layer — context control, editorial review queues, and inside-admin AI assistants — on top of the existing MCP/Abilities foundation.

Saltus already has model-defined CPTs, REST routes, MCP/Abilities tools, capability checks, audit logging, rate limits, health checks, and per-model `mcp_tools`/`show_in_mcp` gates. Phase 6 adds the higher-level product layer.

---

#### 6A — Context Control Center

**Goal:** A Saltus model config area where a plugin defines AI governance rules that MCP tools receive before executing.

**Config shape as planned** (shipped without the `config:` wrapper — `ai_context` is a top-level model key; see [AI Context Guide](guides/ai-context.md)):
```yaml
config:
  ai_context:
    brand_voice: 'Clear, practical, expert, no hype.'
    audiences: ['developers', 'site editors']
    field_rules:
      post_content:
        - 'Maintain technical accuracy'
        - 'Never include affiliate links'
    allowed_statuses: ['draft', 'pending']
    forbidden_actions: ['delete', 'publish']
    require_human_review: true
```

| Item | Status |
|------|--------|
| `ai_context` config schema definition and validation | ✓ Done |
| `AiContextProvider` service — parses and serves ai_context per model | ✓ Done |
| MCP tool `get_context` — exposes ai_context to external agents | ✓ Done |
| Context injection into mutating MCP tools (create/update/delete) | ✓ Done |
| Filter: `saltus/framework/ai_context/defaults` | ✓ Done |
| PHPUnit tests for context validation and injection | ✓ Done |

**Exit criteria:** Models with `config.ai_context` expose a `saltus/get-context` MCP tool. Mutating MCP tools receive context rules and can reject operations that violate them.

---

#### 6B — Editorial Review Queue

**Theme:** Agent-proposed changes go through a human approval workflow instead of publishing directly.

**Flow:**
```
AI write -> draft/pending/revision -> human approval -> publish
```

| Item | Status |
|------|--------|
| `ProposalService` (AI change proposals) — stores agent writes as pending change records | ✓ Done |
| `EditorialReviewController` — REST endpoints for listing/reviewing/approving/rejecting proposals | ✓ Done |
| Review dashboard UI (admin screen with diff view) | ✓ Done |
| Audit log integration — full chain from proposal to approval/rejection | ✓ Done |
| Default all mutating MCP tools to draft/pending (configurable) | ✓ Done |
| PHPUnit tests for proposal lifecycle | ✓ Done |

**Exit criteria:** Mutating MCP tools create pending change records by default. A review admin screen lists proposals with diff view. Approved proposals are published; rejected ones are discarded. Audit log records the full chain.

---

#### 6C — Inside-Admin AI Assistants

**Theme:** AI operates from inside WordPress admin — buttons beside metabox fields, inline suggestions, and validation.

| Item | Status |
|------|--------|
| `AiAssistantProvider` service — registers meta box assistants per model | ✓ Done |
| Admin JS entry point (`assets/Feature/AiAssistant/editor.js`) | ✓ Done |
| Assistant actions: improve title, summarize, generate excerpt, suggest terms | ✓ Done |
| Brand rule validation button for post content | ✓ Done |
| REST endpoints for assistant actions (reuse existing permission checks) | ✓ Done |
| Filter: `saltus/framework/ai/assistant_actions` | ✓ Done |
| Built-in generation through the WordPress AI Client (`AiClient` + `ActionPrompts`) | ✓ Done |
| Filter: `saltus/framework/ai/prompt_builder` (pin provider/model on the builder) | ✓ Done |
| Health + `wp saltus` report AI client availability | ✓ Done |
| PHPUnit tests for assistant REST endpoints | ✓ Done |

**Exit criteria:** Models with `config.ai_context` show AI assistant buttons in the admin. Clicking "Improve title" or "Summarize" calls a REST endpoint and updates the field. Brand rule validation highlights content that violates configured rules. Unhandled actions generate through the WordPress AI Client with provider credentials from Settings > Connectors. ✓ Done 2026-08-07

---

**Exit criteria (Phase 6 overall):** AI governance is configurable per model via `ai_context`. Mutating MCP tools respect context rules and default to review-queue creation. Inside-admin assistants are operational for configured models. All features are tested. ✓ Done 2026-08-06

---

### Phase 7: Advanced Dependency Injection & Container Hardening (v2.3+)

**Theme:** Upgrade the framework's dependency injection container to support reflection-based parameter resolution (autowiring) for third-party services, avoiding standard constructor mapping errors.

| Item | Status |
|------|--------|
| `ReflectionInstantiator` class implementing `Instantiator` | ✓ Done |
| Positional constructor parameter resolution and dependency matching | ✓ Done |
| Constructor parameter default value fallbacks | ✓ Done |
| Clean validation and exception flow for unresolved parameters | ✓ Done |
| Remove requirement for `Assembly::make` boilerplate on custom services | ✓ Done |
| Container autowiring unit tests (`tests/Unit/Infrastructure/Container/`) | ✓ Done |
| Developer documentation update for custom service constructors | ✓ Done |

**Exit criteria:** Developers can register custom services in the container with standard typed/positional constructor arguments. The container uses PHP Reflection to map parameter names to container keys, falling back to default arguments or throwing descriptive runtime exceptions when dependencies cannot be resolved. ✓ Done 2026-08-06

---

### Phase 8: WebMCP Browser Surface (v2.4+)

**Theme:** Project the existing Saltus tool registry into the visitor's browser as WebMCP tools, so an in-browser AI agent can call typed functions instead of scraping model-rendered markup.

Research and rationale are recorded internally: the standards status, the Cloudflare and Shopify implementation patterns this phase borrows from, and the adoption data that bounds the scope.

**Premise:** WebMCP is a *third consumer* of tool definitions Saltus already owns, alongside WordPress-native MCP/Abilities and WP-CLI. The 20 abilities already resolve to 17 REST routes through `RestBackedToolInterface`. This phase adds a browser-side projection of the same definitions plus a small public-safe read tool set — it does not add a parallel tool system.

**Design constraints:**
- **Degrade to nothing.** Chrome ships no earlier than 157; Safari and Firefox have registered no position. Absence of `document.modelContext` is the common case and must be a silent no-op, not a console error.
- **One namespace probe.** `document.modelContext` first, `navigator.modelContext` as deprecated fallback, in exactly one place in the bridge. Chrome 149 exposes only the latter; 150+ warns on it.
- **The browser is an untrusted client.** Every WebMCP invocation re-validates server-side through the existing `src/MCP/Validation` path and the same capability gates as an ability call. `readOnlyHint` is a hint to the agent, never an enforcement mechanism.
- **No parallel execution path.** Tools dispatch through the existing shared service classes (`MetaFieldProvider`, `SettingsManager`, `ReorderPostsService`, `SaltusSingleExport`) exactly as REST controllers and `wp saltus` commands do.
- **Follow existing patterns:** `Service`, `Conditional`, `Registerable`, `RestRouteProvider`, `ToolContributor`, `AssetLoadingService`.
- **Opt-in per model.** Default off. No Saltus site gains a public agent surface without explicit config.

**Config shape (planned):**
```yaml
webmcp:
  enabled: true
  frontend: true          # register tools on public model views
  admin: false            # 8B — register tools on wp-admin screens
  tools:                  # optional allowlist; omit for all public-safe tools
    - search_content
    - get_content
    - list_taxonomy_terms
```

---

#### 8A — Frontend Read-Only Tool Surface

**Goal:** A visitor's in-browser agent can discover and call read tools for published content on any Saltus model with `webmcp.frontend: true`. No writes, no authenticated data, no admin exposure.

**Why frontend first:** admin agents are already served by MCP/Abilities and WP-CLI. Public visitor-agent access to model content is the net-new capability, and it is the surface with no authentication story to invent.

**Why a new tool set is required:** all 20 existing abilities gate on `edit_posts` or narrower, so projecting them verbatim yields an empty list for an anonymous visitor. 8A ships both the projection mechanism and public-safe read tools scoped to published content.

**New public read tools:**

| Tool | Returns | Backed by |
|------|---------|-----------|
| `search_content` | Published posts across WebMCP-enabled post type models, with model, title, excerpt, permalink | `WP_Query`, published + public post status only |
| `get_content` | One published post with public meta fields resolved | `get_post()` + `MetaFieldProvider` filtered to public fields |
| `list_content_models` | WebMCP-enabled models with labels and available taxonomies | `Modeler`, filtered by `WebMcpPolicy` |
| `list_taxonomy_terms` | Terms for a model's public taxonomies, with counts | `get_terms()`, public taxonomies only |
| `filter_content` | Published posts filtered by taxonomy term, plus page navigation | `WP_Query` + archive permalink |

Each carries `readOnlyHint: true` and `untrustedContentHint: true` — post content is user-generated and must be labelled as such. Descriptions stay inside the agent character budgets recorded in the discovery doc (500 tool description, 150 per parameter, 30 per name, 1.5K output).

**Files:**

| File | Purpose |
|------|---------|
Paths below record what shipped. Two differ from the original plan: the separate `SaltusWebMcp`
processable was folded into `WebMcp` (the bridge enqueue was the only work it would have owned), and
the public tools live under `src/WebMcp/Tools/` beside the rest of the WebMCP projection rather than
under `src/MCP/Tools/Public/`.

| File | Purpose |
|------|---------|
| `src/Features/WebMcp/WebMcp.php` | Service class (Service, Conditional, Registerable, RestRouteProvider); also enqueues the bridge and localizes the manifest |
| `src/Features/WebMcp/WebMcpPolicy.php` | Per-model gating: `webmcp.enabled`, `webmcp.frontend`, tool allowlist |
| `src/Features/WebMcp/PublicFieldFilter.php` | Resolves which meta fields are safe to expose publicly |
| `src/WebMcp/ManifestBuilder.php` | Serializes `ToolInterface` definitions into WebMCP descriptors |
| `src/WebMcp/ToolDescriptor.php` | Value object — name, description, `inputSchema`, annotations |
| `src/WebMcp/WebMcpAnnotated.php` | Contract for tools declaring their own agent annotations |
| `src/WebMcp/ClientIdentity.php` | Resolves the calling client for per-client rate limiting and audit |
| `src/WebMcp/ResultBudget.php` | Clamps a serialized result to the agent output budget |
| `src/WebMcp/Tools/PublicTool.php` | Base class — published-only queries, public field filtering, read-only annotations |
| `src/WebMcp/Tools/SearchContent.php` | `search_content` |
| `src/WebMcp/Tools/GetContent.php` | `get_content` |
| `src/WebMcp/Tools/ListContentModels.php` | `list_content_models` |
| `src/WebMcp/Tools/ListTaxonomyTerms.php` | `list_taxonomy_terms` |
| `src/WebMcp/Tools/FilterContent.php` | `filter_content` |
| `src/Rest/WebMcpController.php` | `GET /webmcp/manifest`, `POST /webmcp/execute` |
| `assets/Feature/WebMcp/bridge.js` | Namespace probe, tool registration, same-origin fetch proxy |
| `bin/generate-webmcp-docs.php` | Generates the tool reference in `docs/guides/webmcp.md` |

**REST routes (namespace `saltus-framework/v1/`):**

| Route | Method | Purpose | Permission |
|-------|--------|---------|------------|
| `/webmcp/manifest` | GET | Tool descriptors for the current request context | Public when a model enables frontend WebMCP; otherwise 404 |
| `/webmcp/execute` | POST | Invoke one tool by name with validated args | Public for read tools; per-tool capability check |

**The bridge (mirrors Cloudflare's Site MCP Server pack):**
1. Probe `document.modelContext`, then `navigator.modelContext`. Neither present → return immediately, no console output.
2. Read the localized manifest (no network round trip needed for discovery).
3. `registerTool()` per descriptor, each `execute` posting to `/webmcp/execute` with `credentials: 'same-origin'`.
4. Hold one `AbortController` so tools unregister on teardown.

**How it wires in:**
- `Core::get_service_classes()` adds `'webmcp' => WebMcp::class`
- `WebMcp::is_needed()` returns `! is_admin()` for 8A
- `WebMcpPolicy` resolves enabled models from the top-level `webmcp` model key, consistent with how `ai_context` is read (not nested under `config`)
- `ManifestBuilder` consumes `ToolInterface::get_parameters()` and wraps it in a JSON Schema `object` with `properties`/`required` — our tools return bare property maps today, so the wrapping happens in one place
- Filter `saltus/framework/webmcp/tools` to add or remove descriptors
- Filter `saltus/framework/webmcp/public_fields` to control which meta fields a public tool may return
- Filter `saltus/framework/webmcp/manifest` for the final descriptor list

| Item | Status |
|------|--------|
| `WebMcp` feature service (absorbed the planned `SaltusWebMcp` processable) | ✓ Done |
| `WebMcpPolicy` per-model gating with tool allowlist | ✓ Done |
| `ManifestBuilder` + `ToolDescriptor` projection from `ToolInterface` | ✓ Done |
| JSON Schema wrapping of `get_parameters()` output | ✓ Done |
| Five public read tools with `readOnlyHint` / `untrustedContentHint` | ✓ Done |
| `PublicFieldFilter` — public meta field resolution | ✓ Done |
| `WebMcpController` manifest + execute routes | ✓ Done |
| Server-side arg re-validation through `src/MCP/Validation` | ✓ Done |
| `bridge.js` with single-point namespace probe and no-op fallback | ✓ Done |
| Page-scoped tool registration (archive vs single vs taxonomy) | ✓ Done |
| Audit logging for WebMCP invocations, distinguished from ability calls | ✓ Done |
| Rate limiting on `/webmcp/execute`, per client via `ClientIdentity` | ✓ Done 2026-08-08 |
| Character budgets on names, descriptions, and serialized output | ✓ Done 2026-08-08 |
| PHPUnit coverage: policy, manifest, each tool, execute permissions | ✓ Done |
| `bridge.js` coverage including the absent-API no-op (`npm test`) | ✓ Done 2026-08-08 |
| PHPStan Level 7 clean across `src/Features/WebMcp/` and `src/WebMcp/` | ✓ Done |
| `docs/guides/webmcp.md` + `composer docs:webmcp` generated tool reference | ✓ Done 2026-08-08 |
| Cache-safe discovery (in the page body, not `send_headers` only) | ✓ Done — the manifest is localized into the page HTML, so it survives full-page caching |
| Asset registration through `AssetLoadingService` | ○ Enqueued directly on `wp_enqueue_scripts`, matching how `AiAssistant` loads its editor script |

**Explicitly out of scope for 8A:** write tools, admin-screen registration, cross-origin `exposedTo` delegation, the declarative forms API, and any authenticated-data tool.

**Exit criteria:** A model with `webmcp: { enabled: true, frontend: true }` registers its read tools on public views in a WebMCP-capable browser. `search_content` and `get_content` return only published, publicly-visible data with public meta fields. Browsers without the API register nothing and log nothing. Every invocation re-validates args server-side, is rate-limited per client, and is audit-logged. Results are clamped to the agent output budget. Tools are page-scoped, not a single uniform set. Full suite green, PHPStan Level 7 clean. ✓ Done 2026-08-08

---

#### 8B — Admin Surface and Governed Writes

**Theme:** Extend registration to wp-admin screens, and let mutating WebMCP calls flow through the Phase 6B editorial review queue.

**Why this ordering:** WebMCP has no settled confirmation or elicitation model — `requestUserInteraction()` is in the spec draft but unresolved, and authentication at the WebMCP layer is undefined. Saltus already answered this for MCP/Abilities: `ProposalService::should_queue()` converts a mutating tool call into a `pending` proposal awaiting human approval. Routing WebMCP writes down that same path makes the spec's gap a framework feature rather than something we invent around.

**Write posture:** no WebMCP write tool ever mutates directly. Every one creates a proposal, returns the proposal id and a review URL to the agent, and waits. This mirrors Shopify shipping zero money-moving tools while still exposing page-steering writes.

**Files:**

| File | Purpose |
|------|---------|
| `src/WebMcp/WebMcpTool.php` | Contract adding surface, authentication, and discovery-capability to `ToolInterface` |
| `src/WebMcp/Tools/AdminTool.php` | Decorator projecting an existing ability onto the admin surface, queueing its writes |
| `src/Features/WebMcp/AdminScreen.php` | Resolves the current admin screen and its post type context |
| `src/Features/WebMcp/AdminToolSet.php` | Maps a screen to the tool names meaningful on it |
| `src/Features/WpCli/Commands/WebMcpCommand.php` | `wp saltus webmcp manifest\|validate` |

| Item | Status |
|------|--------|
| `WebMcp::is_needed()` extended to admin screens with `webmcp.admin: true` | ✓ Done 2026-08-08 |
| Admin-context tool projection of existing capability-gated abilities | ✓ Done 2026-08-08 |
| Write tools route through `ProposalService::should_queue()` — never direct mutation | ✓ Done 2026-08-08 |
| Proposal id + review URL returned in the tool result | ✓ Done 2026-08-08 |
| Nonce handling for authenticated invocations, refreshable without a page reload | ✓ Done 2026-08-08 |
| Per-screen tool scoping (post editor, settings page, review queue) | ✓ Done 2026-08-08 |
| Declarative forms API evaluation for Codestar metabox and settings markup | ✓ Done 2026-08-08 — **no-go** |
| Accessibility pass on metabox/settings labels feeding declarative schema derivation | ✓ Audited 2026-08-08 — four defects documented; the fix is vendored-code work deliberately left outside this phase |
| `toolchange` emission when model state alters the available tool set | ✓ Done 2026-08-08 |
| Health output reports WebMCP registration state and enabled model count | ✓ Done 2026-08-08 |
| `wp saltus webmcp {manifest\|validate}` for offline manifest inspection | ✓ Done 2026-08-08 |
| PHPUnit coverage: admin gating, proposal creation from WebMCP writes, nonce failure paths | ✓ Done 2026-08-08 |

**Design notes from implementation:**
- `is_needed()` returns `true` unconditionally rather than branching on `has_admin_surface()`. Models are not registered when the service container evaluates the gate, so the policy would report no surface on every site. The real check happens at enqueue time, when the modeler is populated.
- Discovery capability is separate from `has_permission()`. The latter answers "may this call proceed with these arguments" and usually needs a target id the manifest cannot supply, so listing a tool would otherwise depend on inventing arguments for it.
- `ProposalService::is_mutating()` was extracted from `should_queue()`. The two answer different questions — one is a fact about the tool, the other a site's policy choice — and a `readOnlyHint` must not flip because a site disabled review.
- A mutating tool with no review queue returns `503`, never a direct write. A missing dependency must not silently become an unreviewed change.
- The bridge retries a stale nonce exactly once, keyed on the server's `refresh` flag rather than the 403 status, since a genuine capability failure arrives as a 403 too.
- The frontend payload carries no nonce. One bound to a session an anonymous visitor does not have would imply an authentication story the public surface lacks.

**Exit criteria:** Models with `webmcp.admin: true` register capability-gated tools on their admin screens. Every mutating WebMCP call creates a `pending` proposal and returns its id and review URL — no direct writes exist. Nonce refresh works without reload. Health and `wp saltus` report WebMCP state. The declarative forms evaluation is documented with a go/no-go recommendation. ✓ Done 2026-08-08

---

**Exit criteria (Phase 8 overall):** Saltus models can expose read tools to in-browser agents on the frontend and capability-gated tools in the admin, all projected from the existing tool registry rather than hand-authored. Writes are governed by the editorial review queue. The surface is opt-in per model, degrades silently on unsupported browsers, and re-validates every argument server-side. ✓ Done 2026-08-08

**Non-goals for Phase 8:** shipping our own agent or browser extension, cross-origin tool sharing via `exposedTo`, a `/.well-known/` WebMCP manifest convention (not canonical yet), and any expectation of inbound agent traffic this cycle — no observed deployment has recorded an external agent call.

---

### Phase 10A: Content Relationships (v2.5+)

**Theme:** Relate posts to other posts from model config, with one declaration serving reads and writes from both sides across REST, MCP, and WP-CLI.

The internal planning documents were written before implementation and describe a `src/Migrations/` system and a `src/MCP/Tools/Relationships/` subdirectory that this phase deliberately did not build — see the design notes below.

**Premise:** relationships are a *fourth* surface over one storage model, not a new subsystem. A definition declared on one model resolves to a single row shared with its reciprocal, so the two sides cannot drift.

| Item | Status |
|------|--------|
| `relationships` config section parsed into `RelationshipDefinition` value objects | ✓ Done 2026-08-08 |
| Reciprocal definitions synthesized on the target model, sharing one storage key | ✓ Done 2026-08-08 |
| Four cardinalities: `has_one`, `has_many`, `belongs_to`, `many_to_many` | ✓ Done 2026-08-08 |
| Dedicated `{prefix}saltus_relationships` table with in-process fallback | ✓ Done 2026-08-08 |
| Pivot payload per relationship row, filtered to declared fields | ✓ Done 2026-08-08 |
| Eager loading: one query resolves a relationship for a whole result set | ✓ Done 2026-08-08 |
| Cardinality enforced from both directions, including through the reciprocal | ✓ Done 2026-08-08 |
| `attach` / `detach` / `sync` with ordering, capped at 200 ids per sync | ✓ Done 2026-08-08 |
| `cascade_delete` on the declaring side, sparing shared targets | ✓ Done 2026-08-08 |
| REST: 3 routes covering discovery, read, attach, sync, and detach | ✓ Done 2026-08-08 |
| MCP: `list_relationships`, `get_related`, `attach_related`, `detach_related`, `sync_related` | ✓ Done 2026-08-08 |
| Relationship writes queued for review **on the agent paths** — MCP via `AbilityRuntime`, WebMCP via `WebMcp`; REST and WP-CLI write directly, as they do for every other feature | ✓ Done 2026-08-08 |
| WP-CLI parity: `wp saltus relationship {list\|get\|attach\|detach\|sync}` | ✓ Done 2026-08-08 |
| Author guide at [guides/relationships.md](guides/relationships.md) | ✓ Done 2026-08-08 |
| PHPUnit coverage across registry, store, manager, REST, tools, CLI, and governance | ✓ Done 2026-08-08 |

**Design notes from implementation:**
- **No migration system was added.** The planning docs specified `src/Migrations/` with `up()`/`down()` classes; the repo already creates tables lazily via `ensure_table()` in `ProposalStore` and `AuditLogger`. `RelationshipStore` follows that existing pattern rather than introducing a second, parallel schema mechanism for one table.
- **Tools live in the flat `src/MCP/Tools/`** alongside the other 20, not the nested `Relationships/` subdirectory the docs proposed. `WpCliFeatureTest` enumerates that directory to enforce MCP↔CLI parity, and the flat layout keeps every tool subject to that check.
- **One row, two directions.** The reciprocal flips `own_column()`/`related_column()` instead of writing a second row. A shared storage key is derived by sorting both endpoint names, so it is identical regardless of model load order, and pairs the relationship names too so two relationships between the same models cannot collide.
- **Cardinality is checked on both sides.** A `has_one` declared on one model is enforced when the write arrives through the reciprocal, otherwise the far end becomes a hole in the constraint.
- **Cascade does not inherit.** The reciprocal is built with `cascade_delete: false` unconditionally: inheriting it would delete the declaring posts when a target is removed, inverting the author's intent.
- **`sync` validates before it clears.** Every target is checked first, so a rejected call leaves the existing set intact instead of half-written. Re-attaching an existing pair updates it, and a reorder preserves stored pivot values rather than blanking them.
- **Undeclared pivot keys are discarded**, so the payload cannot become an unbounded, unvalidated bucket written through the API.
- **Post ids are filtered on `> 0`, not truthiness.** A negative id is truthy and would otherwise reach a query as a real post reference; `PostIdListTrait` makes the rule shared between store and manager.
- **Cleanup hooks `before_delete_post`**, not `deleted_post` — resolving which rows to cascade needs the post type, which is gone once the post row is deleted.

**Verification:** 505 tests, 1469 assertions; PHPStan Level 7 and PHPCS clean; 21 JS tests unaffected. Six guards were mutation-tested — removing reciprocal-side cardinality enforcement, allowing undeclared pivot keys, validating sync after clearing, dropping relationship writes from the review queue, cascading into a shared target, or letting the reciprocal inherit cascade each fail at least one test.

**Exit criteria:** A model declares a relationship in config and both sides become readable and writable through REST, MCP, and WP-CLI, with cardinality enforced from either direction, one query per relationship per result set, and agent-originated writes governed by the review queue. ✓ Done 2026-08-08

**Correction (2026-08-11):** this section previously said "all writes governed by the review queue," and the item table said writes were "routed through `ProposalService`." Both overstated it. Queueing is implemented one layer above `RelationshipManager` — in `AbilityRuntime` for MCP and in `WebMcp` for the browser surface, each keyed on `ProposalService::should_queue()`. `RelationshipsController::sync_items()` and `RelationshipCommand::sync()` both call `RelationshipManager::sync()` directly, so a REST or WP-CLI caller holding `edit_posts` writes without review. That is consistent with how every other Saltus feature treats those two surfaces, and `sync_related` *is* in `ProposalService`'s mutating list — so the agent paths do queue exactly as claimed. Only the scope of the word "all" was wrong.

**Non-goals for Phase 10A:** the admin metabox UI (Select2 picker), migration scripts from ACF/Toolset/Pods, a query-builder facade (`Relations::for()->with()`), and relationships to taxonomy terms or users. Storage and the three programmatic surfaces come first; the UI is worth building once the data model has settled.

---

## Release Maintenance

Findings from each version cycle's code review, tracked per release. These are **not** numbered phases: they carry no theme, no exit criteria, and no sequence position. They were briefly numbered 11 and 12, which collided with the strategic phase numbers reserved below — see [Phase Numbering](#phase-numbering).

---

### Maintenance: v1.8.3

**Theme:** Fix the low-severity findings surfaced by the 1.8.3 version cycle code review. None blocked the release; each is tracked here so the next cycle can resolve it.

| Item | Status |
|------|--------|
| `ResultBudget::shrink_lists()` docstring overclaims "guarantee the result fits the allowance" — a payload dominated by short scalar strings can still exceed the allowance after lists are dropped. Re-word to match reality. | [x] |
| `WebMcpController::get_manifest()` lists admin-only post type slugs in a public manifest (`enabled_models()`), disclosing private post types to anonymous visitors. Confirm the slug-only disclosure is acceptable, or narrow the public route. | [x] |
| `AuditLogger::ensure_db()` runs `CREATE TABLE IF NOT EXISTS` DDL on every read path per request (health endpoint, retention cron). Deliberate and guarded per request; consider a low-traffic guard or async creation for high-traffic sites. | [x] |

**Resolution:**

- **`shrink_lists()` docstring** — re-worded rather than made true. The sweep reclaims list cost only; a payload whose scalar keys alone exceed the allowance stays over it, and `clip_strings()` takes the next pass. `apply()` already withheld `truncated` in that case, which is the signal an agent needs. Pinned by `testScalarOnlyPayloadCannotBeTrimmedToFit`.
- **Manifest disclosure** — narrowed rather than accepted. `get_manifest()` now reports `visible_models()`: `frontend_models()` for anonymous and under-capability callers, `enabled_models()` for callers clearing `edit_posts`, the same floor `AdminTool` uses for tool discovery. The `models` and `tools` keys now agree about who is asking. The 1.8.3 change that widened this (`867ae10`) had also locked it into a test, so that test's contract was corrected alongside.
- **Audit DDL** — gated on a one-hour `saltus_mcp_audit_table_verified` transient storing `DB_VERSION`, filterable via `saltus/framework/mcp/audit/table_check_ttl` (`0` restores per-request DDL). Creation still is not gated on the version option, so a dropped table still comes back — within the TTL rather than on the next read. A bumped `DB_VERSION` invalidates every marker without an upgrade step.

**Verification:** 520 tests, 1502 assertions; PHPStan Level 7 and PHPCS clean. Confirmed stable across six random orderings — the first run surfaced a pre-existing harness fragility, where `get_current_user_id()` reads a null `$wp_current_user_id` as user 1 but `0` as anonymous, so a teardown restoring `0` poisons the default for any later class that seeds a user id without setting it first. ✓ Done 2026-08-10

---

### Maintenance: v1.8.4

**Theme:** Fix the medium-severity finding surfaced by the 1.8.4 version cycle code review.

| Item | Status |
|------|--------|
| `AuditLogger::ensure_db()` marks the table verified even when the `CREATE TABLE IF NOT EXISTS` DDL fails (`ensure_table()` discards the query result), so a transient DB failure hides the missing table from every read for up to an hour. Only set the transient on successful DDL so a failed create retries next request. | [x] |

**Resolution:**

- **`ensure_table()` now returns `bool`** — `$wpdb->query()`'s result is compared against `false` rather than cast, so the affected-row count the `AuditDatabase` interface also permits is not misread as a rejected statement. `ensure_db()` returns early when the create failed, leaving both the verification transient and the `saltus_mcp_audit_db_version` option unwritten. The schema marker matters as much as the transient: written after a failed create, it would tell a future migration that this version's table exists.
- **Per-request memoization is unchanged.** `db_initialized` is still set before the work, so a failed DDL is attempted once per request, not once per call. The retry window is the next request — which is the point: previously the failure was cached for the full hour TTL and every read in that window queried a missing table and reported zero errors, a broken log that looks like a healthy one.
- **Two regression tests**, both mutation-tested: dropping the guard fails `testFailedDdlIsNotMarkedVerified` and `testFailedDdlDoesNotRecordSchemaVersion`. `AuditLoggerTest` gained a `tearDown()` restoring the shared `$wpdb` global, since the new tests swap in a double whose `query()` reports `false` and that must not leak into another class.

**Verification:** 522 tests, 1506 assertions; 21 JS tests; PHPStan Level 7 and PHPCS clean; `git diff --check` clean. ✓ Done 2026-08-11

---

## Phase Numbering

Two numbering schemes briefly disagreed. The internal Phase 10 planning documents reserved 11–14 for a strategic sequence, while this file spent 11 and 12 on version-cycle bug-fix buckets.

The buckets lost the numbers: they are per-release maintenance with no theme or exit criteria, and they were the later, more casual use. They now live under [Release Maintenance](#release-maintenance) keyed by version.

**Reserved:** 11 Security & Compliance · 12 Developer Experience · 13 Enhanced UX · 14 Observability.

**Also note:** there is no Phase 9. The sequence runs 8 → 10A. Phase 9 was to be Performance (query optimization, background jobs) and appears only as a passing mention in the internal notes; it was never scoped here. The number stays unused rather than being recycled, so internal references to "Phase 9" keep resolving to the thing they meant.

Phases 10B and 10C remain scoped only in the internal RFC and are summarized under [Phase 10 Remainder](#phase-10-remainder) below.

---

## Phase 10 Remainder

The internal RFC — still at RFC status as of 2026-08-11 — split Phase 10 into three sub-phases. Only 10A shipped, and it shipped without two of its own scoped deliverables. Recorded here so the roadmap does not imply Phase 10 is closed.

| Sub-phase | Scope | State |
|-----------|-------|-------|
| **10A** Relationships | Data model, storage, REST/MCP/WP-CLI surfaces | ✓ Done 2026-08-08 |
| **10A** follow-ups | Admin metabox picker UI (✓ Done 2026-08-11, delivered in Phase 13), query-builder facade (`Relations::for()->with()`), ACF/Toolset/Pods migration scripts (in progress as task #2) | Picker done; query facade and migrations in progress |
| **10B** Workflows | Custom approval states beyond draft/publish, transition validation, dashboard, notifications — **should generalize Phase 6B's `ProposalService` state machine** | Scoped below; approved 2026-08-14 |
| **10C** Scheduled Actions | Scheduled publish/unpublish, auto-archival rules, monitoring | Scoped below; approved 2026-08-14 |
| **10.5** Migration tools | ACF relationship field importer with dry-run preview | Optional; contingent on 10A adoption |

**Where the RFC has drifted from the code:** it specifies a `src/Migrations/` system with `up()`/`down()` classes and a nested `src/MCP/Tools/Relationships/` directory. Neither exists — `RelationshipStore` follows the lazy `ensure_table()` pattern already used by `ProposalStore` and `AuditLogger`, and tools sit flat in `src/MCP/Tools/` so `WpCliFeatureTest`'s parity check covers them. The RFC's `README.md` still points at those uncreated paths. Read it for intent, not for structure.

**Relevance to 10B:** the RFC's workflow engine overlaps Phase 6B's editorial review queue, which already has proposal states (`pending` → `approved`/`rejected`), a `ProposalStore`, a review dashboard at Tools → AI Review Queue, and audit events on every transition. 10B should generalize that state machine rather than build a second one beside it.

---

### Phase 10B: Workflows (v2.10+)

**Theme:** Generalize the Phase 6B proposal queue into a flexible workflow engine that serves both AI review and general content approval flows.

**Premise:** Phase 6B built `ProposalService` for AI-originated changes: `pending` proposals await human approval before applying. The service is hardcoded to two terminal states (`approved`/`rejected`) and knows nothing about transitions beyond "pending → done". Sites that need multi-stage approval (draft → legal review → editorial review → published), conditional routing, or rejection-with-revision have no path. This phase generalizes the state machine without breaking the existing AI review queue.

**Design constraints:**
- **Do not break the existing queue.** The Phase 6B dashboard and `ProposalService::should_queue()` must work unchanged after this ships. A migration that forces every site to reconfigure is a non-starter.
- **States are site-defined, not framework-defined.** Beyond `pending` (the entry state) and two reserved terminal flags (`approved`, `rejected`), states come from model config. A legal site and an e-commerce site need different flows.
- **Transition validation lives in config, not code.** "Draft → Published" is valid; "Published → Draft" might not be. The rules belong in the model config where editors can see them.
- **Audit every transition.** The existing audit trail records proposal creation and terminal states; it must also log every intermediate transition, who initiated it, and when.
- **Notifications are a filter, not a bundled system.** Sites have their own notification infrastructure (email, Slack, MS Teams). The framework emits a `saltus/framework/workflow/transition` action with the proposal, old state, and new state; the site decides what to send.

**Config shape (proposed):**
```yaml
workflows:
  approval:
    states:
      - name: draft
        label: "Draft"
      - name: legal_review
        label: "Legal Review"
      - name: editorial_review
        label: "Editorial Review"
      - name: approved
        label: "Approved"
        terminal: true
      - name: rejected
        label: "Rejected"
        terminal: true
    transitions:
      - from: draft
        to: legal_review
        capability: edit_posts
      - from: legal_review
        to: [editorial_review, rejected]
        capability: review_legal
      - from: editorial_review
        to: [approved, rejected, draft]
        capability: review_editorial
    default_state: draft
```

| Item | Status |
|------|--------|
| Generalize `ProposalStore` schema to support arbitrary states beyond pending/approved/rejected | [ ] |
| `WorkflowDefinition` value object parsed from model config `workflows` section | [ ] |
| Transition validation: `WorkflowEngine::can_transition(from, to, user)` checks capability and config | [ ] |
| `ProposalService` refactored to delegate state logic to `WorkflowEngine` | [ ] |
| Phase 6B dashboard updated to render workflow-aware states and available transitions | [ ] |
| Audit logging extended to record every state transition with timestamp and user | [ ] |
| `saltus/framework/workflow/transition` action for notification integration | [ ] |
| REST endpoints: `POST /proposals/{id}/transition` with `new_state` parameter | [ ] |
| MCP tool: `transition_proposal` wrapping the REST controller | [ ] |
| WP-CLI: `wp saltus proposal transition <id> <new_state>` | [ ] |
| Backward compatibility: existing two-state proposals migrate transparently | [ ] |
| PHPUnit coverage: transition validation, capability checks, audit entries, terminal state detection | [ ] |

**Exit criteria:** A model can declare a multi-state workflow in config, and proposals move through it via REST/MCP/WP-CLI/dashboard with transition validation and audit logging. The Phase 6B AI review queue continues to work without reconfiguration, treating `approved`/`rejected` as the terminal states it already knows.

**Non-goals:** a visual workflow editor (config is YAML), email/Slack/Teams notification delivery (sites wire the action to their own systems), scheduled state transitions (that's Phase 10C), and workflow analytics beyond what the audit log already records.

---

### Phase 10C: Scheduled Actions (v2.11+)

**Theme:** Let models declare time-based rules for automatic state changes — scheduled publish, auto-archival, expiration reminders.

**Premise:** Sites routinely need "publish this post at 9am Monday" or "archive posts 90 days after publication" or "send a reminder when a draft sits untouched for a week." WordPress core has a scheduled publish date, but nothing for unpublish, archival, or model-specific rules. This phase adds a declarative scheduled-action system that runs through WP-Cron and applies via the same governed paths as manual changes.

**Design constraints:**
- **Declarative, not programmatic.** Rules live in model config as YAML, not scattered across theme code as cron callbacks. A rule change is a config change, not a code deploy.
- **WP-Cron, not a custom scheduler.** WordPress already has a cron system; use it. High-traffic sites already replace it with real cron, and that replacement will work here too.
- **Actions route through the same governance as manual ones.** A scheduled publish of a post with AI-generated content goes through `ProposalService::should_queue()` just like a manual publish from MCP. A scheduled action bypassing review is a bypass, not automation.
- **Execution is audited and visible.** Every triggered action logs an audit row with status (succeeded/failed/skipped), and failed runs surface in health output so a broken rule does not silently stop running.
- **Rules are evaluated per post, not per model.** "Archive posts in the 'news' category 30 days after publication" applies only to matching posts, not every post of the type.

**Config shape (proposed):**
```yaml
scheduled_actions:
  - name: auto_archive_old_news
    trigger: after_publish
    delay: 30 days
    condition:
      taxonomy: category
      term: news
    action:
      set_status: archive
  - name: unpublish_expired
    trigger: meta_field
    field: expiration_date
    action:
      set_status: draft
  - name: reminder_stale_draft
    trigger: after_status_change
    status: draft
    delay: 7 days
    action:
      notify:
        message: "Draft post {{title}} has been untouched for a week"
        recipients: [author, editor]
```

| Item | Status |
|------|--------|
| `ScheduledActionDefinition` parsed from model config `scheduled_actions` section | [ ] |
| `ScheduledActionEngine` registers WP-Cron events per rule and evaluates conditions per post | [ ] |
| Trigger types: `after_publish`, `after_status_change`, `meta_field` (date field), `fixed_schedule` (cron expression) | [ ] |
| Action types: `set_status`, `set_meta`, `delete_post`, `notify` (hook for external systems) | [ ] |
| Condition matching: taxonomy terms, meta field values, author role, date ranges | [ ] |
| Governance integration: scheduled writes route through `ProposalService` when `should_queue()` says so | [ ] |
| Health monitoring: failed action rate, last successful run per rule, rules with no eligible posts | [ ] |
| Audit logging: every triggered action with result, timestamp, and the rule that fired it | [ ] |
| Dashboard: "Scheduled Actions" page listing rules, eligible posts, next run times | [ ] |
| REST endpoints: `GET /scheduled-actions`, `GET /scheduled-actions/{rule_id}/eligible` | [ ] |
| MCP tools: `list_scheduled_actions`, `preview_scheduled_action` | [ ] |
| WP-CLI: `wp saltus scheduled-action {list|preview|run-now}` | [ ] |
| Dry-run mode: preview what would happen without applying changes | [ ] |
| PHPUnit coverage: trigger evaluation, condition matching, governance routing, cron registration | [ ] |

**Exit criteria:** A model can declare scheduled actions in config, and they fire through WP-Cron, route through governance, log audits, and surface execution health. A rule can be previewed (dry-run) before enabling, and failed runs appear in health output.

**Non-goals:** sub-minute granularity (WP-Cron is minute-based), distributed lock coordination for high-traffic multi-instance sites (that's an infrastructure concern, not a framework one), a visual rule builder, and real-time triggers (use WordPress hooks directly for that — this is for time-based rules only).

---

### Phase 11: Security & Compliance (v2.7+)

**Theme:** Make Saltus deployable where content carries obligations — field-level access control, encryption at rest for designated fields, and the WordPress privacy hooks a site needs to answer a data subject request.

**Premise:** Saltus already gates at the *surface* boundary. `CapabilityPolicy` decides whether a capability is reachable for a model; `ModelRestPolicy` and `McpPolicy` gate per feature section; `PublicFieldFilter` decides which meta fields an anonymous WebMCP caller may see. What no layer does is gate an *individual field* by role for authenticated callers — once a caller clears `edit_posts` for a model, every field in it is readable and writable across REST, MCP, WP-CLI, and WebMCP. This phase adds the missing axis, and it must add it in one place that all four surfaces consult, or the surfaces will disagree.

**Design constraints:**
- **One resolution point, four consumers.** Field permissions resolve in a single service that REST, MCP, WP-CLI, and WebMCP all call. A per-surface implementation is how a private field leaks through the surface someone forgot.
- **Deny by omission is wrong here.** An array section with no `show_in_rest` key already resolves to *enabled* in `ModelRestPolicy` — a deliberate existing behavior. Field permissions must invert that: a field with no rule stays as accessible as it is today (no silent breakage for existing sites), but a field *with* a rule denies unless the rule matches.
- **Encryption is opt-in per field and never covers the whole table.** Encrypted fields cannot be queried by value or sorted on. Declaring one must make that trade-off visible in config, not discovered at query time.
- **Key material never lives in the database.** A key in `wp_options` beside the ciphertext is not encryption. Keys come from `wp-config.php` constants or a filter resolving to an external store.
- **Privacy hooks are core's, not ours.** Register `wp_privacy_personal_data_exporters` and `..._erasers` and let core drive the request workflow. Nothing in `src/` currently touches either — grep confirms zero references.

**Config shape (planned):**
```yaml
fields:
  salary:
    permissions:
      read: ['manage_options']
      write: ['manage_options']
    encrypted: true          # implies unqueryable, unsortable
  internal_notes:
    permissions:
      read: ['edit_others_posts']
```

| Item | Status |
|------|--------|
| `FieldPermissionPolicy` — resolves per-field read/write capability for a model, one point all four surfaces consult | ✓ Done 2026-08-11 |
| REST enforcement: filter response fields and reject writes to denied fields in `MetaController` | ✓ Done 2026-08-11 |
| MCP/WP-CLI enforcement through the same policy, verified by a parity test per surface | ✓ Done 2026-08-11 |
| WebMCP enforcement: `PublicFieldFilter` composes with the policy rather than duplicating its rules | ✓ Done 2026-08-11 |
| Encryption at rest for fields declaring `encrypted: true`, with key material from `wp-config.php` or a filter | ✓ Done 2026-08-11 |
| Encrypted fields rejected from query, sort, and filter arguments with an actionable error hint | ✓ Done 2026-08-11 |
| GDPR: `wp_privacy_personal_data_exporters` registration covering model meta fields | ✓ Done 2026-08-11 |
| GDPR: `wp_privacy_personal_data_erasers` registration, honoring relationship cascade rules | ✓ Done 2026-08-11 |
| Audit events for denied field access, distinguishable from a capability failure | ✓ Done 2026-08-11 |
| Mutation-test every new guard: removing it must fail at least one test | ✓ Done 2026-08-11 |

**Notes from implementation:**

- **The policy resolves against normalized fields, not raw config.** All four surfaces already hold `MetaFieldProvider`'s normalized field list, so resolving there is what makes one answer possible. `filter_payload()` and `reject_denied_write()` live on the policy for the same reason — three surfaces filter the same payload shape, and a per-surface copy is how one ends up a version behind.
- **No rule means no change.** A field without `permissions` stays exactly as accessible as before, inverting the usual deny-by-omission default so existing sites cannot break by upgrading. A malformed rule is also treated as no rule: it must not silently become a denial of everything, nor an accidental grant.
- **A rule on a parent binds its children.** Without it, denying a serialized parent leaks through any nested field and the caller reconstructs the parent from its parts.
- **Denied writes are rejected, not skipped.** A 200 with the key absent is indistinguishable from "written, value unchanged". A payload mixing allowed and denied fields rejects wholesale rather than applying half.
- **WebMCP composes and runs last.** The policy applies *after* the `public_fields` filter hook — running before would make the hook a bypass. A test pins that ordering.
- **Encryption uses XChaCha20-Poly1305 with an OpenSSL AES-256-GCM fallback**, both authenticated so tampering fails rather than yielding altered plaintext. Ciphertext carries a version prefix, which is what lets `is_encrypted()` recognize an envelope and what makes a backend change readable rather than indistinguishable from corruption. The fallback is exercised explicitly in tests: an untested crypto path is worse than no path, since a site without libsodium would otherwise be the first to run it.
- **Keys never touch the database.** `SALTUS_FIELD_ENCRYPTION_KEY` in `wp-config.php` or the `saltus/framework/field_encryption_key` filter for an external store. A wrong-length key is refused rather than padded — stretching it would weaken every value invisibly.
- **Encryption fails closed.** No key configured means the write is refused, because storing plaintext in a field the author marked encrypted defeats the declaration silently.
- **An encrypted field is never public**, regardless of the `public_fields` filter. Decrypting for an anonymous caller would defeat the reason for encrypting.
- **The query guard is wired into `AbilityRuntime`**, the single point both dispatch paths pass through, and grouped with the permission and governance checks into `pre_dispatch_gates()` so a check added there cannot be forgotten in one path. `search` is deliberately not treated as a field reference: it hits post title and content, not meta.
- **Export decrypts, erasure follows cascade.** A data subject request asks what the site holds about a person, so ciphertext answers nothing — encryption protects the value at rest, not from its subject. Erasure removes meta but keeps posts, matching how core's own erasers anonymize rather than delete, and follows cascade-declared relationships because leaving a dependent's meta behind leaves the subject's data on an orphan the request cannot see.
- **`field_denied` is its own audit status**, and deliberately does not count toward the health error rate. A capability failure and a field denial have different fixes — one is a role, the other is model config — and a working security rule should not look like an outage.

**Verification:** 667 tests, 1834 assertions; 32 JS tests; PHPStan Level 7 and PHPCS clean; stable across 20 random orderings. Eleven guards mutation-tested — parent inheritance, the no-rule default, any-vs-all capability matching, the write rejection, read filtering, the WebMCP composition ordering, nonce reuse, key length refusal, the double-encrypt guard, the query-guard wiring, and the distinct audit status each fail at least one test.

**Deferred within scope:** `FieldPermissionPolicy` is not yet consulted by the Phase 13 relationship picker or post-list column. Those surface *relationships*, not meta fields, so the policy has nothing to say about them. Relationships gained their own permission system in Phase 13 (2026-08-14) via `RelationshipPermissionPolicy` and the `capabilities` config key, which is separate from field-level rules and enforced at the manager choke-point.

**Exit criteria:** A field declaring `permissions` is unreadable and unwritable through REST, MCP, WP-CLI, and WebMCP by a caller lacking the capability, with one policy resolving all four. A field declaring `encrypted: true` is stored as ciphertext and rejected from query arguments. A core privacy request exports and erases model meta.

**Non-goals:** row-level (per-post) permissions beyond what WordPress capabilities already give, an audit-log UI, key rotation tooling, and compliance certification of any kind. Field-level access is the gap; the rest is scope creep.

---

### Phase 12: Developer Experience (v2.8+)

**Theme:** Fail at config time instead of runtime. A model config typo currently produces silence, a half-registered post type, or a fatal deep in a feature service — never a message naming the key that was wrong.

**Premise:** Saltus validates *tool arguments* thoroughly (`src/MCP/Validation/Validator.php`, JSON Schema on every ability call) and validates *model config* not at all — grep finds no config validator, no schema file, no `validate_config()`. That asymmetry is the whole phase. An agent calling a tool with a bad argument gets a structured error with a hint; a developer writing `relationships: { actors: { cardinality: has_meny } }` gets undefined behavior.

**Design constraints:**
- **Validation runs at registration, not on every request.** A config check on each page load is a tax on production for a mistake only the developer can make. Validate when models are processed, cache the verdict, and expose a WP-CLI command for CI.
- **Errors name the file, the key path, and the accepted values.** "Invalid config" is not a deliverable. `books.yml: relationships.actors.cardinality — 'has_meny' is not one of has_one, has_many, belongs_to, many_to_many` is.
- **Never fatal on a warning.** A deprecated key or an unknown-but-harmless one warns and continues; only a config that would corrupt data or half-register a post type refuses. Existing sites must not break on upgrade because a key they have used for two years is now spelled differently.
- **The schema is generated from the code that consumes it**, not hand-maintained beside it. A hand-written schema drifts the moment someone adds a feature — the same failure mode `docs/mcp/abilities.md` avoids by being generated.
- **Migrations stay lazy.** The Phase 10 RFC asked for `src/Migrations/` with `up()`/`down()`; 10A deliberately declined it, and three stores now share the `ensure_table()` pattern. If a real schema *change* (not creation) ever lands, that is when a migration mechanism earns its place — and the [v1.8.4 maintenance fix](#maintenance-v184) is the precedent for how a failed DDL must be handled: never record success you did not get.

| Item | Status |
|------|--------|
| `ConfigValidator` — validates a model config against a schema derived from the feature services that consume each section | ✓ Done 2026-08-11 |
| Structured `ConfigError` value objects carrying file, key path, found value, and accepted values | ✓ Done 2026-08-11 |
| Error/warning severity split: warnings log and continue, errors refuse to register the model | ✓ Done 2026-08-11 |
| `wp saltus config validate [--model=<name>] [--strict]` for CI, exiting non-zero on error | ✓ Done 2026-08-11 |
| Validation verdict cached per config file, invalidated on file mtime change | ✓ Done 2026-08-11 |
| Unknown-key detection with a nearest-match suggestion (`has_meny` → `has_many`) | ✓ Done 2026-08-11 |
| Deprecated-key warnings for `features.draganddrop` vs `features.drag_and_drop` and the other known drift pairs | ✓ Done 2026-08-11 |
| Health endpoint reports config validity per model, so a broken config is visible without CLI access | ✓ Done 2026-08-11 |
| Generated config reference at `docs/api/config-reference.md`, refreshed by `composer docs:all` | ✓ Done 2026-08-11 |
| PHPUnit coverage per config section, including one fixture per known-bad shape | ✓ Done 2026-08-11 |

**Exit criteria:** A malformed model config produces an error naming the file, key path, and accepted values, at registration time and via `wp saltus config validate`. A config with only unknown-but-harmless keys warns and still registers. Health reports per-model config validity. The config reference is generated, not authored. ✓ Done 2026-08-11

**Non-goals:** a GraphQL surface (the RFC listed it; REST + MCP + WP-CLI + WebMCP is already four surfaces to keep in parity, and nothing has asked for a fifth), an IDE plugin, config scaffolding or generators, and a migration system without a migration to run. `has_meny` suggestions are worth building; a fifth transport is not.

---

### Phase 13: Enhanced UX (v2.9+)

**Theme:** The admin surfaces Saltus generates should be usable and accessible. Two debts land here: the relationship picker 10A deferred, and the Codestar accessibility defects that block both assistive technology and any future declarative-forms work.

**Premise:** every programmatic surface is built and none of the UI is. A relationship is fully writable through REST, MCP, and WP-CLI, and completely invisible in the post editor — an editor cannot see, let alone set, what an agent can freely change. Meanwhile the four Codestar defects documented during the Phase 8B declarative-forms evaluation are live WCAG 2.1 findings (1.3.1 Info and Relationships, 4.1.2 Name Role Value) on **every** Saltus admin screen today, not hypothetical future problems.

**Accessibility is the unblocker, so it goes first.** The declarative forms API derives its schema from `<label>` text, `aria-description`, and field `name`. Codestar renders titles as `<h4>` in a sibling div, emits no `id` on any of its 45 field types' inputs, and carries zero `aria-` attributes. Fixing that is what makes a derived schema viable *and* what makes the admin screens conformant — one piece of work paying two debts. It is vendored-code work in `lib/codestar-framework/`, scoped out of Phase 8B on purpose.

**Design constraints:**
- **Fix `field_attributes()` once, inherit 45 times.** All field types route through `CSF_Fields::field_attributes()`; emitting `id` there covers every type without touching 45 files.
- **Keep the class, change the element.** `csf-title` becomes `<label for>` retaining its class, so no stylesheet changes and no visual regression.
- **Vendored changes must be re-appliable.** `lib/codestar-framework/` is third-party. Every edit is recorded so a Codestar upgrade can replay it, or it will be silently reverted by the next vendor bump.
- **The picker is one component, not one per cardinality.** `has_one` is the picker capped at one. A separate single-select implementation is how the two drift.
- **The picker must decide where it sits relative to the queue, explicitly.** Queueing is not in `RelationshipManager` — it lives one layer up, in `AbilityRuntime` for MCP calls and in `WebMcp` for browser calls, both keyed on `ProposalService::should_queue()`. `RelationshipsController` calls `RelationshipManager::sync()` directly, so a REST caller with `edit_posts` already writes without review. A metabox save is closer to the REST path than the agent path, and an editor clearing a capability gate arguably *is* the human review. The picker should therefore write directly like REST does, and the roadmap should stop implying a governance layer that is not where it looked.
- **No new frontend framework.** The picker uses what the admin already loads. Select2 is bundled with Codestar; the RFC assumed it.
- **Bulk operations reuse `wp saltus` service classes**, not a parallel implementation. The CLI already does bulk correctly.

| Item | Status |
|------|--------|
| Codestar: emit `id` alongside `name` in `CSF_Fields::field_attributes()` | ✓ Done 2026-08-11 |
| Codestar: `csf-title` from `<h4>` to `<label for="…">`, class preserved | ✓ Done 2026-08-11 |
| Codestar: `aria-describedby` linking `csf-desc-text` to its input | ✓ Done 2026-08-11 |
| Codestar: vendored-change log so a Codestar upgrade can replay the patches | ✓ Done 2026-08-11 |
| Relationship metabox picker — search, select, reorder, detach; one component across all four cardinalities | ✓ Done 2026-08-11 |
| Picker writes through `RelationshipManager::sync()`, matching what `RelationshipsController` already does — see the constraint above on where queueing actually lives | ✓ Done 2026-08-11 |
| Picker respects per-relationship permissions via `RelationshipPermissionPolicy` | ✓ Done 2026-08-14 — relationship `capabilities` enforced at the manager and metabox; `FieldPermissionPolicy` for meta fields is separate |
| Relationship column on the post list table, with eager loading so the list stays one query per relationship | ✓ Done 2026-08-11 |
| Bulk attach/detach from the post list, delegating to the same service classes `wp saltus relationship` uses | ✓ Done 2026-08-11 |
| Keyboard operability and screen-reader labels verified on the picker specifically | ✓ Done 2026-08-11 |
| Re-evaluate the declarative forms API now that labels and ids exist — the [8B no-go](#8b--admin-surface-and-governed-writes) was conditional on these defects | ✓ Done 2026-08-11 — **closed, not deferred** |
| Accessibility statement in the docs recording what was fixed and what remains unverified | ✓ Done 2026-08-11 |

**Picker notes from implementation:**

- **Selection state lives in the hidden inputs, in document order.** Reordering moves list items and their inputs move with them, so the submitted order *is* whatever the list shows. There is no separate order field to fall out of step with the display.
- **No Select2, no jQuery.** The design constraint above assumed Select2 because the RFC did. It was not used: vanilla JS against WordPress core's own `/wp/v2/{post_type}` collection is fewer moving parts, adds no dependency, and gave better keyboard handling than configuring a library would have. Nothing new is enqueued beyond the picker's own script and style.
- **No new REST route.** Search hits core's post-type collection, so core's capability handling applies and Saltus adds no surface to secure. A target post type that sets its own `rest_base` is resolved server-side into the localized payload rather than guessed in the browser.
- **A missing nonce field means "no submission", not "empty set".** A post saved through REST, WP-CLI, or another plugin fires `save_post` without ever rendering the picker. Treating that as an emptied picker would clear every relationship on the post. The absent-nonce guard is the one whose removal is most quietly destructive, and it is mutation-tested.
- **Ids are passed through unsanitized, deliberately.** `RelationshipManager::sync()` routes them through `PostIdListTrait::post_id_list()`, which casts to int, drops anything not `> 0`, and deduplicates while preserving order. A second filter in the metabox was written first, then removed once a mutation test showed it could not fail — it was dead code that looked load bearing. Only the length cap is applied locally, because `sync()` rejects an oversized list outright and silently refusing an editor's save is worse than trimming it.
- **A relationship the user cannot see is skipped on save, not cleared.** `render_field()` omits it, so it is absent from the payload — indistinguishable from "emptied" without an explicit capability re-check in the save loop.
- **Both sides get a picker.** The target of a declared relationship has the synthesized reciprocal, so `person` gets an `acted_in` picker even though it declares nothing. The registry treats both sides as first-class and the UI follows.
- **Keyboard operability is built in, not deferred:** arrow keys move through results with `aria-selected` tracking, Enter selects, Escape closes, and Alt+Arrow reorders the selected set. Focus is moved deliberately after a removal rather than being allowed to fall to `<body>`. The live region announcing result counts and add/remove is visually clipped rather than `display:none`, which would remove it from the accessibility tree and silence it.
- **A core-rendered title is set with `textContent`, never `innerHTML`.** Core returns rendered titles that may carry entities; a test asserts no child elements are ever built from a title, so the search results cannot become an injection point.

**Verification:** 539 tests, 1534 assertions; 32 JS tests (21 bridge + 11 picker); PHPStan Level 7 and PHPCS clean. Six guards mutation-tested — removing the absent-nonce guard, the nonce verification, the `edit_post` check, the revision guard, the `has_relationships` gate, or the input's `aria-describedby` each fail at least one test.

**List-table notes from implementation:**

- **Priming happens on `the_posts`, not in the render callback.** WordPress renders a custom column one row at a time, so resolving a relationship inside `render()` would cost one query per row. `the_posts` is the last point where the whole result set is available, which is what makes `get_related_for_posts()` — the eager-loading entry point 10A already built — usable here. A test counts SELECTs at the `AuditDatabase` boundary and asserts a three-row page costs exactly one read, and that rendering adds none.
- **An unprimed render is blank, not a fallback query.** If `prime()` never ran — another plugin replacing the query, a screen reached by a path that skips `the_posts` — the cell renders empty rather than querying per row. A silent N+1 on an admin list is worse than a blank cell, and the mutation test for this is the one that catches a well-meaning "fix".
- **Bulk actions have no fast path.** Attaching fifty posts is fifty `RelationshipManager::attach()` calls, each enforcing cardinality and target validity, not one query that skips the checks. A bulk `has_one` attach against a post that already has one is refused per post and counted as failed — asserted, because a bulk operation that can produce state a single operation would have rejected is exactly the bug this shape prevents.
- **Capability is checked twice, at different grains.** Per relationship when offering the action, and per post inside the loop, because a bulk selection can span posts the user may not all edit.
- **The related post id comes from a query argument.** WordPress's bulk-action UI cannot host a second input, so a run without a target redirects back with the selection intact and a "choose a post" notice rather than failing the action. This is how core's own multi-step bulk flows work.
- **The empty cell is `—` plus screen-reader text.** A bare dash is meaningless to a screen reader, so it is `aria-hidden` and paired with "None".

**Declarative forms: closed, not deferred.** The re-evaluation is recorded internally. Fixes 1–3 landed and settings screens are now technically derivable, but the two findings that ruled it out are permanent: field names are bracketed because that *is* WordPress's submission contract (changing it breaks every site's saved data), and a metabox cannot have its own form because a nested form is invalid HTML and the post editor owns the outer one. Beyond that, a declarative form submits itself — there is no interception point, so it would hand an agent a direct write with no proposal, no review, and no audit entry, which is the opposite of the posture 8B established. Adopting it for settings screens alone would add a second tool-definition mechanism beside `AdminTool` over the same screens, which is the drift `AdminTool` exists to prevent. Reopen only if the API grows both an explicit schema override and a submission hook.

**Still open in this phase:** only the `FieldPermissionPolicy` integration. No longer blocked — the policy shipped with Phase 11 — but it governs *meta fields* while the picker and column surface *relationships*, so it has nothing to say about them until field-level rules extend to relationship visibility. That extension is not in Phase 11's scope and needs its own decision. Note that "keyboard and screen-reader verified" means automated assertions on emitted markup and keyboard handlers — not manual testing with a real screen reader, which `docs/ACCESSIBILITY.md` records as outstanding.

**Verification:** 560 tests, 1578 assertions; 32 JS tests; PHPStan Level 7 and PHPCS clean; stable across 40 random orderings. Four more guards mutation-tested — making `render()` fall back to a per-row query, making `prime()` loop per post, dropping the per-post `edit_post` check in bulk, or removing the missing-target guard each fail at least one test.

**Two pre-existing test-isolation bugs were fixed along the way.** `AiContextProvider::validate_mutation()` reads the model name off the stored post when one exists, so a post left in the shared `$wp_posts` global by an earlier test class changes an unrelated class's result. `RelationshipToolsTest` seeded post 7 as a `movie` and never cleared it, while `AbilityRuntimeTest` asserts on post 7 expecting a `book` — a real intermittent failure reproducible on seed `1786446439`, present before this phase and surfacing roughly once in twenty runs. Both classes now clear `$wp_posts` in `tearDown`. Resetting in `setUp` protects the class doing it but not the next one.

**Exit criteria:** An editor can find, set, reorder, and remove related posts from the post editor, and the resulting write is governed identically to an agent's. Every Codestar field type emits an input `id`, a `<label for>`, and `aria-describedby` where a description exists. The declarative-forms decision is revisited against the fixed markup and recorded either way.

**Non-goals:** inline editing in the post list, frontend submission forms (the RFC listed both — they need their own security review, since a frontend form is an unauthenticated write path and nothing in Saltus currently accepts one), a block-editor sidebar panel duplicating the metabox, and a full WCAG audit. Full conformance validation needs manual testing with assistive technology and expert review; this phase fixes four specific documented defects and says so.

---

### Phase 14: Observability (v3.0+)

**Theme:** Turn the audit trail into something an operator can read. The data is already collected and almost nothing surfaces it.

**Premise:** `AuditLogger` records every ability call with timestamp, user, arguments, status, duration, and error code. `HealthController` already computes error rate, average/p95/max latency, and per-status counts from those rows. All of it is available at exactly one place — a JSON REST endpoint — with no UI, no per-tool breakdown, no time series, and no way to notice a problem without polling by hand. The v1.8.3 and v1.8.4 maintenance findings are both cases where a silently broken audit table would have looked identical to a healthy one from the outside. That is the gap.

**Design constraints:**
- **Aggregate, do not re-log.** Everything here reads existing audit rows. This phase adds no new write path into the audit table, because a metrics writer competing with the audit writer is a way to lose audit rows.
- **A dashboard must not scan the whole table.** `get_recent_entries()` takes a limit and the health payload samples it. A dashboard over 90 days of rows needs pre-aggregated buckets, not a full scan on page load — the same instinct that put the DDL behind a transient.
- **Report the missing table as missing.** The one thing health must never do is read an absent or broken audit table as zero errors. Both maintenance findings were versions of this; the invariant belongs in a test, not a comment.
- **Retention already exists and bounds everything.** The daily cron prunes past `saltus/framework/mcp/audit/retention_days` (default 30). Aggregates must survive pruning — roll up before the rows go, or a 90-day view silently becomes a 30-day view.
- **No external services by default.** Error tracking integrates through a filter a site can point at its own collector. Saltus ships no outbound network call and no third-party SDK.
- **Sampling is a config knob, not a rewrite.** A high-traffic site should be able to audit 1-in-N calls without changing what the dashboard means.

| Item | Status |
|------|--------|
| Pre-aggregated daily rollups per ability: call count, error count, latency percentiles | ✓ Done 2026-08-14 |
| Rollups computed on the existing retention cron, before pruning removes the source rows | ✓ Done 2026-08-14 |
| Admin dashboard: per-tool call volume, error rate, and latency over a selectable window | ✓ Done 2026-08-14 |
| Per-tool and per-client breakdown, reusing `ClientIdentity` so no raw visitor IP is surfaced | ✓ Done 2026-08-16 — aggregate and client rows are queried separately; client output uses opaque persisted identifiers |
| Audit table health check that reports "unavailable" distinctly from "zero errors" | ✓ Done 2026-08-14 |
| `wp saltus metrics [--ability=<name>] [--since=<date>] [--format=table\|json\|yaml]` | ✓ Done 2026-08-14 |
| Error tracking hand-off filter (`saltus/framework/observability/error`) for an external collector, no SDK bundled | ✓ Done 2026-08-16 |
| Audit sampling rate filter for high-traffic sites, with the sample rate recorded alongside the aggregates | ✓ Done 2026-08-16 |
| Slow-call log: calls exceeding a filterable duration threshold, retained separately from the sampled set | ✓ Done 2026-08-16 |
| Health payload extended with rollup freshness, so a stalled cron is visible | ✓ Done 2026-08-16 |
| Dashboard accessibility verified against the [Phase 13](#phase-13-enhanced-ux-v29) markup fixes | ✓ Done 2026-08-16 — tables retain scoped sortable headers, keyboard buttons, live status, and empty/error states |
| PHPUnit coverage for rollup arithmetic, retention interaction, and the missing-table case | ✓ Done 2026-08-16 — includes error hand-off, sampling, slow-call, freshness, API, CLI, and runtime wiring contracts |

**Verification:** 1293 tests, 3360 assertions; PHPStan Level 7 clean. Confirmed at the v2.1.1 release commit, which is also where this phase's code was committed — the rollup store, the metrics dashboard and API, `wp saltus metrics`, and the health freshness reporting all landed as atomic commits in that cycle.

**Exit criteria:** An operator can see per-tool and per-client call volume, error rate, and latency over a chosen window from wp-admin and from `wp saltus metrics`, without a full table scan. Aggregate and client-scoped rows are queried separately and are never double-counted. A missing or broken audit table reports as unavailable rather than healthy. Aggregates survive retention pruning. Slow calls remain visible even when normal audit sampling excludes a call. Rollup freshness and stalled retention work are visible in health. Nothing leaves the site unless a filter is wired to send it.

**Non-goals:** APM-grade tracing, a bundled third-party error-tracking SDK, request-level profiling of non-Saltus code, alerting or notification delivery (the hand-off filter is the integration point; a site's existing alerting owns the rest), and multisite network-wide aggregation.

---

## Phase 15 — v1.8.5 Review [~] (4/5)

@priority medium @owner OmensUI

Code review findings from the v1.8.5 cycle. Each finding is an open task to fix in a later cycle.

### 15.1 [medium] Derive known top-level keys from the keys actually read, not every service id

> src/Models/Config/SchemaBuilder.php:278 — `known_top_level_keys()` takes `array_keys( Core::get_service_classes() )`; docs/api/config-reference.md:133 documents the result

All 19 service ids are treated as valid top-level model config keys, but 12 are only meaningful under `features:` (`admin_cols`, `admin_filters`, `ai_assistant`, `draganddrop`, `duplicate`, `editorial_review`, `mcp`, `privacy`, `quick_edit`, `remember_tabs`, `single_export`) and `wp_cli` is not a model-config key at all — nothing reads them at depth 0. The unknown-key warning (and the generated reference) now silently accepts genuinely inert keys.

- [x] Restrict the derived set to service ids actually read at the top level (`frontend`, `meta`, `settings`, `blocks`, `webmcp`, `ai_context`, `relationships`), or keep an explicit top-level allowlist alongside the derivation
- [x] Add a regression test asserting `wp_cli` and at least one `features`-only id (e.g. `admin_cols`) are NOT in `known_top_level_keys`
- [x] Regenerate `docs/api/config-reference.md` (`composer docs:config`) so the published key list matches

### 15.2 [medium] Roadmap marks `--model` and `--strict` for `wp saltus config validate` as done; the command implements neither

> docs/ROADMAP.md:920 (✓ Done 2026-08-11) vs src/Features/WpCli/Commands/ConfigCommand.php:34 — only `--format` is implemented

The Phase 12 roadmap item promises `wp saltus config validate [--model=<name>] [--strict]`. The implemented command has no `--model` filter and no `--strict` mode, so warnings can never fail CI — the advertised CI use case is absent.

- [x] Add `--model=<name>` to narrow the summary/errors to one model, or update the roadmap item to the implemented scope
- [x] Add `--strict` so warnings exit non-zero, and document it in the command docblock
- [x] Cover both flags in `ConfigCommandTest`

### 15.3 [medium] `Core::get_service_classes()` changed from protected instance to public static and is invoked via `self::`

> src/Core.php:351 and src/Core.php:421 — `$services = self::get_service_classes();` where the method was `$this->get_service_classes()`

This is a framework API change shipped in a patch release. A consumer subclass that overrode the former `protected` method now either fatals (non-static override of a static method) or is silently bypassed: `self::` never dispatches to an override, so a subclass's customised service list would no longer be used and its services would silently vanish.

- [x] Call `static::get_service_classes()` so compatible static overrides are honoured, or keep an instance method and add a static bridge for the schema derivation
- [ ] Note the signature change in the changelog and release notes for 1.8.5

### 15.4 [low] `SuggestsNearestKey` trait duplicates `ConfigValidator::nearest()`

> src/Models/Config/ConfigValidator.php:634 — private `nearest()` with `SUGGESTION_MAX_DISTANCE` alongside the new trait in src/Models/Config/SuggestsNearestKey.php

The trait's docblock says it exists so `ConfigValidator` and every contributor agree on one distance threshold, but `ConfigValidator` kept its own private copy — two implementations of the same logic that can drift.

- [x] Make `ConfigValidator` use the `SuggestsNearestKey` trait and delete the private `nearest()` method and `SUGGESTION_MAX_DISTANCE` constant

### 15.5 [low] `--format=csv` advertised by `wp saltus config validate` but silently coerced to table

> src/Features/WpCli/Commands/ConfigCommand.php:17-24 (docblock options include `csv`) vs src/Features/WpCli/Commands/AbstractCommand.php:28 (`format()` accepts only table/json/yaml)

WP-CLI accepts `--format=csv` (it is in the declared options list), then `AbstractCommand::format()` falls back to `table`, so a caller asking for CSV gets a table with no error.

- [x] Add `csv` to the accepted formats in `AbstractCommand::format()`, or drop `csv` from the ConfigCommand docblock options

---

## Phase 16 — v2.1.0 Review [~] (7/9)

@priority high @owner OmensUI

Code review findings from the v2.1.0 cycle. Each finding is an open task to fix in a later cycle.

### 16.1 [high] Make the rollup schema migration portable

> src/MCP/Audit/RollupStore.php:687 — the rollup schema migration relies on database-specific DDL behavior rather than a WordPress-supported portable migration path.

Impact: Schema upgrades can fail or diverge on supported database configurations, leaving rollup storage unavailable or partially migrated.

- [x] Replace the database-specific migration with a portable WordPress-compatible schema upgrade, preserve existing rollup data, and cover fresh installs plus upgrades from the prior schema.

### 16.2 [high] Make relationship synchronization atomic

> src/Features/Relationships/RelationshipManager.php:298 — relationship synchronization performs a multi-step mutation without an atomic boundary.

Impact: A failure between writes can leave the forward and reciprocal relationship state partially updated and internally inconsistent.

- [x] Execute the complete relationship sync atomically, roll back every mutation on failure, and verify that interrupted attach, detach, and reorder operations preserve the prior state.

### 16.3 [high] Reconcile reciprocal relationship permissions explicitly

> src/Features/Relationships/RelationshipRegistry.php:211 — synthesized reciprocal relationships do not explicitly reconcile permission rules from both sides.

Impact: Reciprocal access can inherit ambiguous or asymmetric permissions, potentially exposing or permitting relationship operations that one side intended to restrict.

- [ ] Define and implement deterministic reciprocal permission reconciliation that preserves the stricter applicable policy, and cover conflicting and one-sided capability declarations.

### 16.4 [high] Bypass user-facing read filtering during privacy cascade erasure

> src/Features/Privacy/Privacy.php:321 — privacy cascade discovery uses a user-facing relationship read path whose visibility filters can hide records from erasure.

Impact: Personal data in filtered or otherwise non-visible relationships can survive an erasure request, producing an incomplete privacy cascade.

- [ ] Route cascade discovery through an internal unfiltered erasure query while retaining authorization at the erasure entry point, and prove hidden related records are deleted without widening user-facing reads.

### 16.5 [high] Allow aggregate rollups to coexist with client mode

> src/MCP/Audit/RollupStore.php:67; src/Features/Observability/MetricsApi.php:74 — client-scoped mode displaces or conflates aggregate rollups instead of allowing both datasets to coexist.

Impact: Enabling client metrics can remove or distort the aggregate series, so dashboard and API totals become incomplete or mode-dependent.

- [x] Store and query aggregate and client-scoped rollups as distinct coexisting series, prevent double-counting, and cover API results with client mode both enabled and disabled.

### 16.6 [medium] Make error-rate calculation sampling-aware

> src/MCP/Audit/AuditLogger.php:331 — error-rate inputs do not consistently account for the sampling rate attached to recorded calls.

Impact: Sampled traffic can produce materially misleading error rates and cause operators to misjudge service health.

- [x] Weight or normalize sampled call and error counts using the recorded sampling metadata, define behavior for mixed sampling rates, and add arithmetic coverage for sampled and unsampled windows.

### 16.7 [medium] Decouple slow-call retention cleanup

> src/MCP/Audit/AuditLogger.php:249 — `cleanup_expired_entries()` returns when normal `retention_days <= 0` before running the separately configured slow-call cleanup.

Impact: Configuring normal audit retention as unlimited also disables pruning of the slow-call store, allowing it to grow without honoring its own retention policy.

- [x] Run slow-call cleanup independently of normal audit retention, and cover unlimited normal retention combined with finite slow-call retention.

### 16.8 [medium] Update rollup freshness only after successful rollups

> src/MCP/Audit/AuditLogger.php:281 — rollup freshness can advance even when rollup computation or persistence does not complete successfully.

Impact: Health reporting can claim rollups are fresh while metrics are stale or incomplete, masking a failed retention job.

- [x] Record the freshness timestamp only after all required rollup writes succeed, leave the prior value unchanged on every failure path, and cover partial and total rollup failures.

### 16.9 [medium] Implement or remove the blank metrics chart

> assets/Feature/Observability/dashboard.js:58; src/Features/Observability/ObservabilityDashboard.php:81 — the dashboard emits a metrics chart surface that has no implemented visualization.

Impact: Operators see an empty chart region that implies missing data or a broken dashboard and provides no usable metrics insight.

- [x] Either render an accessible chart from the supplied metrics with loading, empty, and error states, or remove the unused chart markup and JavaScript path so the dashboard exposes no blank control.

---

## Phase 17 — v2.1.1 Review [~] (25/27)

@priority high @owner OmensUI

Code review findings from the v2.1.1 cycle. Each finding is an open task to fix in a later cycle. Findings already recorded in Phase 16 (v2.1.0) against the same working tree are not repeated here.

### 17.1 [high] Correct the audit sampling random range

> src/MCP/Audit/AuditLogger.php:371 — `sample_value()` divides `wp_rand()` by `getrandmax()`, but the two functions do not share an upper bound, so the produced value is not confined to 0..1.

Impact: The effective retention fraction is materially lower than the configured `sample_rate`, audit rows are discarded faster than intended, and the `sample_rate` persisted with every rollup misstates what was actually kept.

- [x] Derive the sampling value from a single bounded source so it is uniform over 0..1 inclusive of the configured rate, and cover the boundary rates 0, 1, and an intermediate value with a deterministic seam.

### 17.2 [high] Make the rollup upsert atomic and unique

> src/MCP/Audit/RollupStore.php:378 — `store_rollup()` performs a separate existence check, delete, and insert, and the unique key covering `client_identifier` does not constrain rows where that column is null.

Impact: Overlapping or repeated rollup runs can persist more than one aggregate row for the same date and ability, so every dashboard, REST, and CLI total silently double-counts.

- [x] Replace the read-then-delete-then-insert sequence with a single atomic write whose uniqueness also holds for aggregate rows, and cover concurrent and repeated rollups of the same date.

### 17.3 [medium] Create the slow-call table before pruning it

> src/MCP/Audit/AuditLogger.php:432 — `cleanup_slow_calls()` deletes from a table that is only ever created inside `record_slow_call()`.

Impact: On every install that has not yet recorded a slow call, the retention cron issues a delete against a missing table and logs a database error on each run.

- [x] Ensure the slow-call table exists on the same schema path as the audit table, and cover retention on an install with no recorded slow calls.

### 17.4 [medium] Throttle the slow-call schema check

> src/MCP/Audit/AuditLogger.php:385 — `record_slow_call()` issues `CREATE TABLE IF NOT EXISTS` on every slow call, with none of the transient throttling `ensure_db()` applies to the audit table.

Impact: A burst of slow calls turns each one into an additional schema statement, adding database work to exactly the requests already identified as slow.

- [x] Move the slow-call schema check behind the same verified-transient throttle used for the audit table, and cover that repeated slow calls issue the schema statement once.

### 17.5 [medium] Reduce global static-analysis strictness back

> phpstan.neon:5 — `treatPhpDocTypesAsCertain: false` was added, applying to every path under `src/`.

Impact: A whole class of type contradictions stops being reported repository-wide, so unrelated existing and future code loses analysis coverage to accommodate the new files.

- [ ] Remove the global setting and resolve the specific contradictions it hides, narrowing to per-file ignores only where a documented stub limitation makes that impossible.

### 17.6 [medium] Bound the rollup read queries

> src/MCP/Audit/RollupStore.php:479; src/MCP/Audit/RollupStore.php:536 — `get_rollups()` and `get_client_rollups()` select every matching row with no limit, and both are called on each metrics request over windows of up to 365 days.

Impact: A long window on a busy site loads an unbounded result set into memory per dashboard request, with the client-scoped query growing by distinct client identifier as well as by ability.

- [x] Apply an explicit bound to both rollup reads with deterministic ordering and a way to page beyond it, and cover a window that exceeds the bound.

### 17.7 [medium] Backfill rollups for missed retention runs

> src/MCP/Audit/AuditLogger.php:274 — `compute_recent_rollups()` rolls up only the previous two days before retention prunes older rows.

Impact: Any gap in the retention schedule permanently loses the metrics for the skipped days, because the source audit rows are deleted with no path to recompute them.

- [x] Roll up every date not yet covered by the recorded completion marker before pruning, bound the catch-up work per run, and cover a multi-day gap.

### 17.8 [medium] Disclose sampling when rates differ across a window

> src/Features/Observability/MetricsApi.php:146 — a window containing more than one distinct `sample_rate` collapses to `is_sampled: false` with a null rate.

Impact: Metrics spanning a sampling configuration change are presented as exact counts with no sampling notice, which is the one case where the disclosure matters most.

- [x] Report sampling whenever any rollup in the window is sampled, expose the per-rate breakdown rather than a single value, and cover a window mixing sampled and unsampled days.

### 17.9 [medium] Answer denied relationship reads with a refusal

> src/Features/Relationships/RelationshipManager.php:116; src/Features/Relationships/RelationshipPermissionPolicy.php:132 — denied reads return an empty list, so REST responds 200 with an empty set, while `reject_denied_read()` exists to produce the 403 and is never called from anywhere.

Impact: A caller cannot distinguish a relationship it may not read from one that is genuinely empty, and the read half of the policy diverges from the write half, which refuses loudly.

- [x] Refuse denied reads through the existing rejection path at the surfaces that can carry an error, keep the filtered-list behaviour only where a hard error would hide permitted relationships, and cover both shapes.

### 17.10 [medium] Cover the new sampling and slow-call logic with tests

> src/MCP/Audit/AuditLogger.php:331; src/MCP/Audit/AuditLogger.php:355; src/MCP/Audit/AuditLogger.php:371; src/MCP/Audit/AuditLogger.php:385 — `should_record()`, `is_slow()`, `sample_value()`, `record_slow_call()`, and the new error action have no tests; the only added assertion counts delete statements.

Impact: The sampling decision, the failure bypass, the slow-call threshold, and the error hook can all regress without any test failing.

- [x] Add coverage for the sampling decision at its boundaries, the error and exception bypass, the slow-call threshold and its persistence, and the error action firing only after a successful insert.

### 17.11 [medium] Discard stale metrics responses

> assets/Feature/Observability/dashboard.js:15 — the fetch effect refires on every range and ability change with no cancellation or request-sequence guard.

Impact: A slower earlier request can resolve after a newer one and overwrite the displayed metrics with data for a filter the operator has already changed.

- [x] Abort or ignore superseded requests so only the newest response updates state, and cover an out-of-order resolution.

### 17.12 [low] Unslash the ability filter before sanitizing

> src/Features/Observability/MetricsApi.php:63 — `$_GET['ability']` is passed to `sanitize_text_field()` without `wp_unslash()`.

Impact: WordPress-added slashes survive into the comparison value, so an ability name containing a quote silently matches nothing instead of filtering.

- [x] Unslash the request value before sanitizing it, matching the convention used by the other request-reading surfaces.

### 17.13 [low] Render or drop the estimated call total

> src/Features/Observability/MetricsApi.php:155 — `estimated_total_calls` is computed and sent, and no consumer reads it; the dashboard notice states the opposite, that counts are not estimated.

Impact: The payload carries a field nothing displays while the visible notice contradicts it, so the extrapolation is both unused and misdescribed.

- [x] Either surface the estimate alongside the recorded count with its own label, or remove the field and keep the notice as the single statement about sampling.

### 17.14 [low] Validate the `--since` argument

> src/Features/WpCli/Commands/MetricsCommand.php:80; src/Features/WpCli/Commands/MetricsCommand.php:155; src/Features/WpCli/Commands/MetricsCommand.php:232 — `--since` is cast to string and used as a date bound with no format check.

Impact: An empty value widens the window to every stored rollup, and a malformed value reports no metrics rather than a bad argument, so both failure modes look like absent data.

- [x] Validate `--since` as a calendar date and fail with a clear message otherwise, and cover an empty and a malformed value.

### 17.15 [low] Prepare the table existence check

> src/Features/WpCli/Commands/MetricsCommand.php:291 — `SHOW TABLES LIKE '{$table}'` interpolates the table name into the statement instead of passing it through `$wpdb->prepare()`.

Impact: This is the one unprepared interpolation among the new audit queries, so the file no longer demonstrates the pattern the rest of the tree follows.

- [x] Pass the table name as a prepared value, matching the prepared form used by the other audit queries.

### 17.16 [low] Make the audit staleness threshold configurable

> src/Features/WpCli/Commands/MetricsCommand.php:320; src/Features/WpCli/Commands/MetricsCommand.php:335 — a one-hour gap since the newest audit row is hardcoded as the staleness bound.

Impact: A site with legitimately low MCP traffic is reported as stale and unhealthy whenever an hour passes without a call.

- [x] Make the staleness bound filterable with the current value as the default, and cover a quiet site reporting healthy.

### 17.17 [low] Guard the observability error action

> src/MCP/Audit/AuditLogger.php:88 — `do_action()` is called without the `function_exists()` guard the rest of the class applies to WordPress functions.

Impact: The class fatals rather than degrading in the non-WordPress contexts its other guards are written to tolerate, so the file is inconsistent with its own convention.

- [x] ~~Guard the action emission like the other WordPress calls in the class~~ — **won't fix.** The framework is WordPress-only, so `do_action()` is always defined; the `function_exists()` guards elsewhere in the class are the inconsistency, not this call. The action is now covered instead by tests asserting it fires only after a successful insert.

### 17.18 [low] Reject unrecognized capability operations at normalization

> src/Features/Relationships/RelationshipDefinition.php:83 — `normalize_capabilities()` keeps any non-empty string key, so a misspelled operation is stored and never consulted.

Impact: A typo in an operation name produces a stored rule that silently never applies, and only config validation reports it, so a site that does not run validation believes the relationship is protected.

- [x] Narrow normalization to the operations the policy defines, and cover an unrecognized operation being dropped rather than stored.

### 17.19 [low] Consider read permission when registering the metabox

> src/Features/Relationships/RelationshipManager.php:158 — `has_relationships()` reports on declared relationships without consulting the read policy, and the metabox uses it to decide registration.

Impact: A post type whose every relationship the caller may not read still registers a relationships metabox that renders no fields.

- [x] Base the registration decision on relationships the caller may actually read, and cover a post type where every relationship is read-denied.

### 17.20 [low] Use a valid ARIA role for the sampling notice

> assets/Feature/Observability/dashboard.js:57 — the sampling notice is given `role="note"`, which is not a defined ARIA role.

Impact: The role is ignored by assistive technology, so the notice carries no semantics and the invalid value fails accessibility validation.

- [x] Replace the invalid role with a defined one or with native markup that conveys the same meaning, and check it against the project accessibility notes.

### 17.21 [low] Guard the numeric formatting in the dashboard

> assets/Feature/Observability/dashboard.js:53; assets/Feature/Observability/dashboard.js:81 — `toLocaleString()` and `toFixed()` are called directly on values taken from the response.

Impact: A single absent or non-numeric field throws during render and blanks the entire dashboard instead of degrading that one cell.

- [x] Coerce and default the response values before formatting them, and cover a response missing a numeric field.

### 17.22 [low] Generate or remove the dashboard asset manifest

> assets/Feature/Observability/dashboard.asset.php:1; src/Features/Observability/ObservabilityDashboard.php:60 — the manifest is hand-maintained for a plain script that no build step produces, and the inline fallback repeats its dependencies with a different version.

Impact: The version and dependency list exist in two places that drift apart, and the manifest implies a build pipeline that does not run for this asset.

- [x] Either generate the manifest from a real build step or drop it and read the version from the single existing source, removing the duplicated fallback.

### 17.23 [medium] Keep the aggregate sentinel out of the client identifier space

> src/MCP/Audit/RollupStore.php:436; src/MCP/Audit/RollupStore.php:141 — the empty string now means "every client", but it is also a value a real identifier can take: `compute_and_store_rollup()` keeps an audit identifier of `''` as a client (only NULL becomes the aggregate), and `client_identifier() ?? AGGREGATE_CLIENT` then writes that client's row into the aggregate slot.

Impact: With client mode on, one client whose identifier sanitizes to empty collides with the aggregate row on the unique key, so the upsert overwrites the day's total with that single client's subtotal. The row hydrates back as the aggregate and is excluded from `get_client_rollups()`, so the corrupted total is presented as exact and the client vanishes from the per-client view.

- [x] Normalize an empty identifier to the aggregate case before the pair list is built, or reserve a sentinel no identifier can produce, and cover an audit row whose identifier is the empty string under client mode.

### 17.24 [medium] Record the rollup schema version only when the 1.2.0 repair applied

> src/MCP/Audit/RollupStore.php:701; src/MCP/Audit/RollupStore.php:746 — the NULL normalization and duplicate collapse run only when a stored version option is present and non-empty, while `update_option()` writes the new version unconditionally on every path.

Impact: A table at the old schema whose version option is absent — a partial restore, a deleted option, a table created in a context without the options API — is stamped 1.2.0 without the repair ever running, and the guard then refuses to retry. The duplicate aggregate rows the fix exists to collapse survive permanently, and the deliberate `IS NULL` tolerance in the aggregate read keeps serving them, so the double-counting continues with no failing signal.

- [x] Decide the repair from the table's own state rather than from the presence of the option, advance the recorded version only after the normalization is confirmed applied, and cover an old-schema table with no version option.

### 17.25 [low] Bound and guard the duplicate-collapse migration

> src/MCP/Audit/RollupStore.php:725; src/MCP/Audit/RollupStore.php:731 — the self-join delete and the `MODIFY` table rebuild run inline from `ensure_table()`, which every metrics read calls, with no advisory lock, no batch bound, and no distinction between a request and an upgrade routine.

Impact: The first dashboard or CLI read after the upgrade pays for an unbounded self-join delete plus a table rebuild inside the request, and concurrent readers each start the same repair before any of them records the new version, serializing on metadata locks.

- [x] Move the repair to an upgrade routine, take a lock so only one runner performs it, bound the delete per pass, and cover a table carrying many duplicate rows.

### 17.26 [low] State the sampling precision floor the draw actually has

> src/MCP/Audit/AuditLogger.php:24; src/MCP/Audit/AuditLogger.php:392 — the docblock describes a value "uniform over 0..1", but `wp_rand( 1, SAMPLE_PRECISION ) / SAMPLE_PRECISION` never yields 0, so the retained fraction is `floor( rate * SAMPLE_PRECISION ) / SAMPLE_PRECISION` and any rate below one millionth retains nothing.

Impact: A rate under the precision floor is silently a total stop rather than sampling, while the rate persisted with each rollup still claims that proportion was kept, so the extrapolation downstream reads a rate that was never applied.

- [x] Say in the docblock what floor the constant imposes, clamp or refuse a configured rate below it rather than treating it as zero, and cover a rate under the floor.

### 17.27 [low] Document the release in the changelog

> CHANGELOG.md:4 — `[Unreleased]` is empty and the newest section is `[2.1.0]`, while package.json:3 is at 2.1.1; the file never mentions the rollup store, the metrics dashboard, the metrics CLI command, the slow-call table, or audit sampling.

Impact: The version the tree carries has no changelog section, and the whole observability surface added in it ships undescribed, so a consumer upgrading has no record of the new tables, filters, REST route, or CLI command.

- [ ] Add the section for the released version and describe the observability additions with their new filters and stored tables, matching the entry style of the sections already present.

---
