# Phase 10A: Content Relationships - Technical Specification

**Version:** 1.0  
**Date:** 2026-08-08  
**Owner:** Lead Engineer  
**Status:** Draft for Review

---

## Document Purpose

This technical specification defines the **implementation details** for Phase 10A: Content Relationships. It serves as the source of truth for engineers building the feature.

**Audience:** Backend engineers, database architects, code reviewers

---

## System Architecture

### High-Level Component Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                    WordPress Core Layer                      │
│                                                              │
│  wp_posts          wp_postmeta         wp_term_relationships│
│  (unchanged)       (unchanged)         (pattern reference)  │
└────────────────────────┬────────────────────────────────────┘
                         │
                         │ reads from
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                 New: wp_saltus_relationships                 │
│                                                              │
│  Stores: relationship connections, pivot data, order        │
└────────────────────────┬────────────────────────────────────┘
                         │
                         │ managed by
                         ▼
┌─────────────────────────────────────────────────────────────┐
│               Saltus Relationship Layer                      │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │ Registry     │  │ Manager      │  │ Query        │     │
│  │ (Config)     │  │ (CRUD)       │  │ (Eager Load) │     │
│  └──────────────┘  └──────────────┘  └──────────────┘     │
└────────────────────────┬────────────────────────────────────┘
                         │
                         │ consumed by
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                     Consumer Layer                           │
│                                                              │
│  Admin UI  │  REST API  │  MCP Tools  │  WebMCP  │  WP-CLI │
└─────────────────────────────────────────────────────────────┘
```

---

## Database Design

### Schema: wp_saltus_relationships

```sql
CREATE TABLE {prefix}_saltus_relationships (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    relationship_key VARCHAR(64) NOT NULL,
    from_post_id BIGINT UNSIGNED NOT NULL,
    to_post_id BIGINT UNSIGNED NOT NULL,
    pivot_data LONGTEXT DEFAULT NULL COMMENT 'JSON',
    order_index INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    KEY rel_key (relationship_key),
    KEY from_post (from_post_id),
    KEY to_post (to_post_id),
    KEY from_to (from_post_id, relationship_key),
    KEY to_from (to_post_id, relationship_key),
    UNIQUE KEY unique_rel (relationship_key, from_post_id, to_post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Column Definitions

| Column | Type | Purpose | Notes |
|--------|------|---------|-------|
| `id` | BIGINT UNSIGNED | Primary key | Auto-increment |
| `relationship_key` | VARCHAR(64) | Identifies the relationship type | Format: `{from_model}_{rel_name}` (e.g., `movie_actors`) |
| `from_post_id` | BIGINT UNSIGNED | Source post ID | Foreign key to wp_posts.ID |
| `to_post_id` | BIGINT UNSIGNED | Target post ID | Foreign key to wp_posts.ID |
| `pivot_data` | LONGTEXT | Many-to-many metadata | JSON string, nullable |
| `order_index` | INT | Order for has_many relationships | 0-based, used for drag-to-reorder |
| `created_at` | DATETIME | Creation timestamp | For audit purposes |
| `updated_at` | DATETIME | Last update timestamp | Auto-updated by DB |

### Index Strategy

**Query patterns optimized:**

1. **Get all related posts** - `WHERE from_post_id = X AND relationship_key = 'Y'`
   - Index: `from_to (from_post_id, relationship_key)`

2. **Get reverse relationships** - `WHERE to_post_id = X AND relationship_key = 'Y'`
   - Index: `to_from (to_post_id, relationship_key)`

3. **Check if relationship exists** - `WHERE relationship_key = 'Y' AND from_post_id = X AND to_post_id = Z`
   - Index: `unique_rel` (also prevents duplicates)

4. **Get all relationships of a type** - `WHERE relationship_key = 'Y'`
   - Index: `rel_key (relationship_key)`

### Migration File

**File:** `db/migrations/001_create_relationships_table.php`

```php
<?php
namespace Saltus\WP\Framework\Migrations;

class CreateRelationshipsTable {
    public function up() {
        global $wpdb;
        $table = $wpdb->prefix . 'saltus_relationships';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (...)";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        return $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
    }
    
    public function down() {
        global $wpdb;
        $table = $wpdb->prefix . 'saltus_relationships';
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
}
```

---

## Class Structure

### Directory Layout

```
src/Features/Relationships/
├── Relationships.php                  // Service class (Service, Conditional, Assembly)
├── RelationshipRegistry.php           // Parse model configs, validate definitions
├── RelationshipManager.php            // CRUD operations on wp_saltus_relationships
├── RelationshipQuery.php              // Eager loading, exists queries, counts
├── MetaBoxRenderer.php                // Admin UI for selecting related posts
├── Types/
│   ├── RelationshipType.php          // Abstract base
│   ├── HasOne.php                    // 1:1 relationship
│   ├── HasMany.php                   // 1:N relationship
│   ├── BelongsTo.php                 // Inverse of HasOne/HasMany
│   └── ManyToMany.php                // N:N with pivot data
└── Exceptions/
    ├── RelationshipNotFoundException.php
    ├── InvalidRelationshipException.php
    └── CircularReferenceException.php

src/Rest/
└── RelationshipsController.php        // REST endpoints

src/MCP/Tools/Relationships/
├── GetRelationships.php               // List all relationship definitions
├── GetRelatedPosts.php                // Fetch related items
├── CreateRelationship.php             // Connect two posts
└── DeleteRelationship.php             // Disconnect

src/WebMcp/Tools/Relationships/
├── GetRelationships.php               // Frontend-safe relationship list
└── GetRelatedPosts.php                // Frontend-safe related items (public only)

src/Migrations/
├── MigrationRunner.php                // Execute up/down migrations
├── MigrationRegistry.php              // Track which migrations ran
└── migrations/
    └── 001_create_relationships_table.php
```

### Class: Relationships (Service)

**File:** `src/Features/Relationships/Relationships.php`

```php
<?php
namespace Saltus\WP\Framework\Features\Relationships;

use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\Infrastructure\Service\Conditional;
use Saltus\WP\Framework\Infrastructure\Service\Assembly;

class Relationships implements Service, Conditional, Assembly {
    
    private RelationshipRegistry $registry;
    private RelationshipManager $manager;
    private MetaBoxRenderer $metabox_renderer;
    
    public function __construct(
        RelationshipRegistry $registry,
        RelationshipManager $manager,
        MetaBoxRenderer $metabox_renderer
    ) {
        $this->registry = $registry;
        $this->manager = $manager;
        $this->metabox_renderer = $metabox_renderer;
    }
    
    public static function is_needed(): bool {
        // Active if any model has relationships defined
        return has_filter('saltus/framework/models/has_relationships');
    }
    
    public function register(): void {
        // Register metaboxes for relationship selection
        add_action('add_meta_boxes', [$this->metabox_renderer, 'register_metaboxes']);
        
        // Save relationship data
        add_action('save_post', [$this->manager, 'save_relationships'], 10, 2);
        
        // Delete relationships when post is deleted
        add_action('before_delete_post', [$this->manager, 'delete_all_for_post']);
        
        // Admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }
    
    public function enqueue_admin_assets(): void {
        global $post;
        
        if (!$post || !$this->registry->has_relationships($post->post_type)) {
            return;
        }
        
        wp_enqueue_script('select2');
        wp_enqueue_style('select2');
        
        wp_enqueue_script(
            'saltus-relationships',
            SALTUS_PLUGIN_URL . 'assets/Feature/Relationships/admin.js',
            ['jquery', 'select2'],
            SALTUS_VERSION,
            true
        );
        
        wp_localize_script('saltus-relationships', 'saltusRelationships', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('saltus_relationships'),
            'i18n' => [
                'searching' => __('Searching...', 'saltus'),
                'noResults' => __('No results found', 'saltus'),
            ],
        ]);
    }
}
```

### Class: RelationshipRegistry

**File:** `src/Features/Relationships/RelationshipRegistry.php`

```php
<?php
namespace Saltus\WP\Framework\Features\Relationships;

class RelationshipRegistry {
    
    private array $relationships = [];
    
    public function register_from_models(array $models): void {
        foreach ($models as $model) {
            if (!isset($model['relationships'])) {
                continue;
            }
            
            $post_type = $model['name'];
            
            foreach ($model['relationships'] as $name => $config) {
                $this->register($post_type, $name, $config);
            }
        }
    }
    
    public function register(string $post_type, string $name, array $config): void {
        // Validate config
        $this->validate_config($config);
        
        // Build full relationship key
        $key = $this->build_key($post_type, $name);
        
        // Normalize config
        $normalized = $this->normalize_config($post_type, $name, $config);
        
        // Store
        $this->relationships[$key] = $normalized;
        
        // Register reciprocal if needed
        if ($normalized['reciprocal']) {
            $this->register_reciprocal($normalized);
        }
    }
    
    public function get(string $post_type, string $name): ?array {
        $key = $this->build_key($post_type, $name);
        return $this->relationships[$key] ?? null;
    }
    
    public function get_all(): array {
        return $this->relationships;
    }
    
    public function has_relationships(string $post_type): bool {
        foreach ($this->relationships as $rel) {
            if ($rel['from_post_type'] === $post_type) {
                return true;
            }
        }
        return false;
    }
    
    private function validate_config(array $config): void {
        if (!isset($config['type'])) {
            throw new InvalidRelationshipException('Relationship type is required');
        }
        
        if (!in_array($config['type'], ['has_one', 'has_many', 'belongs_to', 'many_to_many'])) {
            throw new InvalidRelationshipException("Invalid relationship type: {$config['type']}");
        }
        
        if (!isset($config['model'])) {
            throw new InvalidRelationshipException('Related model is required');
        }
    }
    
    private function normalize_config(string $post_type, string $name, array $config): array {
        return [
            'from_post_type' => $post_type,
            'name' => $name,
            'type' => $config['type'],
            'to_post_type' => $config['model'],
            'reciprocal' => $config['reciprocal'] ?? null,
            'cascade_delete' => $config['cascade_delete'] ?? false,
            'pivot_fields' => $config['meta'] ?? [],
            'capability' => $config['capability'] ?? 'edit_posts',
        ];
    }
    
    private function build_key(string $post_type, string $name): string {
        return "{$post_type}_{$name}";
    }
    
    private function register_reciprocal(array $relationship): void {
        // Auto-generate reverse relationship
        // Example: movie.actors → person.acted_in
    }
}
```

### Class: RelationshipManager (CRUD)

**File:** `src/Features/Relationships/RelationshipManager.php`

```php
<?php
namespace Saltus\WP\Framework\Features\Relationships;

class RelationshipManager {
    
    private RelationshipRegistry $registry;
    
    public function __construct(RelationshipRegistry $registry) {
        $this->registry = $registry;
    }
    
    /**
     * Create a relationship connection
     */
    public function create(
        string $relationship_key,
        int $from_post_id,
        int $to_post_id,
        array $pivot_data = []
    ): int {
        global $wpdb;
        
        $table = $wpdb->prefix . 'saltus_relationships';
        
        // Check if already exists
        if ($this->exists($relationship_key, $from_post_id, $to_post_id)) {
            return $this->update($relationship_key, $from_post_id, $to_post_id, $pivot_data);
        }
        
        // Get next order index for has_many
        $order_index = $this->get_next_order_index($relationship_key, $from_post_id);
        
        $result = $wpdb->insert(
            $table,
            [
                'relationship_key' => $relationship_key,
                'from_post_id' => $from_post_id,
                'to_post_id' => $to_post_id,
                'pivot_data' => !empty($pivot_data) ? json_encode($pivot_data) : null,
                'order_index' => $order_index,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%d', '%s', '%d', '%s', '%s']
        );
        
        if ($result === false) {
            throw new \RuntimeException("Failed to create relationship: {$wpdb->last_error}");
        }
        
        do_action('saltus/relationships/created', $relationship_key, $from_post_id, $to_post_id);
        
        return $wpdb->insert_id;
    }
    
    /**
     * Delete a relationship connection
     */
    public function delete(
        string $relationship_key,
        int $from_post_id,
        int $to_post_id
    ): bool {
        global $wpdb;
        
        $table = $wpdb->prefix . 'saltus_relationships';
        
        $result = $wpdb->delete(
            $table,
            [
                'relationship_key' => $relationship_key,
                'from_post_id' => $from_post_id,
                'to_post_id' => $to_post_id,
            ],
            ['%s', '%d', '%d']
        );
        
        do_action('saltus/relationships/deleted', $relationship_key, $from_post_id, $to_post_id);
        
        return $result !== false;
    }
    
    /**
     * Get all related post IDs
     */
    public function get_related_ids(
        string $relationship_key,
        int $from_post_id
    ): array {
        global $wpdb;
        
        $table = $wpdb->prefix . 'saltus_relationships';
        
        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT to_post_id 
             FROM {$table} 
             WHERE relationship_key = %s 
             AND from_post_id = %d 
             ORDER BY order_index ASC",
            $relationship_key,
            $from_post_id
        ));
        
        return array_map('intval', $results);
    }
    
    /**
     * Save relationships from POST data
     */
    public function save_relationships(int $post_id, \WP_Post $post): void {
        // Verify nonce
        if (!isset($_POST['saltus_relationships_nonce'])) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['saltus_relationships_nonce'], 'save_relationships')) {
            return;
        }
        
        // Get relationships for this post type
        $relationships = $this->registry->get_all();
        
        foreach ($relationships as $key => $config) {
            if ($config['from_post_type'] !== $post->post_type) {
                continue;
            }
            
            // Check capability
            if (!current_user_can($config['capability'], $post_id)) {
                continue;
            }
            
            $field_name = "saltus_rel_{$config['name']}";
            
            if (!isset($_POST[$field_name])) {
                continue;
            }
            
            $related_ids = array_map('intval', (array) $_POST[$field_name]);
            
            // Sync: delete old, insert new
            $this->sync($key, $post_id, $related_ids);
        }
    }
    
    /**
     * Sync related IDs (replace all)
     */
    public function sync(string $relationship_key, int $from_post_id, array $to_post_ids): void {
        global $wpdb;
        
        $table = $wpdb->prefix . 'saltus_relationships';
        
        // Delete all existing
        $wpdb->delete(
            $table,
            ['relationship_key' => $relationship_key, 'from_post_id' => $from_post_id],
            ['%s', '%d']
        );
        
        // Insert new
        foreach ($to_post_ids as $index => $to_post_id) {
            $this->create($relationship_key, $from_post_id, $to_post_id, []);
        }
    }
    
    /**
     * Delete all relationships for a post
     */
    public function delete_all_for_post(int $post_id): void {
        global $wpdb;
        
        $table = $wpdb->prefix . 'saltus_relationships';
        
        // Delete as source
        $wpdb->delete($table, ['from_post_id' => $post_id], ['%d']);
        
        // Delete as target
        $wpdb->delete($table, ['to_post_id' => $post_id], ['%d']);
    }
    
    private function exists(string $relationship_key, int $from_post_id, int $to_post_id): bool {
        global $wpdb;
        
        $table = $wpdb->prefix . 'saltus_relationships';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} 
             WHERE relationship_key = %s 
             AND from_post_id = %d 
             AND to_post_id = %d",
            $relationship_key,
            $from_post_id,
            $to_post_id
        ));
        
        return (int) $count > 0;
    }
    
    private function get_next_order_index(string $relationship_key, int $from_post_id): int {
        global $wpdb;
        
        $table = $wpdb->prefix . 'saltus_relationships';
        
        $max = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(order_index) FROM {$table} 
             WHERE relationship_key = %s 
             AND from_post_id = %d",
            $relationship_key,
            $from_post_id
        ));
        
        return $max !== null ? (int) $max + 1 : 0;
    }
}
```

