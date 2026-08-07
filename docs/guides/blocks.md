# Model-Driven Blocks

Saltus can generate dynamic Gutenberg blocks for any custom post type model. Blocks are registered at runtime with Block API version 3 and rendered on the server, so saved block content stays current when posts or meta values change.

## Enable Blocks

Enable both views with a boolean:

```yaml
type: cpt
name: movie
blocks: true
```

Or enable views independently and configure project templates:

```yaml
type: cpt
name: movie
blocks:
  list: true
  single: true
  templates:
    list: templates/movie-list.php
    single: templates/movie-single.php
```

This registers `saltus/movie-list` and `saltus/movie-single`. A false or omitted view is not registered. Block definitions are generated from model metadata; static `block.json` files are not required.

## List Block

The list block queries published posts from the model's post type. Its inspector controls support:

| Attribute | Type | Default | Notes |
|---|---|---|---|
| `postsToShow` | number | `10` | Clamped to 1-100 |
| `orderBy` | string | `date` | `date`, `title`, `modified`, `menu_order`, or `ID` |
| `order` | string | `DESC` | `ASC` or `DESC` |
| `taxonomy` | string | empty | Must belong to the model post type |
| `terms` | string[] | empty | Term slugs for the selected taxonomy |
| `showExcerpt` | boolean | `true` | Show each post excerpt |
| `showDate` | boolean | `true` | Show each post date |
| `metaFields` | string[] | model fields | Normalized model field paths to render |

## Single Block

The single block renders one published post from the model's post type.

| Attribute | Type | Default | Notes |
|---|---|---|---|
| `postId` | number | `0` | Selected post ID; invalid or unpublished posts render nothing |
| `showTitle` | boolean | `true` | Show the post title |
| `showContent` | boolean | `true` | Show the post content |
| `metaFields` | string[] | model fields | Normalized model field paths to render |

The editor fetches post records through the WordPress REST API. Keep `show_in_rest` enabled for the post type, which is the Saltus default.

## Meta Fields

Selectable fields come directly from the model's `meta` definitions through `MetaFieldProvider`. Nested serialized fields use dotted paths such as `movie_details.cast.director`. Block attributes store only selected field paths, never copies of post values.

For REST-writable meta, set `register_rest_api: true` on the metabox. This is separate from block display: blocks can read configured meta whether or not external REST writes are enabled.

## Template Overrides

Saltus resolves a block template in this order:

1. Model path from `blocks.templates.list` or `blocks.templates.single`, relative to the consuming plugin root.
2. Active theme file at `saltus/{post_type}/block-list.php` or `saltus/{post_type}/block-single.php`.
3. Framework default at `templates/blocks/list.php` or `templates/blocks/single.php`.

List templates receive `$posts`, `$meta_fields`, `$meta_by_post`, `$model`, and `$attributes`. Single templates receive `$post`, `$meta`, `$meta_fields`, `$model`, and `$attributes`. Saltus wraps the result with block wrapper attributes.

## Extension Hooks

| Filter | Purpose |
|---|---|
| `saltus/framework/blocks/attributes` | Change the runtime block registration arguments for a post type and view |
| `saltus/framework/blocks/query_args` | Change the list block's `WP_Query` arguments |
| `saltus/framework/blocks/template` | Replace the resolved template path |

Block definitions are also available from `GET /saltus-framework/v1/blocks`, the `saltus/list-block-models` ability, and `wp saltus block list`.
