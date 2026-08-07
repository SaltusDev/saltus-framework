# Features and Model Reference

Saltus model files return one model array or a list of model arrays. Models may be PHP, JSON, or YAML and are loaded from `src/models/` by default.

## Feature Summary

Features are configured under the CPT model's `features` key. Each value must be truthy to enable the feature. Configuration arrays are passed directly to the feature; do not wrap them in `enabled` or `columns` keys.

| Key | Purpose | Common configuration |
|---|---|---|
| `admin_cols` | Customize the post list columns | Column IDs mapped to native columns or custom column definitions |
| `admin_filters` | Filter the post list | Filter IDs mapped to taxonomy, meta, date, or author definitions |
| `draganddrop` | Reorder posts by `menu_order` | `true` |
| `duplicate` | Duplicate a post from its row actions | `label`, `attr_title`, and optional `show_in_rest` |
| `quick_edit` | Edit text meta from Quick Edit | Meta keys mapped to `title` and `column_name` |
| `remember_tabs` | Restore the active Codestar tab | `true` |
| `single_export` | Export one post as WXR | `label` and optional `show_in_rest` |

`frontend`, `meta`, `settings`, `blocks`, and `ai_context` are top-level model keys, not entries under `features`.

## Frontend Rendering

Enable frontend rendering in a post type model with the top-level `frontend` key:

```yaml
frontend:
  shortcode: true
  shortcode_alias: movies
  templates:
    list: templates/movie-list.php
    single: templates/movie-single.php
```

The framework registers `[saltus_cpt type="movie"]` and an optional alias such as `[movies type="movie"]`. Use `view="single"` with an `id` for one post. List shortcodes accept `limit`, `orderby`, `order`, `taxonomy`, and comma-separated `terms` attributes. Templates receive `$posts`, `$post`, `$meta`, `$model`, and `$attributes` as appropriate.

## Admin Columns

Each key is the resulting column ID. A string keeps a native WordPress column. Custom definitions can display a `meta_key`, `taxonomy`, `post_field`, `featured_image` size, or `function` callback. Set `sortable: false` to disable automatic sorting for supported values.

```yaml
features:
  admin_cols:
    title: title
    poster:
      title: Poster
      featured_image: thumbnail
      sortable: false
    release_year:
      title: Year
      meta_key: release_year
    genre:
      title: Genres
      taxonomy: genre
```

Other useful keys include `cap` to hide a column unless the user has a capability, `prefix`/`suffix` around values, `date_format`, `number_format`, `link`, `title_icon`, and `title_cb`.

## Admin Filters

Filters are selected by their defining key. Supported shapes include `taxonomy`, `meta_key`, `meta_search_key`, `meta_exists`/`meta_key_exists`, `post_date`, and `post_author`.

```yaml
features:
  admin_filters:
    genre:
      taxonomy: genre
      label: All genres
    rating:
      meta_key: rating
      label: All ratings
      options:
        G: G
        PG: PG
        R: R
```

Meta filter options may be an array or callable. Without explicit options, Saltus builds the list from stored values. Use the `saltus/framework/admin_filters/{post_type}/filter_query/{filter_id}` filter for custom query logic.

## Drag And Drop

Enable ordering with a boolean. The admin list becomes sortable and the main query defaults to ascending `menu_order` for that post type.

```yaml
features:
  draganddrop: true
```

Batch reordering over REST/MCP is gated from a separate `drag_and_drop` section, not from the `draganddrop` key above:

```yaml
features:
  draganddrop: true          # admin reordering UI
  drag_and_drop:             # REST/MCP reorder capability
    show_in_rest: true
    show_in_mcp: true
```

The two keys are not interchangeable. See [Permissions](../MCP.md#permissions) for the full resolution rules.

## Duplicate Post

```yaml
features:
  duplicate:
    label: Duplicate movie
    attr_title: Create a draft copy
    show_in_rest: true
```

`label` and `attr_title` customize the row action. `show_in_rest` opts the feature into the Saltus duplicate endpoint; WordPress capability checks still apply.

## Quick Edit

Quick Edit fields are text inputs backed by post meta. The referenced admin column must be present so Saltus can transfer its current value into the form.

```yaml
features:
  admin_cols:
    release_year:
      title: Year
      meta_key: release_year
  quick_edit:
    release_year:
      title: Release year
      column_name: release_year
```

## Remember Tabs

```yaml
features:
  remember_tabs: true
```

This loads the tab persistence script on post edit and new-post screens.

## Single Export

```yaml
features:
  single_export:
    label: Export this movie
    show_in_rest: true
```

Users need WordPress's `export` capability. `show_in_rest` also opts the model into Saltus REST/MCP export handling.

## Labels

Saltus generates the standard WordPress labels from singular and plural names and lets models override specific layers.

| Key | Purpose |
|---|---|
| `has_one` | Singular display name; defaults to the capitalized model name |
| `has_many` | Plural display name; defaults to the model name plus `s` |
| `text_domain` | Model translation domain; defaults to `saltus` |
| `featured_image` | Replaces featured image, set, remove, and use labels |
| `overrides.ui.enter_title_here` | Changes the post title input placeholder |
| `overrides.labels` | Replaces keys in the generated `register_post_type()` or `register_taxonomy()` labels array |
| `overrides.messages` | Replaces individual post update messages |
| `overrides.bulk_messages` | Replaces singular/plural bulk action messages |

```yaml
labels:
  has_one: Movie
  has_many: Movies
  text_domain: my-plugin
  featured_image: Poster
  overrides:
    ui:
      enter_title_here: Enter movie title
    labels:
      all_items: Movie library
    messages:
      post_published: 'Movie published. <a href="{permalink}">View movie</a>'
      post_saved: Movie saved.
    bulk_messages:
      updated_singular: Movie updated.
      updated_plural: '%1$s movies updated.'
```

Post message keys are `post_updated`, `custom_field_updated`, `custom_field_deleted`, `post_updated_short`, `post_restored`, `post_published`, `post_saved`, `post_submitted`, `post_scheduled`, and `post_draft_updated`. Templates may use `{permalink}`, `{date}`, and `{preview_url}`. Bulk prefixes are `updated`, `locked`, `deleted`, `trashed`, and `untrashed`, each with `_singular` and `_plural` variants.

## Meta Boxes

The `meta` array is keyed by metabox ID. Each metabox accepts Codestar metabox arguments plus either a flat `fields` map/list or multiple `sections`. Saltus supplies defaults for `post_type`, `priority`, `context`, `theme`, and `data_type`.

```yaml
meta:
  movie_details:
    title: Movie Details
    context: normal
    priority: high
    register_rest_api: true
    fields:
      release_year:
        type: number
        title: Release year
      rating:
        type: select
        title: Rating
        options:
          G: G
          PG: PG
          R: R
```

Field map keys become IDs when `id` is omitted. Nested `fields` are normalized recursively. Set `data_type: serialize` to store the metabox under its box ID; otherwise fields are stored as separate meta keys. `register_rest_api: true` registers declared fields for REST access and marks them writable in Saltus metadata discovery.

All [Codestar field types](https://codestarframework.com/documentation/#/fields) supported by the bundled version may be used. Saltus maps common field types into JSON-schema-like metadata for REST, MCP, blocks, and WP-CLI discovery.

## Settings Pages

The `settings` array is keyed by the settings ID, which is also the default option name and menu slug. Each page accepts Codestar options arguments and either `fields` or `sections`.

```yaml
settings:
  movie_settings:
    title: Movie Settings
    menu_title: Settings
    menu_parent: edit.php?post_type=movie
    menu_type: submenu
    fields:
      items_per_page:
        type: number
        title: Items per page
        default: 10
```

Saltus defaults `menu_parent` to the CPT menu, `menu_type` to `submenu`, `theme` to `light`, and `menu_slug` to the settings ID. For tabbed pages, replace `fields` with `sections`; each section provides its own `title` and `fields`.

## Related Guides

- [Model-Driven Blocks](./blocks.md)
- [WP-CLI Reference](./wp-cli.md)
- [MCP/Abilities Overview](../mcp/index.md)
