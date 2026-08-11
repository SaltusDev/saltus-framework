# Codestar Framework Vendored Changes

This file records every edit made to the vendored `lib/codestar-framework/` directory, so a Codestar upgrade can replay them without losing the patches.

Codestar is vendored at an unknown version (no version file in the directory). The framework was last updated in the repository in 2019.

---

## 2026-08-11: Accessibility — WCAG 2.1 compliance (1.3.1, 4.1.2)

**Why:** Four documented defects prevent proper assistive-technology operation and block declarative-forms work. See `notes/discovery/webmcp-declarative-forms.md` for the full evaluation that led to this.

**Files changed:**
- `lib/codestar-framework/classes/fields.class.php`
- `lib/codestar-framework/classes/setup.class.php`

### Change 1: Emit `id` attribute on every field input

**File:** `lib/codestar-framework/classes/fields.class.php`  
**Method:** `field_attributes()`  
**Lines:** ~46-51 (inserted after the `$field_id` and `$attributes` assignment)

**What:** Add an `id` attribute to the attributes array if the field has an id and no explicit `id` attribute was already set.

**Before:**
```php
if ( ! empty( $field_id ) && empty( $attributes['data-depend-id'] ) ) {
  $attributes['data-depend-id'] = $field_id;
}
```

**After:**
```php
// Emit an HTML id attribute for every field, unless one is already set.
// This id is what <label for> targets, and screen readers need it for
// proper field identification. Without it, a label would point nowhere
// and the field is unlabeled to assistive technology.
if ( ! empty( $field_id ) && empty( $attributes['id'] ) ) {
  $attributes['id'] = $field_id;
}

if ( ! empty( $field_id ) && empty( $attributes['data-depend-id'] ) ) {
  $attributes['data-depend-id'] = $field_id;
}
```

**Impact:** All 48 field types inherit this through `CSF_Fields::field_attributes()`. Every field that has a `$field['id']` now emits `id="..."` on its input element.

---

### Change 2: Change title from `<h4>` to `<label for>`

**File:** `lib/codestar-framework/classes/setup.class.php`  
**Method:** Inside the field-rendering loop  
**Lines:** ~760-765

**What:** Replace the `<h4>` wrapper around `$field['title']` with a `<label for="...">`, keeping the `.csf-title` class on the parent div.

**Before:**
```php
if ( ! empty( $field['title'] ) ) {
  echo '<div class="csf-title">';
  echo '<h4>'. $field['title'] .'</h4>';
  echo ( ! empty( $field['subtitle'] ) ) ? '<div class="csf-subtitle-text">'. $field['subtitle'] .'</div>' : '';
  echo '</div>';
}
```

**After:**
```php
if ( ! empty( $field['title'] ) ) {
  $field_id_attr = ! empty( $field['id'] ) ? ' for="'. esc_attr( $field['id'] ) .'"' : '';
  echo '<div class="csf-title">';
  echo '<label'. $field_id_attr .'>'. $field['title'] .'</label>';
  echo ( ! empty( $field['subtitle'] ) ) ? '<div class="csf-subtitle-text">'. $field['subtitle'] .'</div>' : '';
  echo '</div>';
}
```

**Impact:** The `.csf-title` class is preserved, so no CSS changes are needed. The title is now a semantic `<label>` that screen readers associate with the field, rather than a heading in a sibling div.

---

### Change 3: Add `aria-describedby` linking description to input

**File:** `lib/codestar-framework/classes/fields.class.php`  
**Method:** `field_after()` and `field_attributes()`

#### Part A: Emit `id` on the `.csf-desc-text` div

**Method:** `field_after()`  
**Lines:** ~78-84 (the desc line was split into a conditional block)

**Before:**
```php
$output  = ( ! empty( $this->field['after'] ) ) ? '<div class="csf-after-text">'. $this->field['after'] .'</div>' : '';
$output .= ( ! empty( $this->field['desc'] ) ) ? '<div class="clear"></div><div class="csf-desc-text">'. $this->field['desc'] .'</div>' : '';
```

**After:**
```php
$output  = ( ! empty( $this->field['after'] ) ) ? '<div class="csf-after-text">'. $this->field['after'] .'</div>' : '';

if ( ! empty( $this->field['desc'] ) ) {
  $desc_id = ! empty( $this->field['id'] ) ? $this->field['id'] . '-desc' : '';
  $id_attr = $desc_id ? ' id="'. esc_attr( $desc_id ) .'"' : '';
  $output .= '<div class="clear"></div><div class="csf-desc-text"'. $id_attr .'>'. $this->field['desc'] .'</div>';
}
```

#### Part B: Emit `aria-describedby` on the field input

**Method:** `field_attributes()`  
**Lines:** ~62-65 (inserted after the `data-depend-id` block)

**What:** Add an `aria-describedby` attribute pointing to `{field_id}-desc` when the field has both an id and a desc.

**Before:**
```php
if ( ! empty( $field_id ) && empty( $attributes['data-depend-id'] ) ) {
  $attributes['data-depend-id'] = $field_id;
}

if ( ! empty( $this->field['placeholder'] ) ) {
  $attributes['placeholder'] = $this->field['placeholder'];
}
```

**After:**
```php
if ( ! empty( $field_id ) && empty( $attributes['data-depend-id'] ) ) {
  $attributes['data-depend-id'] = $field_id;
}

// Link the input to its description for screen readers. When a field has
// a desc, field_after() emits a div with id="{field_id}-desc". This
// aria-describedby tells assistive technology that relationship, so a
// screen reader announces the description when focusing the field.
if ( ! empty( $field_id ) && ! empty( $this->field['desc'] ) && empty( $attributes['aria-describedby'] ) ) {
  $attributes['aria-describedby'] = $field_id . '-desc';
}

if ( ! empty( $this->field['placeholder'] ) ) {
  $attributes['placeholder'] = $this->field['placeholder'];
}
```

**Impact:** A field with a `desc` now has its description announced by screen readers when the field is focused, satisfying WCAG 2.1 SC 1.3.1 (Info and Relationships) and 4.1.2 (Name, Role, Value).

---

## How to replay these changes after a Codestar upgrade

1. **Backup the current vendored directory:**
   ```bash
   cp -r lib/codestar-framework lib/codestar-framework.backup
   ```

2. **Upgrade Codestar** by replacing `lib/codestar-framework/` with the new version.

3. **Re-apply each change listed above** in the order documented, using the "Before" and "After" snippets as the reference.

4. **Run the test suite** to verify nothing broke:
   ```bash
   composer test
   composer test:phpcs
   ```

5. **Verify the accessibility fixes** by inspecting a rendered metabox in the browser:
   - Check that field inputs have an `id` attribute
   - Check that the title is now a `<label for="...">` 
   - Check that fields with a `desc` have `aria-describedby` pointing to the desc div

6. **Update this file** if the Codestar upgrade changed the affected methods, so the line numbers and context stay accurate.

---

## Notes

- These changes are **additive** — they do not remove or rename anything Codestar provides, only add missing accessibility attributes.
- The `.csf-title` class is preserved when changing from `<h4>` to `<label>`, so existing CSS continues to work without modification.
- If a future Codestar version adds its own `id` or `aria-describedby` handling, the `empty()` guards in our patches will defer to theirs.
