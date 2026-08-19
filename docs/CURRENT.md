# Current: Live Working State

## Working
- Nothing in flight. The v2.1.1 patch cycle closed on `feature/mcp-v1`: 19 atomic commits `aa5a5d9`..`871dead`, with an annotated `v2.1.1` tag at `871dead`. Neither the branch nor the tag has been pushed @since 2026-08-19

## Next
- [Phase 16 — v2.1.0 Review](ROADMAP.md#phase-16--v210-review--09): 9 open findings (5 high, 4 medium), approved 2026-08-17 as the next code work
- [Phase 17 — v2.1.1 Review](ROADMAP.md#phase-17--v211-review--227): 25 open findings (11 medium, 14 low). 17.1 (audit sampling range) and 17.2 (rollup upsert atomicity) were fixed in this cycle; 17.3–17.27 remain
- [Phase 15 — v1.8.5 Review](ROADMAP.md#phase-15--v185-review--05): 5 open findings (3 medium, 2 low)
- The precise next task is chosen by the plan-next phase, which refines this file

## Blocked
- None known

## Recent Changes
- **v2.1.1 released 2026-08-19** as a patch cycle over `feature/mcp-v1`. `package.json` was already at 2.1.1, so the bump was a no-op; the cycle committed the pending work as 19 atomic commits `aa5a5d9`..`871dead` and created an annotated `v2.1.1` tag at `871dead`. Not pushed @since 2026-08-19
- **Phase 14 (Observability) shipped.** `RollupStore`/`DailyRollup` pre-aggregate the MCP audit table into one row per day and ability, at schema `DB_VERSION` 1.2.0 with a portable migration and a single atomic upsert. The `Observability` feature adds the metrics dashboard plus `MetricsApi` and the dashboard assets, `wp saltus metrics` exposes the same data on the command line, and the health endpoint now reports rollup freshness @since 2026-08-19
- **Per-relationship capability enforcement shipped.** `RelationshipPermissionPolicy` resolves a relationship's declared `capabilities` at the `RelationshipManager` choke-point, so REST, MCP, WP-CLI, and the metabox cannot disagree; the declaration is validated, and write-denied pickers render read-only @since 2026-08-19
- **Two review findings fixed in-cycle.** 17.1: audit sampling now draws from a single bounded source via `SAMPLE_PRECISION`, so the value is uniform over 0..1 and the effective retention fraction matches the configured `sample_rate`. 17.2: the rollup write is one atomic upsert whose uniqueness also holds for aggregate rows, so repeated or overlapping rollup runs cannot double-count @since 2026-08-19
- **Review gate passed** with 0 new critical and 0 new high findings. 17.1 and 17.2 were re-verified as fixed. Five new non-blocking findings (17.23–17.27) were appended to Phase 17 in `de5ee0c`, ahead of the release commits @since 2026-08-19
- **Test gate passed:** 1293 PHPUnit tests, 3360 assertions, 0 failures; PHPStan level 7 clean @since 2026-08-19

## Known Issues
- 39 open review findings across three backlog phases: Phase 16 (9), Phase 17 (25), Phase 15 (5). None are critical or high-severity regressions from this cycle — Phase 16's five highs predate it.
- `CHANGELOG.md` cuts a `[2.1.0]` section, but `[Unreleased]` is empty and there is no `[2.1.1]` section yet — tracked as finding 17.27.
- Version numbering is still not fully reconciled: `package.json` is at 2.1.1 with a matching `v2.1.1` tag, while the historical `v1.4.2`/`v2.0.0` tags remain unexplained in the version line.
- `phpstan.neon` sets `treatPhpDocTypesAsCertain: false` globally to keep level 7 quiet on the strengthened runtime guards. Narrowing that back to a targeted suppression is finding 17.5.
- Composer script names are `test`, `test:phpstan`, and `test:phpcs` — bare `composer phpstan` / `composer phpcs` do not exist. The `npm test` JS suite was untouched by this cycle.

## Handoff
- The `v2.1.1` tag and the 19 release commits are local only. Pushing the branch and the tag is a deliberate manual step.
- ROADMAP.md and this file are maintained by the docs-update phase; CONTEXT.md, PROJECT.md, DESIGN.md, and the build guide are maintained by the docs-sync phase. This cycle's docs-sync edits landed in the same commit as this file.
- Generated docs must be refreshed with `composer docs:all` whenever a tool, WP-CLI command, or WebMCP tool is added, renamed, or has its schema changed.
- Review-backlog phases are append-only until a cycle explicitly works them: do not tick Phase 15/16/17 checkboxes as a side effect of unrelated changes.
