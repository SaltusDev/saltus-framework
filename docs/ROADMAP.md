# Saltus Framework Roadmap

## Current Status
- Version: 1.3.4 (as of 2026-04-07)
- Features implemented: CPT creation, taxonomies, settings pages, metaboxes, cloning, export, drag&drop reordering.
- WordPress-native MCP/Abilities surface with 16 tools (9 Phase 1 + 7 Phase 2)
- Phase 2 REST API complete: 8 routes registered in `saltus-framework/v1/`
- Active development on `feature/mcp-v0` branch.

## Top Priority: WordPress 7.0 MCP/Abilities Integration

**Theme:** Make Saltus MCP tools discoverable and usable through WordPress-native MCP/Abilities infrastructure in WordPress 7.0. The standalone local stdio MCP server path is skipped.

| Item | Status |
|------|--------|
| Track WordPress 7.0 MCP/Abilities API shape and naming as it stabilizes | ✓ Done |
| Map each existing Saltus MCP tool to a WordPress-native ability definition | ✓ Done |
| Register Saltus abilities from WordPress when the native API is present | ✓ Done |
| Standalone local stdio MCP fallback | Skipped |
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
| Environment-variable-only config (`SALTUS_WP_URL`, `SALTUS_WP_USERNAME`, `SALTUS_WP_PASSWORD`) | ✓ Done |
| `Config::fromEnv()` — no file I/O, no home dir writes | ✓ Done |
| PHPUnit tests for every tool class (mock WP REST API via Guzzle mock handler) | ✓ Done |
| PHPStan Level 7 compliance for all `src/MCP/` code | ✓ Done |
| MCP Prompts support (`prompts/list`, `prompts/get`) — 3 prompt templates | ✓ Done |
| Input validation — JSON Schema validation on tool args before REST API call | ✓ Done |
| Retry logic with exponential backoff in `WordPressClient` | ✓ Done |
| `--help` flag with complete usage reference | ✓ Done |
| Update `README.md` with MCP usage and client configuration examples | ✓ Done |

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
| `/export/{post_id}` | GET | `ExportController` | ✓ Done | WXR export via `export_wp()` |
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

**Exit criteria:** All 9 REST routes registered and tested ✓; all 7 new MCP tools operational ✓; v1.0 release tag ○ (pending).

---

### Phase 3: Premium Polish (v1.0 → v2.0)

**Theme:** Production hardening — caching, audit, errors, and request controls.

| Feature | Description | Status |
|---------|-------------|--------|
| **WordPress 7.0 MCP/Abilities integration** | Register Saltus MCP tools as WordPress-native abilities when available | ✓ |
| **Local stdio MCP server** | Run Saltus as a standalone local MCP server process | Skipped |
| **SSE transport** | Serve MCP over HTTP for remote connections | Skipped |
| **Multi-site management** | Named site profiles, switchable at runtime | Skipped |
| **Role-based access** | Map MCP tool access to WP user roles | Skipped |
| **Audit trail** | Every tool call logged with timestamp, user, args, result | ✓ |
| **Rate limiting** | Throttle requests per client | ✓ |
| **Caching layer** | Cache `list_models`, `list_posts` with TTL | ✓ |
| **Structured error codes** | Machine-readable error codes + resolution hints | ✓ |
| **Health monitoring** | Endpoint with version, error rate, latency stats | Skipped |
| **Configuration profiles** | `--profile=high-volume`, `--profile=strict` | Skipped |

**Exit criteria:** Caching reduces REST calls by 60%+, audit log operational, v2.0 release. SSE, multi-site, role mapping, health monitoring, and configuration profiles are skipped for this track.

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
| **Documentation site** | `docs.saltus.io/mcp` |
| **MCP Registry listing** | Reassess for WordPress-native abilities |
| **Support & SLA model** | Paid support contracts, custom tool development |

**Exit criteria:** WordPress-native MCP docs published; standalone server packaging remains skipped.

---

*Plugin Generator moved to its own repository — see [docs/PLUGIN_GENERATOR_ROADMAP.md](./PLUGIN_GENERATOR_ROADMAP.md).*

## Framework Core Roadmap

### Short-term Goals
- Address codebase technical debt.
- Ensure automated testing suites are stable and passing.
- Ship MCP Phase 1 and Phase 2.

### Long-term Vision
- Continued improvements for WordPress CPT-based plugin development.
- Further refine the Codestar Framework integration.
- Establish WordPress-native MCP/Abilities as the standard AI interface for WordPress CPT plugins.

## Tracking
- Check GitHub Issues for active sprint items.
- Active development on `feature/mcp-v0` branch.
