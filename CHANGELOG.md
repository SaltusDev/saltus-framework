# Changelog


## [Unreleased]

Thirty-three review findings across Phases 15, 16, and 17. Nothing here has been tagged; the observability surface described under [2.1.1] is what a 2.1.1 site actually runs.

### Added
	- **Metrics chart.** The dashboard draws one bar per rolled-up day as inline SVG, with distinct error, loading, empty, and plotted states. It previously emitted a chart surface with no visualization, so an operator saw an empty region that read as missing data or a broken screen. No charting dependency and no build step were added. Bar height is not a value assistive technology can read, so the same figures ship as a data table inside a native `<details>` disclosure — keyboard-reachable without a custom widget — and the SVG is labelled by its heading and summary.
	- Every response-derived number on the dashboard passes through a coercion seam, so one missing field degrades its own cell to an announced placeholder instead of throwing and blanking the whole screen. The estimated call total gets its own card, gated on sampling so it cannot read as a duplicate of the recorded count.
	- `npm run assets:manifest` generates asset manifests from the assets themselves: dependencies from the `wp.*` namespaces the script reads, version from a content hash over the script and its stylesheet. The dashboard manifest was hand-maintained for an asset no build step produces, so its version and dependency list could only drift from what it describes. Manifests are listed explicitly rather than globbed, because an enqueue has to opt in by requiring the file.
	- `RollupWriteWatcher` decorates the audit database seam and reports whether a rollup write landed. `RollupStore` writes through the seam and discards the result, so the retention pass wraps its adapter and asks afterwards. Adding a method to the `AuditDatabase` interface was avoided deliberately: it is public API with implementors across the suite. Only rollup-table writes are judged, and only an explicit `false`, so an upsert that changed nothing does not read as a loss.
	- `--model` and `--strict` on `wp saltus config validate`. The roadmap marked both delivered while the command implemented neither, so the advertised CI use case was absent — warnings could never exit non-zero. `--model` narrows the summary and problem table to one model and fails on a name no result carries, rather than reporting zero models validated, which a typo in a CI invocation would otherwise pass forever. `--strict` merges warnings into the problem table behind a severity column, since a warning would otherwise render identically to a registration-blocking error. Default output is unchanged.
	- Filters `saltus/framework/mcp/audit/rollup_catch_up_days` to bound how many days one rollup pass will backfill, and `saltus/framework/mcp/audit/staleness_threshold_hours` to set when `wp saltus metrics health` calls rollups stale. The staleness bound was hardcoded at one hour, so a legitimately quiet site always reported unhealthy.
	- A rollup now carries an estimated call count that extrapolates only the non-failing remainder, and the metrics window exposes a per-rate breakdown beside its single sample rate.
	- 19 `node:test` cases covering the dashboard without a browser, on a hook runtime honouring effect dependencies and cleanups. The decisive case answers the newer request first, then the older one, and asserts the stale figure never reaches the screen.

### Changed
	- **Breaking.** `Core::get_service_classes()` is a protected instance method again. It became `public static` in a patch release so the schema builder could reach the service list without an instance, which broke consumer subclasses both ways: a non-static override fatals, and a static one is bypassed entirely because `self::` never dispatches to it, so a customised service list silently stopped being used. Nothing needs the static form now that the schema builder carries its own key list, and no bridge is left behind. A subclass override is honoured again, pinned by a boot test. The signature has now moved twice; this is where it stays.
	- **Breaking.** `RelationshipManager::has_relationships()` is renamed `has_readable_relationships()` and its meaning is narrowed to relationships the caller may actually read. It previously reported on declared relationships without consulting the read policy, so a post type whose every relationship the caller may not read still registered a metabox, a list column, and a picker that render nothing. Metabox registration, the post-list column, and picker registration now all depend on read permission. All three callers are migrated with no alias left behind: a method named for declaration returning false while relationships exist would be a lie.
	- **Breaking.** A denied relationship read at REST and MCP returns 403 `rest_relationship_forbidden` instead of 200 with an empty list. A caller previously could not tell a relationship it may not read from one that is genuinely empty; the rejection path that produces the 403 existed and was called from nowhere, so the read half of the policy diverged from the write half. The definition listing still filters rather than refusing, because a hard error there would let one denied relationship hide every other one the caller may read.
	- **Breaking.** `AbstractCommand::format()` accepts `csv`, so a command that advertises csv in its synopsis now renders real CSV instead of falling back to a table with no error. `WP_CLI\Utils\format_items()` renders it natively, so accepting it was a one-word change. Every command's declared options were audited: two that already advertised csv are now truthful, and none became inaccurate.
	- **Breaking.** The rollup table's `client_identifier` is `varchar(191) NOT NULL DEFAULT ''`, with the empty string reserved as the aggregate-row sentinel and kept unreachable from the identifier space — an audit identifier of the empty string could previously overwrite the aggregate row it collides with. Existing tables are repaired automatically on upgrade. Rollup `DB_VERSION` is `1.2.1`, which brings already-stamped MySQL tables back through introspection once; audit `DB_VERSION` is `1.1.0`, so a marker written when only the audit table existed cannot suppress the slow-call table's create for its TTL.
	- The unknown-key warning recognizes only the seven registered service ids a model config is actually read for, each carrying a comment naming the reader that proves it, alongside the twelve keys `BaseModel`/`PostType`/`Taxonomy` read directly. The service half derived from every registered id, so twelve ids only meaningful under `features` counted as valid top-level keys and both the warning and the generated reference accepted keys nothing reads. Deriving was wrong in one direction and a hand-written short list proved wrong in the other — that draft omitted `options`, `settings`, `frontend`, and `blocks`, so every normal config emitted spurious warnings, which under WP-CLI print to stdout and corrupted `--format=json`. Both lists are now pinned by tests.
	- `ConfigValidator` uses the shared nearest-key trait instead of a private copy of the same logic, so the validator and every contributor agree on one distance threshold. No behaviour change: the trait's threshold is the value the deleted constant held.
	- `docs/guides/relationships.md` no longer describes read-denied relationships as returning empty from every read method and from the REST GET route. It states which surfaces refuse and which filter, why the distinction exists, and that `read` and `write` are the only recognized operations.
	- The generated config key reference lists nineteen top-level keys rather than thirty-one, dropping the twelve it advertised that nothing reads at depth zero.

### Fixed
	- The rollup 1.2.0 migration guarded its column and index statements with `IF NOT EXISTS` and `IF EXISTS`, which MariaDB accepts and MySQL rejects, so a MySQL site recorded 1.2.0 while the index half never applied. The version was written unconditionally, so a table whose version option was absent was stamped without being repaired and the guard then refused to retry. The repair is now decided by reading `information_schema` and issuing plain portable DDL, and the version is recorded only after a second read confirms the schema converged. An advisory lock means one runner repairs.
	- The rollup duplicate collapse ran unbounded and unlocked from a path every metrics read reaches. It is now bounded per pass, so a table with many duplicates cannot stall a request.
	- Client-mode rollups added to the aggregate series instead of replacing it. Totals previously changed meaning depending on the `rollup_by_client` filter. Both series are written, and both reads are bounded with deterministic ordering and paging.
	- Slow-call pruning sat behind the normal audit retention early return, so configuring audit retention as unlimited disabled slow-call pruning too and that table grew without honouring its own setting. The audit prune is split into its own method and the slow-call prune runs beside it unconditionally. The slow table was created only inside the slow path, so retention on an install with no recorded slow call deleted from a missing table and logged a database error every run; it is now created on the shared schema path behind the same verified transient, which also stops the schema statement being issued on every slow call and adding database work to already-slow requests.
	- Relationship synchronization is atomic. Sync cleared a post's relationships and rewrote them across several statements with no boundary, so a failure partway left forward and reciprocal rows disagreeing and every later read inherited the inconsistency. The clear and every write run in one transaction, rolling back on a returned `false` or a thrown failure. Nesting takes a savepoint rather than restarting, because MySQL has no nested transactions and a second `START TRANSACTION` would commit the first. Table creation is forced ahead of the boundary, since DDL implicitly commits and would silently end it. The in-process backend honours the same contract by snapshotting its rows, so atomicity does not depend on a database being present.
	- A rollup's error rate ignored the `sample_rate` stored with the row. Failures bypass the sampling draw, so a sampled row holds every error but only a fraction of everything else, and the ratio came out materially wrong on any sampled site. The rate now divides by the estimated call count, which extrapolates the non-failing remainder only — scaling the whole recorded count would inflate the failures too. Identical to the previous value at rate 1.0. A row with no calls estimates zero at any rate, and a corrupted row cannot estimate below its failure count.
	- A metrics window carrying more than one sample rate collapsed to not-sampled with a null rate, so metrics spanning a configuration change were presented as exact counts with no notice. Sampling is reported whenever any rollup in the window was sampled, and the single rate stays a single value only when the window has one. The window's error-rate divisor accumulates the per-rollup estimates, leaving reported counts and latency as recorded rows.
	- The metrics ability filter is unslashed before sanitizing, so a name containing a quote matches instead of silently selecting nothing.
	- Capability normalization kept any non-empty string key, so a misspelled relationship operation was stored and never consulted. Only the operations the policy defines are kept. Config validation still names the offending key with a suggestion, because the config rules read the raw declaration.
	- `wp saltus metrics --since` is validated as a calendar date through a strict format parse with a round-trip comparison. The value was cast to string and used as a date bound with no format check, so an empty value widened the window to every stored rollup and a malformed one reported no metrics — both indistinguishable from having no data. Parsing alone accepts an out-of-range day and an unpadded month that sorts wrongly against the stored dates, hence the round-trip.
	- The dashboard enqueue requires the generated manifest unconditionally from beside the assets it versions. It repeated the manifest's dependencies inline with a different version, so the two copies had already drifted and a missing root path would silently take the stale one. Core Chart.js is no longer enqueued; nothing uses it now that the chart is inline SVG.
	- A stylesheet-only edit now busts the asset URL. The style enqueue shares the script's version, and the hand-maintained version was a release number, so a CSS change shipped under a cached URL. The generated version hashes the stylesheet as well.
	- A superseded dashboard metrics request is aborted and its response ignored, so a slow earlier fetch can no longer overwrite newer data.
	- The dashboard sampling notice no longer carries `role=note`, which is not a defined ARIA role.
	- The test `do_action` stub records the tag and arguments instead of no-opping, so a test can assert an action fired or, more usefully, that it did not. It still does not dispatch: `add_action()` there only remembers registrations, so dispatching would fire hooks no test asked for.
	- The MCP relationship read test registers the controller's routes, matches the tool's own request against the registered patterns, and invokes the matching callback, which is what shows the tool and the gated handler are the same endpoint. The stub `rest_do_request` answers with a canned response rather than routing, so asserting through it proved nothing about the gate.

### Security
	- The metrics table existence check passes its table name as a prepared value. It was the one unprepared statement among the audit queries.
	- A misspelled capability operation can no longer leave a relationship unenforced while appearing protected. Normalization dropped the unrecognized key silently and only config validation reported it, so a site that does not run validation believed a rule applied when nothing consulted it.
	- Metabox, post-list column, and picker registration are gated on read permission, so those surfaces are not offered for relationships the caller may not read.

## [2.1.1] - 2026-08-19

### Added
	- **Daily audit rollups.** `RollupStore` aggregates the audit log into `{prefix}saltus_mcp_audit_rollups` (`DB_VERSION` 1.2.0), one row per day per ability, so a metrics read scans a bounded summary table instead of the raw log. Each `DailyRollup` carries call, error, exception, validation-error, and rate-limited counts alongside average, p50, p95, p99, and maximum duration. A completion marker (`saltus_mcp_rollup_last_completed_at`) records what has been rolled up and doubles as the backfill cursor.
	- Rollups can be computed per client as well as in aggregate, with the empty `client_identifier` reserved for the aggregate row. Filters `saltus/framework/mcp/audit/rollup_enabled` and `saltus/framework/mcp/audit/rollup_by_client` control both.
	- **Audit sampling.** `saltus/framework/mcp/audit/sample_rate` (default `1.0`, so sampling is off until configured) retains a fraction of normal audit rows on a busy site. Failures bypass the draw entirely, so error visibility is never lost to sampling, and the rate that produced a row is stored with it. `saltus/framework/mcp/audit/sample_value` is a seam for deterministic tests.
	- **Slow-call log.** A call exceeding `saltus/framework/mcp/audit/slow_threshold_ms` (default 5000) is persisted to `{prefix}saltus_mcp_audit_slow_calls` independently of audit sampling, so the slowest calls are never the ones sampled away. Pruned on its own `saltus/framework/mcp/audit/slow_retention_days` (default 90).
	- **Metrics dashboard.** An admin screen reading rollups through a `wp_ajax_saltus_get_metrics` endpoint, gated on a `saltus_metrics` nonce and the `manage_options` capability.
	- **`wp saltus metrics`** with `summary`, `per-tool`, `per-client`, and `health` subcommands, taking `--since`, `--ability`, and `--format`.
	- `GET /saltus-framework/v1/health` reports rollup freshness under a `rollups` key, and marks the framework degraded when rollups are stale and there is audit traffic to roll up. Stale metrics that look fresh are worse than metrics that admit they are behind.
	- `PermissionSurfaceTest` asserts every registered REST route carries a permission callback, so a new route cannot reach production publicly readable by omission.

### Changed
	- PHPStan no longer treats PHPDoc types as certain (`treatPhpDocTypesAsCertain: false`). The audit payload arrays are documented as `mixed` but narrowed by `is_numeric()` checks at runtime, and with PHPDoc trusted, level 7 called those checks redundant on values the database can hand back as strings.

## [2.1.0] - 2026-08-14

### Added
	- **Per-relationship permissions.** A relationship can declare `capabilities` with `read` and `write` capability lists, and one policy resolves it for REST, MCP, WP-CLI, and the metabox UI so a denied relationship is denied everywhere. Previously, clearing `edit_posts` for a model granted access to every relationship it declares. A relationship with no rule is unaffected regardless of the caller's capabilities — this deliberately does not deny by default, because adding the feature must not change what an existing site exposes.
	- `RelationshipPermissionPolicy` enforces access at the manager choke-point: every read (`get_related_ids`, `get_related`, `get_related_for_posts`, `describe`) and every write (`attach`, `detach`, `sync`) routes through it, so REST, MCP, WP-CLI, and the admin surfaces cannot disagree.
	- Reciprocals inherit the declaring side's capabilities, so a rule on `movie.actors` also gates `person.acted_in`. Both write the same row — gating one side but not the other would make the rule a bypass rather than a guard.
	- Read-denied relationships are filtered from `describe()` rather than refused, so one private relationship does not hide every other one on the post type. Write-denied operations return `WP_Error` with `rest_relationship_forbidden` and a hint naming the relationship and the operation.
	- Cascade deletion bypasses read gates: resolving targets for cleanup is about referential integrity, not user permissions. Routing it through the gated read would let a read-denied user delete a post and silently strand every cascade target, leaving rows pointing at a post that no longer exists.
	- Metabox picker renders read-denied relationships as omitted and write-denied relationships as read-only: values stay visible but the control is a plain list with no inputs and no picker script binding, so re-enabling it in devtools yields no submittable field. The save path re-checks write permission per relationship before syncing, so a read-only render does not clear data when saved.
	- Post-list columns and bulk actions check read/write permissions before offering the UI, so surfaces do not tease capabilities the user does not have.
	- The addon filter contract: `saltus/framework/relationships/capabilities` adjusts the capability list before resolution, and `saltus/framework/relationships/can` can override the verdict. Both pass primitives (strings, arrays) rather than objects so sibling addons — whose namespace is rewritten by Strauss and cannot import framework classes — can still impose rules.
	- `RelationshipConfigRules` validates the `capabilities` declaration and reports malformed rules as errors rather than warnings, because a permission rule that silently does not apply is worse than a loud refusal.
	- 35 new tests (21 policy, 14 surface enforcement) covering resolution, reciprocal inheritance, manager gates, metabox rendering, the cascade exemption, and the addon filter contract.
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
