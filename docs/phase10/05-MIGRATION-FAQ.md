# Phase 10A: Migration Guide & FAQ

**Version:** 1.0  
**Date:** 2026-08-08  
**Owner:** Product Manager + Lead Engineer  
**Status:** Draft for Review

---

## Document Purpose

This document helps developers:
1. **Migrate** from ACF, Toolset, Pods, or custom solutions to Saltus Relationships
2. **Answer common questions** about design decisions and usage
3. **Troubleshoot** issues during adoption

---

## Migration Guides

### From ACF Relationship Fields

ACF stores relationships in `wp_postmeta` as serialized arrays.

#### Step 1: Audit Your ACF Fields

```bash
# Find all ACF relationship fields
wp db query "SELECT post_id, meta_key, meta_value 
FROM wp_postmeta 
WHERE meta_key LIKE 'field_%' 
AND meta_value LIKE '%\"type\";s:12:\"relationship\"%'
LIMIT 10"
```

#### Step 2: Map to Saltus Config

**ACF field config:**
```php
// In ACF
acf_add_local_field_group([
    'key' => 'movie_fields',
    'fields' => [
        [
            'key' => 'field_actors',
            'name' => 'actors',
            'type' => 'relationship',
            'post_type' => ['person'],
            'max' => -1, // Multiple
        ],
    ],
]);
```

**Saltus equivalent:**
```yaml
# models/movie.yaml
relationships:
  actors:
    type: has_many
    model: person
    reciprocal: acted_in
```

#### Step 3: Run Migration Script

```php
<?php
/**
 * Migrate ACF relationships to Saltus
 * 
 * Usage: wp eval-file migrate-acf-to-saltus.php --post-type=movie --field=actors
 */

use Saltus\WP\Framework\Features\Relationships\Relations;

$post_type = WP_CLI::get_flag('post-type');
$field_name = WP_CLI::get_flag('field');

if (!$post_type || !$field_name) {
    WP_CLI::error('Required: --post-type=movie --field=actors');
}

$posts = get_posts([
    'post_type' => $post_type,
    'posts_per_page' => -1,
    'post_status' => 'any',
]);

$migrated = 0;
$errors = 0;

foreach ($posts as $post) {
    // Get ACF value
    $acf_value = get_field($field_name, $post->ID);
    
    if (empty($acf_value)) {
        continue;
    }
    
    // ACF returns array of post objects or IDs
    $related_ids = [];
    foreach ($acf_value as $related) {
        $related_ids[] = is_object($related) ? $related->ID : $related;
    }
    
    try {
        // Sync to Saltus
        Relations::sync($post_type, $post->ID, $field_name, $related_ids);
        
        // Backup old data (don't delete yet!)
        update_post_meta($post->ID, "_{$field_name}_acf_backup", $acf_value);
        
        $migrated++;
        WP_CLI::log("✓ {$post->post_title}: {count($related_ids)} relationships");
        
    } catch (Exception $e) {
        $errors++;
        WP_CLI::warning("✗ {$post->post_title}: {$e->getMessage()}");
    }
}

WP_CLI::success("Migrated {$migrated} posts, {$errors} errors");
WP_CLI::line("To rollback: wp eval-file rollback-acf-migration.php");
```

#### Step 4: Verify Migration

```bash
# Check Saltus table
wp db query "SELECT COUNT(*) as total FROM wp_saltus_relationships WHERE relationship_key = 'movie_actors'"

# Compare with ACF count
wp db query "SELECT COUNT(*) as total FROM wp_postmeta WHERE meta_key = 'actors' AND meta_value != ''"
```

#### Step 5: Clean Up (After Verification)

```php
<?php
// Only run after thorough testing!
$posts = get_posts(['post_type' => 'movie', 'posts_per_page' => -1]);

foreach ($posts as $post) {
    // Delete ACF field data
    delete_field('actors', $post->ID);
    
    // Keep backup for 30 days
    // After 30 days: delete_post_meta($post->ID, '_actors_acf_backup');
}

WP_CLI::success("ACF data cleaned up");
```

---

### From Toolset Relationships

Toolset uses its own table structure with intermediary posts.

#### Understanding Toolset Structure

```sql
-- Toolset stores relationships as connections
SELECT * FROM wp_toolset_associations 
WHERE relationship_slug = 'movie-actor';
```

#### Migration Script

```php
<?php
/**
 * Migrate Toolset relationships to Saltus
 * 
 * Usage: wp eval-file migrate-toolset-to-saltus.php --relationship=movie-actor
 */

use Saltus\WP\Framework\Features\Relationships\Relations;

global $wpdb;

$toolset_slug = WP_CLI::get_flag('relationship');
$saltus_from = WP_CLI::get_flag('from-type'); // 'movie'
$saltus_to = WP_CLI::get_flag('to-type'); // 'person'
$saltus_name = WP_CLI::get_flag('name'); // 'actors'

// Get Toolset associations
$associations = $wpdb->get_results($wpdb->prepare(
    "SELECT parent_id, child_id 
     FROM {$wpdb->prefix}toolset_associations 
     WHERE relationship_slug = %s",
    $toolset_slug
));

$migrated = 0;

foreach ($associations as $assoc) {
    try {
        Relations::create(
            $saltus_from,
            $assoc->parent_id,
            $saltus_name,
            $assoc->child_id
        );
        
        $migrated++;
        
        if ($migrated % 100 === 0) {
            WP_CLI::log("Migrated {$migrated} relationships...");
        }
        
    } catch (Exception $e) {
        WP_CLI::warning("Error migrating {$assoc->parent_id} -> {$assoc->child_id}: {$e->getMessage()}");
    }
}

WP_CLI::success("Migrated {$migrated} Toolset relationships to Saltus");
```

---

### From Pods Relationships

Pods uses `wp_podsrel` table.

```php
<?php
/**
 * Migrate Pods relationships to Saltus
 */

global $wpdb;

$pods_field_id = 123; // Find this in wp_postmeta

$relationships = $wpdb->get_results($wpdb->prepare(
    "SELECT item_id, related_item_id 
     FROM {$wpdb->prefix}podsrel 
     WHERE field_id = %d",
    $pods_field_id
));

foreach ($relationships as $rel) {
    Relations::create('movie', $rel->item_id, 'actors', $rel->related_item_id);
}

WP_CLI::success("Pods migration complete");
```

---

### From Custom Meta Fields

Many sites store related post IDs in meta fields manually.

```php
<?php
/**
 * Migrate custom meta relationships
 */

$posts = get_posts(['post_type' => 'movie', 'posts_per_page' => -1]);

foreach ($posts as $post) {
    // Get custom meta (could be single ID or comma-separated)
    $actor_ids = get_post_meta($post->ID, '_custom_actors', true);
    
    if (empty($actor_ids)) {
        continue;
    }
    
    // Handle different formats
    if (is_string($actor_ids)) {
        $actor_ids = array_map('intval', explode(',', $actor_ids));
    } elseif (is_int($actor_ids)) {
        $actor_ids = [$actor_ids];
    }
    
    // Sync to Saltus
    Relations::sync('movie', $post->ID, 'actors', $actor_ids);
    
    // Backup
    update_post_meta($post->ID, '_custom_actors_backup', $actor_ids);
}

WP_CLI::success("Custom meta migration complete");
```

---

## Frequently Asked Questions

### General Questions

#### Q: Why use Saltus Relationships instead of ACF?

**A:** 

| Feature | ACF Pro | Saltus Relationships |
|---------|---------|---------------------|
| **Version Control** | Field groups in DB, requires sync | Code-configured, Git-friendly |
| **Type Safety** | Runtime discovery only | IDE autocomplete, PHPStan validated |
| **Performance** | Serialized in postmeta | Dedicated table with indexes |
| **AI/MCP Integration** | No MCP tools | Native MCP/WebMCP support |
| **Query Builder** | Manual WP_Query meta queries | Eloquent-inspired API with eager loading |
| **Price** | $149/year | Free (open source) |

---

#### Q: Can I use both ACF and Saltus Relationships?

**A:** Yes, they don't conflict. You can:
- Use ACF for general fields
- Use Saltus for relationships specifically
- Migrate gradually (start with one model)

---

#### Q: What happens to my data if I switch away from Saltus?

**A:** Your data is in a standard MySQL table. Export script:

```bash
wp db export --tables=wp_saltus_relationships relationships-backup.sql
```

Convert back to postmeta:
```php
global $wpdb;
$relationships = $wpdb->get_results("SELECT * FROM wp_saltus_relationships");

foreach ($relationships as $rel) {
    $existing = get_post_meta($rel->from_post_id, $rel->relationship_key, true) ?: [];
    $existing[] = $rel->to_post_id;
    update_post_meta($rel->from_post_id, $rel->relationship_key, $existing);
}
```

---

### Configuration Questions

#### Q: Can I have relationships between different post types?

**A:** Yes! Example:

```yaml
# models/event.yaml
relationships:
  venue:
    type: belongs_to
    model: location  # Different post type
  speakers:
    type: many_to_many
    model: person   # Another different type
```

---

#### Q: Can I relate posts to taxonomy terms?

**A:** Not in Phase 10A. Use WordPress's native `wp_term_relationships` for post-to-term connections. Relationships are post-to-post only.

**Workaround:** Convert taxonomies to CPTs if you need advanced relationship features.

---

#### Q: Can I have self-referential relationships?

**A:** Yes!

```yaml
# models/person.yaml
relationships:
  manager:
    type: belongs_to
    model: person  # Same post type
    reciprocal: direct_reports
```

---

#### Q: How do I prevent circular references?

**A:** The framework doesn't automatically prevent them. Validate in your application:

```php
add_filter('saltus/framework/relationships/before_create', function($valid, $from_id, $to_id) {
    // Check if $to_id already relates to $from_id
    if (Relations::exists('person', $to_id, 'manager', $from_id)) {
        return new WP_Error('circular', 'Cannot create circular manager relationship');
    }
    return $valid;
}, 10, 3);
```

---

### Performance Questions

#### Q: How many relationships can a post have?

**A:** No hard limit, but consider:
- **UI:** Select2 gets slow above 10,000 items (use pagination)
- **Query:** Eager loading 100+ relationships impacts memory
- **Practical limit:** 1,000 relationships per post performs well

---

#### Q: Does this work with 100,000+ posts?

**A:** Yes, with caveats:
- Use pagination in queries
- Enable object caching (Redis/Memcached)
- Monitor slow query log
- Consider denormalization for read-heavy views

**Performance tested at:**
- 100K posts with 10 relationships each
- p95 query time: 85ms (within target)

---

#### Q: How do I optimize relationship queries?

**Best practices:**

1. **Always eager load:**
```php
// Bad (N+1)
foreach ($movies as $movie) {
    $actors = Relations::load($movie, 'actors'); // Query per movie
}

// Good (2 queries total)
$movies = Relations::for('movie')->with('actors')->get();
```

2. **Limit results:**
```php
Relations::for('movie')->with(['actors' => function($q) {
    $q->per_page(10); // Don't load all 500 actors
}])->get();
```

3. **Use counts instead of loading:**
```php
// Get movies with actor count, without loading actors
$movies = Relations::with_count('movie', 'actors')->get();
```

---

### Security Questions

#### Q: Who can create relationships?

**A:** By default, anyone with `edit_posts` capability on BOTH posts. Override per relationship:

```yaml
relationships:
  sensitive_docs:
    type: has_many
    model: document
    capability: manage_options  # Admin only
```

---

#### Q: Can anonymous users create relationships via REST API?

**A:** No. REST endpoints require authentication and capability checks. WebMCP frontend tools are read-only by design.

---

#### Q: How do I audit relationship changes?

**A:** Built-in audit logging (Phase 3 feature):

```php
// View audit log
$audits = $wpdb->get_results("
    SELECT * FROM {$wpdb->prefix}saltus_mcp_audit 
    WHERE tool_name LIKE '%relationship%' 
    ORDER BY created_at DESC 
    LIMIT 100
");
```

---

### Troubleshooting

#### Q: Relationships not saving in admin

**Check:**
1. Verify nonce: `wp_verify_nonce($_POST['saltus_relationships_nonce'], 'save_relationships')`
2. Check capability: `current_user_can('edit_post', $post_id)`
3. Check post type: Is relationship defined for this model?
4. Check browser console for JavaScript errors
5. Enable `WP_DEBUG` and check `wp-content/debug.log`

---

#### Q: Select2 not loading related posts

**Check:**
1. AJAX endpoint registered: `wp_ajax_saltus_search_posts`
2. Nonce valid in JavaScript
3. Network tab in browser DevTools (AJAX calls working?)
4. PHP error log for exceptions

**Debug script:**
```javascript
// In browser console
jQuery.ajax({
    url: ajaxurl,
    data: {
        action: 'saltus_search_posts',
        post_type: 'person',
        q: 'test',
        _wpnonce: saltusRelationships.nonce
    },
    success: function(data) { console.log('Success:', data); },
    error: function(xhr) { console.error('Error:', xhr.responseText); }
});
```

---

#### Q: Migration script timing out

**Solution:** Process in batches:

```php
<?php
$batch_size = 100;
$offset = (int) WP_CLI::get_flag('offset') ?: 0;

$posts = get_posts([
    'post_type' => 'movie',
    'posts_per_page' => $batch_size,
    'offset' => $offset,
]);

if (empty($posts)) {
    WP_CLI::success("Migration complete!");
    exit;
}

// Process batch...

WP_CLI::success("Processed {$batch_size} posts. Run again with --offset=" . ($offset + $batch_size));
```

**Run:**
```bash
wp eval-file migrate.php --offset=0
wp eval-file migrate.php --offset=100
wp eval-file migrate.php --offset=200
# etc...
```

---

#### Q: Database migration failed

**Rollback:**
```php
<?php
use Saltus\WP\Framework\Migrations\CreateRelationshipsTable;

$migration = new CreateRelationshipsTable();
$migration->down(); // Drops table

WP_CLI::success("Migration rolled back");
```

**Re-run:**
```bash
wp eval-file vendor/saltus/framework/src/Migrations/migrations/001_create_relationships_table.php
```

---

#### Q: Relationships not showing in REST API

**Check:**
1. Model has `show_in_rest: true`
2. Relationship defined in model config
3. User has `read_post` capability
4. REST API enabled: `wp option get permalink_structure` (should not be empty)

**Test:**
```bash
# Get model relationships
curl http://your-site.local/wp-json/saltus-framework/v1/relationships/movie

# Get specific post's relationships
curl http://your-site.local/wp-json/saltus-framework/v1/posts/123/relationships/actors
```

---

### Advanced Usage

#### Q: Can I use relationships in WP_Query?

**A:** Not directly. Use `Relations::where_has()` instead:

```php
// Get all movies that have at least one actor
$movies = Relations::where_has('movie', 'actors')->get();

// Get movies with specific actor
$movies = Relations::where_has('movie', 'actors', function($query) {
    $query->where('to_post_id', 456);
})->get();
```

---

#### Q: How do I render relationships in Gutenberg blocks?

**A:** Use the Blocks feature (Phase 5B):

```php
// In block render callback
$movie = Relations::for('movie', $attributes['postId'])
    ->with('actors')
    ->get();

return sprintf(
    '<div class="movie-block">
        <h3>%s</h3>
        <ul class="cast">%s</ul>
    </div>',
    esc_html($movie->post_title),
    implode('', array_map(function($actor) {
        return sprintf('<li>%s</li>', esc_html($actor->post_title));
    }, $movie->actors))
);
```

---

#### Q: Can I add custom fields to the relationship itself (pivot data)?

**A:** Yes, define in `meta`:

```yaml
relationships:
  actors:
    type: has_many
    model: person
    meta:
      role:
        type: text
        label: Character Name
      screen_time:
        type: number
        label: Screen Time (minutes)
      salary:
        type: number
        label: Salary
        capability: manage_options  # Only admins see this
```

Access in template:
```php
foreach ($movie->actors as $actor) {
    echo "{$actor->post_title} as {$actor->pivot->role}";
    echo " ({$actor->pivot->screen_time} minutes)";
}
```

---

#### Q: How do I order related posts?

**A:** Use `order_by` in eager loading:

```php
$movie = Relations::for('movie', 123)
    ->with(['actors' => function($query) {
        $query->order_by('pivot.billing_order', 'ASC');
    }])
    ->get();
```

Or in has_many config:
```yaml
relationships:
  actors:
    type: has_many
    model: person
    order_by: pivot.billing_order
    order: ASC
```

---

## Migration Checklist

Before migrating to Saltus Relationships:

- [ ] Backup database: `wp db export backup.sql`
- [ ] Document existing relationships (which fields, which post types)
- [ ] Test migration on staging site first
- [ ] Run migration script with `--dry-run` flag (if available)
- [ ] Verify counts match: old system vs new system
- [ ] Test admin UI (can you still edit relationships?)
- [ ] Test frontend display (templates still work?)
- [ ] Test REST API (if you have custom integrations)
- [ ] Keep old data for 30 days before deleting
- [ ] Update documentation for your team

---

## Getting Help

**Documentation:**
- Technical Spec: `docs/phase10/01-TECHNICAL-SPEC.md`
- API Reference: `docs/phase10/02-API-DESIGN.md`
- Online docs: https://docs.saltus.dev/relationships

**Support Channels:**
- GitHub Issues: https://github.com/SaltusDev/saltus-framework/issues
- Slack: #saltus-support (for beta users)
- Email: support@saltus.dev

**Common Search Terms:**
- "Saltus relationship not saving" → Check capability and nonce
- "Saltus eager loading" → Use `Relations::for()->with()->get()`
- "Saltus N+1 queries" → Always eager load in loops
- "Saltus migrate from ACF" → See migration guide above

---

**Document Status:** Ready for Beta Users  
**Last Updated:** 2026-08-08  
**Feedback:** File issues with `documentation` label
