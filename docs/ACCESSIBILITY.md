# Accessibility Statement

Saltus Framework aims to produce admin interfaces that are usable with assistive technology. This statement records what has been fixed, what remains, and what has not been verified.

**Last updated:** 2026-08-11

---

## What We Fixed

### Phase 13 Slice 1: Codestar Field Accessibility (2026-08-11)

Four defects were documented during the Phase 8B declarative-forms evaluation (see `notes/discovery/webmcp-declarative-forms.md`). All four have been addressed in the vendored Codestar Framework:

1. **No input `id` attributes** — `CSF_Fields::field_attributes()` now emits an `id` attribute for every field that has a `$field['id']`, inherited by all 48 field types. This `id` is what `<label for>` targets and what assistive technology uses to associate a label with its input.

2. **Titles were `<h4>` in a sibling div, not `<label>`** — `setup.class.php` now renders the field title as `<label for="...">`, linking it to the field's `id`. The `.csf-title` class is preserved, so no CSS changes were needed.

3. **No `aria-describedby` linking descriptions to inputs** — When a field has a `desc`, `field_after()` emits `id="{field_id}-desc"` on the `.csf-desc-text` div, and `field_attributes()` adds `aria-describedby="{field_id}-desc"` to the input. Screen readers now announce the description when the field is focused.

4. **Bracketed names in Codestar (`book_options[isbn]`)** — This remains unchanged in Codestar itself. Saltus's `MetaFieldProvider` already normalizes bracketed paths into dotted paths (`book_options.isbn`) for MCP/REST/CLI surfaces, so this defect only affects the raw Codestar config, not the programmatic interfaces.

**WCAG 2.1 criteria addressed:**
- **1.3.1 Info and Relationships (Level A)** — Form inputs now have programmatically associated labels and descriptions.
- **4.1.2 Name, Role, Value (Level A)** — Every field emits the attributes assistive technology needs to identify it.

**Verification:**
- Changes applied to vendored `lib/codestar-framework/classes/fields.class.php` and `setup.class.php`.
- Replay instructions recorded in `CODESTAR-VENDORED-CHANGES.md` for future Codestar upgrades.
- Full test suite green (522 tests, 1506 assertions), PHPCS clean.
- Visual inspection required: a rendered metabox in the browser confirms `id`, `<label for>`, and `aria-describedby` are present.

---

### Phase 13 Slice 2: Relationship Picker (2026-08-11)

The relationship picker on the post editor was built keyboard-operable and screen-reader-labelled from the start rather than retrofitted:

- **Label and description are programmatically associated.** Each control emits `<label for>` bound to the search input's `id`, and `aria-describedby` pointing at its description — the same pattern the Codestar fixes established, so the picker is consistent with every other field on the screen.
- **Full keyboard operation.** Arrow keys move through search results with `aria-selected` tracking the active option, Enter selects, Escape closes the list, and Alt+Arrow reorders the selected set. Reordering is not mouse-only, which is the usual failure of drag-to-sort UI.
- **Focus is managed on removal.** Removing an item moves focus to the next item, the previous one, or back to the search field. It is never allowed to fall to `<body>`, which would lose a keyboard user's place entirely.
- **Status changes are announced.** A `role="status"` `aria-live="polite"` region reports result counts, additions, removals, and reorder moves. It is visually hidden by clipping rather than `display: none`, which would remove it from the accessibility tree and stop the announcements — the bug that makes most live regions silent.
- **Search results are text, never markup.** Titles come back from core rendered and may carry entities. They are set with `textContent` and a test asserts no child elements are ever constructed from a title.

**Verification:** 16 PHPUnit tests over rendering and save behavior, 11 JS tests over selection state and keyboard reordering. Six guards mutation-tested.

**Not verified:** none of this has been tested with an actual screen reader. The assertions confirm the markup and handlers are present and behave as intended in a synthetic DOM; they cannot confirm that NVDA, JAWS, or VoiceOver announce it usefully. See below.

---

## What Remains

### Manual Assistive-Technology Testing

Everything above is verified by automated assertions on emitted markup and simulated events. That catches regressions but proves less than it appears to: markup can satisfy every ARIA rule and still be confusing to use. Outstanding:

- Screen-reader passes with NVDA, JAWS, and VoiceOver on the picker and on a representative metabox
- Confirmation that the live-region announcements are useful rather than merely present, and not so chatty they drown the content
- Tab-order review on a screen with several relationship fields plus other Codestar fields
- Visible focus indicators checked against the WordPress admin's own styles at each interactive step

---

### Full WCAG Audit

Full WCAG 2.1 conformance validation requires:
- Manual testing with multiple assistive technologies (NVDA, JAWS, VoiceOver, TalkBack)
- Expert accessibility review beyond automated checks
- Testing across user scenarios, not just technical compliance

Saltus has **not** undergone such an audit. The four defects fixed in Phase 13 slice 1 were specific, documented problems. Other accessibility barriers may exist and remain unidentified.

**This statement does not claim full conformance.** It records what was deliberately fixed and what is known to remain.

---

### Known Gaps

- **Codestar's 45 field types** — The three fixes (`id`, `<label>`, `aria-describedby`) apply to the base `CSF_Fields` class, so they inherit to all field types. However, individual field types (e.g., `gallery`, `repeater`, `map`) may have their own accessibility defects in how they render complex interactions. Those have not been individually audited.

- **Color contrast** — Saltus inherits Codestar's styles and WordPress admin's styles. Neither color contrast nor minimum touch target sizes have been verified against WCAG 2.1 criteria.

- **Dynamic content** — Codestar's own JavaScript-driven field interactions (dependency showing/hiding fields, AJAX-powered fields) have not been verified for screen-reader announcements or focus management. The Saltus relationship picker is the exception: its announcements and focus handling are implemented and unit-tested, though not screen-reader-tested.

- **Metabox collapsing** — WordPress's metabox drag-and-drop and collapse/expand UI is core functionality, not Saltus's. Saltus does not modify it and does not claim it is accessible.

---

## What You Can Do

If you encounter an accessibility barrier in a Saltus-generated admin interface:

1. **Report it** — Open a GitHub issue with:
   - The screen reader and browser you are using
   - The specific field type or screen where the barrier exists
   - What you expected vs what happened

2. **Workarounds** — Some barriers can be mitigated with:
   - WordPress's built-in accessibility mode (Users → Your Profile → disable visual editor, enable keyboard shortcuts)
   - Browser extensions that enhance form labels or add missing ARIA
   - Custom CSS to improve color contrast on a per-site basis

3. **Contribute** — Accessibility fixes are welcome contributions. See `CODESTAR-VENDORED-CHANGES.md` for how to apply patches to the vendored framework.

---

## Commitment

Saltus aims to meet WCAG 2.1 Level AA where feasible. The Phase 13 fixes are a step in that direction, not the end of the work. Accessibility is an ongoing process, and this statement will be updated as further fixes land.

**This statement was written by the development team** and has not been reviewed by an independent accessibility expert. If you are an accessibility professional and can help audit Saltus, please reach out via GitHub.

---

## References

- [WCAG 2.1 Guidelines](https://www.w3.org/WAI/WCAG21/quickref/)
- [WordPress Accessibility Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/accessibility/)
- [Codestar Framework](https://github.com/Codestar/codestar-framework) (vendored, version unknown)
- [Phase 13 Roadmap](ROADMAP.md#phase-13-enhanced-ux-v29) — full scope of UX and accessibility work
