# Current: Live Working State

## Working
- Nothing in progress. The 2026-08-19 fix pass is complete and committed: thirty-seven findings closed across Phases 15, 16, and 17, leaving two open findings that both need a design decision before code (16.3 and 16.4, below) @since 2026-08-19

## Next
- **Awaiting a decision, not implementation.** Both remaining findings are open because the correct behaviour is a judgement call, not because the work is unscoped:
  - **16.3 [high] Reconcile reciprocal relationship permissions explicitly** — a reciprocal currently inherits the declaring side's capabilities. Both directions write the same row, so gating one side only would leave the other a bypass. The decision is whether the stricter side wins, or whether each direction is declared independently and a mismatch is a config-validation error
  - **16.4 [high] Bypass user-facing read filtering during privacy cascade erasure** — cascade discovery runs through the same read path that now returns 403 for a denied relationship, so an erasure request may not see rows it is legally required to delete. The decision is where the internal unfiltered query lives and how authorization stays enforced at the request boundary rather than at discovery
- [Phase 16 — v2.1.0 Review](ROADMAP.md#phase-16--v210-review--79) is 7/9. [Phase 15](ROADMAP.md#phase-15--v185-review-x-55) (5/5) and [Phase 17](ROADMAP.md#phase-17--v211-review-x-2727) (27/27) are closed
- Feature work — [Phase 10 Remainder](ROADMAP.md#phase-10-remainder), Phases 11–13, and Phases 6–7 — stays parked until 16.3 and 16.4 clear

## Blocked
- 16.3 and 16.4 are blocked on a design decision from the maintainer. No roadmap task declares `@requires`, so nothing else in the backlog waits on an unmet dependency

## Recent Changes
- **Thirty-seven review findings closed in one pass, 2026-08-19**, committed as atomic commits over `feature/mcp-v1` after the `v2.1.1` tag. Twelve agents worked concurrently, then the whole tree was validated as a unit rather than trusting the individual reports @since 2026-08-19
- **17.5 closed: static-analysis strictness restored repository-wide.** `treatPhpDocTypesAsCertain: false` is gone from `phpstan.neon`. The contradictions it hid are fixed at the source: `AuditDatabase::get_results()` declares a return type conditional on the requested row format and `WpdbAuditDatabase` normalizes the associative case, so the declared shape is a guarantee. Three checks that re-tested what the seam and a native `?string` parameter already guarantee were removed, along with two `WP_Error` branches that `WP_REST_Server::dispatch()` cannot reach — it converts errors at all three exits and then calls `set_matched_route()` on the result unconditionally. Three ignores removed, none added @since 2026-08-19
- **17.27 closed: the release is documented.** `CHANGELOG.md` cuts a `[2.1.1]` section for the observability surface as tagged, with the post-tag fix pass under `[Unreleased]`. The 2.1.1 entry is left describing what that tag actually shipped, including the phpstan setting it carried @since 2026-08-19
- **16.1 closed: the rollup schema migration is portable.** The repair is decided by reading `information_schema` and issuing plain DDL, the version is recorded only after a second read confirms convergence, and an advisory lock means one runner repairs. 17.24 and 17.25 travelled with it as planned @since 2026-08-19
- **Test and analysis state: 1369 PHPUnit tests, 3560 assertions, 0 failures; PHPStan level 7 clean with no global certainty relaxation; 51 `node --test` JS tests across three suites; phpcs clean** @since 2026-08-19

## Known Issues
- 2 open review findings, both Phase 16 highs awaiting a design decision (16.3, 16.4). Phases 15 and 17 are closed
- Version numbering is still not fully reconciled: `package.json` is at 2.1.1 with a matching `v2.1.1` tag, while the historical `v1.4.2`/`v2.0.0` tags remain unexplained in the version line
- Roadmap cross-references use GitHub-style heading anchors, which VitePress does not generate: the built site emits `phase-15-—-v1-8-5-review-✗-0-5` where the markdown links say `phase-15--v185-review-x-55`. Every count-bearing phase anchor is therefore broken on the published site and silently re-breaks whenever a count changes. Not filed as a finding yet
- Composer script names are `test`, `test:phpstan`, and `test:phpcs` — bare `composer phpstan` / `composer phpcs` do not exist

## Handoff
- The `v2.1.1` tag and every commit after it are local only. Pushing the branch and the tag is a deliberate manual step
- ROADMAP.md and this file are maintained by the docs-update phase; CONTEXT.md, PROJECT.md, DESIGN.md, and the build guide are maintained by the docs-sync phase
- Generated docs must be refreshed with `composer docs:all` whenever a tool, WP-CLI command, or WebMCP tool is added, renamed, or has its schema changed
- Review-backlog phases are append-only until a cycle explicitly works them: do not tick Phase 15/16/17 checkboxes as a side effect of unrelated changes
