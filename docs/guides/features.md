# Features Reference

Saltus Framework provides several built-in features that can be enabled per model via the model configuration.

## Admin Columns

Customize the admin post list table with additional columns.

```yaml
features:
  admin_cols:
    enabled: true
    columns:
      genre:
        label: 'Genre'
        callback: 'my_plugin_genre_column'
        sortable: true
```

## Admin Filters

Add filter dropdowns to the admin post list.

```yaml
features:
  admin_filters:
    genre:
      label: 'Filter by Genre'
      taxonomy: 'genre'
```

## Drag & Drop Reordering

Enable drag-and-drop reordering of posts.

```yaml
features:
  draganddrop:
    enabled: true
```

## Duplicate Post

Allow one-click duplication of posts.

```yaml
features:
  duplicate:
    enabled: true
```

## Quick Edit

Add custom fields to the quick edit screen.

```yaml
features:
  quick_edit:
    enabled: true
```

## Remember Tabs

Persist active tab state in admin settings pages.

```yaml
features:
  remember_tabs:
    enabled: true
```

## Single Export

Export individual posts as WXR.

```yaml
features:
  single_export:
    enabled: true
```

## Labels

Customize all labels and messages for your Custom Post Type:

```yaml
labels:
  has_one: 'Movie'
  has_many: 'Movies'
  text_domain: 'my-plugin'
  overrides:
    ui:
      add_new: 'Add New Movie'
      edit_item: 'Edit Movie'
    messages:
      updated: 'Movie updated.'
      created: 'Movie created.'
    labels:
      name: 'Movies'
      singular_name: 'Movie'
```

## Meta Boxes

Define meta boxes with Codestar Framework fields:

```yaml
meta:
  movie_details:
    title: 'Movie Details'
    sections:
      - name: 'general'
        title: 'General'
        fields:
          - id: 'release_date'
            type: 'date'
            title: 'Release Date'
          - id: 'rating'
            type: 'select'
            title: 'Rating'
            options:
              G: 'G'
              PG: 'PG'
              PG-13: 'PG-13'
              R: 'R'
```

## Settings Pages

Define settings pages for your CPT:

```yaml
settings:
  page_args:
    menu_title: 'Movie Settings'
    menu_slug: 'movie-settings'
    parent_slug: 'edit.php?post_type=movie'
    option_name: 'movie_settings'
  sections:
    - name: 'general'
      title: 'General Settings'
      fields:
        - id: 'items_per_page'
          type: 'number'
          title: 'Items Per Page'
```
