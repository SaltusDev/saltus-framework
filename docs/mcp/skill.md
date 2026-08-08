---
title: Saltus MCP Skill
---

# Saltus MCP Skill

A ready-to-use agent skill for connecting AI clients to the `saltus/*` MCP/Abilities surface. Drop it into your agent's skills directory and it will guide the agent through health checks, discovery, safe reads, and confirmed writes — without you repeating the integration rules.

## Download

[Download `SKILL.md`](/downloads/saltus-mcp/SKILL.md)

Or fetch it directly:

```bash
curl -L -o saltus-mcp-SKILL.md \
  https://raw.githubusercontent.com/SaltusDev/saltus-framework/main/.agents/skills/saltus-mcp/SKILL.md
```

## Why use it

Saltus exposes 25 `saltus/*` abilities over WordPress-native MCP/Abilities. Clients that treat them as raw REST calls tend to:

- guess post type slugs instead of discovering them with `list_models`
- write meta before checking the normalized field paths from `list_meta_fields`
- retry permission failures that will not resolve until roles change
- fire broad list queries as a discovery shortcut, tripping rate limits
- force-delete without explicit confirmation

The skill encodes the guardrails the framework already expects — the health-first call flow, normalized metadata reasoning, read-confirm-write confirmation, and permission/rate-limit handling — so any agent that loads it behaves correctly out of the box.

## How to install

Copy `SKILL.md` into your agent's skills folder. For example, for a filesystem-based agent:

```bash
mkdir -p ~/.agents/skills/saltus-mcp
curl -L -o ~/.agents/skills/saltus-mcp/SKILL.md \
  https://raw.githubusercontent.com/SaltusDev/saltus-framework/main/.agents/skills/saltus-mcp/SKILL.md
```

The skill front matter declares when it applies:

```yaml
name: saltus-mcp
description: >-
  Use when connecting an AI agent/client to Saltus Framework MCP/Abilities
  (saltus/*) on an active WordPress site — discovery, health-first flow,
  model/meta inspection, safe read/write patterns, permission and rate-limit
  handling, and write confirmation flows.
```

Agents that auto-load skills by description will pick it up whenever a task mentions connecting to Saltus MCP or working with `saltus/*` tools.

## How it works

The skill is a single self-contained `SKILL.md` organized around the client integration guide:

1. **Discovery** — filter abilities for the `saltus/` prefix and trust the ability metadata (`meta.mcp_tool`, `meta.namespace`, `meta.transport`).
2. **Health first** — call `saltus/get-health` at session start and branch on `status`, `error_rate`, and latency.
3. **Model and metadata discovery** — `saltus/list-models` and `saltus/list-meta-fields` before touching content; prefer `normalized.fields` paths.
4. **Safe reads** — list, confirm, then single-get with small page sizes.
5. **Safe writes** — read model/meta, build a minimal patch, summarize to the user, execute, then re-read to confirm.
6. **Destructive actions** — require explicit confirmation for force deletes, bulk deletes, reordering, settings updates, and serialized-meta writes.
7. **Failure handling** — never retry permission failures; respect `retry_after` on rate limits; confirm mutations against the post-write cache clear.
8. **Planning heuristics** — an intent-to-first-tools table and copy-paste prompt guidance for agents.
9. **Anti-patterns** — the behaviors to avoid.

## Example

A task like "update the director of the movie with the slug interstellar" resolves through the skill's flow:

1. Call `saltus/get-health` → `status: ok`.
2. Call `saltus/list-models` → find the `movie` post type.
3. Call `saltus/get-meta-fields` with `post_type: "movie"` → normalized path `director` maps to the writable meta root.
4. Call `saltus/list-posts` with `post_type: "movie"`, `search: "interstellar"` → confirm the post ID with the user.
5. Call `saltus/get-post` → read current `director` value.
6. Summarize: "Update `director` on post 42 (Interstellar) from 'Nolan' to 'Christopher Nolan'?"
7. Call `saltus/update-post` with `post_id: 42`, `meta: { director: "Christopher Nolan" }`.
8. Call `saltus/get-post` again → report the confirmed value.

## Keeping it in sync

- The authoritative copy lives in the repository at `.agents/skills/saltus-mcp/SKILL.md`.
- The docs site serves a copy at `/downloads/saltus-mcp/SKILL.md` for direct download.
- The skill mirrors [Client Integration](/mcp/clients); when the client guidance changes, update both.

## Further Reading

- [MCP/Abilities Overview](/mcp/index)
- [Abilities Reference](/mcp/abilities)
- [Client Integration](/mcp/clients)
