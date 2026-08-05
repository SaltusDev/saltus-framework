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
- **Decision:** `AiAssistantProvider` exposes fixed, model-scoped actions through `saltus/framework/ai/assistant_actions`. The framework owns permissions, context normalization, REST validation, and editor controls; consuming plugins own provider credentials and network calls.
- **Scope:** Post editor screens only. Existing posts use `edit_post`; new posts use `edit_posts`. Suggestions require explicit editor confirmation before changing fields.

### 12. Reflection-Based Dependency Injection
- **Purpose:** Let framework consumers register services with ordinary typed and positional constructors.
- **Decision:** `ReflectionInstantiator` is the default instantiator for both `ServiceContainer` and `GenericContainer`. It resolves named dependencies first, then positional values, compatible typed objects, defaults, and nullable parameters.
- **Compatibility:** Services that accept one `array $dependencies` parameter continue to receive the full dependency bag. Unresolvable required parameters fail with `FailedToMakeInstance::UNRESOLVED_ARGUMENT`.

### 13. AI Editorial Review Queue
- **Purpose:** Queue mutating AI/MCP changes for human approval before they are applied, enforcing the `human-review` governance flag end-to-end.
- **Decision:** `AbilityRuntime` gates every mutating tool behind `has_permission()` before governance, rate-limit, or dispatch work. When `ProposalService::should_queue()` matches a mutating tool, the runtime stores the change as a `pending` proposal via `ProposalStore` instead of dispatching it. A reviewer then approves or rejects the proposal through the REST review API or the "AI Review Queue" admin page; `approve()` applies the stored mutation and records the resulting post id.
- **Surface:** `EditorialReview` service, `EditorialReviewController` routes (`/proposals`, `/proposals/{id}`, `/proposals/{id}/approve`, `/proposals/{id}/reject`), and the `saltus-ai-review` admin management page.

## Naming & Standards
- **Quality Assurance:** PHP CodeSniffer (PHPCS) ensures adherence to WordPress coding standards, while PHPStan handles static analysis to catch type errors and logical bugs early.
- **Testing:** Automated tests are powered by PHPUnit, ensuring framework stability across different WordPress and PHP versions.
