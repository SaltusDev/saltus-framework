# Evaluation: WebMCP Declarative Forms for Codestar Markup

**Date:** 2026-08-08
**Status:** Complete — recommendation below
**Scope:** Phase 8B checklist items "declarative forms API evaluation" and "accessibility pass on
metabox/settings labels feeding declarative schema derivation"
**Verdict:** **No-go for 8B.** Revisit only after a Codestar accessibility pass, which is worth
doing on its own merits.

---

## The question

WebMCP has two halves. The imperative half — `document.modelContext.registerTool()` — is what
Phase 8A shipped and 8B extends. The declarative half turns an existing `<form>` into a tool with
two attributes:

```html
<form toolname="createSupportRequest" tooldescription="Submits a support request." action="/submit">
  <label for="firstName">First Name</label>
  <input type="text" name="firstName" id="firstName">
</form>
```

Its appeal for a framework like Saltus is that **the schema is derived, not authored**. Field
`name` becomes the property name, `<label>` text becomes the description, `required` becomes a
`required` entry, `<select>` becomes an `anyOf` of `const`/`title` pairs. If it worked on our
markup, Saltus would expose every Codestar-rendered metabox and settings form as an agent-callable
tool with no per-field-type schema work — 45 field types for free.

The discovery notes flagged this as "the cheapest path to exposing Codestar-rendered forms without
hand-authoring schemas for every field type." This evaluation checks that claim against the actual
markup.

---

## What the markup actually looks like

Verified against `lib/codestar-framework/` at the version vendored in this repo.

### Finding 1: field titles are not labels

`CSF_Setup::render_field()` emits the title as a heading in a sibling div, not a `<label>`:

```php
// lib/codestar-framework/classes/setup.class.php:760-767
if ( ! empty( $field['title'] ) ) {
  echo '<div class="csf-title">';
  echo '<h4>'. $field['title'] .'</h4>';
  echo ( ! empty( $field['subtitle'] ) ) ? '<div class="csf-subtitle-text">'. $field['subtitle'] .'</div>' : '';
  echo '</div>';
}
echo ( ! empty( $field['title'] ) ) ? '<div class="csf-fieldset">' : '';
```

The declarative API reads property descriptions from `<label>` text, with `aria-description` as the
fallback and `toolparamdescription` as an explicit override. An `<h4>` in a preceding div is none of
those. **Every derived property would have no description** — which is the single most valuable
thing the derivation was going to give us, since the field titles are exactly the human-readable
names an agent needs.

### Finding 2: no field input carries an `id`, so no label could be associated anyway

```
$ grep -rn '<input[^>]*id="' --include=*.php lib/codestar-framework/fields/ | wc -l
0
```

Inputs are rendered with `name`, `value`, and `data-depend-id`, but no `id`:

```php
// lib/codestar-framework/fields/text/text.php:23
echo '<input type="'. esc_attr( $type ) .'" name="'. esc_attr( $this->field_name() ) .'" value="'. esc_attr( $this->value ) .'"'. $this->field_attributes() .' />';
```

So even if titles were changed to `<label>`, there is no `for`/`id` pair to bind them. Fixing
Finding 1 without fixing this yields an unassociated label — no better for the API, and no better
for a screen reader.

### Finding 3: zero ARIA attributes across all 45 field types

```
$ grep -rl "aria-" --include=*.php lib/codestar-framework/fields/ | wc -l
0
$ ls lib/codestar-framework/fields/ | wc -l
45
```

`aria-description` is the API's documented fallback for property descriptions. It is available
nowhere in the vendored framework.

The 16 `<label>` elements that *do* exist are wrapper labels around radio and checkbox options
(`<label><input type="radio" …>`), which describe individual *options*, not the field. Useful for a
human clicking a radio; not a field-level description the API can lift.

### Finding 4: field names are bracketed, producing unusable property names

`CSF_Fields::field_name()` composes names as `unique[field_id]`:

```php
// lib/codestar-framework/classes/fields.class.php:29
$unique_id  = ( ! empty( $this->unique ) ) ? $this->unique .'['. $field_id .']' : $field_id;
```

Real names therefore look like `book_options[isbn]`, and repeaters nest further —
`book_options[authors][0][name]`. As derived JSON Schema property names those are hostile to an
agent: the 30-character tool-name budget is separate, but a model filling
`book_options[points_info][0][coordinates][latitude]` is being asked to reconstruct PHP's array
serialization from a flat key. Saltus already solved this properly for MCP with
`MetaFieldProvider`'s normalized dotted paths (`points_info.coordinates.latitude`) — the declarative
derivation would bypass that work and expose the raw form encoding instead.

### Finding 5: the metabox has no form of its own

Settings screens render a real form (`admin-options.class.php:489`), so `toolname` would have
somewhere to attach. Metaboxes do not — they render a `<div class="csf csf-metabox">` inside the
post editor's own `<form id="post">`. Attaching `toolname` there would scope the tool to *the entire
post editor form*, including title, content, status, and every other metabox on the screen. Submitting
it as an agent tool would mean submitting the whole post editor.

That is not a tool. That is a save button with extra steps, and it is precisely the "tools break on
pages where they do not apply" failure the discovery notes recorded from Shopify's uniform rollout.

---

## Why this does not block 8B

The governed write path already delivers what the declarative API was going to be a shortcut to.
`AdminTool` projects the existing abilities, whose parameter schemas are hand-maintained, correct,
and already normalized for nested meta. Writes flow through `ProposalService` to the review queue.
An agent editing a book calls `update_post` and `update_meta_fields` with clean dotted paths — a
better interface than a derived schema of `book_options[...]` keys would have produced, and it works
in Chrome 149 through 157+ rather than only where the declarative half has shipped.

The declarative API was an *efficiency* bet, not a capability bet. The efficiency is not there at the
current markup quality, and the capability is covered.

---

## Accessibility pass: findings and recommendation

The checklist paired the evaluation with an accessibility pass, on the reasoning that the
declarative API rewards accessibility work twice. That reasoning holds, and the audit above **is**
the accessibility finding — the same four defects block both:

| Defect | Declarative impact | Accessibility impact |
|---|---|---|
| Title is `<h4>`, not `<label>` | No property description | Screen reader announces an unlabeled input |
| No `id` on inputs | No label association possible | `for`/`id` binding impossible |
| No `aria-*` anywhere | No description fallback | No programmatic name or description |
| Bracketed names | Hostile property names | (No direct impact) |

The first three are WCAG 2.1 issues — 1.3.1 Info and Relationships, and 4.1.2 Name, Role, Value —
in vendored third-party code, affecting every Saltus admin screen today regardless of WebMCP.

**This was not fixed as part of 8B, deliberately.** The defects live in `lib/codestar-framework/`,
a vendored dependency. Patching 45 field renderers in vendored code inside a WebMCP phase would
create a maintenance burden disproportionate to the phase, and would land accessibility changes to
every Saltus admin screen inside a browser-agent feature — the wrong place to review them.

Recommended instead, as its own scoped work:

1. Emit `id` alongside `name` in `CSF_Fields::field_attributes()` — one method, all 45 types inherit it.
2. Change `csf-title` from `<h4>` to `<label for="…">`, keeping the class so no CSS changes.
3. Add `aria-describedby` linking `csf-desc-text` to its input.
4. Re-run this evaluation afterwards. With 1–3 done, the declarative API becomes viable for
   **settings screens only** (Finding 5 still rules out metaboxes), and would be a genuine
   addition rather than a replacement for the imperative path.

Codestar's `patches/` directory suggests the vendored copy is already patched locally, so there is a
precedent and a mechanism for this. Note that any patch must survive the vendor update path — which
is exactly why it deserves its own review rather than riding along here.

Full WCAG conformance cannot be claimed from static inspection: it needs manual testing with assistive
technologies and expert review. The findings above are specific, reproducible defects, not a
conformance audit.

---

## Recommendation

**No-go for 8B.** Ship the admin surface on the imperative API, which is what 8B implements.

Do not adopt declarative forms until the Codestar accessibility work lands, and then only for
settings screens. Track the accessibility items as their own task — they are worth doing for the
humans using the admin, independent of whether an agent ever reads them.

---

## References

- [Chrome: Declarative API](https://developer.chrome.com/docs/ai/webmcp/declarative-api)
- [Discovery: WebMCP](webmcp.md) — standards status, security model, adoption data
- `lib/codestar-framework/classes/setup.class.php` — field wrapper rendering
- `lib/codestar-framework/classes/fields.class.php` — name and attribute composition
- `lib/codestar-framework/classes/metabox-options.class.php` — metabox container
- `src/Features/Meta/MetaFieldProvider.php` — the normalized path scheme this would have bypassed
