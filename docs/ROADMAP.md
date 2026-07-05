# Saltus Framework Roadmap

## Current Status
- Version: 2.0.0 (released 2026-06-30)
- Features implemented: CPT creation, taxonomies, settings pages, metaboxes, cloning, export, drag&drop reordering.
- WordPress-native MCP/Abilities surface with 17 tools (9 Phase 1 + 7 Phase 2 + health)
- Phase 2 REST API complete: 9 routes registered in `saltus-framework/v1/`
- Phase 3 hardening complete: caching, rate limiting, audit trail, structured error codes, health monitoring
- PHPStan Level 7 clean across the configured analysis set as of 2026-07-02, including the asset loading helper path
- MCP v1 refactoring complete: per-tool REST dispatch, RestBackedToolInterface, ToolContributor, @phpstan-type AbilityDefinition
- Legacy refactoring: inline REST controller logic extracted into shared service classes (SaltusSingleExport, MetaFieldProvider, ReorderPostsService, SettingsManager) wired into both REST controllers and MCP tools — resolved 2026-07-03
- 201 PHPUnit tests passing (586 assertions), PHPStan Level 7 clean across the configured analysis set
- **v2.0.0 released 2026-06-30** — MCP, REST API, and Phase 3 shipped

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
| **Documentation site** | Source pages added at `docs/MCP.md`, `docs/MCP-CLIENTS.md`, and generated `docs/MCP-ABILITIES.md` for future `docs.saltus.dev/mcp` |
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

#### 5A — Block Editor Integration (block.json tied to models)

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
| `templates/block-list.php` | Default list block template |
| `templates/block-single.php` | Default single block template |
| `assets/Feature/Blocks/editor.js` | Editor script (InspectorControls) |
| `assets/Feature/Blocks/style.css` | Block styles |

**How it wires in:**
- `Core::get_service_classes()` adds `'blocks' => Blocks::class`
- `ModelFactory::process_services()` reads `config['blocks']` → `Blocks::make()` → `process()`
- Each call to `register_block_type()` uses a metadata array (no static block.json needed)
- Meta fields from model `meta` config auto-mapped as block attributes via `MetaFieldProvider`
- Dedicated MCP tool `list_block_models` contributed by `Blocks::get_mcp_tools()`
- Filter: `saltus/framework/blocks/attributes` to customize auto-generated attributes

| Item | Status |
|------|--------|
| Blocks feature service + SaltusBlocks implementation | ○ Pending |
| BlockRenderer with default render callbacks | ○ Pending |
| Default list/single block templates | ○ Pending |
| Editor script and styles | ○ Pending |
| MCP tool for block model discovery | ○ Pending |
| PHPUnit tests for block registration | ○ Pending |
| Integration with existing ModelRestPolicy | ○ Pending |

**Exit criteria:** `saltus/{cpt_name}-list` and `saltus/{cpt_name}-single` blocks are registered for every CPT with `blocks: true`. Block attributes reflect the model's meta field config. List block queries and renders posts; single block renders a post with all meta.

---

#### 5B — WP-CLI Tools

**Goal:** `wp saltus <command>` mapping every MCP tool to a WP-CLI command, using the same shared service classes.

**Command tree (7 grouped command classes):**

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

**Output formatting:** `--format=table|json|yaml` flag on all list/detail commands. Table is default for interactive, JSON for scripting.

**Generated docs:** `composer docs:wpcli` auto-generates command reference from command classes (parallel to `bin/generate-mcp-docs.php`).

| Item | Status |
|------|--------|
| WpCli feature service | ○ Pending |
| SaltusCommand (health + help) | ○ Pending |
| ModelCommand (list, get) | ○ Pending |
| PostCommand (list, get, create, update, delete, duplicate, export) | ○ Pending |
| TermCommand (list, create) | ○ Pending |
| SettingsCommand (get, update) | ○ Pending |
| MetaCommand (list, get) | ○ Pending |
| ReorderCommand | ○ Pending |
| `composer docs:wpcli` script | ○ Pending |
| PHPUnit tests with WP_CLI stubs | ○ Pending |

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
| Frontend feature service | ○ Pending |
| SaltusFrontend shortcode registration | ○ Pending |
| Default list template | ○ Pending |
| Default single template | ○ Pending |
| Shortcode attribute parsing (limit, orderby, taxonomy, terms, etc.) | ○ Pending |
| Template override resolution (config → theme → default) | ○ Pending |
| PHPUnit tests for shortcode rendering | ○ Pending |

**Exit criteria:** `[saltus_cpt type="movie"]` renders a styled list of posts. `[saltus_cpt type="movie" view="single" id="123"]` renders a single post with meta. Templates are overridable per model. Output is escaped and safe.

---

#### 5D — Documentation

**Goal:** Fill all README placeholders, add docs for new features, provide complete examples.

**README.md changes:**

| Section | Current State | Target |
|---------|---------------|--------|
| `features` parameter table | `(More Info Soon)` | Complete table of all 7 features with config keys and one-liners |
| `labels` parameter table | `(More Info Soon)` | Full reference: `has_one`, `has_many`, `text_domain`, `featured_image`, `overrides.ui`, `overrides.messages`, `overrides.bulk_messages`, `overrides.labels` |
| `meta` parameter table | `(More Info Soon)` | Metabox structure: sections, fields, Codestar field types, REST API registration |
| `settings` parameter table | `(More Info Soon)` | Settings page structure: page args, sections, fields, parent menu, tabs |
| CPT example file | `(Soon)` | Complete YAML + PHP model with all common parameters |
| Taxonomy example file | `(Soon)` | Complete YAML + PHP taxonomy model with associations |

**New doc files:**

| File | Content |
|------|---------|
| `docs/BLOCKS.md` | Block config reference, template customization, attributes guide, editor integration |
| `docs/WPCLI.md` | Full command reference with examples (auto-generated marker from `composer docs:wpcli`) |
| `docs/FRONTEND.md` | Shortcode API, template variables, customization guide, attribute reference |
| `docs/FEATURES.md` | Deep feature reference extracted from README: admin_cols, admin_filters, draganddrop, duplicate, quick_edit, remember_tabs, single_export |

**Auto-generation:** `composer docs:wpcli` script in `bin/generate-wpcli-docs.php` to generate WP-CLI command tables (parallel to `bin/generate-mcp-docs.php`).

| Item | Status |
|------|--------|
| README features table | ○ Pending |
| README labels reference | ○ Pending |
| README meta structure | ○ Pending |
| README settings structure | ○ Pending |
| README CPT example | ○ Pending |
| README taxonomy example | ○ Pending |
| docs/BLOCKS.md | ○ Pending |
| docs/WPCLI.md (auto-generated) | ○ Pending |
| docs/FRONTEND.md | ○ Pending |
| docs/FEATURES.md | ○ Pending |
| bin/generate-wpcli-docs.php | ○ Pending |

**Exit criteria:** Zero `(More Info Soon)` or `(Soon)` placeholders in README. All four new features have dedicated doc files. Feature reference is extracted to `docs/FEATURES.md`. WP-CLI docs are auto-generated.

---

*Plugin Generator moved to its own repository — see [docs/PLUGIN_GENERATOR_ROADMAP.md](./PLUGIN_GENERATOR_ROADMAP.md).*

## Framework Core Roadmap

### Short-term Goals
- ✓ Address remaining PHPStan errors (2 pre-existing in ResourceProvider) — resolved 2026-07-01.
- ✓ Code-review hardening pass — export isolation, lifecycle hook file registration, fail-closed MCP permissions, structured settings sanitization, JSON fallback, and AssetLoader PHPStan coverage resolved 2026-07-02.
- ✓ Service extraction — inline REST controller logic (WXR export, meta field normalization, post reorder, settings CRUD) moved into dedicated shared service classes and wired into both REST controllers and MCP tools; defensive guards for null post, private property access, taxonomy object, and asset data types — resolved 2026-07-03.
- Continue maintaining automated testing suites (201 tests, 586 assertions as of 2026-07-04).
- WordPress-native MCP/Abilities integration shipped in v2.0.0.
- **Phase 5 implementation** — Block Editor integration, WP-CLI tools, Frontend rendering, and documentation completion.

### Long-term Vision
- Continued improvements for WordPress CPT-based plugin development.
- Further refine the Codestar Framework integration.
- Establish WordPress-native MCP/Abilities as the standard AI interface for WordPress CPT plugins.
- Complete frontend-to-admin coverage: blocks, CLI, shortcodes, and documentation for every framework feature.

## Tracking
- Check GitHub Issues for active sprint items.
- Active development on `feature/mcp-v1` branch.
