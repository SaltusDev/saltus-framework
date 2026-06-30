---
name: Saltus Framework 1
description: Saltus Framework helps you develop WordPress plugins that are based on Custom Post Types.
type: project
homepage: https://saltus.io/
repository: https://github.com/SaltusDev/saltus-framework
---

# Saltus Framework Identity and Metadata

## Overview
Saltus Framework is designed to make things easier and faster for developers with different skills to develop WordPress plugins based on Custom Post Types. It allows adding metaboxes, settings pages, and other enhancements with minimal code.

## Key Metadata
- **Package Name:** `saltus/framework`
- **Requires:** PHP >= 7.4
- **License:** GPL-3.0-only
- **Authors:** Saltus Plugin Framework (web@saltus.io)

## Core Capabilities & Features
- **Rapid Custom Post Type (CPT) Creation**: Define robust CPTs via simple array or YAML configurations.
- **Taxonomy Management**: Easily register hierarchical ('category') or non-hierarchical ('tag') taxonomies and associate them with CPTs.
- **Advanced Administration Interfaces**:
  - Control all labels and messages
  - Add custom administration columns and lists
  - Add settings pages and custom metaboxes
- **Data Management**:
  - Enable one-click post cloning
  - Single entry export functionality
  - Built-in drag-and-drop reordering
- **Extensibility**: Provides a robust set of hooks (`actions` and `filters`) to customize the framework's behavior (e.g., duplicate post data, admin filter queries, modeler priorities).

## Core Concepts

### Models
The framework operates primarily on a **Model-driven** architecture. A model file defines a single or multidimensional array of configuration that instructs the framework what to build. Models are usually placed in `src/models/` (by default).

There are two primary model types:
1. **`cpt` (Custom Post Type)**: Defines a custom post type, its features, supported WordPress core features, block editor status, metaboxes, and settings.
2. **`category` or `tag` (Taxonomies)**: Defines custom taxonomies and associations to existing Custom Post Types.

### Hooks System
Saltus Framework provides a comprehensive hook system to modify its internal data structures and output. Examples include overriding admin filter HTML output, filtering duplicated post data, and adjusting the directory path for models dynamically.
