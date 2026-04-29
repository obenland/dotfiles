# WordPress Escaping & Sanitization Reference for Block Render Callbacks

## Safe Functions by Output Context

### HTML Attribute Values

Use `esc_attr()`. Encodes `<`, `>`, `&`, `"`, and `'` so a value cannot break out of a quoted attribute.

```php
// SAFE
sprintf( '<div class="%s">', esc_attr( $attributes['className'] ) );
```

### HTML Content (between tags)

Use `esc_html()`. Encodes `<`, `>`, `&`, `"`, and `'` so a value cannot inject HTML tags or entities.

```php
// SAFE
sprintf( '<span>%s</span>', esc_html( $attributes['label'] ) );
```

### URLs (href, src, action)

Use `esc_url()`. Validates the URL scheme (allows `http`, `https`, `mailto`, etc.), encodes special characters, and strips dangerous protocols like `javascript:`.

```php
// SAFE
sprintf( '<a href="%s">', esc_url( $attributes['url'] ) );
```

### Inline CSS (style attributes)

Use `esc_attr( safecss_filter_attr( $value ) )`. The `safecss_filter_attr()` function validates CSS property/value pairs, but its output can still contain characters that break out of an HTML attribute. You MUST wrap it in `esc_attr()`.

```php
// SAFE
sprintf( '<div style="%s">', esc_attr( safecss_filter_attr( $attributes['style'] ) ) );

// VULNERABLE — safecss_filter_attr alone does NOT encode quotes
sprintf( '<div style="%s">', safecss_filter_attr( $attributes['style'] ) );
```

### JavaScript Contexts

Use `esc_js()` for values embedded in inline JavaScript strings. Escapes single quotes, double quotes, backslashes, and newlines.

```php
// SAFE
sprintf( '<button onclick="doThing(\'%s\')">', esc_js( $attributes['action'] ) );
```

Note: Prefer `wp_json_encode()` + `esc_attr()` for complex data passed to JS via data attributes instead of inline scripts.

### Numeric Values

Use `intval()`, `absint()`, or `(int)` cast. Guarantees the value is an integer.

```php
// SAFE
sprintf( '<img width="%d">', intval( $attributes['width'] ) );
```

Use `(float)` cast or `floatval()` for decimal values:

```php
// SAFE
sprintf( '<div style="opacity: %f;">', floatval( $attributes['opacity'] ) );
```

## Sanitization vs. Escaping

Sanitization and escaping serve different purposes. Sanitization modifies input to make it "safe" for storage. Escaping encodes output for a specific rendering context. In render callbacks, **escaping at output time is mandatory** even if input was sanitized on save.

### `sanitize_text_field()`

Strips tags, removes extra whitespace, encodes some special characters. Does NOT encode `"` or `'` reliably for attribute contexts. **Not sufficient for output escaping.**

```php
// VULNERABLE in attribute context — does not encode quotes
sprintf( '<div title="%s">', sanitize_text_field( $attributes['title'] ) );

// FIX
sprintf( '<div title="%s">', esc_attr( $attributes['title'] ) );
```

### `wp_kses()` / `wp_kses_post()`

Filters HTML tags and attributes against an allowlist. Useful for rich content where some HTML is permitted. Does NOT encode output for attribute contexts.

```php
// SAFE for HTML content context (allows permitted tags)
printf( '<div>%s</div>', wp_kses_post( $attributes['richContent'] ) );

// VULNERABLE in attribute context — wp_kses_post allows quotes through
sprintf( '<div title="%s">', wp_kses_post( $attributes['title'] ) );
```

### `safecss_filter_attr()`

Validates CSS property names against an allowlist and filters values. Returns a sanitized CSS string. However, it does NOT HTML-encode its output. A crafted CSS value can contain `"` characters that break out of a `style=""` attribute.

**Always wrap in `esc_attr()`:**

```php
// VULNERABLE
sprintf( '<div style="%s">', safecss_filter_attr( $css ) );

// SAFE
sprintf( '<div style="%s">', esc_attr( safecss_filter_attr( $css ) ) );
```

## Common Vulnerable Patterns

### Pattern 1: Direct Interpolation

Attribute value inserted directly into output with no escaping at all.

```php
// VULNERABLE
function render_my_block( $attributes ) {
    return '<div class="' . $attributes['className'] . '">Content</div>';
}

// FIX
function render_my_block( $attributes ) {
    return '<div class="' . esc_attr( $attributes['className'] ) . '">Content</div>';
}
```

### Pattern 2: sprintf Without Escaping

Using `sprintf` for formatting but forgetting to escape the substituted values.

```php
// VULNERABLE
function render_my_block( $attributes ) {
    return sprintf(
        '<a href="%s" title="%s">%s</a>',
        $attributes['url'],
        $attributes['title'],
        $attributes['label']
    );
}

// FIX
function render_my_block( $attributes ) {
    return sprintf(
        '<a href="%s" title="%s">%s</a>',
        esc_url( $attributes['url'] ),
        esc_attr( $attributes['title'] ),
        esc_html( $attributes['label'] )
    );
}
```

### Pattern 3: safecss_filter_attr Without esc_attr Wrapper

This is the pattern from WordPress/Gutenberg PR #45045. The `safecss_filter_attr()` function sanitizes CSS but does not prevent HTML attribute breakout.

```php
// VULNERABLE
$styles = safecss_filter_attr( $attributes['style'] );
return sprintf( '<nav style="%s">%s</nav>', $styles, $content );

// FIX
$styles = safecss_filter_attr( $attributes['style'] );
return sprintf( '<nav style="%s">%s</nav>', esc_attr( $styles ), $content );
```

### Pattern 4: Variable Intermediary Losing Track

A value is assigned to an intermediate variable, and by the time it reaches output, the developer forgets it originated from `$attributes` and is user-controlled.

```php
// VULNERABLE — $wrapper_classes is user-controlled but not escaped at output
function render_my_block( $attributes ) {
    $wrapper_classes = 'wp-block-mine';
    if ( ! empty( $attributes['className'] ) ) {
        $wrapper_classes .= ' ' . $attributes['className'];
    }
    return sprintf( '<div class="%s">Content</div>', $wrapper_classes );
}

// FIX
function render_my_block( $attributes ) {
    $wrapper_classes = 'wp-block-mine';
    if ( ! empty( $attributes['className'] ) ) {
        $wrapper_classes .= ' ' . $attributes['className'];
    }
    return sprintf( '<div class="%s">Content</div>', esc_attr( $wrapper_classes ) );
}
```

### Pattern 5: Mixed Escaped and Unescaped sprintf Arguments

Some arguments to `sprintf` are properly escaped but others are not. Every argument must be individually checked.

```php
// VULNERABLE — $attributes['align'] is not escaped
function render_my_block( $attributes ) {
    return sprintf(
        '<figure class="align%s"><img src="%s" alt="%s" /></figure>',
        $attributes['align'],
        esc_url( $attributes['url'] ),
        esc_attr( $attributes['alt'] )
    );
}

// FIX
function render_my_block( $attributes ) {
    return sprintf(
        '<figure class="align%s"><img src="%s" alt="%s" /></figure>',
        esc_attr( $attributes['align'] ),
        esc_url( $attributes['url'] ),
        esc_attr( $attributes['alt'] )
    );
}
```

### Pattern 6: CSS Value Composition Into Style Attribute

Individual CSS properties are composed from attributes and concatenated into a style string without escaping the final result.

```php
// VULNERABLE — individual values are not escaped, composed style is not escaped
function render_my_block( $attributes ) {
    $styles = '';
    if ( ! empty( $attributes['backgroundColor'] ) ) {
        $styles .= 'background-color: ' . $attributes['backgroundColor'] . ';';
    }
    if ( ! empty( $attributes['fontSize'] ) ) {
        $styles .= 'font-size: ' . $attributes['fontSize'] . ';';
    }
    return sprintf( '<div style="%s">Content</div>', $styles );
}

// FIX — escape each value and wrap the composed style in esc_attr()
function render_my_block( $attributes ) {
    $styles = '';
    if ( ! empty( $attributes['backgroundColor'] ) ) {
        $styles .= 'background-color: ' . $attributes['backgroundColor'] . ';';
    }
    if ( ! empty( $attributes['fontSize'] ) ) {
        $styles .= 'font-size: ' . $attributes['fontSize'] . ';';
    }
    return sprintf( '<div style="%s">Content</div>', esc_attr( safecss_filter_attr( $styles ) ) );
}
```
