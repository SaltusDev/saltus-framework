---
name: saltus-mcp
description: "Use when connecting an AI agent/client to Saltus Framework MCP/Abilities (saltus/*) on an active WordPress site — discovery, health-first flow, model/meta inspection, safe read/write patterns, permission and rate-limit handling, and write confirmation flows."
compatibility: "Requires WordPress 6.9+ with the Abilities API and an active plugin built on Saltus Framework. Annotations and the HTTP verb they imply are part of that 6.9 surface; WordPress 7.1 adds the `public` intent flag and intent-based discovery. Client-side skill; no PHP required on the agent side."
---

# Saltus MCP

Connect an AI client to the WordPress-native MCP/Abilities surface exposed by Saltus Framework.

## When to use

Use this skill when the task involves:

- consuming `saltus/*` abilities from an AI client or editor agent,
- discovering what post types, taxonomies, meta fields, and tools a Saltus site exposes,
- reading or writing WordPress content through Saltus tools,
- diagnosing `saltus/*` call failures (permissions, params, rate limits, model visibility).

## Prerequisites

- A running WordPress site (6.9+) with the Abilities API.
- An active plugin using Saltus Framework (registers `saltus/*` abilities on `wp_abilities_api_init`).
- Client access to the abilities (discovery list, or a configured tool list).
- The site URL and a user with the needed WordPress capabilities.

## Discovery

- Discover abilities from WordPress and filter for the `saltus/` prefix.
- Treat the ability definitions as the source of truth for tool names, input schemas, permissions, and transport metadata.
- Each Saltus ability carries metadata like:

```json
{
  "meta": {
    "mcp_tool": "list_models",
    "namespace": "saltus-framework/v1",
    "transport": "wordpress-rest",
    "show_in_rest": true
  }
}
```

- Use `meta.mcp_tool` for user-facing tool names and logs. Use the ability name (e.g. `saltus/list-models`) for native execution.

## Recommended call flow

1. `saltus/get-health`
2. `saltus/list-models`
3. `saltus/list-meta-fields`
4. Choose the relevant model
5. `saltus/get-model` or `saltus/get-meta-fields` for detail
6. Read content with `saltus/list-posts`, `saltus/get-post`, `saltus/list-terms`, or settings tools
7. Propose changes to the user
8. Execute writes only after target model, fields, and permissions are clear

For narrow workflows that already know the post type, skip the aggregate metadata call and use `saltus/get-meta-fields` directly.

## Health first

Call `saltus/get-health` at session start.

| Health signal | Client behavior |
|---------------|-----------------|
| `status: ok` | Continue normally |
| `status: degraded` | Prefer read-only planning; explain the degraded state before writes |
| High `error_rate` | Avoid repeated retries; inspect permission and input errors |
| High latency | Reduce broad listing calls; keep page sizes modest |
| Rate limit enabled | Respect rate-limit errors and `retry_after` data |

Health is framework-scoped; it does not prove a specific model or write is available.

## Model discovery

- Use `saltus/list-models` to discover post types and taxonomies. Never assume a model exists from a user phrase.
- Map user language to model names after reading labels and slugs; prefer exact slugs.
- If multiple models are plausible, ask the user to choose.
- Treat missing models as configuration or permission issues, not empty content.

## Metadata discovery

- Use `saltus/list-meta-fields` for site-wide discovery; `saltus/get-meta-fields` for one post type.
- Saltus returns raw config in `meta` and normalized data in `normalized.fields` (flattened paths) plus `normalized.rest_meta_keys`.
- Prefer `normalized.fields` for reasoning. Example paths:

```text
points_info.coordinates.latitude
points_info.coordinates.longitude
points_info.tooltipContent
```

- When writing post meta, map the field path back to its writable REST meta root. Update the containing serialized structure carefully; do not send only the leaf path.

## Safe read patterns

1. `saltus/list-posts` with `post_type`, `search`, `status`, and pagination.
2. Ask the user to confirm the target when the search result is ambiguous.
3. `saltus/get-post` with the confirmed `post_id`.

Keep list queries small; use `per_page` values that fit the task. For terms: discover the taxonomy with `saltus/list-models`, call `saltus/list-terms`, and use returned term IDs in create/update calls.

## Safe write patterns

Before creating or updating, know: target post type, target post ID (for updates/deletes), writable meta roots, expected field shape, current user intent, and the relevant capability outcome.

Write flow:

1. Read current model and metadata.
2. Read the target post or settings.
3. Build a minimal patch.
4. Summarize the planned mutation to the user.
5. Execute the mutation.
6. Read the object again to confirm the result.

Avoid broad writes (many posts from one instruction) unless the exact target list is shown and confirmed.

## Destructive actions

Require explicit confirmation for:

- force deletion
- bulk deletion
- reordering more than a small visible set
- settings updates
- writes to serialized or nested meta

Prefer trashing over force deletion unless the user explicitly asks for permanent deletion.

## Permission failures

Saltus permissions are WordPress permissions; never bypass them.

| Failure | Likely cause | Client response |
|---------|--------------|-----------------|
| `rest_forbidden` | Current user lacks capability | Explain required access and stop |
| `invalid_params` | Missing or malformed arguments | Fix arguments once, then retry |
| model not found | Model not registered / not REST-enabled / not visible | Ask for a different model or admin config |
| write denied | User can read but not mutate | Offer a read-only summary instead |

Do not retry permission failures repeatedly; they are stable until role or model config changes.

## Rate limits

- Stop parallel calls after the first rate-limit error.
- Use the returned `retry_after` value when available.
- Prefer cached or already-read context while waiting.
- Avoid broad discovery loops that repeatedly call the same list tools.

## Caching

Saltus may cache read-only responses in WordPress transients. Repeated reads may return cached data. To confirm a mutation: execute the write, let Saltus clear its MCP cache, then read the object again. Mutating tools clear the cache after execution.

## Planning heuristics

| User intent | First tools |
|-------------|-------------|
| "What content types are available?" | `saltus/get-health`, `saltus/list-models` |
| "Show me entries for X" | `saltus/list-models`, `saltus/list-posts` |
| "Edit field Y on item Z" | `saltus/list-models`, `saltus/get-meta-fields`, `saltus/list-posts`, `saltus/get-post` |
| "Create a new X" | `saltus/list-models`, `saltus/get-meta-fields`, `saltus/create-post` |
| "Change plugin settings" | `saltus/list-models`, `saltus/get-settings`, `saltus/update-settings` |
| "Export this item" | `saltus/list-posts`, `saltus/get-post`, `saltus/export-post` |
| "Reorder items" | `saltus/list-posts`, user confirmation, `saltus/reorder-posts` |

## Prompt guidance

Ground tool use in a short internal plan:

```text
First check Saltus health. Then list models. Then discover metadata for the target post type. Do not write until the target post ID, field path, and new value are confirmed.
```

For destructive work:

```text
Before deletion or force deletion, show the exact post ID, title, post type, and deletion mode. Require explicit confirmation.
```

For nested meta:

```text
Use normalized field paths for reasoning, but write through the REST meta root reported by Saltus. Preserve sibling fields in serialized structures.
```

## Anti-patterns

- guessing post type slugs without `list_models`
- writing meta before checking normalized metadata
- retrying permission failures
- using large list queries as a discovery shortcut
- force deleting without explicit confirmation
- treating health `ok` as proof that all model-scoped capabilities are enabled
- assuming every Saltus model allows every REST-backed capability

## Reference

- Main docs: https://docs.saltus.dev/mcp/
- Abilities reference: https://docs.saltus.dev/mcp/abilities.html
- Client integration guide: https://docs.saltus.dev/mcp/clients.html
