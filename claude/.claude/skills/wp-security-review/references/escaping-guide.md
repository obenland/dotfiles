# WordPress Escaping & Sanitization Reference

Complete guide to WordPress escaping and sanitization functions for security auditing.

## Table of Contents

1. [Escaping Functions by Output Context](#escaping-functions-by-output-context)
2. [Sanitization Functions](#sanitization-functions)
3. [Common Vulnerable Patterns](#common-vulnerable-patterns)
4. [Context-Specific Guidelines](#context-specific-guidelines)
5. [Safe vs Unsafe Functions](#safe-vs-unsafe-functions)

---

## Escaping Functions by Output Context

### HTML Attribute Values

**Function:** `esc_attr($text)`

**Purpose:** Encodes `<`, `>`, `&`, `"`, and `'` so a value cannot break out of a quoted HTML attribute.

**Usage:**
```php
// SAFE
echo '<div class="' . esc_attr($user_input) . '">';
echo '<input value="' . esc_attr($_GET['name']) . '">';
sprintf('<div title="%s">', esc_attr($attributes['title']));
```

**Also available:**
- `esc_attr_e($text)` - escapes and echoes
- `esc_attr_x($text, $context)` - escapes with translation context

### HTML Content (Between Tags)

**Function:** `esc_html($text)`

**Purpose:** Encodes `<`, `>`, `&`, `"`, and `'` so a value cannot inject HTML tags or entities.

**Usage:**
```php
// SAFE
echo '<span>' . esc_html($user_input) . '</span>';
echo '<p>' . esc_html($_POST['comment']) . '</p>';
sprintf('<div>%s</div>', esc_html($content));
```

**Also available:**
- `esc_html_e($text)` - escapes and echoes
- `esc_html_x($text, $context)` - escapes with translation context

### URLs (href, src, action)

**Function:** `esc_url($url)` or `esc_url_raw($url)`

**Purpose:**
- Validates URL scheme (allows http, https, mailto, etc.)
- Encodes special characters
- Strips dangerous protocols like `javascript:`, `data:`, `vbscript:`

**Usage:**
```php
// SAFE
echo '<a href="' . esc_url($url) . '">Link</a>';
echo '<img src="' . esc_url($image_url) . '">';
sprintf('<link rel="stylesheet" href="%s">', esc_url($css_url));
```

**Important Notes:**
- `esc_url()` - for display/output
- `esc_url_raw()` - for database storage or redirects (less restrictive)
- ⚠️ **Neither function prevents Open Redirect or SSRF** - they validate format but not destination

### Textarea Content

**Function:** `esc_textarea($text)`

**Purpose:** Encodes HTML entities for safe display in `<textarea>` elements.

**Usage:**
```php
// SAFE
echo '<textarea>' . esc_textarea($user_content) . '</textarea>';
```

### Inline CSS (Style Attributes)

**Function:** `esc_attr(safecss_filter_attr($css))`

**Purpose:**
- `safecss_filter_attr()` validates CSS properties and values
- `esc_attr()` prevents HTML attribute breakout

**Critical:** `safecss_filter_attr()` alone is **NOT SAFE** - must wrap in `esc_attr()`

**Usage:**
```php
// SAFE
echo '<div style="' . esc_attr(safecss_filter_attr($css)) . '">';
sprintf('<nav style="%s">', esc_attr(safecss_filter_attr($attributes['style'])));

// VULNERABLE - Can break out of attribute
echo '<div style="' . safecss_filter_attr($css) . '">';
```

### JavaScript Contexts

**Function:** `esc_js($text)`

**Purpose:** Escapes single quotes, double quotes, backslashes, and newlines for use in JavaScript strings.

**Usage:**
```php
// SAFE (but not recommended - prefer wp_localize_script)
echo '<script>var message = "' . esc_js($user_message) . '";</script>';
echo '<button onclick="alert(\'' . esc_js($text) . '\')">Click</button>';
```

**Better Alternatives:**
```php
// PREFERRED - Use data attributes + JSON
echo '<div data-config="' . esc_attr(wp_json_encode($data)) . '">';

// PREFERRED - Use wp_localize_script for inline scripts
wp_localize_script('my-script', 'myData', $data);
```

**Important:** `esc_js()` is context-dependent and error-prone. Prefer passing data via `wp_json_encode()` + `esc_attr()` in data attributes.

### Numeric Values

**Functions:** `intval()`, `absint()`, `(int)`, `floatval()`, `(float)`

**Purpose:** Guarantees the value is a number.

**Usage:**
```php
// SAFE
echo '<img width="' . intval($width) . '">';
echo '<div style="opacity: ' . floatval($opacity) . ';">';
$wpdb->prepare("SELECT * FROM table WHERE id = %d", intval($_GET['id']));

// Using type casting
$user_id = (int)$_GET['user_id'];
$price = (float)$_POST['price'];
```

**Functions:**
- `intval($value)` - converts to integer
- `absint($value)` - absolute integer (always positive)
- `floatval($value)` - converts to float
- `(int)`, `(float)` - type casts

---

## Sanitization Functions

Sanitization **modifies input** to make it "safe" for storage. **Sanitization is NOT escaping** - you must still escape on output.

### `sanitize_text_field($text)`

**Purpose:** Strips tags, removes extra whitespace, encodes some characters.

**Safe for:** Storing single-line text

**NOT safe for:** Output in HTML attributes (doesn't encode quotes)

**Usage:**
```php
// SAFE for storage
update_option('site_tagline', sanitize_text_field($_POST['tagline']));

// VULNERABLE for output in attributes
echo '<input value="' . sanitize_text_field($_GET['name']) . '">'; // BAD

// CORRECT
echo '<input value="' . esc_attr(sanitize_text_field($_GET['name'])) . '">'; // GOOD
```

### `sanitize_email($email)`

**Purpose:** Strips out characters not valid in an email address.

**Usage:**
```php
$email = sanitize_email($_POST['user_email']);
if (is_email($email)) {
    // Valid email
}
```

### `sanitize_file_name($filename)`

**Purpose:** Removes special characters, converts spaces to dashes, prevents directory traversal.

**Usage:**
```php
$filename = sanitize_file_name($_FILES['upload']['name']);
```

### `sanitize_key($key)`

**Purpose:** Restricts to lowercase alphanumeric characters, dashes, and underscores.

**Usage:**
```php
$option_key = sanitize_key($_GET['setting']);
// Safe for use in option names, meta keys
```

### `sanitize_title($title)`

**Purpose:** Creates URL-safe slugs (lowercase, alphanumeric, dashes).

**Usage:**
```php
$slug = sanitize_title($_POST['post_title']);
```

### `sanitize_html_class($class)`

**Purpose:** Sanitizes HTML class names (alphanumeric, underscores, dashes).

**Usage:**
```php
$class = sanitize_html_class($_GET['custom_class']);
echo '<div class="' . esc_attr($class) . '">'; // Still use esc_attr!
```

### `wp_kses($text, $allowed_html)` / `wp_kses_post($text)`

**Purpose:** Strips tags and attributes not in allowlist. Allows controlled HTML.

**Usage:**
```php
// Allows only specific HTML
$allowed = [
    'a' => ['href' => [], 'title' => []],
    'br' => [],
    'strong' => []
];
$safe_html = wp_kses($user_html, $allowed);

// Allows post content tags (p, a, img, etc)
$safe_content = wp_kses_post($_POST['content']);
```

**Important:**
- Safe for HTML **content** context
- **NOT safe for attribute context** (doesn't encode quotes reliably)
- Use `esc_attr()` if outputting in attributes

### `safecss_filter_attr($css)`

**Purpose:** Validates CSS property names and values against allowlist.

**Critical:** **MUST wrap in `esc_attr()`** for attribute context.

**Usage:**
```php
// VULNERABLE
echo '<div style="' . safecss_filter_attr($css) . '">';

// SAFE
echo '<div style="' . esc_attr(safecss_filter_attr($css)) . '">';
```

### `wp_strip_all_tags($text)`

**Purpose:** Removes all HTML tags. More aggressive than `strip_tags()`.

**Usage:**
```php
$plain_text = wp_strip_all_tags($_POST['content']);
```

---

## Common Vulnerable Patterns

### Pattern 1: Direct Interpolation (No Escaping)

```php
// VULNERABLE
echo '<div class="' . $_GET['class'] . '">';
echo '<span>' . $_POST['name'] . '</span>';
echo '<a href="' . $url . '">Link</a>';

// SAFE
echo '<div class="' . esc_attr($_GET['class']) . '">';
echo '<span>' . esc_html($_POST['name']) . '</span>';
echo '<a href="' . esc_url($url) . '">Link</a>';
```

### Pattern 2: Wrong Context Escaping

```php
// VULNERABLE - esc_html() in attribute context
echo '<div class="' . esc_html($user_input) . '">';
// Doesn't properly handle quotes - can still break out

// VULNERABLE - esc_attr() in HTML content context
echo '<p>' . esc_attr($user_content) . '</p>';
// Over-escapes, may not prevent all XSS

// CORRECT
echo '<div class="' . esc_attr($user_input) . '">';
echo '<p>' . esc_html($user_content) . '</p>';
```

### Pattern 3: safecss_filter_attr Without esc_attr

```php
// VULNERABLE - Critical pattern from WP core bugs
$style = safecss_filter_attr($attributes['style']);
echo '<div style="' . $style . '">';
// User input: " onload="alert(1)
// Output: <div style="" onload="alert(1)">

// SAFE
echo '<div style="' . esc_attr(safecss_filter_attr($attributes['style'])) . '">';
```

### Pattern 4: Sanitized But Not Escaped

```php
// VULNERABLE
$value = sanitize_text_field($_GET['value']);
echo '<input value="' . $value . '">';
// sanitize_text_field doesn't encode quotes
// Input: " onload="alert(1)
// Output: <input value="" onload="alert(1)">

// SAFE - Both sanitize AND escape
$value = sanitize_text_field($_GET['value']);
echo '<input value="' . esc_attr($value) . '">';
```

### Pattern 5: sprintf Without Escaping

```php
// VULNERABLE - sprintf doesn't auto-escape
echo sprintf('<a href="%s" title="%s">%s</a>', $url, $title, $text);

// SAFE - Escape each argument individually
echo sprintf(
    '<a href="%s" title="%s">%s</a>',
    esc_url($url),
    esc_attr($title),
    esc_html($text)
);
```

### Pattern 6: Variable Intermediary

```php
// VULNERABLE - Loses track that $classes contains user input
$classes = 'wrapper';
if (!empty($_GET['custom_class'])) {
    $classes .= ' ' . $_GET['custom_class'];
}
echo '<div class="' . $classes . '">'; // Forgot to escape

// SAFE
echo '<div class="' . esc_attr($classes) . '">';
```

### Pattern 7: Mixed Escaped/Unescaped Arguments

```php
// VULNERABLE - $align not escaped
echo sprintf(
    '<figure class="align%s"><img src="%s">',
    $attributes['align'],  // NOT escaped
    esc_url($attributes['src'])  // Escaped
);

// SAFE - Escape ALL arguments
echo sprintf(
    '<figure class="align%s"><img src="%s">',
    esc_attr($attributes['align']),
    esc_url($attributes['src'])
);
```

### Pattern 8: add_query_arg/remove_query_arg XSS

```php
// VULNERABLE - These functions use $_SERVER['REQUEST_URI'] when called with 1 arg
$url = add_query_arg('key', 'value'); // Contains unescaped REQUEST_URI
echo $url;
// If REQUEST_URI contains: "><script>alert(1)</script>
// Output reflects it

// SAFE
echo esc_url(add_query_arg('key', 'value'));

// SAFER - Provide explicit base URL
echo esc_url(add_query_arg('key', 'value', home_url('/page')));
```

### Pattern 9: Stored XSS via Options

```php
// VULNERABLE - Save without sanitization
update_option('custom_value', $_POST['value']);
// Later...
echo get_option('custom_value'); // Output without escaping

// SAFE - Sanitize on save, escape on output
update_option('custom_value', sanitize_text_field($_POST['value']));
// Later...
echo esc_html(get_option('custom_value'));
```

---

## Context-Specific Guidelines

### When to Use Each Function

| Output Context | Required Function | Notes |
|---------------|------------------|-------|
| HTML attribute (`class=`, `id=`, `title=`) | `esc_attr()` | Encodes quotes and special chars |
| HTML content (`<p>`, `<span>`, `<div>`) | `esc_html()` | Prevents tag injection |
| URL (`href=`, `src=`, `action=`) | `esc_url()` | Validates scheme, encodes chars |
| Textarea (`<textarea>`) | `esc_textarea()` | Handles textarea-specific encoding |
| Inline CSS (`style=`) | `esc_attr(safecss_filter_attr())` | BOTH functions required |
| JavaScript string | `esc_js()` (avoid) | Prefer `wp_json_encode()` + `esc_attr()` |
| Numeric value | `intval()`, `absint()`, `(int)` | Forces to integer |
| Decimal value | `floatval()`, `(float)` | Forces to float |

### SQL Context

**Never use for SQL:**
- ❌ `esc_sql()` - Insufficient, can still be exploited in numeric contexts
- ❌ Manual escaping or sanitization functions

**Always use:**
- ✅ `$wpdb->prepare()` with placeholders (`%s`, `%d`, `%f`)
- ✅ Type casting (`intval()`, `absint()`) for numeric values
- ✅ WordPress sanitizers (`sanitize_key()`, `sanitize_email()`)

```php
// WRONG
$wpdb->query("SELECT * FROM table WHERE id = " . esc_sql($_GET['id']));

// CORRECT
$wpdb->prepare("SELECT * FROM table WHERE id = %d", intval($_GET['id']));
$wpdb->prepare("SELECT * FROM table WHERE name = %s", sanitize_text_field($_POST['name']));
```

---

## Safe vs Unsafe Functions

### Always Safe (Neutralize Taint)

**XSS-safe:**
- `esc_attr()`, `esc_html()`, `esc_url()`, `esc_js()`, `esc_textarea()`
- `intval()`, `absint()`, `floatval()`, `(int)`, `(float)`
- `sanitize_key()`, `sanitize_hex_color()` - extremely restrictive

**SQL-safe:**
- `$wpdb->prepare()` with placeholders
- `intval()`, `absint()`, `floatval()` for numeric context
- `sanitize_key()`, `sanitize_email()`, `sanitize_title()`

**Command Injection-safe:**
- `escapeshellarg()`, `escapeshellcmd()`

### Partially Safe (Context-Dependent)

- `wp_kses()`, `wp_kses_post()` - Safe for HTML content, NOT for attributes
- `safecss_filter_attr()` - **Must wrap in `esc_attr()`**
- `sanitize_html_class()` - Still needs `esc_attr()` for output

### Sanitization Only (NOT Escaping)

These modify/validate input but **do NOT prevent XSS on output:**
- `sanitize_text_field()` - Strips tags but not quotes
- `sanitize_email()` - Email format only
- `sanitize_file_name()` - File names only
- `wp_strip_all_tags()`, `strip_tags()` - Remove tags but not quotes

**Always escape on output even after sanitizing on input.**

### Neutral Functions (Pass Taint Through)

These do **NOT** sanitize or escape:
- `trim()`, `ltrim()`, `rtrim()` - Whitespace only
- `strtolower()`, `strtoupper()`, `ucfirst()`, `lcfirst()` - Case changes
- `sprintf()` - Formatting only, no escaping
- `explode()`, `implode()`, `join()` - Array/string conversion
- `base64_decode()`, `base64_encode()` - Encoding only
- `json_decode()`, `json_encode()` - Format conversion (json_encode can help in some contexts)
- `urldecode()`, `rawurldecode()`, `urlencode()` - URL encoding
- `stripslashes()`, `wp_unslash()` - Slash removal only
- `basename()` - **NOT XSS-safe despite name**
- `wp_json_encode()` - Like `json_encode()`, safe in attribute context when wrapped in `esc_attr()`

### Unsafe Functions (Never Sufficient Alone)

- ❌ `esc_sql()` - Not safe for SQL injection
- ❌ `strip_tags()` - Doesn't encode quotes
- ❌ `filter_var()` - Context-dependent, not reliable for XSS
- ❌ `htmlspecialchars()` without `ENT_QUOTES` - Misses quotes in single-quoted attrs

---

## Best Practices Summary

### Golden Rules

1. **Escape at output time** - Don't rely on input sanitization alone
2. **Match function to context** - Use correct escape function for each output context
3. **Escape every argument** - In `sprintf()`, escape each `%s` individually
4. **Never trust sanitization for output** - `sanitize_text_field()` ≠ `esc_attr()`
5. **safecss_filter_attr needs esc_attr** - This is a critical, frequently-missed pattern
6. **Use wpdb->prepare for SQL** - Never use `esc_sql()` alone
7. **Type-cast numeric values** - Use `intval()` for IDs and counts
8. **Check intermediate variables** - Trace user input through all variable assignments

### Audit Checklist

When reviewing code:

✅ Every `echo`, `print`, `printf` with user data → check escaping
✅ Every `sprintf` argument → check individual escaping
✅ Every SQL query → check `$wpdb->prepare()` usage
✅ Every `style=` attribute → check for `esc_attr(safecss_filter_attr())`
✅ Block `$attributes` → trace to output, verify escaping
✅ Shortcode `$atts` → trace to output, verify escaping
✅ `get_option()` output → verify escaping
✅ `add_query_arg`/`remove_query_arg` → verify `esc_url()` wrapping

### Defense in Depth

**Ideal security:**
1. **Input validation** - Reject invalid data early
2. **Sanitization on save** - Store clean data
3. **Escaping on output** - Always escape, regardless of sanitization

**Example:**
```php
// Step 1: Validate input
if (!in_array($_POST['role'], ['author', 'editor', 'admin'])) {
    wp_die('Invalid role');
}

// Step 2: Sanitize before storage
update_option('user_role', sanitize_key($_POST['role']));

// Step 3: Escape on output
echo '<div data-role="' . esc_attr(get_option('user_role')) . '">';
```

---

**End of Escaping Guide**
