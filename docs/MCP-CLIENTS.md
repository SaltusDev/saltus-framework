# MCP Client Integration Guide

This guide is for WordPress-native MCP/Abilities clients that consume Saltus Framework abilities from an active WordPress site.

Saltus registers `saltus/*` abilities inside WordPress. Clients should treat the ability definitions as the source of truth for available tools, input schemas, permissions, and transport metadata.

## Client Goals

A good Saltus MCP client should:

- discover available `saltus/*` abilities before planning actions
- check site health before making a workflow plan
- inspect models and meta fields before reading or writing content
- use normalized metadata paths when reasoning about nested custom fields
- handle WordPress capability failures without retry loops
- respect rate limits and cacheable read operations
- ask for explicit user confirmation before destructive or broad writes

## Recommended Call Flow

Use this sequence for most client sessions:

1. Call `saltus/get-health`.
2. Call `saltus/list-models`.
3. Call `saltus/list-meta-fields`.
4. Choose the relevant model.
5. Call `saltus/get-model` or `saltus/get-meta-fields` for model-specific detail.
6. Read content with `saltus/list-posts`, `saltus/get-post`, `saltus/list-terms`, or settings tools.
7. Propose changes to the user.
8. Execute writes only after the target model, fields, and permissions are clear.

For narrow workflows where the client already knows the post type, it can skip the aggregate metadata call and use `saltus/get-meta-fields` directly.

## Discovery

Clients should discover abilities from WordPress and filter for the `saltus/` prefix.

Every Saltus ability includes metadata similar to:

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

Use `meta.mcp_tool` for user-facing tool names and logs. Use the ability name, such as `saltus/list-models`, for native client execution.

## Health First

Call `saltus/get-health` at the start of a session. A healthy response tells the client that Saltus' runtime controls are available and gives recent audit-derived error and latency information.

Suggested handling:

| Health signal | Client behavior |
|---------------|-----------------|
| `status: ok` | Continue normally |
| `status: degraded` | Prefer read-only planning and explain the degraded state before writes |
| High `error_rate` | Avoid repeated retries; inspect permission and input errors |
| High latency | Reduce broad listing calls and keep page sizes modest |
| Rate limit enabled | Respect rate-limit errors and retry-after data |

Health is framework-scoped. It does not prove that a specific model or write operation is available.

## Model Discovery

Use `saltus/list-models` to discover post types and taxonomies exposed by Saltus. Clients should not assume a model exists based only on a user phrase.

Recommended behavior:

- Map user language to model names after reading model labels and slugs.
- Prefer exact model slugs when calling tools.
- If multiple models are plausible, ask the user to choose.
- Treat missing models as configuration or permission issues, not as empty content.

## Metadata Discovery

Use `saltus/list-meta-fields` for site-wide field discovery. Use `saltus/get-meta-fields` for one post type.

Saltus returns both raw and normalized metadata:

- `meta`: raw Saltus/Codestar model configuration
- `normalized.fields`: flattened field paths for client reasoning
- `normalized.rest_meta_keys`: REST-writable roots and type information

Clients should prefer `normalized.fields` when explaining or mapping field-level work.

Example normalized paths:

```text
points_info.coordinates.latitude
points_info.coordinates.longitude
points_info.tooltipContent
```

When writing post meta, clients should map the desired field path back to its writable REST meta root. If a field is nested in serialized meta, update the containing structure carefully rather than sending only the leaf path.

## Safe Read Patterns

Use list operations before single-item operations:

1. `saltus/list-posts` with `post_type`, `search`, `status`, and pagination.
2. Ask the user to confirm the target when the search result is ambiguous.
3. `saltus/get-post` with the confirmed `post_id`.

Keep list queries small. Use `per_page` values that fit the user's task instead of pulling large collections by default.

For terms:

1. Discover taxonomy models with `saltus/list-models`.
2. Call `saltus/list-terms` with the taxonomy slug or REST base expected by the tool.
3. Use returned term IDs for post create/update calls when needed.

## Safe Write Patterns

Before creating or updating content, clients should know:

- target post type
- target post ID for updates/deletes
- writable meta roots
- expected field shape
- current user intent
- relevant capability outcome

Recommended write flow:

1. Read current model and metadata.
2. Read the target post or settings.
3. Build a minimal patch.
4. Summarize the planned mutation to the user.
5. Execute the mutation.
6. Read the object again to confirm the result.

Avoid broad writes such as updating many posts from one instruction unless the client can show the exact target list and the user confirms it.

## Destructive Actions

`saltus/delete-post`, `saltus/reorder-posts`, and settings updates can materially change the site. Clients should require explicit confirmation for:

- force deletion
- bulk deletion
- reordering more than a small visible set
- settings updates
- writes to serialized or nested meta

Prefer trashing over force deletion unless the user explicitly asks for permanent deletion.

## Permission Failures

Saltus permissions are WordPress permissions. Clients should not try to bypass them.

Common responses:

| Failure | Likely cause | Client response |
|---------|--------------|-----------------|
| `rest_forbidden` | Current user lacks capability | Explain the required access and stop |
| `invalid_params` | Missing or malformed arguments | Fix arguments once, then retry |
| model not found | Model is not registered, not REST-enabled, or not visible to this user | Ask for a different model or admin configuration |
| write denied | User can read but not mutate the target | Offer a read-only summary instead |

Do not retry permission failures repeatedly. They are usually stable until the user's role or model configuration changes.

## Rate Limits

Saltus rate limiting protects WordPress from excessive ability calls. When a call returns a rate-limit error, clients should respect the error data and wait before retrying.

Recommended behavior:

- Stop parallel calls after the first rate-limit error.
- Use the returned `retry_after` value when available.
- Prefer cached or already-read context while waiting.
- Avoid broad discovery loops that repeatedly call the same list tools.

## Caching

Saltus may cache read-only ability responses in WordPress transients. Clients can still call read tools normally, but should understand that repeated calls may return cached data.

For workflows that must confirm a mutation:

1. Execute the write.
2. Let Saltus clear its MCP cache.
3. Read the changed object again.

Mutating Saltus tools clear the MCP cache after execution.

## Client Planning Heuristics

Use these heuristics when planning autonomous workflows:

| User intent | First tools |
|-------------|-------------|
| "What content types are available?" | `saltus/get-health`, `saltus/list-models` |
| "Show me entries for X" | `saltus/list-models`, `saltus/list-posts` |
| "Edit field Y on item Z" | `saltus/list-models`, `saltus/get-meta-fields`, `saltus/list-posts`, `saltus/get-post` |
| "Create a new X" | `saltus/list-models`, `saltus/get-meta-fields`, `saltus/create-post` |
| "Change plugin settings" | `saltus/list-models`, `saltus/get-settings`, `saltus/update-settings` |
| "Export this item" | `saltus/list-posts`, `saltus/get-post`, `saltus/export-post` |
| "Reorder items" | `saltus/list-posts`, user confirmation, `saltus/reorder-posts` |

## Example Workflows

### Inspect Available Saltus Content

1. Call `saltus/get-health`.
2. Call `saltus/list-models` with `type: "all"`.
3. Summarize exposed post types and taxonomies.
4. Mention if no models are visible or if health is degraded.

### Update a Custom Meta Field

1. Call `saltus/list-models`.
2. Identify the post type.
3. Call `saltus/get-meta-fields`.
4. Find the normalized field path.
5. Call `saltus/list-posts` to locate the item.
6. Call `saltus/get-post`.
7. Build a minimal meta update.
8. Ask for confirmation.
9. Call `saltus/update-post`.
10. Call `saltus/get-post` again and report the confirmed value.

### Create a New CPT Entry

1. Call `saltus/list-models`.
2. Call `saltus/get-meta-fields` for the chosen post type.
3. Collect required title/content/meta/term data from the user.
4. Call `saltus/create-post`.
5. Read the new post and summarize its ID, status, title, and important meta.

### Diagnose Client Errors

1. Call `saltus/get-health`.
2. Check recent error rate and latency.
3. Confirm the requested ability exists.
4. Confirm the target model is visible through `saltus/list-models`.
5. Confirm required parameters match `docs/MCP-ABILITIES.md` or the discovered input schema.
6. Stop if the error is permission-related.

## Editor And VS Code Guidance

For editor agents and future VS Code integrations:

- Show discovered Saltus models before offering write operations.
- Show the exact post IDs or setting keys affected by a mutation.
- Surface normalized meta paths in pickers/autocomplete.
- Keep ability call logs visible enough for debugging.
- Prefer staged edits or previews before `update_post`, `update_settings`, `delete_post`, or `reorder_posts`.
- Use `get_health` as a connection/status check in the extension UI.

## Prompt Guidance For Clients

Clients can improve reliability by grounding tool use in a short internal plan:

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

## Anti-Patterns

Avoid these client behaviors:

- guessing post type slugs without `list_models`
- writing meta before checking normalized metadata
- retrying permission failures
- using large list queries as a discovery shortcut
- force deleting without explicit confirmation
- treating health `ok` as proof that all model-scoped capabilities are enabled
- assuming every Saltus model allows every REST-backed capability

## Reference

- Main MCP docs: [MCP.md](MCP.md)
- Generated ability reference: [MCP-ABILITIES.md](MCP-ABILITIES.md)
