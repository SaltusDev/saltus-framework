# Current: Live Working State

## Working
- Nothing in progress. Phases 15, 16 and 17 are closed: the 2026-08-19 fix pass took thirty-seven findings, and 16.3 and 16.4 — the two held for a design decision — closed 2026-08-20 @since 2026-08-20

## Next
- Feature work resumes: [Phase 10 Remainder](ROADMAP.md#phase-10-remainder) (the query-builder facade, then the ACF/Toolset/Pods migration scripts), then Phases 11–13 and Phases 6–7
- [Phase 16 — v2.1.0 Review](ROADMAP.md#phase-16--v210-review-x-99) is 9/9. [Phase 15](ROADMAP.md#phase-15--v185-review-x-55) (5/5) and [Phase 17](ROADMAP.md#phase-17--v211-review-x-2727) (27/27) are closed

## Blocked
- Nothing. No roadmap task declares `@requires`, so nothing in the backlog waits on an unmet dependency

## Recent Changes
- **16.3 closed: mutual reciprocal declarations reconcile into one row, 2026-08-20.** The finding's premise did not hold — a *synthesized* reciprocal already inherited capabilities — and the real defect sat beside it: when both sides are hand-declared and each names the other, neither is synthesized, so both stayed forward sides reading `from_post_id`. One relationship was stored as two half-relationships; a cross-side read returned empty and a cross-side detach reported success while changing nothing. The lower-sorting endpoint now owns the row and the other is rebuilt as its inverse, tied to the endpoint sort rather than model load order. The shared row's `capabilities` resolve to the owner's rule when it declared one and the far side's otherwise — a declared rule is never dropped, since the side left without one would read around it — and both sides carry the survivor. Self-referential pairs reached the same break a second way, deriving two different keys because equal endpoints leave the name pair unordered; their mismatched *derived* keys now reconcile while explicitly keyed declarations are left alone, so no stored row moves @since 2026-08-20
- **16.4 closed: an unreadable privacy cascade is reported, not skipped.** The unfiltered read the task asked for already existed at `RelationshipManager::stored_related_ids()`; what was missing was a caller reaching it. Silently bypassing the rule was rejected: `erase_others_personal_data` is authority over every relationship, so a read rule blocking one is a contradictory configuration, and overriding it quietly hides the mistake exactly as skipping quietly hides the incomplete erasure. `cascade_targets_for_erasure()` reads unfiltered and returns the cascade relationships it could not read alongside the targets it could; `Privacy::erase()` retains and names them through core's eraser messages. Confined to cascade-declaring relationships, so one an erasure would never follow raises nothing @since 2026-08-20
- **Thirty-seven review findings closed in one pass, 2026-08-19**, committed as atomic commits over `feature/mcp-v1` after the `v2.1.1` tag. Twelve agents worked concurrently, then the whole tree was validated as a unit rather than trusting the individual reports @since 2026-08-19
- **17.5 closed: static-analysis strictness restored repository-wide.** `treatPhpDocTypesAsCertain: false` is gone from `phpstan.neon`. The contradictions it hid are fixed at the source: `AuditDatabase::get_results()` declares a return type conditional on the requested row format and `WpdbAuditDatabase` normalizes the associative case, so the declared shape is a guarantee. Three checks that re-tested what the seam and a native `?string` parameter already guarantee were removed, along with two `WP_Error` branches that `WP_REST_Server::dispatch()` cannot reach — it converts errors at all three exits and then calls `set_matched_route()` on the result unconditionally. Three ignores removed, none added @since 2026-08-19
- **Test and analysis state: 1388 PHPUnit tests, 3617 assertions, 0 failures; PHPStan level 7 clean with no global certainty relaxation; 51 `node --test` JS tests, 0 failures; phpcs reports 0 errors, with 28 pre-existing warnings in 6 `src/` files** @since 2026-08-27

## Known Issues
- 0 open review findings. Phases 15, 16 and 17 are all closed
- Version numbering is still not fully reconciled: `package.json` is at 2.1.1 with a matching `v2.1.1` tag, while the historical `v1.4.2`/`v2.0.0` tags remain unexplained in the version line
- Roadmap cross-references use GitHub-style heading anchors, which VitePress does not generate: the built site emits `phase-15-—-v1-8-5-review-✗-0-5` where the markdown links say `phase-15--v185-review-x-55`. Every count-bearing phase anchor is therefore broken on the published site and silently re-breaks whenever a count changes. Not filed as a finding yet
- Composer script names are `test`, `test:phpstan`, and `test:phpcs` — bare `composer phpstan` / `composer phpcs` do not exist
- `composer tests` exits non-zero on a clean checkout: `test:phpcs` reports 28 warnings across 6 `src/` files (`MetricsApi`, `MetricsCommand`, `AuditLogger`, `RollupStore`, `SchemaBuilder`, `RelationshipRegistry`), 20 of them auto-fixable with `composer fix:phpcbf`. Present at HEAD and unrelated to any current change. Not filed as a finding yet

## Handoff
- The `v2.1.1` tag and every commit after it are local only. Pushing the branch and the tag is a deliberate manual step
- Re-running the version cycle picks up from a clean tree; its bump phase is a no-op for 2.1.1, since those strings are already committed
- ROADMAP.md and this file are maintained by the docs-update phase; CONTEXT.md, PROJECT.md, DESIGN.md, and the build guide are maintained by the docs-sync phase
- Generated docs must be refreshed with `composer docs:all` whenever a tool, WP-CLI command, or WebMCP tool is added, renamed, or has its schema changed
- Review-backlog phases are append-only until a cycle explicitly works them: do not tick Phase 15/16/17 checkboxes as a side effect of unrelated changes
