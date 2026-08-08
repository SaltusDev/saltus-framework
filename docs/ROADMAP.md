# Saltus Framework Roadmap

## Current Status
- Version: `package.json` bumped to 1.8.1 (2026-08-08); `CHANGELOG.md` carries a 1.8.1 release section; relationship to the historical `v2.0.0` tag still pending; see Known Issues in [CURRENT.md](CURRENT.md)
- Phases 1–8 delivered. Phase 8A (WebMCP frontend browser surface) delivered 2026-08-07; Phase 8B (admin surface and governed writes) delivered 2026-08-08. See [Discovery: WebMCP](discovery/webmcp.md).
- Features implemented: CPT creation, taxonomies, settings pages, metaboxes, cloning, export, drag&drop reordering, model-driven blocks, frontend shortcodes, WP-CLI parity, AI governance, WebMCP frontend read surface
- WordPress-native MCP/Abilities surface with 25 tools
- REST API: 23 routes registered in `saltus-framework/v1/` across 13 controllers
- Phase 3 hardening complete: caching, rate limiting, audit trail, structured error codes, health monitoring
- MCP v1 refactoring complete: per-tool REST dispatch, RestBackedToolInterface, ToolContributor, @phpstan-type AbilityDefinition
- MCP namespace/category/prefix now filterable via MCPConfig utility class (saltus/framework/mcp/namespace, saltus/framework/mcp/ability_category, saltus/framework/mcp/ability_prefix)
- MCP/REST capability gating refactored: McpPolicy class with mcp_tools/show_in_mcp gating; ModelRestPolicy switched from the old saltus_rest array to a per-feature config-section model (using show_in_rest and show_in_mcp gates)
- Legacy refactoring: inline REST controller logic extracted into shared service classes (SaltusSingleExport, MetaFieldProvider, ReorderPostsService, SettingsManager) wired into both REST controllers and MCP tools — resolved 2026-07-03
- Conditional registration fix: `is_needed()` gate bypass for RestRouteProvider/ToolContributor registries via two-pass approach in `Core`, ensuring REST routes always appear in WP-REST index even before `REST_REQUEST` is defined — resolved 2026-07-06
- 394 PHPUnit tests passing (1072 assertions) plus 13 `bridge.js` tests via `npm test`, PHPStan Level 7 clean across the configured analysis set
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
| `wp saltus` (no args) | health + help | `GetHealth` |
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
| `src/Features/WpCli/Commands/SaltusCommand.php` | `wp saltus` — health + help summary |
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
- Continue maintaining automated testing suites (372 tests, 1015 assertions as of 2026-08-07).
- WordPress-native MCP/Abilities integration shipped in v2.0.0.
- ✓ **Phase 5 implementation** — Block Editor integration, WP-CLI tools, Frontend rendering, and documentation completion — delivered 2026-07-31.
- ✓ **Phase 6C AI client generation** — unhandled assistant actions generate through the WordPress AI Client; `saltus/framework/ai/prompt_builder` filter; AI availability reported in health + `wp saltus` — delivered 2026-08-07.
- Reconcile version numbering across `package.json` (now 1.8.1), `docs/ROADMAP.md`, `CHANGELOG.md`, and the `v1.4.2`/`v2.0.0` tags.
- ✓ **Phase 8B implementation** — admin WebMCP surface and proposal-queue-governed writes: `AdminScreen`/`AdminToolSet` per-screen scoping, `AdminTool` decorating existing abilities with the review-queue write posture, nonce-authenticated execute + refresh route, `saltus-webmcp-toolchange` re-registration in the bridge, `wp saltus webmcp manifest|validate`, health/`wp saltus health` WebMCP stats, and the declarative forms no-go evaluation in [Discovery: Declarative Forms](discovery/webmcp-declarative-forms.md) — delivered 2026-08-08.
- ✓ **Phase 8 scope defined** — WebMCP browser surface: frontend read-only tools in 8A, admin surface and proposal-queue-governed writes in 8B; research recorded in [Discovery: WebMCP](discovery/webmcp.md) — scoped 2026-08-07.
- ✓ **Phase 8A implementation** — `WebMcp` feature service, `WebMcpPolicy` gating, `ManifestBuilder` projection from the existing tool registry, five public read tools (`search_content`, `get_content`, `list_content_models`, `list_taxonomy_terms`, `filter_content`), `PublicFieldFilter`, `WebMcpController` manifest/execute routes, and the `bridge.js` single-point namespace probe — delivered 2026-08-07.
- ✓ **Phase 8A hardening and docs** — per-client rate limiting via `ClientIdentity`, `ResultBudget` output clamping, `bridge.js` test coverage, and the generated `docs/guides/webmcp.md` reference — delivered 2026-08-08.
- ✓ **Phase 8B implementation** — admin surface and proposal-queue-governed writes: `WebMcpTool` contract, `AdminTool` ability projection, `AdminScreen`/`AdminToolSet` per-screen scoping, `/webmcp/nonce` route with silent bridge refresh-and-retry, `saltus-webmcp-toolchange` re-registration, WebMCP state in health output, and `wp saltus webmcp manifest|validate` — delivered 2026-08-08.
- ✓ **Declarative forms evaluation** — no-go for 8B; Codestar emits `<h4>` titles, no input `id`, and no ARIA across 45 field types, so a derived schema would carry no property descriptions. Four accessibility defects documented for separate scoped work in [Evaluation](discovery/webmcp-declarative-forms.md) — evaluated 2026-08-08.

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

Research and rationale: [Discovery: WebMCP](discovery/webmcp.md). Read that first — it records the standards status, the Cloudflare and Shopify implementation patterns this phase borrows from, and the adoption data that bounds the scope.

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
| Declarative forms API evaluation for Codestar metabox and settings markup | ✓ Done 2026-08-08 — **no-go**, recorded in [Evaluation](discovery/webmcp-declarative-forms.md) |
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

Planning documents: [Phase 10 Highway](PHASE-10-HIGHWAY.md) and [docs/phase10/](phase10/README.md). Those were written before implementation and describe a `src/Migrations/` system and a `src/MCP/Tools/Relationships/` subdirectory that this phase deliberately did not build — see the design notes below.

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
| Relationship writes routed through `ProposalService` review queue | ✓ Done 2026-08-08 |
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

**Exit criteria:** A model declares a relationship in config and both sides become readable and writable through REST, MCP, and WP-CLI, with cardinality enforced from either direction, one query per relationship per result set, and all writes governed by the review queue. ✓ Done 2026-08-08

**Non-goals for Phase 10A:** the admin metabox UI (Select2 picker), migration scripts from ACF/Toolset/Pods, a query-builder facade (`Relations::for()->with()`), and relationships to taxonomy terms or users. Storage and the three programmatic surfaces come first; the UI is worth building once the data model has settled.
