<?php
/**
 * Vulnerable Block Render Callback Examples
 *
 * Four annotated examples based on real WordPress core patterns.
 * Each demonstrates a stored XSS vulnerability in a render_callback,
 * explains the flaw, provides a proof-of-concept payload, and shows the fix.
 *
 * These examples are for educational and auditing reference only.
 */

// ============================================================================
// EXAMPLE 1: safecss_filter_attr without esc_attr (Navigation Block Pattern)
// Based on: WordPress/Gutenberg PR #45045
// Severity: Critical (Sub-type D)
// ============================================================================

// VULNERABLE VERSION
function render_block_core_navigation_vulnerable( $attributes, $content ) {
    $colors     = block_core_navigation_build_css_colors( $attributes );
    $font_sizes = block_core_navigation_build_css_font_sizes( $attributes );

    // safecss_filter_attr() validates CSS properties but does NOT HTML-encode.
    // An attacker can craft an attribute value containing a double quote to
    // break out of the style="" attribute and inject event handlers.
    $style_attr = safecss_filter_attr( $colors['inline_styles'] . $font_sizes['inline_styles'] );

    return sprintf(
        '<nav class="wp-block-navigation %s %s" style="%s">%s</nav>',
        esc_attr( $colors['css_classes'] ),
        esc_attr( $font_sizes['css_classes'] ),
        $style_attr, // <-- NOT wrapped in esc_attr()
        $content
    );
}

// PoC payload (saved via REST API or block editor manipulation):
// <!-- wp:navigation {"style":{"color":{"text":"\" onmouseover=\"alert(document.cookie)\" style=\"color:"}}} /-->
//
// Rendered output:
// <nav class="..." style="" onmouseover="alert(document.cookie)" style="color:">...</nav>

// FIXED VERSION
function render_block_core_navigation_fixed( $attributes, $content ) {
    $colors     = block_core_navigation_build_css_colors( $attributes );
    $font_sizes = block_core_navigation_build_css_font_sizes( $attributes );

    // FIX: Wrap safecss_filter_attr() output in esc_attr()
    $style_attr = esc_attr( safecss_filter_attr( $colors['inline_styles'] . $font_sizes['inline_styles'] ) );

    return sprintf(
        '<nav class="wp-block-navigation %s %s" style="%s">%s</nav>',
        esc_attr( $colors['css_classes'] ),
        esc_attr( $font_sizes['css_classes'] ),
        $style_attr, // Now safe — quotes are encoded
        $content
    );
}


// ============================================================================
// EXAMPLE 2: Direct Attribute Interpolation (Widget Group Pattern)
// Severity: Critical (Sub-type A)
// ============================================================================

// VULNERABLE VERSION
function render_block_widget_group_vulnerable( $attributes, $content ) {
    $classname = '';
    if ( ! empty( $attributes['className'] ) ) {
        $classname = $attributes['className'];
    }

    // Direct interpolation — $classname is user-controlled and unescaped.
    // An attacker can break out of the class attribute and inject arbitrary HTML.
    return '<div class="widget ' . $classname . '">' . $content . '</div>';
}

// PoC payload:
// <!-- wp:widget-group {"className":"\" onfocus=\"alert(document.cookie)\" tabindex=\"1\" class=\""} -->
// <p>Innocent content</p>
// <!-- /wp:widget-group -->
//
// Rendered output:
// <div class="widget " onfocus="alert(document.cookie)" tabindex="1" class="">...</div>

// FIXED VERSION
function render_block_widget_group_fixed( $attributes, $content ) {
    $classname = '';
    if ( ! empty( $attributes['className'] ) ) {
        $classname = $attributes['className'];
    }

    // FIX: Escape the entire class attribute value
    return '<div class="' . esc_attr( 'widget ' . $classname ) . '">' . $content . '</div>';
}


// ============================================================================
// EXAMPLE 3: CSS Value Composition Into Style (Search Block Pattern)
// Severity: Critical (Sub-type D)
// ============================================================================

// VULNERABLE VERSION
function render_block_core_search_vulnerable( $attributes ) {
    $styles = '';

    // Values from $attributes are composed into a CSS string without escaping.
    if ( ! empty( $attributes['width'] ) ) {
        $styles .= 'width: ' . $attributes['width'] . $attributes['widthUnit'] . ';';
    }
    if ( ! empty( $attributes['borderColor'] ) ) {
        $styles .= 'border-color: ' . $attributes['borderColor'] . ';';
    }

    // The composed $styles is interpolated without esc_attr().
    // Even though these look like CSS values, an attacker can inject a quote
    // to close the style attribute and add event handlers.
    return sprintf(
        '<form class="wp-block-search" style="%s"><input type="search" /></form>',
        $styles
    );
}

// PoC payload:
// <!-- wp:search {"width":"100","widthUnit":"px","borderColor":"red\" onmouseover=\"alert(1)\" style=\"border-color:red"} /-->
//
// Rendered output:
// <form class="wp-block-search" style="width: 100px;border-color: red" onmouseover="alert(1)" style="border-color:red;">...</form>

// FIXED VERSION
function render_block_core_search_fixed( $attributes ) {
    $styles = '';

    if ( ! empty( $attributes['width'] ) ) {
        $styles .= 'width: ' . $attributes['width'] . $attributes['widthUnit'] . ';';
    }
    if ( ! empty( $attributes['borderColor'] ) ) {
        $styles .= 'border-color: ' . $attributes['borderColor'] . ';';
    }

    // FIX: Run the composed styles through safecss_filter_attr for CSS validation,
    // then wrap in esc_attr() to prevent attribute breakout.
    return sprintf(
        '<form class="wp-block-search" style="%s"><input type="search" /></form>',
        esc_attr( safecss_filter_attr( $styles ) )
    );
}


// ============================================================================
// EXAMPLE 4: Mixed Escaped/Unescaped sprintf (Common Third-Party Pattern)
// Severity: High (Sub-type D)
// ============================================================================

// VULNERABLE VERSION
function render_block_custom_card_vulnerable( $attributes ) {
    $title = $attributes['title'] ?? '';
    $url   = $attributes['url'] ?? '#';
    $align = $attributes['align'] ?? 'none';
    $desc  = $attributes['description'] ?? '';

    // Some arguments are escaped, others are not.
    // $url is escaped with esc_url() — good.
    // $title uses esc_attr() — good.
    // $align is NOT escaped — can break out of class attribute.
    // $desc is NOT escaped — allows HTML injection in content.
    return sprintf(
        '<div class="wp-block-card align%s">
            <a href="%s" title="%s">
                <h3>%s</h3>
            </a>
            <p>%s</p>
        </div>',
        $align,                   // <-- VULNERABLE: no escaping
        esc_url( $url ),          // OK
        esc_attr( $title ),       // OK
        esc_html( $title ),       // OK
        $desc                     // <-- VULNERABLE: no escaping
    );
}

// PoC payload (attribute breakout via align):
// <!-- wp:custom/card {"align":"none\" onclick=\"alert(document.cookie)\" class=\"align","title":"Hello","url":"https://example.com","description":"<img src=x onerror=alert(1)>"} /-->
//
// PoC payload (HTML injection via description):
// <!-- wp:custom/card {"align":"none","title":"Hello","url":"#","description":"<img src=x onerror=alert(document.cookie)>"} /-->

// FIXED VERSION
function render_block_custom_card_fixed( $attributes ) {
    $title = $attributes['title'] ?? '';
    $url   = $attributes['url'] ?? '#';
    $align = $attributes['align'] ?? 'none';
    $desc  = $attributes['description'] ?? '';

    // FIX: Escape every argument for its output context
    return sprintf(
        '<div class="wp-block-card align%s">
            <a href="%s" title="%s">
                <h3>%s</h3>
            </a>
            <p>%s</p>
        </div>',
        esc_attr( $align ),       // FIX: escape for attribute context
        esc_url( $url ),
        esc_attr( $title ),
        esc_html( $title ),
        esc_html( $desc )         // FIX: escape for HTML content context
    );
}
