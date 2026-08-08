# Context: Architecture & Decisions

## Architecture
The framework is built to be included within a WordPress plugin. It uses models (PHP/JSON/YAML) to define Custom Post Types and Taxonomies.
It searches for model files by default in the `src/models` directory.

### Initialization
```php
if ( class_exists( \Saltus\WP\Framework\Core::class ) ) {
    $framework = new \Saltus\WP\Framework\Core( dirname( __FILE__ ) );
    $framework->register();
}
```

## Key Decisions

### 1. Codestar Framework Integration
- **Purpose:** Used for rapidly building complex settings pages, metaboxes, and options panels.
- **Decision:** Instead of building a custom options framework from scratch, Codestar provides a robust, heavily tested foundation under a GPL license.
- **Trade-offs:** We maintain custom patches (`lib/codestar-framework/patches`) to tailor its behavior to our specific framework needs. This means updates to Codestar require manually re-applying patches via a script.

### 2. SoberWP/Models (Simplified)
- **Purpose:** Provides a declarative approach to registering Custom Post Types (CPTs) and Taxonomies.
- **Decision:** A simplified version of SoberWP/Models was included directly in the framework. This abstracts the repetitive and verbose WordPress core functions (`register_post_type`, `register_taxonomy`) into clean, easily readable PHP arrays, JSON, or YAML configuration files (models). 
- **Impact:** Speeds up development time for developers of any skill level by relying on simple configuration rather than complex procedural code.

### 3. Composer Classmap Autoloading
- **Purpose:** Handling legacy or third-party library loading.
- **Decision:** The project migrated from requiring 'files' directly to using Composer's `classmap` for libraries like the Codestar Framework.
- **Impact:** Enhances performance and standardizes autoloading. However, it introduces a manual step: developers must run `composer dump-autoload` whenever new classes are added to these mapped directories.

### 4. GitHub Updater Support
- **Purpose:** Plugin update management.
- **Decision:** Includes native support for `afragen/github-updater`.
- **Impact:** Allows plugins built with this framework to receive seamless updates directly from the WordPress admin dashboard, bypassing the need to host plugins on the official WordPress.org repository.

### 5. WordPress-Native MCP/Abilities
- **Purpose:** Expose Saltus model, content, settings, and metadata operations to AI clients through WordPress-native MCP/Abilities.
- **Decision:** WordPress 7.0 Abilities is the supported MCP path. The local stdio MCP server was removed; SSE transport and standalone server distribution remain out of scope.
- **Service Registration (Two-Pass Model):** To ensure REST endpoints and MCP tools are always available (regardless of whether the request is admin, frontend, or REST), `Core` registers `RestRouteProvider` and `ToolContributor` unconditionally on plugin boot. However, the core service activation (admin screens, scripts, hooks) remains strictly gated behind the `is_needed()` method. This prevents admin-only/frontend-only hooks and assets from running amok in contexts where they aren't needed.
- **Documentation:** `docs/MCP.md` is the canonical long-form source for the future Saltus MCP documentation site.
- **Metadata:** `list_meta_fields` exposes all registered CPT meta configs through `GET /saltus-framework/v1/meta`; `get_meta_fields` exposes one CPT through `GET /saltus-framework/v1/meta/{post_type}`.
- **Health:** `get_health` exposes `GET /saltus-framework/v1/health` through `saltus/get-health`, including version, error rate, latency, cache, and rate-limit status.
- **Current Shape:** Metadata responses preserve the raw Saltus/Codestar config in `meta` and add `normalized.fields` plus `normalized.rest_meta_keys` for client write guidance.
- **Normalized Metadata:** Nested Codestar fields are flattened into paths such as `points_info.coordinates.latitude`, with label, source meta key, serialized status, REST writability, and JSON-schema-like type data.

### 6. Legacy Refactoring Track
- **Purpose:** Stabilize high-traffic legacy framework paths such as `Modeler.php`, `src/Features/`, and `Saltus*.php`.
- **Decision:** Refactoring these paths is debt reduction around runtime-critical behavior, not cosmetic cleanup. The goal is to reduce regression risk, improve standards compliance, and prepare the code for focused unit and integration tests.
- **Compatibility:** Existing Saltus plugins should continue working while internals become safer to maintain, easier to type-check, and easier to test.

### 7. Model-Driven Blocks
- **Purpose:** Provide editor-native list and single views without duplicating post data into block content.
- **Decision:** Blocks use runtime Block API v3 metadata arrays and one shared unbundled editor script. Model metadata supplies selectable field paths; dynamic PHP rendering reads current post values.
- **Templates:** Resolution order is consuming-plugin config, active theme override, then framework defaults under `templates/blocks/`.

### 8. WP-CLI Parity
- **Purpose:** Make the WordPress-native ability surface available to operators and scripts without HTTP dispatch.
- **Decision:** Eight `wp saltus` command groups call WordPress APIs and shared framework services directly. `CommandCatalog` is the authoritative 20-command parity map and generates `docs/guides/wp-cli.md`.

### 9. Model-Scoped AI Governance Context
- **Purpose:** Expose normalized, per-model AI governance context (brand voice, audiences, field rules, allowed statuses, forbidden actions, human-review flag) to AI clients via REST and MCP.
- **Decision:** `AiContextProvider` normalizes raw model `ai_context` config against registered defaults (overridable via `saltus/framework/ai_context/defaults`). Mutating MCP tools are validated through `validate_mutation()` before REST dispatch, rejecting forbidden actions and disallowed statuses.
- **Surface:** `GetContext` MCP tool (`saltus/get-context`) + `GET /saltus-framework/v1/context/{post_type}` and the `wp saltus context get <post-type>` WP-CLI command.

### 10. Model-Driven Frontend Shortcodes
- **Purpose:** Render model-driven list and single views without writing template code, using a shared `FrontendRenderer` and configurable templates.
- **Decision:** `SaltusFrontend` registers the `[saltus_cpt]` shortcode (plus optional aliases) per model. Arguments are strictly validated/sanitized (limit clamp, allowlisted orderby/order, sanitized taxonomy and terms, path-traversal-guarded template resolution). Default templates ship under `templates/`.

### 11. Inside-Admin AI Assistants
- **Purpose:** Provide contextual AI suggestions and brand-rule validation inside configured post editors.
- **Decision:** `AiAssistantProvider` exposes fixed, model-scoped actions through `saltus/framework/ai/assistant_actions`. `AiAssistantProvider` first lets a consuming plugin handle an action via that filter; if none does, it generates content through the WordPress AI Client (`AiClient` + `ActionPrompts`) using credentials configured under Settings > Connectors. Saltus never reads, stores, or transmits API keys and hardcodes no provider or model. The `saltus/framework/ai/prompt_builder` filter lets consumers pin a provider or model.
- **Model Context:** `brand_voice`, `audiences`, and `field_rules` are composed into the AI client's system instruction, so prompts are model-scoped. Missing title/content/excerpt are filled from the post being edited.
- **Availability:** `GET /saltus-framework/v1/health` reports `ai.client_available` and `ai.connectors_available`; `wp saltus` reports AI availability.
- **Scope:** Post editor screens only. Existing posts use `edit_post`; new posts use `edit_posts`. Suggestions require explicit editor confirmation before changing fields. On WordPress versions without the AI client, only the filter path applies.

### 12. Reflection-Based Dependency Injection
- **Purpose:** Let framework consumers register services with ordinary typed and positional constructors.
- **Decision:** `ReflectionInstantiator` is the default instantiator for both `ServiceContainer` and `GenericContainer`. It resolves named dependencies first, then positional values, compatible typed objects, defaults, and nullable parameters.
- **Compatibility:** Services that accept one `array $dependencies` parameter continue to receive the full dependency bag. Unresolvable required parameters fail with `FailedToMakeInstance::UNRESOLVED_ARGUMENT`.

### 13. AI Editorial Review Queue
- **Purpose:** Queue mutating AI/MCP changes for human approval before they are applied, enforcing the `human-review` governance flag end-to-end.
- **Decision:** `AbilityRuntime` gates every mutating tool behind `has_permission()` before governance, rate-limit, or dispatch work. When `ProposalService::should_queue()` matches a mutating tool, the runtime stores the change as a `pending` proposal via `ProposalStore` instead of dispatching it. A reviewer then approves or rejects the proposal through the REST review API or the "AI Review Queue" admin page; `approve()` applies the stored mutation and records the resulting post id.
- **Surface:** `EditorialReview` service, `EditorialReviewController` routes (`/proposals`, `/proposals/{id}`, `/proposals/{id}/approve`, `/proposals/{id}/reject`), and the `saltus-ai-review` admin management page.

### 14. WebMCP Browser Surface (Phase 8)
- **Purpose:** Project the existing Saltus tool registry into the browser as WebMCP tools, so an in-browser AI agent can call typed functions instead of scraping model-rendered markup.
- **Decision:** WebMCP is a third consumer of tool definitions alongside WordPress-native MCP/Abilities and WP-CLI. Two independent surfaces: `frontend: true` registers read-only tools on public views for anonymous visitors, `admin: true` registers capability-gated tools on wp-admin screens. `ManifestBuilder` projects Saltus tool definitions into WebMCP descriptors (JSON Schema input and character budgets); `PublicFieldFilter` narrows frontend output to public/model-declared fields, denying secret-like field paths outright. The browser is an untrusted client: every invocation is re-validated through `src/MCP/Validation` and rate limited, and only `publish`-status posts of publicly queryable models surface on the frontend. Public queryability is deliberately *not* required for the admin surface — an agent there acts as a user who can already reach the screen, so gating is by capability instead.
- **Untrusted-client budgets:** two guards exist because the endpoint is reachable by unauthenticated visitors. `ClientIdentity` keys the rate-limit window per caller (user id when logged in, otherwise a salted `REMOTE_ADDR` hash) so one agent cannot exhaust the window for the whole site; caller-supplied forwarding headers are ignored by design, since honoring them would let an agent reset its own limit. `ResultBudget` clamps a serialized result to 1500 characters, because agents enforce their own output limits by cutting mid-token — trimming server-side keeps the JSON well-formed and flags `truncated` so the agent re-queries instead of trusting a partial answer.
- **Governed writes (8B):** no WebMCP write tool mutates directly. `AdminTool` decorates an existing ability — name, description, parameters, and capability checks all come from the ability already registered for MCP/Abilities and `wp saltus`, so the browser surface cannot drift from the other two consumers — and routes every mutating call through `ProposalService` to the Phase 6B review queue, returning the proposal id and a review URL. This exists because WebMCP has no settled confirmation model (`requestUserInteraction()` is unresolved in the spec draft) and no authentication story at all; rather than invent one, writes go down the path Saltus already built. With the queue unavailable a write returns 503 rather than falling back to a direct mutation, so a missing dependency cannot silently become an unreviewed change. Write tools are not annotated `readOnlyHint` — a queued write still changes state, and that hint is the only signal that makes an agent confirm.
- **Discovery vs invocation:** `WebMcpTool::get_discovery_capability()` is separate from `has_permission()` on purpose. The latter answers "may this call proceed with these arguments" and usually needs a target id the manifest cannot supply, so listing a tool would otherwise depend on inventing arguments for it. A tool the current user could never call is never advertised.
- **Per-screen scoping:** `AdminScreen` + `AdminToolSet` map post editor / post list / settings / review queue to distinct tool sets. The discovery notes record Shopify shipping uniform tools across page types and some breaking where they did not apply; an agent cannot distinguish a tool that is wrong for the screen from one that is right. The review queue gets reads only — an agent proposing changes from the screen where a human reviews proposals would be arguing with itself.
- **Nonce lifecycle:** admin calls carry `X-WP-Nonce`; `/webmcp/nonce` issues replacements. The bridge retries a stale nonce exactly once, keyed on the server's `refresh` flag rather than the 403 status, because a genuine capability failure arrives as 403 too and retrying it just burns a request. The frontend payload carries no nonce — one bound to a session an anonymous visitor does not have would imply an authentication story the public surface lacks.
- **Surface:** `WebMcp` feature service, `WebMcpPolicy`, `AdminScreen`, `AdminToolSet`, `WebMcpTool` contract, `AdminTool`, `WebMcpController` routes (`/webmcp/manifest`, `/webmcp/execute`, `/webmcp/nonce`), `bridge.js` namespace-probing loader with nonce refresh and `saltus-webmcp-toolchange` re-registration, `wp saltus webmcp manifest|validate`, the `webmcp` block in health output, filters `saltus/framework/webmcp/tools`, `saltus/framework/webmcp/admin_tools`, `saltus/framework/webmcp/manifest`, `saltus/framework/webmcp/public_fields`, `saltus/framework/webmcp/client_identifier`, `saltus/framework/webmcp/output_budget`, the guide `docs/guides/webmcp.md` (tool table generated by `composer docs:webmcp`), and the discovery docs `docs/discovery/webmcp.md` and `docs/discovery/webmcp-declarative-forms.md`.
- **Testing:** `bridge.js` is covered by `tests/js/bridge.test.js` on Node's built-in runner (`npm test`) rather than PHPUnit — it is the only WebMCP component that runs in a browser, and its most important behavior is registering and logging nothing when the API is absent, which is the common case. The two load-bearing 8B guards were mutation-tested: removing nonce enforcement fails 2 tests, bypassing the proposal queue fails 2.
- **Declarative forms:** evaluated and rejected for 8B. Codestar renders field titles as `<h4>` rather than `<label>`, emits no `id` on any of its 45 field types' inputs, carries zero `aria-` attributes, and composes bracketed names (`book_options[isbn]`), so a derived schema would have no property descriptions and would bypass `MetaFieldProvider`'s normalized dotted paths. Metaboxes also have no form of their own. The same defects are WCAG 2.1 findings affecting every admin screen today; the fix is vendored-code work tracked separately. See `docs/discovery/webmcp-declarative-forms.md`.
- **Research:** Cloudflare two-phase consumer/producer rollout and Shopify read/steer/no-transact split recorded in `docs/discovery/webmcp.md`, including the adoption data that bounds the scope.

## Naming & Standards
- **Quality Assurance:** PHP CodeSniffer (PHPCS) ensures adherence to WordPress coding standards, while PHPStan handles static analysis to catch type errors and logical bugs early.
- **Testing:** Automated tests are powered by PHPUnit, ensuring framework stability across different WordPress and PHP versions.
