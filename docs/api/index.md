---
title: API Reference
---

# API Reference

This section contains auto-generated API documentation for the Saltus Framework's public interfaces and classes.

## Overview

The API reference is generated from PHPDoc annotations using phpDocumentor. It covers the public API surface — interfaces, classes, and methods tagged with `@api`.

## Key Namespaces

| Namespace | Description |
|-----------|-------------|
| `Saltus\WP\Framework` | Core entry point and modeler |
| `Saltus\WP\Framework\Models` | Model interface, PostType, Taxonomy |
| `Saltus\WP\Framework\Rest` | REST controllers, route providers, policies |
| `Saltus\WP\Framework\MCP` | MCP/Abilities infrastructure |
| `Saltus\WP\Framework\MCP\Tools` | MCP tool interfaces and implementations |
| `Saltus\WP\Framework\Infrastructure\Container` | DI container interfaces |
| `Saltus\WP\Framework\Infrastructure\Plugin` | Plugin lifecycle interfaces |
| `Saltus\WP\Framework\Infrastructure\Service` | Service framework interfaces |
| `Saltus\WP\Framework\Features` | Feature service implementations |

## Browse the Reference

The full generated class reference — every class, interface, method, and property — is at
[/api/reference/](/api/reference/).

## Configuration Reference

[Config Reference](/api/config-reference) documents every key a model config may declare,
generated from `SchemaBuilder` — the same schema the config validator consumes.

## Generating the API Docs

```bash
composer docs:api      # phpDocumentor class reference
composer docs:config   # model config reference
composer docs:all      # every generator, including the two above
```

`docs:api` writes phpDocumentor's HTML to `build/docs/api/`, which the site build stages into
`/api/reference/`. It requires phpDocumentor to be installed. See the
[Build Guide](/guides/build) for setup instructions.
