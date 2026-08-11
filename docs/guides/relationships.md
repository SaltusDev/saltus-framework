# Relationships

Relate posts to other posts from model config. A relationship is declared once,
on one model, and is immediately readable and writable from both sides through
REST, MCP, and WP-CLI.

## Declaring a relationship

Add a `relationships` section to a model config file. Each key is the
relationship name as you will refer to it everywhere else.

```yaml
# src/models/movie.yaml
name: movie
type: post_type

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
      role:
        type: text
      screen_time:
        type: number

  genres:
    type: many_to_many
    model: genre
    reciprocal: movies
```

Nothing needs to be declared on `person` or `genre`. Each `reciprocal` name is
registered on the target model automatically.

### Options

| Key | Required | Default | Purpose |
|---|---|---|---|
| `type` | yes | `has_many` | Cardinality: `has_one`, `has_many`, `belongs_to`, or `many_to_many`. |
| `model` | yes | — | Post type slug on the far end. |
| `reciprocal` | no | none | Name to register on the target model for the reverse direction. |
| `meta` (or `pivot`) | no | none | Fields stored on the relationship itself rather than on either post. |
| `cascade_delete` | no | `false` | Delete related posts when the declaring post is deleted. |
| `capability` | no | none | Capability recorded on the definition for site-specific gating. |
| `key` | no | derived | Explicit storage key, for adopting existing relationship data. |

A declaration naming an unknown cardinality, or omitting `model`, is skipped
rather than guessed at — a silently wrong cardinality would let writes through
that the schema is meant to reject.

### Cardinality

`has_one` accepts a single related post; the other three accept many. The limit
is enforced from both directions, so a `has_one` declared on `movie` cannot be
given a second target by writing through `person.directed_movies`.

To replace the target of a `has_one`, use sync rather than attach — attaching a
second post returns a `409` with code `saltus_relationship_cardinality`.

## Storage

Rows live in a dedicated `{prefix}saltus_relationships` table, created on first
use. Both sides of a relationship share one row: the reciprocal flips the query
direction rather than writing a second copy, so the two can never disagree.

The pair `(relationship_key, from_post_id, to_post_id)` is unique. Re-attaching
an existing pair updates its pivot payload and position instead of duplicating
it.

The table exists because every read here spans two posts. Post meta would need
one query per post to answer "what is related to these fifty posts" — the N+1
pattern the eager-loading read below exists to avoid.

## Reading in PHP

```php
$manager = $relationships->manager();

// Related ids, in stored order.
$actor_ids = $manager->get_related_ids( $movie_id, 'movie', 'actors' );

// Full rows: id, post_id, post_type, title, order_index, pivot.
$actors = $manager->get_related( $movie_id, 'movie', 'actors' );

// The same relationship read from the far side.
$movies = $manager->get_related( $person_id, 'person', 'acted_in' );
```

### Eager loading

Resolving a relationship across a result set costs one query, not one per post:

```php
$grouped = $manager->get_related_for_posts( $movie_ids, 'movie', 'actors' );

foreach ( $movie_ids as $movie_id ) {
    // Always present, empty array when nothing is related.
    $actors = $grouped[ $movie_id ];
}
```

## Writing in PHP

```php
// Attach, optionally with pivot values and an explicit position.
$manager->attach( $movie_id, 'movie', 'actors', $person_id, [ 'role' => 'Ripley' ] );

// Detach one pair.
$manager->detach( $movie_id, 'movie', 'actors', $person_id );

// Replace the whole set, in the order given.
$manager->sync( $movie_id, 'movie', 'actors', [ $second_id, $first_id ] );
```

Every write returns either an array describing the result or a `WP_Error`.
Writes are refused when the relationship is not declared, the related post does
not exist or is of the wrong post type, a post is related to itself, or the
declared cardinality would be exceeded. Pivot keys the relationship does not
declare are discarded rather than stored.

`sync` validates every target before it clears anything, so a rejected call
leaves the existing set untouched instead of half-written. It accepts at most
200 ids per call.

## In the post editor

Every post type in a relationship gets a **Relationships** metabox with no
configuration. Search for a post by title, select it, drag or Alt+Arrow to
reorder, Remove to detach. One field appears per relationship.

Both sides of a relationship get a picker. Declaring `movie.actors` gives movies
an "Actors" field *and* people an "Acted In" field, because the reciprocal is a
first-class relationship rather than a read-only view. Editing either side writes
the same row.

A relationship declaring a `capability` is hidden from users who lack it, and a
save by such a user leaves it untouched rather than clearing it.

The picker submits the whole set on save and applies it with `sync()`, so
removing everything from a field and saving clears that relationship. Saves that
never rendered the picker — REST, WP-CLI, another plugin calling
`wp_update_post()` — leave stored relationships alone.

Search queries WordPress core's own `/wp/v2/{post_type}` endpoint, so results
respect core's capability handling and no additional Saltus route is involved.
The target post type needs `show_in_rest` enabled, which is the Saltus default.

Keyboard: `↑`/`↓` move through results, `Enter` selects, `Esc` closes,
`Alt`+`↑`/`↓` reorders the selected set.

## On the post list

Each relationship also gets a column on the post list table, showing up to three
related posts as links to their editors and summarizing the rest as "+N more".
The whole page is resolved in one query per relationship, not one per row.

Two bulk actions per relationship — **Attach to …** and **Detach from …** — apply
to every checked post. Choose the post to attach or detach with the
`saltus_rel_target` query argument; without it the action returns you to the list
with your selection intact and asks for one.

Bulk operations run the same per-post checks a single attach does. Attaching a
second post to a `has_one` relationship is refused for that post and reported in
the result count rather than overwriting what is there, and posts you cannot edit
are skipped rather than failing the whole run.

## REST

| Route | Methods | Purpose |
|---|---|---|
| `/relationships/{post_type}` | GET | List relationships a post type declares. |
| `/posts/{post_id}/relationships/{relationship}` | GET | Read related posts. |
| `/posts/{post_id}/relationships/{relationship}` | POST | Attach one related post. |
| `/posts/{post_id}/relationships/{relationship}` | PUT | Replace the related set. |
| `/posts/{post_id}/relationships/{relationship}/{related_id}` | DELETE | Detach one related post. |

All routes are under `saltus-framework/v1/`. Reads require the post type's
`edit_posts` capability; writes are checked against the specific post being
changed, so a user able to edit one post cannot rewrite relationships on
another.

```bash
curl -X POST https://example.test/wp-json/saltus-framework/v1/posts/42/relationships/actors \
  -H 'Content-Type: application/json' \
  -H 'X-WP-Nonce: <nonce>' \
  -d '{"related_id": 108, "pivot": {"role": "Ripley"}}'
```

### Gating

Routes follow the same per-model opt-in as every other Saltus REST surface. A
`relationships` section without a `show_in_rest` key resolves to enabled; set it
to `false` to turn the routes off for that model:

```yaml
relationships:
  show_in_rest: false
```

## MCP

Five abilities cover the same surface: `list_relationships`, `get_related`,
`attach_related`, `detach_related`, and `sync_related`. Reads are cacheable;
the three writes are not.

All three writes are treated as mutating, so they pass through the editorial
review queue wherever it is enabled: the call returns a pending proposal id
rather than applying the change, and the write lands on approval. See
[MCP/Abilities](/mcp/abilities) for the generated parameter reference.

## WP-CLI

```bash
wp saltus relationship list <post-type>
wp saltus relationship get <post-id> <relationship>
wp saltus relationship attach <post-id> <relationship> <related-id> [--pivot=<json>]
wp saltus relationship detach <post-id> <relationship> <related-id>
wp saltus relationship sync <post-id> <relationship> <json|@file>
```

Unlike the MCP abilities above, these commands apply immediately — they call
`RelationshipManager` directly and do not create a proposal. The same is true of
the REST routes. The review queue governs the agent surfaces, where a caller may
be an autonomous client; a WP-CLI or REST caller has already cleared a WordPress
capability check, and that is the gate on those paths.

```bash
wp saltus relationship attach 42 actors 108 --pivot='{"role":"Ripley"}'
wp saltus relationship sync 42 actors '[108,109]'
```

## Deleting posts

Deleting a post removes its relationship rows on `before_delete_post`, while the
post type is still resolvable.

When a relationship declares `cascade_delete: true`, its related posts are
deleted along with the declaring post — but only those no other post still
references, so shared targets survive. Cascade belongs to the declaring side
only: deleting a target never deletes the post that declared the cascade.

## Actions

Each write fires an action carrying the relationship name, both post ids, and
the storage key:

```php
add_action( 'saltus/framework/relationships/attached', function ( $name, $post_id, $related_id, $key ) {
    // React to a new relationship.
}, 10, 4 );
```

`attached`, `detached`, and `synced` are fired. On `synced` the related id is
`0`, since the event describes the set rather than one pair.
