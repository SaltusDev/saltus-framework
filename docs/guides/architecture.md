# Architecture

## Overview

Saltus Framework follows a service-oriented architecture with a dependency injection container at its core. The framework bootstraps through the `Core` class, which registers all services, routes, and tool providers.

```
Core (Plugin interface)
  |
  |- ServiceContainer (DI container)
  |    |- Registers 16 feature services
  |    |- Two-pass registration: unconditional for routes/tools,
  |    |   gated via is_needed() for hooks/assets
  |
  |- Modeler (RestRouteProvider + ToolContributor)
  |    |- Scans {project}/src/models/ for config files
  |    |- Creates PostType or Taxonomy instances via ModelFactory
  |    |- Exposes get_models(), REST routes, and MCP tools
  |
  |- RestServer
  |    |- Registers 17 REST routes in saltus-framework/v1/
  |    |- Uses ModelRestPolicy + CapabilityPolicy for gating
  |
  |- MCP/Abilities
       |- 20 MCP tools registered as saltus/* abilities
       |- Middleware pipeline (permission, validation, audit, cache, rate-limit)
       |- Backed by REST controllers
```

## Key Components

### Core (`src/Core.php`)
The main entry point. Initializes the service container, registers all services, and hooks into WordPress's `init` action.

### Modeler (`src/Modeler.php`)
Loads model configuration files from the project's `src/models/` directory and registers them as WordPress post types and taxonomies.

### Service Container (`src/Infrastructure/Container/`)
A PSR-11-compatible dependency injection container with support for:
- Service registration and resolution
- Conditional service loading via `is_needed()`
- Factory-based instantiation
- Assembly-based wiring
- Reflection-based constructor resolution through `ReflectionInstantiator`

### Constructor Resolution

Services registered in `ServiceContainer` or `GenericContainer` may use normal constructor parameters. `ReflectionInstantiator` resolves each parameter in this order:

1. A dependency with the parameter's name.
2. A positional dependency at the parameter's index.
3. An object compatible with the parameter's declared class or interface.
4. The parameter's default value.
5. `null` when the parameter allows it.

If no value can be resolved, the container throws `FailedToMakeInstance` with the unresolved parameter and target class. Existing services using a single `array $dependencies` constructor continue to receive the complete dependency bag.

### REST API (`src/Rest/`)

Eleven REST controllers register 17 routes under the `saltus-framework/v1/` namespace:

| Route | Methods | Controller |
|---|---|---|
| `/models` | GET | `ModelsController` |
| `/models/{post_type}` | GET | `ModelsController` |
| `/meta` | GET | `MetaController` |
| `/meta/{post_type}` | GET | `MetaController` |
| `/meta/{post_type}/{post_id}` | PUT | `MetaController` |
| `/settings/{post_type}` | GET, PUT | `SettingsController` |
| `/duplicate/{post_id}` | POST | `DuplicateController` |
| `/export/{post_id}` | GET | `ExportController` |
| `/reorder` | POST | `ReorderController` |
| `/blocks` | GET | `BlocksController` |
| `/health` | GET | `HealthController` |
| `/context/{post_type}` | GET | `AiContextController` |
| `/ai-assistant/{post_type}/{post_id}/{action}` | POST | `AiAssistantController` |
| `/proposals` | GET | `EditorialReviewController` |
| `/proposals/{id}` | GET | `EditorialReviewController` |
| `/proposals/{id}/approve` | POST | `EditorialReviewController` |
| `/proposals/{id}/reject` | POST | `EditorialReviewController` |

Model-scoped routes are gated by `ModelRestPolicy`. `/health` and the `/proposals` routes are framework-scoped and do not require per-model opt-in.

### MCP/Abilities (`src/MCP/`)
WordPress-native MCP/Abilities integration exposing 20 tools through a middleware pipeline with caching, rate limiting, audit logging, and permission gating.

### AI Governance (`src/Features/AiContext/`, `src/Features/EditorialReview/`, `src/Features/AiAssistant/`)

Three services layer governance over the MCP surface:

- `AiContextProvider` normalizes each model's `ai_context` config and validates mutations before REST dispatch, rejecting forbidden actions and disallowed statuses.
- `ProposalService` and `ProposalStore` queue mutating tool calls as pending proposals when a model requires human review; a reviewer applies or discards them through the REST review API or the AI Review Queue admin page.
- `AiAssistantProvider` exposes model-scoped editor actions. The framework owns permissions and validation; consuming plugins supply the provider credentials and network calls through a filter.

## Design Decisions

### Two-Pass Service Registration
REST route providers and MCP tool contributors are registered unconditionally during plugin boot to ensure endpoints appear in the WordPress REST index. All other services (admin screens, scripts, frontend hooks) are gated behind `is_needed()` to avoid loading unnecessary code.

### Middleware Pipeline
Permission, validation, error formatting, audit, cache, and rate-limit logic each exist in exactly one place — a composable middleware pipeline shared by both REST and MCP paths.
