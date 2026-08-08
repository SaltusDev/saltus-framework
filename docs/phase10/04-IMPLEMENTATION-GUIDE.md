# Phase 10A: Content Relationships - Implementation Guide

**Version:** 1.0  
**Date:** 2026-08-08  
**Owner:** Lead Engineer  
**Status:** Draft for Review

---

## Document Purpose

This guide provides **week-by-week implementation steps** for Phase 10A. It's the tactical playbook for engineers building relationships.

**Audience:** Engineers on the Phase 10 team

---

## Implementation Approach

### Development Philosophy

1. **Test-Driven Development (TDD)** - Write tests first, then implementation
2. **Incremental Delivery** - Ship working pieces, not big-bang at end
3. **Documentation as Code** - Update docs with every PR
4. **Performance First** - Profile early, optimize continuously
5. **Security by Default** - Validate inputs, sanitize outputs, check capabilities

### Working Agreement

- **Pair programming** on core relationship logic
- **Code review** required on all PRs (2 approvals for breaking changes)
- **Daily standups** at 10am (15 minutes max)
- **Weekly demos** on Fridays (show progress to stakeholders)
- **Spike time** on Wednesdays (explore unknowns, technical debt)

---

## Week-by-Week Breakdown

### Week 1: Foundation & Database

**Goal:** Database schema working, migrations runnable

#### Monday: Project Setup
- [ ] Create feature branch `feature/phase-10a-relationships`
- [ ] Set up directory structure under `src/Features/Relationships/`
- [ ] Create test directories
- [ ] Add Phase 10 milestone in GitHub
- [ ] Create initial PRD review issues

**Tasks:**
```bash
# Create directories
mkdir -p src/Features/Relationships/{Types,Exceptions}
mkdir -p tests/{Unit,Integration}/Features/Relationships
mkdir -p src/Rest
mkdir -p src/MCP/Tools/Relationships

# Create stub files
touch src/Features/Relationships/{Relationships,RelationshipRegistry,RelationshipManager,RelationshipQuery,MetaBoxRenderer}.php
```

#### Tuesday-Wednesday: Database Schema
- [ ] Write migration: `001_create_relationships_table.php`
- [ ] Implement `MigrationRunner` class
- [ ] Write tests for migration up/down
- [ ] Test on clean WordPress install
- [ ] Test migration rollback

**Deliverable:** Migration runs successfully, table created with correct indexes

**Test checklist:**
- ✓ Migration creates table
- ✓ Migration is idempotent (can run multiple times)
- ✓ Rollback removes table
- ✓ All indexes created
- ✓ Foreign key constraints work (if using InnoDB)

#### Thursday-Friday: RelationshipManager CRUD
- [ ] Implement `create()` method with tests
- [ ] Implement `delete()` method with tests
- [ ] Implement `exists()` method with tests
- [ ] Implement `get_related_ids()` method with tests
- [ ] Write integration tests with real database

**Deliverable:** CRUD operations work, 90%+ test coverage

**Code Review Checklist:**
- SQL injection prevention (prepared statements)
- Type safety (int casting on IDs)
- Error handling (DB failures)
- Action hooks fired correctly

---

### Week 2: Registry & Configuration

**Goal:** Model config parsing works, relationships register correctly

#### Monday: RelationshipRegistry
- [ ] Implement `register()` method
- [ ] Implement config validation
- [ ] Implement `get()` and `get_all()` methods
- [ ] Write unit tests (pure, no WordPress)

**Validation rules:**
- `type` must be one of: has_one, has_many, belongs_to, many_to_many
- `model` must be a registered post type
- `capability` must be a valid WordPress capability
- `pivot_fields` must have valid field types

#### Tuesday: Model Integration
- [ ] Hook into `Modeler` to read relationship config
- [ ] Trigger relationship registration on `init`
- [ ] Handle reciprocal relationships
- [ ] Write integration test with full model config

**Example integration:**
```php
// In Core::register()
add_action('init', function() {
    $models = Modeler::get_models();
    $registry = Container::get(RelationshipRegistry::class);
    $registry->register_from_models($models);
}, 20); // After models loaded
```

#### Wednesday-Thursday: Relationship Types
- [ ] Implement abstract `RelationshipType` base class
- [ ] Implement `HasOne` type
- [ ] Implement `HasMany` type  
- [ ] Implement `BelongsTo` type
- [ ] Implement `ManyToMany` type
- [ ] Unit tests for each type

**Each type defines:**
- `get_related()` - Query logic
- `validate_config()` - Type-specific validation
- `sync()` - How to update relationships

#### Friday: Service Wiring
- [ ] Create `Relationships` service class
- [ ] Implement `Service`, `Conditional`, `Assembly` interfaces
- [ ] Wire into `Core::get_service_classes()`
- [ ] Write service registration tests

**Deliverable:** Relationships service loads when models have relationships defined

---

### Week 3: Query Builder

**Goal:** Eager loading works, no N+1 queries

#### Monday-Tuesday: RelationshipQuery Core
- [ ] Implement `Relations::for()` entry point
- [ ] Implement `with()` for eager loading
- [ ] Implement `get()` to execute query
- [ ] Write query tests

**Query flow:**
1. `Relations::for('movie', 123)` - Initialize query builder
2. `->with('actors')` - Mark relationships to eager load
3. `->get()` - Execute: fetch post, fetch all relationships in 1-2 queries
4. Return WP_Post with relationships as properties

#### Wednesday: Advanced Queries
- [ ] Implement `where_has()` - Filter by related posts
- [ ] Implement `with_count()` - Count relationships
- [ ] Implement `order_by()` - Order by relationship count
- [ ] Performance tests for each query type

**Performance target:** 
- 100 posts with 2 relationships each: < 3 queries total
- p95 query time: < 100ms

#### Thursday: Lazy Loading
- [ ] Implement `Relations::load($post, 'relationship')` for lazy loading
- [ ] Per-request caching to avoid duplicate queries
- [ ] Write cache invalidation tests

#### Friday: Query Optimization
- [ ] Profile queries with large datasets
- [ ] Add query result caching
- [ ] Optimize indexes based on slow query log
- [ ] Document query patterns in API guide

**Deliverable:** Eager loading prevents N+1, meets performance targets

---

### Week 4: Admin UI

**Goal:** Metabox UI works, users can select related posts

#### Monday: MetaBox Scaffolding
- [ ] Implement `MetaBoxRenderer::register_metaboxes()`
- [ ] Render basic relationship metabox
- [ ] Hook into `save_post` to save relationships
- [ ] Verify nonce and capability checks

#### Tuesday-Wednesday: Select2 Integration
- [ ] Enqueue Select2 library
- [ ] Build AJAX search endpoint for posts
- [ ] Render Select2 fields for each relationship
- [ ] Handle has_one (single select) vs has_many (multi-select)

**AJAX endpoint:**
```php
add_action('wp_ajax_saltus_search_posts', function() {
    check_ajax_referer('saltus_relationships');
    
    $post_type = sanitize_key($_GET['post_type']);
    $search = sanitize_text_field($_GET['q']);
    
    $posts = get_posts([
        'post_type' => $post_type,
        's' => $search,
        'posts_per_page' => 20,
    ]);
    
    $results = array_map(function($post) {
        return [
            'id' => $post->ID,
            'text' => $post->post_title,
        ];
    }, $posts);
    
    wp_send_json($results);
});
```

#### Thursday: Pivot Data Fields
- [ ] Render input fields for pivot data
- [ ] Save pivot data with relationships
- [ ] Load pivot data on edit screen
- [ ] Validate pivot data types

**UI design:**
```
┌─────────────────────────────────────┐
│ Actors                              │
├─────────────────────────────────────┤
│ [Select actors...        ▼]         │
│                                     │
│ Selected:                           │
│ ┌─────────────────────────────────┐ │
│ │ Daniel Craig            [Remove]│ │
│ │   Role: James Bond              │ │
│ │   Screen time: 120 min          │ │
│ ├─────────────────────────────────┤ │
│ │ Judi Dench              [Remove]│ │
│ │   Role: M                       │ │
│ │   Screen time: 45 min           │ │
│ └─────────────────────────────────┘ │
└─────────────────────────────────────┘
```

#### Friday: Polish & Accessibility
- [ ] Add aria labels for screen readers
- [ ] Keyboard navigation works
- [ ] Focus management
- [ ] Loading states and error messages
- [ ] Browser testing (Chrome, Firefox, Safari)

**Deliverable:** Functional admin UI, passes accessibility audit

---

### Week 5: REST API

**Goal:** REST endpoints work, permissions correct

#### Monday: Controller Scaffolding
- [ ] Create `RelationshipsController` class
- [ ] Implement `register_routes()` method
- [ ] Wire into `RestServer`
- [ ] Test route registration

#### Tuesday: GET Endpoints
- [ ] Implement GET `/relationships/{post_type}`
- [ ] Implement GET `/posts/{id}/relationships/{name}`
- [ ] Write REST API tests
- [ ] Test with Postman/Insomnia

#### Wednesday: POST/DELETE Endpoints
- [ ] Implement POST `/posts/{id}/relationships/{name}`
- [ ] Implement DELETE `/posts/{id}/relationships/{name}/{related_id}`
- [ ] Permission checks on all mutations
- [ ] Rate limiting (reuse existing framework)

#### Thursday: PUT Sync Endpoint
- [ ] Implement PUT `/posts/{id}/relationships/{name}`
- [ ] Batch sync logic
- [ ] Return attached/detached IDs in response

#### Friday: REST API Documentation
- [ ] Generate OpenAPI/Swagger spec
- [ ] Add examples to API docs
- [ ] Test with REST API console

**Deliverable:** All REST endpoints functional, documented

---

### Week 6: MCP Tools

**Goal:** AI agents can discover and use relationships

#### Monday-Tuesday: Tool Definitions
- [ ] Implement `GetRelationships` tool
- [ ] Implement `GetRelatedPosts` tool
- [ ] Implement `CreateRelationship` tool
- [ ] Implement `DeleteRelationship` tool
- [ ] Register in tool registry

#### Wednesday: Tool Testing
- [ ] Unit tests for each tool
- [ ] Integration tests with ability runtime
- [ ] Test with actual MCP client (if available)

#### Thursday: WebMCP Projection
- [ ] Create WebMCP-safe versions (read-only)
- [ ] Register on frontend when `webmcp.frontend: true`
- [ ] Test in Chrome Canary with WebMCP enabled

#### Friday: Documentation
- [ ] Generate MCP tool reference with `composer docs:mcp`
- [ ] Add relationship examples to MCP guide
- [ ] Update README with relationship tools

**Deliverable:** MCP tools discoverable, functional

---

### Week 7: WP-CLI Commands

**Goal:** CLI parity with REST/MCP

#### Monday-Tuesday: Command Classes
- [ ] Implement `RelationshipCommand` base
- [ ] Implement `wp saltus relationship list`
- [ ] Implement `wp saltus relationship get`
- [ ] Implement `wp saltus relationship create`
- [ ] Implement `wp saltus relationship delete`
- [ ] Implement `wp saltus relationship sync`

#### Wednesday: Output Formatting
- [ ] Add `--format=table|json|yaml` support
- [ ] Colorized output for terminal
- [ ] Progress bars for bulk operations

#### Thursday-Friday: Bulk Operations & Testing
- [ ] Implement bulk sync from CSV
- [ ] Write WP-CLI integration tests
- [ ] Performance test: sync 1000 relationships

**Deliverable:** Full CLI coverage, bulk operations work

---

### Week 8: Polish & Beta Prep

**Goal:** Ready for beta release

#### Monday: Bug Fixes
- [ ] Triage GitHub issues
- [ ] Fix top 5 bugs
- [ ] Address code review feedback

#### Tuesday: Performance Optimization
- [ ] Profile with Blackfire/XDebug
- [ ] Optimize slow queries
- [ ] Add database indexes if missing
- [ ] Run load tests

#### Wednesday: Documentation
- [ ] Complete API reference
- [ ] Write "Your First Relationship" tutorial
- [ ] Record 5-minute demo video
- [ ] Update CHANGELOG

#### Thursday: Beta Release
- [ ] Tag `v2.6.0-beta.1`
- [ ] Deploy to staging
- [ ] Invite 50 beta users
- [ ] Set up feedback channel (Slack/Discord)

#### Friday: Beta Support
- [ ] Monitor beta feedback
- [ ] Fix critical bugs
- [ ] Plan Week 9+ based on feedback

**Deliverable:** Beta release shipped, feedback loop active

---

## Code Standards

### Naming Conventions

**Classes:**
- `RelationshipManager` (not `RelationshipsManager`)
- `HasOne`, `HasMany` (not `HasOneRelationship`)

**Methods:**
- `create_relationship()` (not `createRelationship()`)
- `get_related_ids()` (not `getRelatedIDs()`)

**Variables:**
- `$from_post_id` (not `$fromPostId` or `$from_id`)
- `$relationship_key` (not `$rel_key` or `$relationshipKey`)

**Database:**
- Table: `wp_saltus_relationships` (not `wp_saltus_relationship`)
- Column: `relationship_key` (not `rel_key` or `relationshipKey`)

### Documentation Standards

Every public method needs:

```php
/**
 * Create a relationship between two posts.
 * 
 * @param string $relationship_key The relationship identifier (e.g., 'movie_actors')
 * @param int    $from_post_id     Source post ID
 * @param int    $to_post_id       Target post ID
 * @param array  $pivot_data       Optional metadata for many-to-many
 * 
 * @return int Relationship ID
 * 
 * @throws InvalidArgumentException If posts don't exist
 * @throws RuntimeException If database insert fails
 * 
 * @since 2.6.0
 */
public function create(
    string $relationship_key,
    int $from_post_id,
    int $to_post_id,
    array $pivot_data = []
): int {
    // ...
}
```

### Error Handling

**Use exceptions for programmer errors:**
```php
if (!post_type_exists($post_type)) {
    throw new InvalidArgumentException("Post type '{$post_type}' does not exist");
}
```

**Use WP_Error for user-facing errors:**
```php
if (!current_user_can('edit_post', $post_id)) {
    return new WP_Error(
        'permission_denied',
        __('You do not have permission to edit this post.', 'saltus'),
        ['status' => 403]
    );
}
```

### SQL Safety

**Always use prepared statements:**
```php
// WRONG - SQL injection risk
$results = $wpdb->get_results("SELECT * FROM {$table} WHERE relationship_key = '{$key}'");

// RIGHT
$results = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$table} WHERE relationship_key = %s",
    $key
));
```

### Capability Checks

**Check before every mutation:**
```php
public function create_relationship_via_rest($request) {
    $post_id = $request['post_id'];
    $capability = $this->get_relationship_capability($request['relationship']);
    
    if (!current_user_can($capability, $post_id)) {
        return new WP_Error('permission_denied', __('Insufficient permissions', 'saltus'), ['status' => 403]);
    }
    
    // ... proceed with creation
}
```

---

## Development Tools

### Local Environment Setup

```bash
# Clone repo
git clone https://github.com/SaltusDev/saltus-framework.git
cd saltus-framework

# Install dependencies
composer install
npm install

# Run tests
composer test

# Run specific test suite
./vendor/bin/phpunit --testsuite=relationships

# Watch mode (requires entr)
ls tests/Unit/Features/Relationships/*.php | entr ./vendor/bin/phpunit --testsuite=relationships
```

### Database Tools

**View relationships table:**
```bash
wp db query "SELECT * FROM wp_saltus_relationships LIMIT 10"
```

**Check indexes:**
```bash
wp db query "SHOW INDEX FROM wp_saltus_relationships"
```

**Analyze slow queries:**
```bash
# Enable slow query log in wp-config.php
define('SAVEQUERIES', true);

# Then in code:
global $wpdb;
print_r($wpdb->queries);
```

### Debugging

**Enable WordPress debug mode:**
```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', true);
```

**Log relationship operations:**
```php
add_action('saltus/relationships/created', function($key, $from, $to) {
    error_log("Relationship created: {$key} ({$from} -> {$to})");
}, 10, 3);
```

**Profile queries:**
```php
add_filter('saltus/framework/relationships/query_args', function($args) {
    error_log('Relationship query: ' . print_r($args, true));
    return $args;
});
```

---

## Common Pitfalls & Solutions

### Pitfall 1: N+1 Queries

**Problem:**
```php
foreach (get_posts(['post_type' => 'movie']) as $movie) {
    $actors = Relations::load($movie, 'actors'); // Query per movie!
}
```

**Solution:**
```php
$movies = Relations::for('movie')->with('actors')->get();
foreach ($movies as $movie) {
    $actors = $movie->actors; // Already loaded
}
```

### Pitfall 2: Missing Capability Check

**Problem:**
```php
public function create_relationship($from_id, $to_id) {
    // No permission check!
    return $this->manager->create($from_id, $to_id);
}
```

**Solution:**
```php
public function create_relationship($from_id, $to_id) {
    if (!current_user_can('edit_post', $from_id)) {
        return new WP_Error('permission_denied', __('Insufficient permissions', 'saltus'));
    }
    return $this->manager->create($from_id, $to_id);
}
```

### Pitfall 3: Not Handling Post Deletion

**Problem:** Relationships persist after post is deleted, causing orphaned data.

**Solution:**
```php
add_action('before_delete_post', function($post_id) {
    $manager = Container::get(RelationshipManager::class);
    $manager->delete_all_for_post($post_id);
});
```

### Pitfall 4: Forgetting Reciprocal Relationships

**Problem:** Movie → Actor works, but Actor → Movies doesn't.

**Solution:** Auto-generate reciprocal in registry:
```php
if ($config['reciprocal']) {
    $this->register_reciprocal($post_type, $name, $config);
}
```

---

## Pull Request Template

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Testing
- [ ] Unit tests added/updated
- [ ] Integration tests pass
- [ ] Manual testing completed
- [ ] Performance tested

## Checklist
- [ ] Code follows style guidelines
- [ ] PHPStan Level 7 passes
- [ ] PHPCS passes
- [ ] Documentation updated
- [ ] CHANGELOG updated

## Screenshots (if UI changes)
[Add screenshots here]

## Related Issues
Closes #XXX
```

---

## Success Metrics

Track weekly:

| Metric | Week 1 | Week 2 | Week 3 | Week 4 | Week 5 | Week 6 | Week 7 | Week 8 |
|--------|--------|--------|--------|--------|--------|--------|--------|--------|
| Test coverage | 70% | 75% | 80% | 82% | 84% | 85% | 85% | 85% |
| Open bugs | 0 | 2 | 5 | 8 | 6 | 4 | 2 | 0 |
| PR velocity | 3 | 5 | 5 | 4 | 4 | 3 | 3 | 2 |
| Code review time | - | 24h | 18h | 12h | 12h | 12h | 12h | 8h |

---

**Document Status:** Ready for Engineering Team  
**Next Steps:** Week 1 kickoff meeting Monday 10am  
**Questions:** Post in #phase-10-dev Slack channel
