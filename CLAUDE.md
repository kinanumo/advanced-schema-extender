# Yoast Schema Extender — Agency Pack (v2.5.5)

## What this plugin does
Enriches Yoast SEO's schema graph — never replaces it. Hooks:
- `wpseo_schema_organization` — merges Organization fields (name, url, logo, email, telephone, sameAs, address, geo, openingHours, areaServed) and optionally promotes @type to LocalBusiness.
- `wpseo_schema_graph` — appends multi-location LocalBusiness nodes and per-post FAQPage nodes.

Settings stored in a single WP option: `yse_settings` (key: `YSE_OPTION_KEY`).

---

## Active features (keep all of these)
| Feature | Key class | Schema hook |
|---------|-----------|-------------|
| Organization / LocalBusiness enrichment | `filter_organization_node()` | `wpseo_schema_organization` |
| Multi-location | `build_location_node()`, `filter_schema_graph()` | `wpseo_schema_graph` |
| FAQ Builder | `render_faq_meta_box()`, `save_post_meta()`, `inject_faq_node()` | `wpseo_schema_graph` |
| Status table | `build_status_rows()`, `render_status_table()` | — admin only |
| Import / Export | `handle_import()`, `process_import_payload()`, `render_import_export_panel()` | — admin only |

---

## Permanently removed features — do not re-add
- **Identifiers** — no settings field, no schema output.
- **Page Intent Detection** — no settings field, no schema output.
- **CPT → Schema Mapping** — no settings field, no `sanitize_cpt_map()`, no schema output.
- **Topic Mentions** — no settings field, no schema output.

---

## Bug invariants — never reintroduce

### 1 · FAQ newline handling
`save_post_meta()` stores answers with `<br>` (not literal `\n`):
```php
$raw_a = wp_unslash( ... );
$raw_a = str_replace( "\r\n", "\n", $raw_a );
$raw_a = str_replace( "\r",   "\n", $raw_a );
$a     = str_replace( "\n", '<br>', $raw_a );  // then wp_kses()
```
`render_faq_row()` reverses on load:
```php
$answer_display = preg_replace( '/<br\s*\/?>/i', "\n", $item['a'] );
```
**Never use `nl2br()`** — it appends `\n` after each `<br />`, producing `nn` through subsequent processing.

### 2 · Array-to-string conversion
`sanitize_lines_as_urls()`, `sanitize_lines_as_text()`, and `sanitize_json_field()` all accept `$raw` as either a string or an already-saved array. They route through `coerce_to_lines()` or check `is_array()` first. **Never add `(string)` cast before checking.**

### 3 · PHP closure scope
Any closure that reads an outer variable must explicitly capture it:
```php
// correct
array_filter( $items, function( $item ) use ( $L ) { ... } );
// wrong — $L would be undefined inside the closure
array_filter( $items, function( $item ) { ... $L ... } );
```
Method references (`[$this, 'method']`) do not need `use`.

### 4 · UI layout
The settings page must keep these three structural elements:
1. `.yse-status-wrap` comparison table at the **top** of the page (above the form).
2. Main form wrapped in a `.yse-card` boxed card.
3. `.yse-ie-panel` import/export panel **outside / below** the card.

### 5 · Yoast enrichment — not replacement
- No standalone `<script type="application/ld+json">` block for Organization.
- If Yoast Site Representation = "Person", `wpseo_schema_organization` never fires → enrichment is silently skipped. That is correct behavior.
- `override_org = false` (default): Extender fills only empty Yoast fields.
- `override_org = true`: Extender wins for every scalar/object field; `sameAs` is always merged regardless.

### 6 · FAQ deduplication
`inject_faq_node()` must check for an existing FAQPage node before appending:
```php
foreach ( $graph as $gnode ) {
    if ( in_array( 'FAQPage', (array) ( $gnode['@type'] ?? [] ), true ) ) {
        return $graph;  // already present — do nothing
    }
}
```

### 7 · Multi-location inheritance
`build_location_node()` falls back to global settings **only** for telephone and email:
- `telephone` empty → use `$settings['telephone']`
- `email` empty → use `$settings['org_email']`
- `opening_hours` and `service_area`: **location value only**, no global fallback.

### 8 · `foreach` by-reference cleanup
After any `foreach ( $array as &$item )` loop, always call `unset( $item )` immediately after the closing brace. Without it the last element is still aliased and can be silently corrupted by subsequent code that reuses the variable name.

---

## Sanitization cheatsheet
| Data type | Helper |
|-----------|--------|
| One URL per line, or already-saved array of URLs | `sanitize_lines_as_urls($raw)` |
| One string per line, or already-saved array | `sanitize_lines_as_text($raw)` |
| JSON string or already-decoded array | `sanitize_json_field($raw, $fallback, $key, $label)` |
| Geo coordinate | `sanitize_geo($raw)` |
| LB subtype slug | `sanitize_lb_subtype($raw)` |
| Individual location array | `sanitize_location($raw)` |

---

## Schema hook timing
`hook_schema_filters()` (called from `__construct`) schedules `maybe_register_schema_filter()` on `wp_loaded` so Yoast classes are guaranteed present. Both `wpseo_schema_organization` and `wpseo_schema_graph` are registered inside that callback only if `Abstract_Schema_Piece` exists.
