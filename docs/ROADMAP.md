# Saltus Framework Roadmap

## Current Status
- Version: 1.3.4 (as of 2026-04-07)
- Features implemented: CPT creation, taxonomies, settings pages, metaboxes, cloning, export, drag&drop reordering.
- First MCP server (v0.1) shipped on `feature/mcp-v0`.

---

## MCP Server Roadmap

### Vision
Embed a **Model Context Protocol (MCP) server** directly in the Saltus Framework that exposes both the WordPress REST API and framework-specific operations to AI assistants. The framework registers its own REST API routes via `register_rest_route()` — no separate bridge plugin needed. Config is passed via environment variables; zero filesystem writes.

---

### Phase 1: Foundation Hardening (v0.1 → v0.5)

**Theme:** Make it reliable, secure, and spec-compliant.

| Item | Status |
|------|--------|
| MCP core protocol (initialize, tools, resources) | ✓ Done |
| 9 Phase 1 CRUD tools (models, posts, terms) | ✓ Done |
| Interactive setup wizard | ✗ Remove |
| Environment-variable-only config (`SALTUS_WP_URL`, `SALTUS_WP_USERNAME`, `SALTUS_WP_PASSWORD`) | ☐ |
| `Config::fromEnv()` — no file I/O, no home dir writes | ☐ |
| PHPUnit tests for every tool class (mock WP REST API via Guzzle mock handler) | ✓ Done |
| PHPStan Level 7 compliance for all `src/MCP/` code | ☐ |
| MCP Prompts support (`prompts/list`, `prompts/get`) — 3 prompt templates | ☐ |
| Input validation — JSON Schema validation on tool args before REST API call | ☐ |
| Retry logic with exponential backoff in `WordPressClient` | ☐ |
| `--help` flag with complete usage reference | ✓ Done |
| Update `README.md` with MCP usage and client configuration examples | ✓ Done |

**Exit criteria:** Full test suite green, PHPStan level 7, prompts working, zero file I/O, no wizard.

---

### Phase 2: Framework REST API (v0.5 → v1.0)

**Theme:** Expose every framework feature as a registered REST API route. Consume them from MCP tools.

**Framework REST namespace:** `saltus-framework/v1/`

#### REST Controllers (new `src/Rest/` namespace)

| Route | Method | Controller | Wraps |
|-------|--------|------------|-------|
| `/models` | GET | `ModelsController` | `Modeler` — list loaded models with full config |
| `/models/{post_type}` | GET | `ModelsController` | Model config, features, meta, settings |
| `/duplicate/{post_id}` | POST | `DuplicateController` | `SaltusDuplicate::perform_duplication()` |
| `/export/{post_id}` | GET | `ExportController` | `SaltusSingleExport` — WXR export |
| `/settings/{post_type}` | GET | `SettingsController` | `get_option($settings_id)` |
| `/settings/{post_type}` | PUT | `SettingsController` | `update_option($settings_id, $data)` |
| `/meta/{post_type}` | GET | `MetaController` | List meta field definitions from model config |
| `/reorder` | POST | `ReorderController` | Batch `menu_order` update |

**Registration:** `Core::register()` adds `add_action('rest_api_init', [$restServer, 'register_routes'])`.

**Permission callback:** `current_user_can('edit_posts')` by default.

#### New MCP Tools

| Tool | Calls |
|------|-------|
| `duplicate_post` | `POST /saltus-framework/v1/duplicate/{id}` |
| `export_post` | `GET /saltus-framework/v1/export/{id}` |
| `get_settings` | `GET /saltus-framework/v1/settings/{post_type}` |
| `update_settings` | `PUT /saltus-framework/v1/settings/{post_type}` |
| `reorder_posts` | `POST /saltus-framework/v1/reorder` |
| `get_meta_fields` | `GET /saltus-framework/v1/meta/{post_type}` |

#### Updated MCP Resources

`saltus://models` and `saltus://features` return live data by calling framework REST endpoints instead of hardcoded text.

**Exit criteria:** All 8 REST routes registered and tested; all 6 new MCP tools operational; v1.0 release tag.

---

### Phase 3: Premium Polish (v1.0 → v2.0)

**Theme:** Production hardening — caching, audit, SSE transport, multi-site.

| Feature | Description |
|---------|-------------|
| **SSE transport** | Serve MCP over HTTP (not just stdio) for remote connections |
| **Multi-site management** | Named site profiles, switchable at runtime |
| **Role-based access** | Map MCP tool access to WP user roles |
| **Audit trail** | Every tool call logged with timestamp, user, args, result |
| **Rate limiting** | Throttle requests per client |
| **Caching layer** | Cache `list_models`, `list_posts` with TTL |
| **Structured error codes** | Machine-readable error codes + resolution hints |
| **Health monitoring** | Endpoint with version, error rate, latency stats |
| **Configuration profiles** | `--profile=high-volume`, `--profile=strict` |

**Exit criteria:** SSE server running, caching reduces REST calls by 60%+, audit log operational, v2.0 release.

---

### Phase 4: Ecosystem & Distribution (v2.0+)

**Theme:** From framework feature to standalone product.

| Item | Target |
|------|--------|
| **Composer package** (`saltus/mcp-server`) | Standalone install, non-framework users |
| **PHAR distribution** | `saltus-mcp.phar` — no Composer needed |
| **Docker image** | `ghcr.io/saltusdev/mcp-server` |
| **GitHub Action** | `uses: saltusdev/mcp-action@v1` |
| **VS Code extension** | "Saltus MCP Explorer" — browse CPTs from editor |
| **Documentation site** | `docs.saltus.io/mcp` |
| **MCP Registry listing** | Submit to `github.com/modelcontextprotocol/servers` |
| **Support & SLA model** | Paid support contracts, custom tool development |

**Exit criteria:** PHAR published, Docker image on GHCR, docs site live.

---

## Framework Core Roadmap

### Short-term Goals
- Address codebase technical debt.
- Ensure automated testing suites are stable and passing.
- Ship MCP Phase 1 and Phase 2.

### Long-term Vision
- Continued improvements for WordPress CPT-based plugin development.
- Further refine the Codestar Framework integration.
- Establish MCP server as the standard AI interface for WordPress CPT plugins.

## Tracking
- Check GitHub Issues for active sprint items.
- Active development on `feature/mcp-v0` branch.
