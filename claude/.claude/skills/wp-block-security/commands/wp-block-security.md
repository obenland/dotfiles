---
description: Audit WordPress blocks for stored XSS in render_callback functions
allowed-tools: [Read, Glob, Grep, Bash]
---

# WordPress Block Security Auditor

Audit this WordPress codebase for stored XSS vulnerabilities in Gutenberg block `render_callback` functions. Trace how user-controlled `$attributes` values flow through PHP render callbacks into HTML output, and flag insufficient or incorrect escaping.

$ARGUMENTS

## Scope

- Only trace the `$attributes` parameter of render callbacks registered via `register_block_type`.
- Do not audit `$content` or `$block` parameters.
- Do not audit blocks registered via `block.json` `render` field.

## Audit Procedure

Follow these 8 steps in order. Do not skip steps.

### Step 1: Locate Block Registrations

Search the codebase for all `register_block_type` calls that include a `render_callback` argument.

```
Grep for: register_block_type\s*\(
Then filter to results that also reference: render_callback
```

Build a list of every block name and its corresponding callback function name or closure. Record the file and line number for each.

### Step 2: Check block.json Attribute Schemas (False-Positive Reduction)

For each block found in Step 1, search for its corresponding `block.json` file. Look in the same directory as the PHP file, or search by block name. If a `block.json` exists, read the `attributes` schema and record the `type` of each attribute.

**Skip attributes with safe types.** WordPress validates attribute values against the `block.json` schema before they reach `render_callback`. Attributes declared with the following types are coerced/validated by WordPress and cannot contain XSS payloads:

| block.json type | Why it's safe | Action |
|----------------|---------------|--------|
| `integer` | WordPress casts to int — no string content possible | Skip — not exploitable |
| `number` | WordPress casts to float — no string content possible | Skip — not exploitable |
| `boolean` | WordPress casts to bool — no string content possible | Skip — not exploitable |

**Still audit these types** — they can carry arbitrary attacker-controlled strings:

| block.json type | Risk |
|----------------|------|
| `string` | Full XSS risk — arbitrary string content |
| `object` | Values within the object may be strings |
| `array` | Items may be strings |
| Not declared in block.json | No schema validation — full XSS risk |

When reporting, if an attribute is skipped due to its block.json type, note it briefly in the Clean Blocks section (e.g., "width: integer per block.json — skipped"). If no `block.json` is found for a block, treat all attributes as untrusted strings and audit normally.

### Step 3: Trace Each Callback

For each `render_callback` found in Step 1, read the full function body. If the callback is a closure defined inline, read the entire closure. If it references a named function, locate and read that function. Follow one level of helper calls — if the callback delegates to another function, read that function too.

### Step 4: Track `$attributes` Data Flow

Within each callback, trace every path that `$attributes` data can take:

- **Direct access:** `$attributes['color']`, `$attributes['url']`, etc.
- **Destructured variables:** `$color = $attributes['color'];` or via `extract()`, `wp_parse_args()`, `array_merge()` with defaults.
- **Nested access:** `$attributes['style']['color']['background']` and similar deep paths.
- **Helper transformations:** Values passed through `sprintf()`, string concatenation, array joins, or helper functions before output.

For each traced value, record: the original attribute key, any intermediate variable names, and where it ultimately appears in output.

### Step 5: Identify Output Contexts

Classify where each traced `$attributes` value is rendered. The context determines which escaping function is required:

| Context | Example | Required Escaping |
|---------|---------|-------------------|
| HTML attribute value | `class="$val"` | `esc_attr()` |
| HTML content | `<span>$val</span>` | `esc_html()` |
| URL (href/src) | `href="$val"` | `esc_url()` |
| Inline CSS (style attr) | `style="$val"` | `esc_attr( safecss_filter_attr( $val ) )` |
| JavaScript | `<script>var x='$val'</script>` | `esc_js()` |
| Numeric context | `width="$val"` | `intval()` or `absint()` |

### Step 6: Evaluate Escaping Adequacy

For each `$attributes` value reaching output, check whether the escaping applied matches the context identified in Step 4. Flag findings that match any of these vulnerability sub-types:

**Sub-type A — No escaping:** The attribute value is interpolated directly into output with no escaping function applied.

**Sub-type B — Wrong escaping function:** An escaping function is used but does not match the output context (e.g., `esc_html()` used inside an HTML attribute, or `esc_url()` used for a CSS value).

**Sub-type C — Too-early escaping:** The value is escaped but then further string manipulation occurs after escaping (e.g., concatenation, `sprintf`, or `str_replace` after `esc_attr()`), potentially introducing unescaped content.

**Sub-type D — Partial escaping in compound output:** Some arguments to `sprintf()` or concatenation are escaped but others are not, or a sanitization function like `safecss_filter_attr()` is used without the required `esc_attr()` wrapper.

Refer to the escaping guide for detailed guidance on each escaping function and common vulnerable patterns:

### Escaping Quick Reference

| Context | Safe function | Common mistake |
|---------|--------------|----------------|
| HTML attribute | `esc_attr()` | Using `sanitize_text_field()` or `wp_kses_post()` |
| HTML content | `esc_html()` | Direct interpolation |
| URL | `esc_url()` | Using `esc_attr()` for href |
| Inline CSS | `esc_attr( safecss_filter_attr() )` | `safecss_filter_attr()` alone — does NOT encode quotes |
| JavaScript | `esc_js()` | Using `esc_attr()` or `esc_html()` |
| Numeric | `intval()` / `absint()` | Trusting string attribute as number |

### Key Vulnerable Patterns to Check

1. **Direct interpolation** — `'<div class="' . $attributes['className'] . '">'`
2. **sprintf without escaping** — `sprintf('<a href="%s">', $attributes['url'])`
3. **safecss_filter_attr without esc_attr** — `sprintf('<div style="%s">', safecss_filter_attr($css))` ← Critical, this was fixed in WordPress/Gutenberg PR #45045
4. **Variable intermediary** — value assigned to `$classes` from `$attributes`, then `$classes` used unescaped in output
5. **Mixed escaped/unescaped sprintf** — some `%s` args escaped, others not
6. **CSS value composition** — building style string from multiple attributes without escaping the final result

### Step 7: Classify Severity

| Severity | Criteria |
|----------|----------|
| **Critical** | Attribute breakout possible — attacker can escape the attribute context entirely (e.g., inject `"` to close attribute, then add `onload=`, `onfocus=`, or new HTML tags). Typically: unescaped or `safecss_filter_attr()` without `esc_attr()` in attribute context. |
| **High** | HTML injection possible — attacker can inject HTML tags or entities in content context. Typically: unescaped value in HTML body. |
| **Medium** | Limited injection — value is partially sanitized but edge cases remain. Typically: wrong escaping function, or `wp_kses_post()` used where `esc_attr()` is needed. |
| **Low** | Theoretical risk — value passes through sanitization that likely prevents exploitation but does not follow WordPress escaping best practices. |

### Step 8: Generate Report

Produce a structured report with the following format.

#### Per-Finding Entry

For each vulnerability found, output:

```
### [SEVERITY] — [Short description]

**File:** `path/to/file.php:LINE`
**Block:** `namespace/block-name`
**Sub-type:** [A|B|C|D] — [description]

**Vulnerable code:**
php
// The relevant code snippet showing the vulnerability


**Explanation:**
[1-3 sentences explaining why this is vulnerable, what context the value appears in, and what escaping is missing or incorrect.]

**Proof-of-Concept payload:**
html
<!-- wp:namespace/block-name {"attribute":"PAYLOAD_HERE"} /-->

Where PAYLOAD_HERE demonstrates the injection (e.g., " onmouseover="alert(document.cookie) for attribute breakout, or <img src=x onerror=alert(1)> for HTML injection).

**Recommended fix:**
php
// The corrected code

```

#### Summary Table

After all findings, produce a summary:

```
| # | Severity | Block | File:Line | Sub-type | Description |
|---|----------|-------|-----------|----------|-------------|
```

#### Clean Blocks

List all audited blocks where no issues were found:

```
Clean blocks (no issues found):
- namespace/block-a
- namespace/block-b
```

## Important Notes

- Always read the actual code. Never assume escaping is present or absent without reading the function body.
- Trace through variable assignments. A value assigned to `$classes` from `$attributes['className']` is still user-controlled.
- Pay special attention to `sprintf()` — check every `%s` argument individually.
- The `safecss_filter_attr()` function filters CSS property names and values but does NOT prevent attribute breakout. It MUST be wrapped in `esc_attr()` when output is inside an HTML attribute.
