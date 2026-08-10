# Frontend Rendering

Saltus can render model-defined post types on the frontend through a shortcode. Enable it in the model configuration:

```yaml
name: movie
type: post-type
frontend:
  shortcode: true
  shortcode_alias: movies
  templates:
    list: templates/movie-list.php
    single: templates/movie-single.php
```

The framework registers `[saltus_cpt type="movie"]`. When `shortcode_alias` is configured, `[movies]` is also available: the alias is bound to its own model, so it needs no `type`. Passing one explicitly still wins, so `[movies type="movie"]` behaves the same, and an unknown type renders nothing.

## List View

The default view is a list of published posts:

```text
[saltus_cpt type="movie" limit="10" orderby="date" order="desc"]
[saltus_cpt type="movie" taxonomy="genre" terms="action,comedy"]
```

Supported list attributes are `limit`, `orderby`, `order`, `taxonomy`, and `terms`. Limits are capped at 100, order and orderby values are allowlisted, and taxonomy filters are only applied when the taxonomy is registered for the model.

## Single View

Render one published post by ID:

```text
[saltus_cpt type="movie" view="single" id="123"]
```

Posts from another post type, drafts, and missing posts return no output.

## Templates

Template resolution follows this order:

1. The model's `frontend.templates.list` or `frontend.templates.single` path, constrained to the project directory.
2. The active theme's `saltus/{post_type}/list.php` or `single.php` file.
3. Saltus's default `templates/list.php` or `templates/single.php` template.

List templates receive `$posts`, `$meta_by_post`, `$model`, and `$attributes`. Single templates receive `$post`, `$meta`, `$model`, and `$attributes`. Meta values are keyed by the normalized field paths produced from the model's `meta` configuration.
