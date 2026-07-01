# Context: Architecture & Decisions

## Architecture
The framework is built to be included within a WordPress plugin. It uses models (PHP/JSON/YAML) to define Custom Post Types and Taxonomies.
It searches for model files by default in the `src/models` directory.

### Initialization
```php
if ( class_exists( \Saltus\WP\Framework\Core::class ) ) {
    $framework = new \Saltus\WP\Framework\Core( dirname( __FILE__ ) );
    $framework->register();
}
```

## Key Decisions

### 1. Codestar Framework Integration
- **Purpose:** Used for rapidly building complex settings pages, metaboxes, and options panels.
- **Decision:** Instead of building a custom options framework from scratch, Codestar provides a robust, heavily tested foundation under a GPL license.
- **Trade-offs:** We maintain custom patches (`lib/codestar-framework/patches`) to tailor its behavior to our specific framework needs. This means updates to Codestar require manually re-applying patches via a script.

### 2. SoberWP/Models (Simplified)
- **Purpose:** Provides a declarative approach to registering Custom Post Types (CPTs) and Taxonomies.
- **Decision:** A simplified version of SoberWP/Models was included directly in the framework. This abstracts the repetitive and verbose WordPress core functions (`register_post_type`, `register_taxonomy`) into clean, easily readable PHP arrays, JSON, or YAML configuration files (models). 
- **Impact:** Speeds up development time for developers of any skill level by relying on simple configuration rather than complex procedural code.

### 3. Composer Classmap Autoloading
- **Purpose:** Handling legacy or third-party library loading.
- **Decision:** The project migrated from requiring 'files' directly to using Composer's `classmap` for libraries like the Codestar Framework.
- **Impact:** Enhances performance and standardizes autoloading. However, it introduces a manual step: developers must run `composer dump-autoload` whenever new classes are added to these mapped directories.

### 4. GitHub Updater Support
- **Purpose:** Plugin update management.
- **Decision:** Includes native support for `afragen/github-updater`.
- **Impact:** Allows plugins built with this framework to receive seamless updates directly from the WordPress admin dashboard, bypassing the need to host plugins on the official WordPress.org repository.

### 5. WordPress-Native MCP/Abilities
- **Purpose:** Expose Saltus model, content, settings, and metadata operations to AI clients through WordPress-native MCP/Abilities.
- **Decision:** WordPress 7.0 Abilities is the supported MCP path. The local stdio MCP server was removed; SSE transport and standalone server distribution remain out of scope.
- **Metadata:** `list_meta_fields` exposes all registered CPT meta configs through `GET /saltus-framework/v1/meta`; `get_meta_fields` exposes one CPT through `GET /saltus-framework/v1/meta/{post_type}`.
- **Current Shape:** Metadata responses preserve the raw Saltus/Codestar config in `meta` and add `normalized.fields` plus `normalized.rest_meta_keys` for client write guidance.
- **Normalized Metadata:** Nested Codestar fields are flattened into paths such as `points_info.coordinates.latitude`, with label, source meta key, serialized status, REST writability, and JSON-schema-like type data.

## Naming & Standards
- **Quality Assurance:** PHP CodeSniffer (PHPCS) ensures adherence to WordPress coding standards, while PHPStan handles static analysis to catch type errors and logical bugs early.
- **Testing:** Automated tests are powered by PHPUnit, ensuring framework stability across different WordPress and PHP versions.
