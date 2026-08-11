# Changelog


## [Unreleased]

### Added
	- **Field-level permissions.** A meta field can declare `permissions` with `read` and `write` capability lists, and one policy resolves it for REST, MCP, WP-CLI, and WebMCP so a denied field is denied everywhere. Previously, clearing `edit_posts` for a model granted access to every field in it. A field with no rule is unaffected regardless of the caller's capabilities — this deliberately does not deny by default, because adding the feature must not change what an existing site exposes. A rule on a parent governs its nested children, so a denied serialized parent cannot be reconstructed from its parts.
	- **Encryption at rest.** A field declaring `encrypted` is stored as authenticated ciphertext (XChaCha20-Poly1305, with an OpenSSL AES-256-GCM fallback). Keys come from `SALTUS_FIELD_ENCRYPTION_KEY` in `wp-config.php` or the `saltus/framework/field_encryption_key` filter — never the database, since a key stored beside the ciphertext it protects is not encryption. Writing an encrypted field with no key configured is refused rather than silently stored as plaintext.
	- Encrypted fields are rejected from query, sort, and filter arguments with `field_not_queryable` and a hint naming the field. Ciphertext does not compare, so the alternative was a query that silently returned nothing. Encrypted fields are also never exposed on the public WebMCP surface, regardless of the `public_fields` filter.
	- **GDPR export and erasure.** Model meta is registered with core's `wp_privacy_personal_data_exporters` and `..._erasers`, so it appears in the built-in workflow with no configuration. Export decrypts encrypted fields — a data subject request asks what the site holds about a person, and ciphertext answers nothing. Erasure removes meta but keeps posts, matching how core's own erasers anonymize rather than delete, and follows `cascade_delete` relationships so a dependent post cannot retain the subject's data as an orphan.
	- Field denials are audited under their own `field_denied` status rather than the generic `error` used for capability failures, because the two have different fixes. They deliberately do not count toward the health endpoint's error rate: a working security rule is not an outage.
	- `docs/guides/field-security.md` documenting both declarations, the key setup, and what encryption costs.
	- Relationship columns on the post list table, one per relationship, showing up to three related posts as links and summarizing the rest as "+N more". A whole page is resolved in one query per relationship: priming happens on `the_posts`, where the full result set is still available, rather than in the per-row render callback. If priming did not run the cell renders empty rather than falling back to a per-row query — a silent N+1 on an admin list is worse than a blank cell.
	- Bulk **Attach to …** and **Detach from …** actions per relationship. There is no bulk fast path: fifty posts is fifty governed `attach()` calls, each enforcing cardinality and target validity, so a bulk run cannot produce state a single operation would have refused. Capability is checked per relationship when offering the action and again per post inside the loop, since a selection can span posts the user may not all edit.
	- Relationship picker in the post editor. Every post type in a relationship gets a **Relationships** metabox with no configuration: search a post by title, select it, reorder with drag or `Alt`+arrow, remove to detach. One component covers all four cardinalities — `has_one` is the same picker with search disabled once filled, not a second implementation to keep in step.
	- Both sides of a relationship get a picker. Declaring `movie.actors` gives movies an "Actors" field and people an "Acted In" field, because the reciprocal is a first-class relationship rather than a read-only view of one.
	- The picker is keyboard-operable and screen-reader-labelled by construction: `<label for>` bound to the input, `aria-describedby` to the description, arrow-key navigation through results with `aria-selected`, `Alt`+arrow to reorder, and deliberate focus placement after a removal so focus never falls to `<body>`. Result counts and add/remove are announced through a live region that is clipped rather than `display: none`, which would have removed it from the accessibility tree and silenced it.
	- Search queries WordPress core's own `/wp/v2/{post_type}` collection, so core's capability handling applies and Saltus adds no route to secure. A target post type with its own `rest_base` is resolved server-side rather than guessed in the browser.
	- Codestar accessibility fixes, addressing four defects documented during the Phase 8B declarative-forms evaluation: every field type now emits an input `id`, field titles render as `<label for>` instead of `<h4>` (keeping `.csf-title`, so no CSS changed), and a field with a description gets `aria-describedby` linking the two. These are WCAG 2.1 SC 1.3.1 and 4.1.2 findings that affected every Saltus admin screen. `CODESTAR-VENDORED-CHANGES.md` records the patches so a Codestar upgrade can replay them.
	- `docs/ACCESSIBILITY.md` — what was fixed, what remains, and what has not been verified. It states plainly that Saltus has had no independent accessibility audit and that the picker has not been tested with a real screen reader; the automated tests assert the markup is right, not that the announcements are useful.
	- Phase 8B WebMCP admin surface: models adding `webmcp: { admin: true }` expose their capability-gated abilities as tools on the relevant wp-admin screens. Nothing new is authored — `AdminTool` decorates the abilities already registered for MCP/Abilities and `wp saltus`, so the browser surface cannot drift from the other two consumers.
	- **Every mutating WebMCP call is queued for human review, never applied.** Writes route through `ProposalService` to the Phase 6B review queue and the agent receives the proposal id plus a review URL to hand to a person. WebMCP has no settled confirmation model and no authentication story, so writes take the path Saltus already built rather than a new one.
	- Per-screen tool scoping: post editor, post list, settings, and review queue each get a distinct tool set, because an agent cannot distinguish a tool that is wrong for the current screen from one that is right. The review queue gets reads only.
	- `GET /saltus-framework/v1/webmcp/nonce` issues a replacement REST nonce, and the bridge retries a stale one once without the user noticing — an admin screen left open beside an agent conversation will outlive its nonce.
	- `saltus-webmcp-toolchange` window event re-registers the tool set against a fresh `AbortController`, so the browser fires its own `toolchange` and a listening agent re-reads the list instead of planning against tools that no longer exist.
	- `wp saltus webmcp manifest|validate` inspects the surface offline. The surface is otherwise only observable inside a browser implementing an API Chrome ships no earlier than 157, which makes a misconfiguration and an unsupported browser look identical. `validate` catches over-long tool names, `frontend: true` on a non-publicly-queryable model, allowlist typos that fail open, and models enabled for neither surface.
	- Health output and `wp saltus health` report WebMCP registration state and the enabled model count per surface.
	- Filter `saltus/framework/webmcp/admin_tools` adjusts the tool names offered on one admin screen.
	- `ProposalService::is_mutating()` reports whether a tool changes state, separately from `should_queue()`, which reports whether a site's policy requires review for it.
	- `docs/discovery/webmcp-declarative-forms.md` — the declarative forms evaluation, with a no-go recommendation and four documented Codestar accessibility defects.
	- `docs/guides/webmcp.md`, with a tool reference generated from the tool classes by `composer docs:webmcp`. Registered in the docs nav and sidebar.
	- `ResultBudget` clamps a serialized WebMCP result to the 1500-character agent output budget, dropping entries from the longest list before clipping strings and setting `truncated` when it does. Sibling `count` values are corrected so they never overstate what shipped.
	- `ClientIdentity` resolves the caller behind a WebMCP tool call: logged-in callers by user id, everyone else by a salted hash of `REMOTE_ADDR`. No raw visitor IP reaches the audit table.
	- Filters: `saltus/framework/webmcp/output_budget` to raise or lower the result budget, and `saltus/framework/webmcp/client_identifier` to resolve the real client behind a proxy or CDN.
	- First JavaScript test suite: 13 `node:test` cases covering `bridge.js`, including the silent no-op on browsers without a WebMCP surface. Run with `npm test`; no npm dependencies required.

### Changed
	- `WebMcp::is_needed()` now returns true in the admin as well as the frontend. The admin bridge is gated at enqueue time instead, because models are not yet registered when the service container evaluates the gate.
	- `/webmcp/manifest` lists a tool only if the current user could actually call it, so an agent is never handed options that will refuse every invocation.
	- `/webmcp/execute` is now rate-limited per client instead of on a single shared key. Previously one visitor's agent working through a multi-step task could exhaust the 60-call window for every other visitor on the site.
	- WebMCP audit rows now record the resolved client identifier rather than a constant, so browser traffic is attributable per client while staying distinguishable from WordPress-native ability calls.
	- The audit table existence check is gated on a one-hour transient instead of running `CREATE TABLE IF NOT EXISTS` on every request that touches the log. A busy site was sending DDL against the same table from every worker to learn something it already knew. Creation is still not gated on the stored schema version, so a dropped table comes back on its own — within the hour rather than on the very next read. Filter `saltus/framework/mcp/audit/table_check_ttl`; `0` restores the per-request check.

### Fixed
	- Two test-isolation bugs that made the suite fail roughly once in twenty random orderings. `AiContextProvider::validate_mutation()` reads a model name off the stored post when one exists, so a post left behind in the shared test `$wp_posts` global changed an unrelated test class's result — `RelationshipToolsTest` seeded post 7 as a `movie` while `AbilityRuntimeTest` asserts on post 7 expecting a `book`. Both classes now clear the global in `tearDown`; resetting in `setUp` protects the class doing it but not the next one. No production code was involved.
	- A failed audit-table create is no longer recorded as a success. `AuditLogger::ensure_table()` discarded the DDL result, so `ensure_db()` set its one-hour verification transient even when `CREATE TABLE IF NOT EXISTS` was rejected — and every read in that window then queried a table that did not exist and reported zero errors, which is a broken audit log that looks like a healthy one. The transient and the `saltus_mcp_audit_db_version` marker are now written only on success, so a transient database failure retries on the next request instead of being cached for the full TTL.
	- `ResultBudget::shrink_lists()` documented a guarantee it does not make. Dropping every remaining list entry reclaims the list cost, not the whole payload: a result whose scalar keys alone exceed the allowance stays over it, and `clip_strings()` takes the next pass. Behavior is unchanged and was already correct — `apply()` withholds `truncated` when it finds nothing to trim, which is what tells an agent the result is whole rather than silently short.

### Security
	- Admin-surface WebMCP tools require a logged-in user and a valid `wp_rest` nonce. Without the nonce a cross-site page could drive the admin surface using the visitor's own cookies.
	- A mutating WebMCP tool returns 503 when the review queue is unavailable, rather than falling back to a direct write. A missing dependency must not silently become an unreviewed change.
	- Write tools are deliberately not annotated `readOnlyHint`. A queued write still changes state, and that annotation is the only signal that makes an agent pause to confirm with a human.
	- Caller-supplied forwarding headers are deliberately ignored when identifying a WebMCP client. Honoring `X-Forwarded-For` by default would let an agent reset its own rate limit by varying one header; sites behind a trusted edge opt in through the `client_identifier` filter.
	- The public `/webmcp/manifest` `models` key no longer names admin-only post types to callers who could not use them. `admin_models()` deliberately skips the publicly-queryable filter — an admin agent is a capable user, so a private type is legitimately in scope for it — which meant reporting every enabled model on a public route handed anonymous visitors the slug of every private type on the site. Admin slugs are now gated on the same `edit_posts` floor that gates admin tool discovery, so `models` and `tools` agree about who is asking.

## [1.7.0]

### Added
	- Phase 8A WebMCP browser surface: model content exposed to in-browser AI agents through read-only WebMCP tools (list content models, search content, get content, list taxonomy terms, filter content).
	- `WebMcp` feature service registers the bridge script and localizes the tool manifest on public frontend views for models that opt in with `webmcp: { enabled: true, frontend: true }`.
	- `ManifestBuilder` projects Saltus tool definitions into WebMCP descriptors with JSON Schema parameter objects and character budget enforcement.
	- Per-model policy gating via `PublicFieldFilter` that narrows frontend output to public/model-declared fields.
	- Filters: `saltus/framework/webmcp/tools`, `saltus/framework/webmcp/manifest`, and the per-model public field allowlist.
	- Bridge script at `assets/Feature/WebMcp/bridge.js`; no-op on browsers without a WebMCP surface.

## [1.6.0]

### Added
	- AI assistant actions now generate content through the WordPress AI Client, using provider credentials configured under Settings > Connectors. The framework stores no API keys and hardcodes no provider or model.
	- Model `ai_context` (`brand_voice`, `audiences`, `field_rules`) is composed into the system instruction, so prompts are model-scoped.
	- Added the `saltus/framework/ai/prompt_builder` filter to pin a provider or model, or to apply any other builder option before generation.
	- Health output and `wp saltus` now report AI client availability.

### Fixed
	- Fixed single-post REST export so `/saltus-framework/v1/export/{post_id}` returns WXR for only the requested post instead of the entire post type.
	- Fixed framework lifecycle hook registration by allowing `Core` to receive the consuming plugin bootstrap file for activation/deactivation hooks.
	- Tightened WordPress-native MCP/Abilities permission callbacks so mutating post and term tools fail closed when required target arguments are missing.
	- Fixed structured settings updates so nested arrays, booleans, numbers, and null values are preserved while string values and keys are sanitized recursively.
	- Fixed settings sanitization so non-stringable object payloads cannot fatal during REST/MCP settings updates.
	- Fixed single-post REST export so WordPress core download headers from `export_wp()` do not leak into REST responses.
	- Fixed health error-rate reporting so validation and rate-limit outcomes remain visible but do not mark framework health as degraded.
	- Fixed the non-WordPress JSON encoding fallback in `AbilityRuntime`.
	- Cleared the remaining PHPStan Level 7 issues in `Modeler`, REST route registration, and MCP taxonomy REST-base handling.
	- Added an explicit model name accessor contract so `Modeler` no longer depends on concrete model properties.

### Changed
	- Added regression coverage for export isolation, plugin-file lifecycle hook registration, fail-closed MCP ability permissions, and structured settings payloads.
	- Brought `AssetLoader` back under PHPStan analysis with a typed `AssetLoadingService` base class.
	- Removed the standalone stdio MCP server path; WP7 MCP/Abilities is now the only supported MCP transport.
	- Migrated MCP audit logging, rate limiting, and caching to WordPress-native storage around WP7 ability execution.
	- Moved MCP audit retention cleanup from the audit write path to a daily WP-Cron event.

## [2.0.0] - 2026-06-30

### Added
	- MCP/Abilities integration: WordPress 7.0 native ability definitions through AbilityRegistrar
	- MCP protocol server with stdio transport: initialize, tools/list, tools/call, resources/list, resources/read, prompts/list, prompts/get
	- REST API namespace `saltus-framework/v1/` with 9 routes: models, duplicate, export, settings (GET/PUT), meta (aggregate + per-CPT), reorder
	- 16 MCP tools (9 Phase 1 CRUD + 7 Phase 2): models, posts, terms, duplicate, export, settings, reorder, meta fields
	- 3 MCP prompt templates for post/content generation workflows
	- Meta field normalization: nested Codestar fields flattened to explicit paths with JSON-schema-like types
	- ToolFactory for shared tool definitions between MCP and WordPress-native abilities
	- Caching layer (CacheInterface + InMemoryCache) for WordPressClient GET requests
	- Sliding-window rate limiter (default 60 req/60s) for tool calls
	- Audit trail logger with JSON records to STDERR and optional file
	- Structured error codes (ErrorCode constants + McpError value object with resolution hints)
	- Config::fromEnv for environment-variable-based configuration (15 env vars)
	- 243 PHPUnit tests across MCP, REST, and framework core

### Fixed
	- Rate limiter race condition in concurrent test scenarios
	- ModelFactory static make method call operator
	- WalkerTaxonomyDropdown incompatible void return type
	- WordPress key length validation in get_registration_name()
	- Admin columns non-string return from get_edit_term_link
	- Various type safety issues across services, models, assets, admin columns, meta, drag-drop, and container

### Changed
	- Version bumped to 2.0.0
	- Type safety improvements across the entire codebase
	- MCP Server uses ToolFactory for unified tool definitions
	- Config constructor refactored to array bag pattern
	- PHP 8.3+ required (str_starts_with in McpError)
	- PHPStan Level 7 compliance for all src/MCP/ and src/Rest/ code
	- PHPUnit configuration with strict flags, random execution order, and failOn* rules

## [1.3.5] - 2026-06-20
	- Chore: Package it for packagist

## [1.3.4] - 2026-04-07
	- New: Add h3 to meta sections
	- Fix: typo in drag and drop filter
	- Docs: Add docs for patching codestar
	- Docs: Add docs for existing hooks
	- Improvement: Reduce complexity and improve typing safety
	- Chore: Update dependencies
	- Test: Test phpstan level 7

## [1.3.3] - 2026-03-20
	- Rework remember tabs
	- Allow models to pass schema for meta fields
	- Fix schema for other types of meta
	- Wrap registering gutenberger block
	- Allow assets to skip prefixing
	- Allow admin cols to get called on rest api endpoints
	- Allow duplicate to run on rest api endpoints
	- Fix: Patch codestar framework

## [1.3.2] - 2025-09-21
	- Fix: Add Assets data container to allow add_data

## [1.3.1] - 2025-07-06
	- Feature: Quick edit

## [1.3.0] - 2025-05-26
	- Breaking changes: Allow Injection of container type to Assembler
	- Feature: Manage HasAssets to manage assets ( css, js )
	- Feature: Provide Project class
	- Feature: Provide Service Factory
	- Fix: Correct types to be compatible with php84 without warnings
	- Fix: Escape exception messages

## [1.2.1] - 2025-04-16

### Added
	- Allow filter to have a key property to be used when filtering
	- Allow filter to force to use key as value in meta filter
### Changed
	- Update filters to reflect that

## [1.2.0] - 2025-04-08

### Added
	- New hook in Admin Filters
	- Allow meta query params to be passed by model
	- Allow models to overwrite sort type
	- CS and analyzers dependencies
	- Set possible prefixes

### Changed
	- Fix admin filters
	- Improve naming of filters and actions
	- CS, Docs, and optimizations
	- Update admin cols from ext-cpts and small optimizations
	- Change action name for drag and drop to be more descriptive
	- Improve naming of Duplicate nonce and action
	- Simplify parsing of meta box setttings for registering in rest api

### Removed
	- CMB2Meta
	- Demo feature

## [1.1.4] - 2025-02-18

### Changed
	- Move loading codestar from files to classmap

## [1.1.3] - 2025-01-28

### Added
	- Add changelog

## [1.1.2] - 2024-12-10

### Fixed
	- Load models by ascending filename order

## [1.1.1] - 2024-12-10

### Fixed
	- Correct Media meta field type
	- Rename drag and drop action
	- Allow settings meta boxes to have any number of sections

## [1.1.0] - 2024-11-28

### Added
	- Items as CPTs
### Changed
	- Updated Codestar
	- Updated dependencies
### Fixed
	- Save linebreaks on save meta
	- interfaces usage
### Removed
	- p2p integration


## [1.0.2] - 2023-10-20

### Added
	- Remember tabs
### Fixed
	- Fix missing properties
	- Update features to be Processable


## [1.0.1] - 2022-04-20

### Added
	- Meta feature
	- Settings feature
	- Drag and drop feature
	- Duplicate feature
	- Export feature
	- Admin Cols and Admin Filters features

## [1.0.0] - 2019-04-23
	- Initial Release


The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
