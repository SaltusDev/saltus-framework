# Phase 10A: Content Relationships - API Design Document

**Version:** 1.0  
**Date:** 2026-08-08  
**Owner:** Lead Engineer  
**Status:** Draft for Review

---

## Document Purpose

This document defines the **developer-facing API** for content relationships. Every public method, every config option, every filter — documented with examples.

**Audience:** Plugin developers using Saltus, technical writers, API reviewers

---

## Configuration API

### Model Configuration

Relationships are defined in the model YAML/PHP config file:

```yaml
# models/movie.yaml
type: cpt
name: movie
relationships:
  director:
    type: has_one
    model: person
    reciprocal: directed_movies
    cascade_delete: false
    capability: edit_posts
  
  actors:
    type: has_many
    model: person
    reciprocal: acted_in
    meta:
      role: 
        type: text
        label: Character Role
      screen_time:
        type: number
        label: Screen Time (minutes)
  
  genres:
    type: many_to_many
    model: genre
    reciprocal: movies
```

### Configuration Options

#### `type` (required)

Relationship cardinality:

| Type | Description | Example |
|------|-------------|---------|
| `has_one` | 1:1 relationship | Movie has one Director |
| `has_many` | 1:N relationship | Movie has many Actors |
| `belongs_to` | Inverse of has_one/has_many | Actor belongs to many Movies |
| `many_to_many` | N:N relationship | Movie has many Genres, Genre has many Movies |

#### `model` (required)

Target post type slug:

```yaml
relationships:
  author:
    type: has_one
    model: person  # References 'person' post type
```

#### `reciprocal` (optional)

Name of the reverse relationship on the target model:

```yaml
# models/movie.yaml
relationships:
  director:
    type: has_one
    model: person
    reciprocal: directed_movies  # person.directed_movies auto-generated

# models/person.yaml
# No need to define directed_movies explicitly - it's auto-generated
```

#### `cascade_delete` (optional, default: false)

Whether deleting the source post should delete related posts:

```yaml
relationships:
  chapters:
    type: has_many
    model: chapter
    cascade_delete: true  # Deleting book deletes all chapters
```

**Warning:** Use carefully! Consider making related posts orphans instead.

#### `meta` / `pivot_fields` (optional)

Additional data stored on the relationship (many-to-many):

```yaml
relationships:
  actors:
    type: has_many
    model: person
    meta:
      role: 
        type: text
        label: Character Name
      billing_order:
        type: number
        label: Billing Position
```

#### `capability` (optional, default: 'edit_posts')

WordPress capability required to manage this relationship:

```yaml
relationships:
  sensitive_documents:
    type: has_many
    model: document
    capability: manage_options  # Only admins can connect documents
```

---

## Query API

### The Relations Facade

Central entry point for relationship queries:

```php
use Saltus\WP\Framework\Features\Relationships\Relations;
```

### Get Related Posts

#### Basic Usage

```php
// Get a movie with its related actors
$movie = Relations::for('movie', 123)
    ->with('actors')
    ->get();

// Access related posts
foreach ($movie->actors as $actor) {
    echo $actor->post_title;
    
    // Access pivot data
    echo $actor->pivot->role;  // Character name
    echo $actor->pivot->screen_time;  // Minutes
}
```

#### Multiple Relationships

```php
// Eager load multiple relationships
$movie = Relations::for('movie', 123)
    ->with(['actors', 'director', 'genres'])
    ->get();

// $movie->actors is array of WP_Post
// $movie->director is single WP_Post or null
// $movie->genres is array of WP_Post
```

#### Nested Relationships

```php
// Load relationships of related posts
$movie = Relations::for('movie', 123)
    ->with([
        'actors' => function($query) {
            $query->with('agency');  // Each actor's agency
        },
        'director'
    ])
    ->get();

// Access nested
foreach ($movie->actors as $actor) {
    echo $actor->agency->post_title;
}
```

### Query Related Posts

#### Where Has (Filter by Related)

```php
// Get all movies that have actors
$movies = Relations::where_has('movie', 'actors')->get();

// Get all movies that have a specific actor
$movies = Relations::where_has('movie', 'actors', function($query) {
    $query->where('to_post_id', 456);
})->get();
```

#### With Count

```php
// Get movies with actor count
$movies = Relations::with_count('movie', 'actors')->get();

foreach ($movies as $movie) {
    echo "{$movie->post_title} has {$movie->actors_count} actors";
}
```

#### Order By Count

```php
// Get movies ordered by number of actors (descending)
$popular_movies = Relations::with_count('movie', 'actors')
    ->order_by('actors_count', 'DESC')
    ->limit(10)
    ->get();
```

### Lazy Loading

If you don't eager load, relationships lazy load on access:

```php
$movie = get_post(123);

// First access queries the database
$actors = Relations::load($movie, 'actors');

// Subsequent access uses cached result
$actors_again = Relations::load($movie, 'actors');  // No query
```

### Check Relationship Exists

```php
// Check if movie is related to specific actor
$is_related = Relations::exists('movie', 123, 'actors', 456);

if ($is_related) {
    echo "Actor is in this movie";
}
```

---

## Mutator API

### Create Relationship

```php
use Saltus\WP\Framework\Features\Relationships\Relations;

// Connect movie to actor
Relations::create('movie', 123, 'actors', 456);

// With pivot data
Relations::create('movie', 123, 'actors', 456, [
    'role' => 'James Bond',
    'screen_time' => 120,
]);
```

### Delete Relationship

```php
// Disconnect movie from actor
Relations::delete('movie', 123, 'actors', 456);
```

### Sync Relationships

Replace all related posts:

```php
// Replace all actors for this movie
Relations::sync('movie', 123, 'actors', [456, 789, 101]);

// Sync with pivot data
Relations::sync('movie', 123, 'actors', [
    456 => ['role' => 'Hero', 'screen_time' => 90],
    789 => ['role' => 'Villain', 'screen_time' => 60],
]);
```

### Attach/Detach (Alias Methods)

```php
// Attach = create
Relations::attach('movie', 123, 'actors', 456);

// Detach = delete
Relations::detach('movie', 123, 'actors', 456);

// Detach all actors
Relations::detach_all('movie', 123, 'actors');
```

---

## REST API

### Endpoints

Base namespace: `saltus-framework/v1`

#### Get Relationships for Post Type

```
GET /saltus-framework/v1/relationships/{post_type}
```

**Response:**
```json
{
  "post_type": "movie",
  "relationships": [
    {
      "name": "director",
      "type": "has_one",
      "target_post_type": "person",
      "reciprocal": "directed_movies",
      "pivot_fields": []
    },
    {
      "name": "actors",
      "type": "has_many",
      "target_post_type": "person",
      "reciprocal": "acted_in",
      "pivot_fields": [
        {
          "name": "role",
          "type": "text",
          "label": "Character Role"
        }
      ]
    }
  ]
}
```

#### Get Related Posts

```
GET /saltus-framework/v1/posts/{post_id}/relationships/{relationship_name}
```

**Example:**
```
GET /saltus-framework/v1/posts/123/relationships/actors
```

**Response:**
```json
{
  "post_id": 123,
  "relationship": "actors",
  "related_posts": [
    {
      "id": 456,
      "title": "Daniel Craig",
      "post_type": "person",
      "pivot": {
        "role": "James Bond",
        "screen_time": 120
      }
    },
    {
      "id": 789,
      "title": "Javier Bardem",
      "post_type": "person",
      "pivot": {
        "role": "Silva",
        "screen_time": 45
      }
    }
  ],
  "total": 2
}
```

**Query Parameters:**
- `per_page` (int, default: 10) - Number of results
- `page` (int, default: 1) - Page number
- `orderby` (string) - Order by field
- `order` (string) - ASC or DESC

#### Create Relationship

```
POST /saltus-framework/v1/posts/{post_id}/relationships/{relationship_name}
```

**Request Body:**
```json
{
  "related_id": 456,
  "pivot_data": {
    "role": "James Bond",
    "screen_time": 120
  }
}
```

**Response:**
```json
{
  "success": true,
  "relationship_id": 789,
  "message": "Relationship created"
}
```

#### Delete Relationship

```
DELETE /saltus-framework/v1/posts/{post_id}/relationships/{relationship_name}/{related_id}
```

**Response:**
```json
{
  "success": true,
  "message": "Relationship deleted"
}
```

#### Sync Relationships

```
PUT /saltus-framework/v1/posts/{post_id}/relationships/{relationship_name}
```

**Request Body:**
```json
{
  "related_ids": [456, 789],
  "pivot_data": {
    "456": {"role": "Hero"},
    "789": {"role": "Villain"}
  }
}
```

**Response:**
```json
{
  "success": true,
  "attached": [456, 789],
  "detached": [101],
  "message": "Relationships synced"
}
```

### Permission Model

All relationship REST endpoints check:

1. **Read operations:** `current_user_can('read_post', $post_id)`
2. **Write operations:** `current_user_can($capability, $post_id)` where `$capability` is from relationship config

Default capability: `edit_posts`

---

## MCP/Abilities Tools

### get_relationships

**Description:** List all relationship definitions for a post type or all post types

**Parameters:**
```json
{
  "post_type": "movie"  // Optional: filter to one post type
}
```

**Returns:**
```json
{
  "relationships": [
    {
      "post_type": "movie",
      "name": "director",
      "type": "has_one",
      "target_post_type": "person",
      "reciprocal": "directed_movies"
    }
  ]
}
```

### get_related_posts

**Description:** Get all posts related to a specific post

**Parameters:**
```json
{
  "post_id": 123,
  "relationship": "actors",
  "per_page": 10,
  "page": 1
}
```

**Returns:**
```json
{
  "post_id": 123,
  "relationship": "actors",
  "related": [
    {
      "id": 456,
      "title": "Daniel Craig",
      "post_type": "person",
      "pivot": {"role": "James Bond"}
    }
  ],
  "total": 2,
  "pages": 1
}
```

### create_relationship

**Description:** Connect two posts via a relationship

**Parameters:**
```json
{
  "from_post_id": 123,
  "relationship": "actors",
  "to_post_id": 456,
  "pivot_data": {
    "role": "James Bond"
  }
}
```

**Returns:**
```json
{
  "success": true,
  "relationship_id": 789
}
```

### delete_relationship

**Description:** Disconnect two posts

**Parameters:**
```json
{
  "from_post_id": 123,
  "relationship": "actors",
  "to_post_id": 456
}
```

**Returns:**
```json
{
  "success": true
}
```

---

## WebMCP Tools

### Frontend-Safe Tools

Only these tools are exposed on `webmcp: { frontend: true }`:

#### get_relationships (read-only)

Same as MCP tool, but only returns relationships for public post types.

#### get_related_posts (read-only)

Same as MCP tool, but only returns published, public posts.

**Mutations (`create_relationship`, `delete_relationship`) are NOT available on frontend.**

---

## WP-CLI Commands

### List Relationships

```bash
wp saltus relationship list [--post-type=<type>] [--format=<format>]
```

**Example:**
```bash
$ wp saltus relationship list --post-type=movie --format=table

+-----------+----------+-------------------+-------------------+
| name      | type     | target_post_type  | reciprocal        |
+-----------+----------+-------------------+-------------------+
| director  | has_one  | person            | directed_movies   |
| actors    | has_many | person            | acted_in          |
+-----------+----------+-------------------+-------------------+
```

### Get Related Posts

```bash
wp saltus relationship get <post_id> <relationship> [--format=<format>]
```

**Example:**
```bash
$ wp saltus relationship get 123 actors --format=json

[
  {
    "id": 456,
    "title": "Daniel Craig",
    "pivot": {"role": "James Bond"}
  }
]
```

### Create Relationship

```bash
wp saltus relationship create <from_id> <relationship> <to_id> [--pivot=<json>]
```

**Example:**
```bash
wp saltus relationship create 123 actors 456 --pivot='{"role":"James Bond"}'
```

### Delete Relationship

```bash
wp saltus relationship delete <from_id> <relationship> <to_id>
```

### Sync Relationships

```bash
wp saltus relationship sync <from_id> <relationship> <to_ids> [--pivot=<json>]
```

**Example:**
```bash
wp saltus relationship sync 123 actors 456,789,101
```

---

## Filters & Actions

### Filters

#### `saltus/framework/relationships/config`

Modify relationship config before registration:

```php
add_filter('saltus/framework/relationships/config', function($config, $post_type, $name) {
    // Force all relationships to require manage_options
    $config['capability'] = 'manage_options';
    return $config;
}, 10, 3);
```

#### `saltus/framework/relationships/query_args`

Modify WP_Query args when loading related posts:

```php
add_filter('saltus/framework/relationships/query_args', function($args, $relationship) {
    // Only load published posts
    $args['post_status'] = 'publish';
    return $args;
}, 10, 2);
```

#### `saltus/framework/relationships/pivot_data`

Modify pivot data before saving:

```php
add_filter('saltus/framework/relationships/pivot_data', function($data, $relationship_key) {
    // Add timestamp
    $data['updated_at'] = current_time('mysql');
    return $data;
}, 10, 2);
```

### Actions

#### `saltus/relationships/created`

Fired after a relationship is created:

```php
add_action('saltus/relationships/created', function($relationship_key, $from_id, $to_id) {
    error_log("Created: {$relationship_key} between {$from_id} and {$to_id}");
}, 10, 3);
```

#### `saltus/relationships/deleted`

Fired after a relationship is deleted:

```php
add_action('saltus/relationships/deleted', function($relationship_key, $from_id, $to_id) {
    // Clean up related data
}, 10, 3);
```

#### `saltus/relationships/synced`

Fired after sync operation:

```php
add_action('saltus/relationships/synced', function($relationship_key, $from_id, $attached, $detached) {
    // $attached = array of IDs that were connected
    // $detached = array of IDs that were disconnected
}, 10, 4);
```

---

## Performance Considerations

### N+1 Query Problem

**Bad (N+1):**
```php
$movies = get_posts(['post_type' => 'movie', 'posts_per_page' => 10]);

foreach ($movies as $movie) {
    // This queries the database every iteration
    $actors = Relations::load($movie, 'actors');  // 10 queries
}
```

**Good (Eager Loading):**
```php
$movies = Relations::for('movie')
    ->with('actors')
    ->get(['posts_per_page' => 10]);

foreach ($movies as $movie) {
    // Already loaded, no query
    $actors = $movie->actors;  // 0 queries
}
// Total: 2 queries (1 for movies, 1 for all actors)
```

### Query Limits

When querying many related posts, use pagination:

```php
// Bad: Load all 1000 actors
$movie->actors;  // Memory spike

// Good: Paginate
Relations::for('movie', 123)
    ->with(['actors' => function($query) {
        $query->per_page(20)->page(1);
    }])
    ->get();
```

### Caching

Related posts are cached per request:

```php
// First call queries DB
$actors1 = Relations::load($movie, 'actors');

// Second call uses cache
$actors2 = Relations::load($movie, 'actors');  // No query

// Clear cache if needed
Relations::clear_cache($movie);
```

---

## Migration from Other Systems

### From ACF Relationship Fields

```php
// ACF stores relationships in postmeta as serialized arrays
$acf_actors = get_post_meta($movie_id, 'actors', true);  // [456, 789]

// Migrate to Saltus
foreach ($acf_actors as $actor_id) {
    Relations::create('movie', $movie_id, 'actors', $actor_id);
}

// Clean up old meta
delete_post_meta($movie_id, 'actors');
```

### From Posts 2 Posts (P2P)

```php
// P2P uses its own table
global $wpdb;
$p2p_connections = $wpdb->get_results($wpdb->prepare(
    "SELECT p2p_to FROM {$wpdb->p2p} WHERE p2p_from = %d AND p2p_type = %s",
    $movie_id,
    'movie_to_actor'
));

// Migrate to Saltus
foreach ($p2p_connections as $row) {
    Relations::create('movie', $movie_id, 'actors', $row->p2p_to);
}
```

### Migration Script Template

```php
<?php
/**
 * Migrate ACF Relationships to Saltus
 * 
 * Usage: wp eval-file migrate-acf-relationships.php
 */

$movies = get_posts(['post_type' => 'movie', 'posts_per_page' => -1]);

foreach ($movies as $movie) {
    // Get ACF actors
    $acf_actors = get_post_meta($movie->ID, 'actors', true);
    
    if (empty($acf_actors)) {
        continue;
    }
    
    // Sync to Saltus
    Relations::sync('movie', $movie->ID, 'actors', $acf_actors);
    
    // Backup old data (don't delete yet)
    update_post_meta($movie->ID, '_acf_actors_backup', $acf_actors);
    
    WP_CLI::log("Migrated {$movie->post_title}: " . count($acf_actors) . " actors");
}

WP_CLI::success("Migration complete!");
```

---

## Error Handling

### Exceptions

```php
use Saltus\WP\Framework\Features\Relationships\Exceptions\RelationshipNotFoundException;
use Saltus\WP\Framework\Features\Relationships\Exceptions\InvalidRelationshipException;

try {
    Relations::create('movie', 123, 'nonexistent_rel', 456);
} catch (RelationshipNotFoundException $e) {
    // Relationship not defined in model config
    error_log($e->getMessage());
} catch (InvalidRelationshipException $e) {
    // Invalid configuration or data
    error_log($e->getMessage());
}
```

### Validation Errors

```php
// Check if posts exist before creating relationship
if (!get_post(123) || !get_post(456)) {
    throw new \InvalidArgumentException('One or both posts do not exist');
}

// Check if user can edit
if (!current_user_can('edit_post', 123)) {
    throw new \WP_Error('permission_denied', 'You cannot edit this post');
}
```

---

## Code Examples

### Example 1: Movie Database

```php
// models/movie.yaml
relationships:
  director:
    type: has_one
    model: person
    reciprocal: directed_movies
  actors:
    type: has_many
    model: person
    reciprocal: acted_in
    meta:
      role: { type: text }
      billing_order: { type: number }

// Template: single-movie.php
<?php
$movie = Relations::for('movie', get_the_ID())
    ->with(['director', 'actors'])
    ->get();
?>

<h1><?php echo $movie->post_title; ?></h1>

<p><strong>Director:</strong> <?php echo $movie->director->post_title; ?></p>

<h2>Cast</h2>
<ul>
<?php foreach ($movie->actors as $actor): ?>
    <li>
        <?php echo $actor->post_title; ?> 
        as <?php echo $actor->pivot->role; ?>
    </li>
<?php endforeach; ?>
</ul>
```

### Example 2: Team Directory

```php
// models/person.yaml
relationships:
  department:
    type: belongs_to
    model: department
    reciprocal: members
  projects:
    type: many_to_many
    model: project
    reciprocal: team_members
    meta:
      role: { type: text }
      hours_allocated: { type: number }

// Query: All people in Engineering
$engineers = Relations::where_has('person', 'department', function($query) {
    $query->where('to_post_id', $engineering_dept_id);
})->get();

// Query: Projects by team size (largest first)
$projects = Relations::with_count('project', 'team_members')
    ->order_by('team_members_count', 'DESC')
    ->get();
```

### Example 3: E-commerce

```php
// models/product.yaml
relationships:
  category:
    type: belongs_to
    model: product_category
    reciprocal: products
  brand:
    type: belongs_to
    model: brand
    reciprocal: products
  related_products:
    type: many_to_many
    model: product
    reciprocal: related_to

// Template: Show related products
<?php
$product = Relations::for('product', get_the_ID())
    ->with('related_products')
    ->get();
?>

<h2>You Might Also Like</h2>
<?php foreach ($product->related_products as $related): ?>
    <div class="product-card">
        <h3><?php echo $related->post_title; ?></h3>
    </div>
<?php endforeach; ?>
```

---

**Document Status:** Ready for Engineering Review  
**Next Steps:** Code review, implementation begins Week 1  
**Feedback:** Submit GitHub issues with `phase-10` label
