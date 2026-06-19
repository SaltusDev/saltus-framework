# Current: Live Working State

## Working
- Tag v1.0 release @since 2026-06-19
- Implement audit trail logging @since 2026-06-19

## Next
- SSE transport: Serve MCP over HTTP for remote connections
- Multi-site management: Named site profiles, switchable at runtime
- Role-based access: Map MCP tool access to WP user roles
- Health monitoring: Endpoint with version, error rate, latency stats
- Configuration profiles: `--profile=high-volume`, `--profile=strict`

## Blocked
- (none)

## Recent Changes
- Phase 3 progress: 4 of 9 items completed
- Structured error codes: ErrorCode constants + McpError value object with resolution hints
- Caching layer: CacheInterface + InMemoryCache integrated into WordPressClient GET
- Rate limiting: Sliding-window RateLimiter throttles tool calls (default 60/60s)
- Audit trail: AuditLogger writes JSON tool call records to STDERR and optional file
- Config: 9 new env vars for cache, rate limit, and audit configuration
- 103 new PHPUnit tests (208 total, 551 assertions) covering all 4 Phase 3 features
- PHPStan Level 7 clean across all new MCP code
- PHP 8.3+ required for `str_starts_with()` in McpError

## Known Issues
- Reference `phpstan_errors.txt` for current static analysis warnings/errors (Level 7 clean for MCP + Rest, 318 pre-existing errors in legacy core code).
