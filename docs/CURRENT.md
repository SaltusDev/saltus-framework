# Current: Live Working State

## Working
- Tag v1.0 release @since 2026-06-19

## Next

## Recent Changes
- WordPress 7.0 MCP/Abilities connector: `AbilityRegistrar` registers 15 Saltus tool definitions when `wp_register_ability()` exists
- Shared `ToolFactory` keeps stdio MCP and native ability definitions on the same tool list
- Native ability callbacks dispatch through `rest_do_request()` so existing REST permission callbacks remain authoritative
- Added compatibility tests covering native ability registration, capability gating, and REST-backed dispatch
- README documents both WordPress-native MCP client discovery and standalone stdio MCP client setup
- Phase 3 progress: 4 of 9 items completed
- Skipped SSE transport: Serve MCP over HTTP for remote connections
- Skipped Multi-site management: Named site profiles, switchable at runtime
- Skipped Role-based access: Map MCP tool access to WP user roles
- Skipped Health monitoring: Endpoint with version, error rate, latency stats
- Skipped Configuration profiles: `--profile=high-volume`, `--profile=strict`
- Structured error codes: ErrorCode constants + McpError value object with resolution hints
- Caching layer: CacheInterface + InMemoryCache integrated into WordPressClient GET
- Rate limiting: Sliding-window RateLimiter throttles tool calls (default 60/60s)
- Audit trail: AuditLogger writes JSON tool call records to STDERR and optional file
- Config: 9 new env vars for cache, rate limit, and audit configuration
- 103 new PHPUnit tests (208 total, 551 assertions) covering all 4 Phase 3 features
- PHPStan Level 7 clean across all new MCP code
- PHP 8.3+ required for `str_starts_with()` in McpError
- Code review: Config constructor refactored to array bag pattern (#49 — medium)
- Code review: McpError dead ternary removed (#49 — low)
- Code review: RateLimitResult split into own file (#49 — low)
- Code review: Unused getDefaultMessage() removed (#49 — low)

## Known Issues
- Reference `phpstan_errors.txt` for current static analysis warnings/errors (Level 7 clean for MCP + Rest, 318 pre-existing errors in legacy core code).
- Code review flagged RateLimitResultTest.php as added (3 new tests, 559 assertions).
- Code review 4/4 findings resolved.
