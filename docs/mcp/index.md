---
title: MCP/Abilities Overview
---

# MCP/Abilities Overview

Saltus Framework exposes its AI-facing tool surface through WordPress-native MCP/Abilities. Native WordPress MCP clients can discover and call the `saltus/*` abilities directly from the active plugin.

## Quick Start

Install and activate the plugin that uses Saltus Framework on a WordPress version with the Abilities API. Saltus registers its abilities during `wp_abilities_api_init`; clients that understand WordPress-native MCP/Abilities can discover the `saltus/*` tools from WordPress.

## Available Tools

| Tool | Description |
|------|-------------|
| `get_health` | Framework health, version, error rate, latency, cache, and rate-limit status |
| `list_models` | List all registered CPTs and taxonomies |
| `get_model` | Get details of a specific post type or taxonomy |
| `list_posts` | Query posts with filters (status, search, pagination) |
| `get_post` | Get a single post with all fields and meta |
| `create_post` | Create a new post in any CPT |
| `update_post` | Update an existing post's fields and meta |
| `delete_post` | Trash or force delete a post |
| `list_terms` | List terms from a taxonomy |
| `create_term` | Create a new term in a taxonomy |
| `duplicate_post` | Duplicate a WordPress post |
| `export_post` | Export a post as WXR |
| `get_settings` | Get Saltus settings for a post type |
| `update_settings` | Update Saltus settings for a post type |
| `reorder_posts` | Batch update post menu order |
| `list_meta_fields` | Discover Saltus meta field definitions across all registered CPTs |
| `get_meta_fields` | Get Saltus meta field definitions for a post type |
| `update_meta_fields` | Update meta field values for a post |
| `list_block_models` | List post type models with their registered list and single blocks |
| `get_context` | Get the AI governance context for a model |

## Requirements

- WordPress 7.0+ with the Abilities API
- A WordPress-native MCP/Abilities client
- The plugin using Saltus Framework must be active

## Further Reading

- [Abilities Reference](/mcp/abilities) — full parameter details for every tool
- [Client Integration](/mcp/clients) — integration guide, workflow, and anti-patterns
- [Saltus MCP Skill](/mcp/skill) — why, how, and example for the ready-to-use agent skill
- [Download the Saltus MCP Skill](/downloads/saltus-mcp/SKILL.md) — agent skill for connecting AI clients to `saltus/*` abilities
