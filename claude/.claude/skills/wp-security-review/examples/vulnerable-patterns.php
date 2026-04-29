<?php
/**
 * WordPress Security - Vulnerable Patterns Examples
 *
 * This file contains annotated examples of common WordPress security vulnerabilities
 * and their corrected versions. Use as reference for security auditing.
 *
 * WARNING: These are INTENTIONALLY VULNERABLE examples for educational purposes.
 * DO NOT use vulnerable patterns in production code.
 */

// ============================================================================
// XSS (Cross-Site Scripting) Examples
// ============================================================================

/**
 * Example 1: Reflected XSS - No Escaping
 * SEVERITY: Critical
 */

// VULNERABLE - Direct echo of user input
function vulnerable_search_display() {
    echo '<h2>Search results for: ' . $_GET['q'] . '</h2>';
}

// FIXED - Proper escaping with esc_html()
function fixed_search_display() {
    echo '<h2>Search results for: ' . esc_html($_GET['q']) . '</h2>';
}

/**
 * Example 2: XSS in HTML Attribute - Wrong Context Escaping
 * SEVERITY: High
 */

// VULNERABLE - esc_html() doesn't prevent attribute breakout
function vulnerable_input_field() {
    echo '<input type="text" value="' . esc_html($_GET['default']) . '">';
    // Payload: " onload="alert(1)
    // Output: <input type="text" value="" onload="alert(1)">
}

// FIXED - Use esc_attr() for attributes
function fixed_input_field() {
    echo '<input type="text" value="' . esc_attr($_GET['default']) . '">';
}

/**
 * Example 3: Critical Pattern - safecss_filter_attr without esc_attr
 * SEVERITY: Critical
 */

// VULNERABLE - safecss_filter_attr alone doesn't prevent attribute breakout
function vulnerable_block_render($attributes) {
    $style = safecss_filter_attr($attributes['style']);
    return '<div style="' . $style . '">Content</div>';
    // Payload: " onload="alert(1)
    // Output: <div style="" onload="alert(1)">Content</div>
}

// FIXED - Wrap in esc_attr()
function fixed_block_render($attributes) {
    $style = safecss_filter_attr($attributes['style']);
    return '<div style="' . esc_attr($style) . '">Content</div>';
}

/**
 * Example 4: add_query_arg XSS
 * SEVERITY: High
 */

// VULNERABLE - add_query_arg uses REQUEST_URI which may contain XSS
function vulnerable_pagination() {
    $url = add_query_arg('page', 2); // Uses current URL
    echo '<a href="' . $url . '">Next</a>';
}

// FIXED - Escape output with esc_url()
function fixed_pagination() {
    $url = add_query_arg('page', 2);
    echo '<a href="' . esc_url($url) . '">Next</a>';
}

/**
 * Example 5: Stored XSS via Options
 * SEVERITY: Critical
 */

// VULNERABLE - Save and display without sanitization/escaping
function vulnerable_save_option() {
    update_option('site_tagline', $_POST['tagline']); // No sanitization
}

function vulnerable_display_option() {
    echo get_option('site_tagline'); // No escaping
}

// FIXED - Sanitize on save, escape on output
function fixed_save_option() {
    update_option('site_tagline', sanitize_text_field($_POST['tagline']));
}

function fixed_display_option() {
    echo esc_html(get_option('site_tagline'));
}

/**
 * Example 6: Sanitized But Not Escaped
 * SEVERITY: High
 */

// VULNERABLE - sanitize_text_field doesn't encode quotes
function vulnerable_sanitized_output() {
    $value = sanitize_text_field($_GET['name']);
    echo '<div title="' . $value . '">User</div>';
    // Payload: " onload="alert(1)
    // Output: <div title="" onload="alert(1)">User</div>
}

// FIXED - Sanitize AND escape
function fixed_sanitized_output() {
    $value = sanitize_text_field($_GET['name']);
    echo '<div title="' . esc_attr($value) . '">User</div>';
}

// ============================================================================
// SQL Injection Examples
// ============================================================================

/**
 * Example 7: Direct User Input in Query
 * SEVERITY: Critical
 */

// VULNERABLE - Concatenating user input directly
function vulnerable_get_user() {
    global $wpdb;
    $id = $_GET['id'];
    $user = $wpdb->get_row("SELECT * FROM {$wpdb->users} WHERE ID = $id");
    return $user;
}

// FIXED - Use prepare() with placeholders
function fixed_get_user() {
    global $wpdb;
    $id = intval($_GET['id']);
    $user = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->users} WHERE ID = %d",
        $id
    ));
    return $user;
}

/**
 * Example 8: esc_sql() Misuse (INSUFFICIENT)
 * SEVERITY: Critical
 */

// VULNERABLE - esc_sql alone is not sufficient
function vulnerable_search_posts() {
    global $wpdb;
    $search = esc_sql($_GET['s']);
    // Can still be exploited in numeric contexts
    $results = $wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE ID = $search");
    return $results;
}

// FIXED - Use prepare() with placeholders
function fixed_search_posts() {
    global $wpdb;
    $search = sanitize_text_field($_GET['s']);
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->posts} WHERE post_title LIKE %s",
        '%' . $wpdb->esc_like($search) . '%'
    ));
    return $results;
}

/**
 * Example 9: ORDER BY Injection
 * SEVERITY: High
 */

// VULNERABLE - User-controlled ORDER BY
function vulnerable_order_posts() {
    global $wpdb;
    $order = $_GET['order'];
    $results = $wpdb->get_results("SELECT * FROM {$wpdb->posts} ORDER BY $order");
    // Payload: (SELECT SLEEP(5))
    return $results;
}

// FIXED - Use allowlist validation
function fixed_order_posts() {
    global $wpdb;
    $allowed_columns = ['post_title', 'post_date', 'ID'];
    $order = in_array($_GET['order'], $allowed_columns) ? $_GET['order'] : 'post_date';
    $results = $wpdb->get_results("SELECT * FROM {$wpdb->posts} ORDER BY $order DESC");
    return $results;
}

// ============================================================================
// CSRF (Cross-Site Request Forgery) Examples
// ============================================================================

/**
 * Example 10: Missing Nonce Check
 * SEVERITY: High
 */

// VULNERABLE - No nonce verification
function vulnerable_delete_user() {
    if (isset($_POST['delete_user'])) {
        wp_delete_user($_POST['user_id']);
    }
}

// FIXED - Verify nonce before action
function fixed_delete_user() {
    if (isset($_POST['delete_user'])) {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'delete-user-action')) {
            wp_die('Security check failed');
        }
        if (!current_user_can('delete_users')) {
            wp_die('Unauthorized');
        }
        wp_delete_user(intval($_POST['user_id']));
    }
}

/**
 * Example 11: Inverted Nonce Check
 * SEVERITY: High
 */

// VULNERABLE - Dies when nonce is VALID (backwards)
function vulnerable_inverted_nonce() {
    if (wp_verify_nonce($_POST['nonce'], 'my-action')) {
        wp_die('Invalid nonce'); // Wrong!
    }
    // Action proceeds when nonce is invalid
    update_option('setting', $_POST['value']);
}

// FIXED - Proper nonce check logic
function fixed_inverted_nonce() {
    if (!wp_verify_nonce($_POST['nonce'], 'my-action')) {
        wp_die('Invalid nonce'); // Correct
    }
    // Action proceeds only when nonce is valid
    update_option('setting', sanitize_text_field($_POST['value']));
}

/**
 * Example 12: Flawed Nonce Logic
 * SEVERITY: High
 */

// VULNERABLE - Only checks if nonce is set
function vulnerable_flawed_nonce() {
    if (!empty($_POST['nonce']) && !wp_verify_nonce($_POST['nonce'], 'action')) {
        wp_die('Invalid nonce');
    }
    // If nonce not provided, check is bypassed!
    update_option('setting', $_POST['value']);
}

// FIXED - Check for nonce presence AND validity
function fixed_flawed_nonce() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'action')) {
        wp_die('Invalid nonce');
    }
    update_option('setting', sanitize_text_field($_POST['value']));
}

// ============================================================================
// Open Redirect Examples
// ============================================================================

/**
 * Example 13: User-Controlled Redirect
 * SEVERITY: Medium
 */

// VULNERABLE - User controls redirect destination
function vulnerable_redirect() {
    $redirect = $_GET['redirect_to'];
    wp_redirect($redirect);
    exit;
}

// FIXED - Use wp_safe_redirect to validate domain
function fixed_redirect() {
    $redirect = $_GET['redirect_to'];
    wp_safe_redirect($redirect); // Validates same-domain
    exit;
}

// ============================================================================
// SSRF (Server-Side Request Forgery) Examples
// ============================================================================

/**
 * Example 14: User-Controlled Remote Request
 * SEVERITY: High
 */

// VULNERABLE - User controls URL for remote request
function vulnerable_fetch_data() {
    $url = $_GET['api_url'];
    $response = wp_remote_get($url);
    return wp_remote_retrieve_body($response);
    // Can access: http://169.254.169.254/latest/meta-data/ (AWS metadata)
    // Can access: http://localhost:3306, http://192.168.1.1, etc.
}

// FIXED - Validate against domain allowlist
function fixed_fetch_data() {
    $url = $_GET['api_url'];
    $parsed = parse_url($url);

    $allowed_domains = ['api.example.com', 'cdn.example.com'];
    if (!in_array($parsed['host'], $allowed_domains)) {
        return new WP_Error('invalid_url', 'URL not allowed');
    }

    $response = wp_remote_get($url);
    return wp_remote_retrieve_body($response);
}

// ============================================================================
// LFI (Local File Inclusion) Examples
// ============================================================================

/**
 * Example 15: User-Controlled Include Path
 * SEVERITY: Critical
 */

// VULNERABLE - User controls file path
function vulnerable_load_template() {
    $template = $_GET['template'];
    include('/templates/' . $template . '.php');
    // Payload: ../../../wp-config
    // Includes: /templates/../../../wp-config.php
}

// FIXED - Use allowlist validation
function fixed_load_template() {
    $allowed_templates = ['header', 'footer', 'sidebar', 'content'];
    $template = $_GET['template'];

    if (!in_array($template, $allowed_templates)) {
        $template = 'content'; // Default
    }

    include('/templates/' . $template . '.php');
}

// ============================================================================
// Object Injection Examples
// ============================================================================

/**
 * Example 16: Unserialize User Input
 * SEVERITY: High
 */

// VULNERABLE - Unserialize user-controlled data
function vulnerable_unserialize() {
    $data = unserialize($_COOKIE['user_data']);
    return $data;
}

// FIXED - Use allowed_classes or better, use JSON
function fixed_unserialize() {
    // Option 1: Restrict classes
    $data = unserialize($_COOKIE['user_data'], ['allowed_classes' => false]);

    // Option 2 (BETTER): Use JSON instead
    $data = json_decode($_COOKIE['user_data'], true);
    return $data;
}

// ============================================================================
// Command Injection Examples
// ============================================================================

/**
 * Example 17: User Input in System Command
 * SEVERITY: Critical
 */

// VULNERABLE - User input in shell command
function vulnerable_ping() {
    $host = $_GET['host'];
    $output = system('ping -c 4 ' . $host);
    return $output;
    // Payload: 127.0.0.1; cat /etc/passwd
}

// FIXED - Use escapeshellarg()
function fixed_ping() {
    $host = $_GET['host'];
    $output = system('ping -c 4 ' . escapeshellarg($host));
    return $output;
}

// ============================================================================
// Auth Bypass Examples
// ============================================================================

/**
 * Example 18: wp_set_auth_cookie with User Input
 * SEVERITY: Critical
 */

// VULNERABLE - User controls which user to authenticate as
function vulnerable_auto_login() {
    if (isset($_GET['user_id'])) {
        wp_set_auth_cookie($_GET['user_id']);
        wp_redirect(home_url());
        exit;
    }
}

// FIXED - Only set auth cookie after proper authentication
function fixed_auto_login() {
    // Only after verifying credentials
    $credentials = [
        'user_login' => $_POST['username'],
        'user_password' => $_POST['password']
    ];

    $user = wp_signon($credentials);

    if (!is_wp_error($user)) {
        wp_set_auth_cookie($user->ID); // Now safe
        wp_redirect(home_url());
        exit;
    }
}

/**
 * Example 19: REST API Permission Bypass
 * SEVERITY: Critical
 */

// VULNERABLE - Always returns true (public access)
function vulnerable_rest_route() {
    register_rest_route('myplugin/v1', '/admin-action', [
        'methods' => 'POST',
        'callback' => 'dangerous_admin_action',
        'permission_callback' => '__return_true' // Anyone can access!
    ]);
}

// FIXED - Proper capability check
function fixed_rest_route() {
    register_rest_route('myplugin/v1', '/admin-action', [
        'methods' => 'POST',
        'callback' => 'dangerous_admin_action',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
}

// ============================================================================
// Options Manipulation Examples
// ============================================================================

/**
 * Example 20: User-Controlled Option Key
 * SEVERITY: High
 */

// VULNERABLE - User controls option name and value
function vulnerable_update_option() {
    if (isset($_POST['option_name'])) {
        update_option($_POST['option_name'], $_POST['option_value']);
        // Attacker can set: option_name=admin_email&option_value=attacker@evil.com
    }
}

// FIXED - Hardcode option key, verify capabilities, check nonce
function fixed_update_option() {
    if (isset($_POST['custom_setting'])) {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        if (!wp_verify_nonce($_POST['_wpnonce'], 'update-setting')) {
            wp_die('Invalid nonce');
        }
        update_option('custom_setting', sanitize_text_field($_POST['custom_setting']));
    }
}

// ============================================================================
// Extract() Vulnerability Examples
// ============================================================================

/**
 * Example 21: extract() with User Input
 * SEVERITY: High
 */

// VULNERABLE - extract() can overwrite variables
function vulnerable_extract() {
    $important_var = 'safe_value';
    extract($_POST); // User can overwrite $important_var!

    if ($important_var === 'safe_value') {
        // User can bypass this by setting $_POST['important_var'] = 'safe_value'
        delete_all_data();
    }
}

// FIXED - Don't use extract() with user input
function fixed_extract() {
    $important_var = 'safe_value';

    // Manually access needed values
    $username = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';

    if ($important_var === 'safe_value') {
        delete_all_data();
    }
}

// ============================================================================
// Summary of Key Principles
// ============================================================================

/*
1. XSS Prevention:
   - Always escape on output: esc_attr() for attributes, esc_html() for content, esc_url() for URLs
   - CRITICAL: safecss_filter_attr() MUST be wrapped in esc_attr()
   - sanitize_text_field() is NOT escaping - still need esc_attr/esc_html on output

2. SQL Injection Prevention:
   - Always use $wpdb->prepare() with placeholders (%s, %d, %f)
   - Use intval() or absint() for numeric values
   - NEVER use esc_sql() alone - insufficient protection

3. CSRF Prevention:
   - Verify nonces with wp_verify_nonce() before processing actions
   - Use check_ajax_referer() for AJAX handlers
   - Combine with capability checks for admin actions

4. Input Validation:
   - Use allowlists where possible: in_array($value, $allowed)
   - Reject invalid input early
   - Type-cast numeric inputs: (int)$_GET['id']

5. Auth & Options:
   - Always check current_user_can() for admin actions
   - Verify nonces for state-changing operations
   - Don't let users control option keys
   - REST API permission_callback must return boolean from capability check

6. File Operations:
   - Use allowlist validation for includes
   - Use escapeshellarg() for shell commands
   - Use json_decode() instead of unserialize()

7. Defense in Depth:
   - Validate input → Sanitize on storage → Escape on output
   - Capability check + nonce verification
   - Principle of least privilege
*/
