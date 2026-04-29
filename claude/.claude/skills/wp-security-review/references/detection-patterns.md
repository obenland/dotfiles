# WordPress Security Detection Patterns

This document provides detection hints for WordPress security vulnerabilities, organized by category. These patterns are derived from comprehensive security analysis and should be used by subagents to identify potential vulnerabilities through manual taint analysis.

## Table of Contents

1. [XSS (Cross-Site Scripting)](#xss-cross-site-scripting)
2. [SQL Injection](#sql-injection)
3. [CSRF (Cross-Site Request Forgery)](#csrf-cross-site-request-forgery)
4. [Open Redirect](#open-redirect)
5. [SSRF (Server-Side Request Forgery)](#ssrf-server-side-request-forgery)
6. [LFI (Local File Inclusion)](#lfi-local-file-inclusion)
7. [Object Injection](#object-injection)
8. [Command Injection](#command-injection)
9. [Auth Bypass & Options Manipulation](#auth-bypass--options-manipulation)

---

## XSS (Cross-Site Scripting)

### User Input Sources (Untrusted Data)

**Direct User Input:**
- `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`
- `$_SERVER['REQUEST_URI']`, `$_SERVER['PHP_SELF']`, `$_SERVER['QUERY_STRING']`
- `filter_input(INPUT_GET, ...)`, `filter_input(INPUT_POST, ...)` without proper filters
- `filter_input_array()` with FILTER_DEFAULT or FILTER_UNSAFE_RAW

**WordPress-Specific Sources:**
- `get_option()` - stored options from database (user may have written unsafe data)
- `add_query_arg()` with 1 argument or second arg = false
- `remove_query_arg()` with 1 argument or second arg = false
- Block render callback `$attributes` parameter
- Shortcode callback `$atts` parameter (first parameter)

**Plugin-Specific:**
- `rgpost()`, `rgget()` (Gravity Forms)
- `pb_backupbuddy::_GET()`, `pb_backupbuddy::_POST()`

### Dangerous Sinks (Output Contexts)

**HTML Output:**
- `echo`, `print`, `printf()`, `sprintf()` output
- `print_r($var)`, `print_r($var, false)`, `var_export($var)`, `var_export($var, false)`, `var_dump()`
- `die()`, `wp_die()` with user input
- `return` statement in block render_callback or shortcode callback

**Script Context:**
- `wp_add_inline_script($handle, $user_input)`
- `<script>` tags with interpolated user data

### Safe Functions (Context-Specific Escaping)

**HTML Attribute Context:**
- ✅ `esc_attr()` - escapes for HTML attributes
- ❌ NOT `esc_html()` - wrong context

**HTML Content Context:**
- ✅ `esc_html()` - escapes for HTML body content
- ❌ NOT `esc_attr()` - insufficient for some cases

**URL Context:**
- ✅ `esc_url()` - validates and escapes URLs
- ✅ `esc_url_raw()` - for database/redirect (not output)

**JavaScript Context:**
- ✅ `esc_js()` - escapes for JavaScript strings (MUST be quoted)
- ⚠️ `wp_json_encode()`, `json_encode()` - safe but context-dependent

**CSS Context:**
- ✅ `esc_attr(safecss_filter_attr($value))` - BOTH functions required
- ❌ CRITICAL: `safecss_filter_attr()` alone is UNSAFE

**Numeric Context:**
- ✅ `intval()`, `absint()`, `floatval()`, `(int)`, `(float)`

**Partial Sanitization (NOT Escaping):**
- ⚠️ `sanitize_text_field()` - removes tags but NOT quotes - insufficient for attributes
- ⚠️ `sanitize_key()` - alphanumeric only, safe for most contexts
- ⚠️ `wp_kses_post()` - allows some HTML, NOT safe for attributes
- ⚠️ `strip_tags()`, `wp_strip_all_tags()` - removes tags but not quotes

### Common Vulnerability Patterns

**Pattern 1: No Escaping**
```php
// VULNERABLE
echo $_GET['name'];
echo $attributes['url'];
```

**Pattern 2: Wrong Context Escaping**
```php
// VULNERABLE - esc_html in attribute
echo '<div class="' . esc_html($user_input) . '">';

// VULNERABLE - esc_attr in JavaScript without quotes
echo '<script>var x=' . esc_attr($user_input) . ';</script>';
```

**Pattern 3: safecss_filter_attr Without esc_attr (CRITICAL)**
```php
// VULNERABLE - Can break out of attribute
echo '<div style="' . safecss_filter_attr($user_input) . '">';

// SAFE
echo '<div style="' . esc_attr(safecss_filter_attr($user_input)) . '">';
```

**Pattern 4: add_query_arg/remove_query_arg XSS**
```php
// VULNERABLE - Returns URL with $_SERVER['REQUEST_URI'] (may contain XSS)
$url = add_query_arg('key', 'value'); // Single arg or false second arg
echo $url;

// SAFE
echo esc_url(add_query_arg('key', 'value'));
```

**Pattern 5: Sanitized But Not Escaped**
```php
// VULNERABLE - sanitize_text_field doesn't escape quotes
$value = sanitize_text_field($_GET['value']);
echo '<input value="' . $value . '">'; // Can inject " onload="alert(1)

// SAFE
echo '<input value="' . esc_attr(sanitize_text_field($_GET['value'])) . '">';
```

**Pattern 6: Stored XSS via Options**
```php
// VULNERABLE flow:
// 1. User input saved to option
update_option('custom_setting', $_POST['value']); // No sanitization

// 2. Later retrieved and output
echo get_option('custom_setting'); // No escaping

// SAFE
update_option('custom_setting', sanitize_text_field($_POST['value']));
echo esc_html(get_option('custom_setting'));
```

### Neutral Functions (Taint Propagation)

These functions pass taint through without sanitizing:
- `sprintf()`, `trim()`, `rtrim()`, `ltrim()`, `strval()`
- `base64_decode()`, `stripslashes()`, `wp_unslash()`
- `urldecode()`, `rawurldecode()`, `json_decode()`
- `explode()`, `implode()`, `join()`, `strtolower()`, `ucfirst()`, `lcfirst()`
- `basename()` - NOT XSS safe despite name

### False Positive Checks

**WordPress Core Validation:**
- `$_GET['page']` - WordPress validates this in admin context, usually safe
- But still audit direct file access scenarios

**Variable Validation:**
- Check for `in_array($var, ['safe1', 'safe2'])` before output
- Check for `if ($var === 'safe_value')` conditionals
- Ternary: `$var ? 'safe1' : 'safe2'`

**Property Access:**
- `$object->$user_input` - using as property name, not output
- `$array[$user_input]` - using as array key, not value (context-dependent)

---

## SQL Injection

### User Input Sources

**Direct User Input:**
- `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`
- `$_SERVER['REQUEST_URI']`, `$_SERVER['PHP_SELF']`, `$_SERVER['QUERY_STRING']`
- `filter_input()` with FILTER_DEFAULT or FILTER_UNSAFE_RAW
- `filter_input_array()` without proper filters

**WordPress-Specific:**
- Shortcode `$atts` parameter
- Block render `$attributes` parameter

**Plugin-Specific:**
- `rgpost()`, `rgget()` (Gravity Forms)
- `pb_backupbuddy::_GET()`, `pb_backupbuddy::_POST()`

### Dangerous Sinks

**wpdb Methods:**
- `$wpdb->query($sql)` - direct query execution
- `$wpdb->get_var($sql)`, `$wpdb->get_row($sql)`, `$wpdb->get_col($sql)`, `$wpdb->get_results($sql)`
- `$wpdb->prepare($sql, ...)` - FIRST argument with user input is vulnerable

### Safe Functions

**Proper Preparation:**
- ✅ `$wpdb->prepare("SELECT * FROM table WHERE id = %d", $user_input)`
- ✅ `$wpdb->prepare("SELECT * FROM table WHERE name = %s", $user_input)`
- ✅ Placeholders: `%d` (integer), `%f` (float), `%s` (string)

**Type Casting:**
- ✅ `intval()`, `absint()`, `(int)`, `(float)`, `floatval()`
- ✅ `array_map('intval', $array)`, `array_map('absint', $array)`

**WordPress Sanitizers:**
- ✅ `sanitize_key()` - alphanumeric + hyphens/underscores only
- ✅ `sanitize_email()` - validates email format
- ✅ `sanitize_file_name()` - safe for file names
- ✅ `sanitize_html_class()` - safe for CSS classes
- ✅ `sanitize_title()` - creates slugs
- ✅ `sanitize_sql_orderby()` - validates ORDER BY clauses
- ✅ `wp_parse_id_list()` - array of integers

**Other Safe Methods:**
- ✅ `$wpdb->insert()` - uses prepare internally
- ✅ `$wpdb->escape()` - escapes strings (but prefer prepare)
- ✅ `md5()`, `base64_encode()` - produces safe strings

### Common Vulnerability Patterns

**Pattern 1: Direct User Input in Query**
```php
// VULNERABLE
$wpdb->query("SELECT * FROM table WHERE id = " . $_GET['id']);
$wpdb->get_results("DELETE FROM table WHERE name = '" . $_POST['name'] . "'");
```

**Pattern 2: esc_sql() Misuse (NOT SAFE)**
```php
// VULNERABLE - esc_sql alone is insufficient
$wpdb->query("SELECT * FROM table WHERE id = " . esc_sql($_GET['id']));
// Can still be exploited if used without quotes in numeric context
```

**Pattern 3: prepare() First Argument with User Input**
```php
// VULNERABLE - user controls the query structure
$table = $_GET['table'];
$wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id);
```

**Pattern 4: Unescaped ORDER BY / Column Names**
```php
// VULNERABLE
$order = $_GET['order'];
$wpdb->query("SELECT * FROM table ORDER BY $order");

// SAFER - allowlist
$allowed = ['id', 'name', 'date'];
$order = in_array($_GET['order'], $allowed) ? $_GET['order'] : 'id';
$wpdb->query("SELECT * FROM table ORDER BY $order");
```

### False Positive Checks

- Verify user input actually reaches query (not just nearby code)
- Check for allowlist validation: `in_array($var, ['col1', 'col2'])`
- Verify variable is truly user-controlled (not hardcoded)
- Check WP_Query context (doesn't use raw queries)

---

## CSRF (Cross-Site Request Forgery)

### Detection Patterns

Look for sensitive actions (POST/GET handlers, AJAX, admin actions) without proper nonce verification.

### Required Protection

**Standard Form Actions:**
```php
// SAFE - proper nonce check
if (!wp_verify_nonce($_POST['_wpnonce'], 'my-action')) {
    wp_die('Invalid nonce');
}
// Process action
```

**AJAX Actions:**
```php
// SAFE - AJAX nonce check
check_ajax_referer('my-ajax-action', 'security');
```

### Common Vulnerability Patterns

**Pattern 1: Missing Nonce Check**
```php
// VULNERABLE - no nonce verification
if (isset($_POST['action'])) {
    update_option('setting', $_POST['value']);
}
```

**Pattern 2: Inverted Nonce Check**
```php
// VULNERABLE - dies when nonce is VALID (backwards logic)
if (wp_verify_nonce($_POST['nonce'], 'action')) {
    wp_die('Invalid nonce');
}
// Action proceeds when nonce is invalid!
```

**Pattern 3: Flawed Logic**
```php
// VULNERABLE - only checks if nonce is set
if (!empty($_POST['nonce']) && !wp_verify_nonce($_POST['nonce'], 'action')) {
    wp_die('Invalid');
}
// If nonce not set, check is bypassed

// VULNERABLE - isset + inverted check
if (isset($_POST['nonce']) && !wp_verify_nonce($_POST['nonce'], 'action')) {
    // Dies only if nonce is set but invalid
    wp_die('Invalid');
}
// If nonce not provided, action proceeds
```

**Pattern 4: Nonce Check Not in Conditional**
```php
// VULNERABLE - check result not used
wp_verify_nonce($_POST['nonce'], 'action');
// Action proceeds regardless
update_option('setting', $_POST['value']);
```

**Pattern 5: Nonce Check But No Die**
```php
// VULNERABLE - check doesn't prevent execution
if (!wp_verify_nonce($_POST['nonce'], 'action')) {
    // Logs error but continues
    error_log('Invalid nonce');
}
// Action proceeds even with invalid nonce
update_option('setting', $_POST['value']);
```

**Pattern 6: process_bulk_action Without Nonce**
```php
// VULNERABLE
public function process_bulk_action() {
    $action = $this->current_action();
    if ('delete' === $action) {
        // No nonce check
        $this->delete_items();
    }
}
```

### Safe Patterns

**Correct Nonce Flow:**
```php
// 1. Generate nonce in form
wp_nonce_field('my-action', 'my-nonce');

// 2. Verify nonce in handler
if (!isset($_POST['my-nonce']) || !wp_verify_nonce($_POST['my-nonce'], 'my-action')) {
    wp_die('Security check failed');
}

// 3. Process action
update_option('setting', $_POST['value']);
```

### Sensitive Actions Requiring CSRF Protection

- Database modifications: `update_option()`, `delete_option()`, `wpdb->query()` with INSERT/UPDATE/DELETE
- User modifications: `wp_insert_user()`, `wp_delete_user()`, `wp_set_auth_cookie()`
- File operations: file uploads, deletions
- Plugin/theme installations
- Settings changes
- AJAX handlers processing user data

---

## Open Redirect

### User Input Sources

- `$_GET`, `$_POST`, `$_REQUEST`
- `filter_input()` without URL validation
- `add_query_arg()`, `remove_query_arg()` can build URLs

### Dangerous Sink

- `wp_redirect($url)` - redirects to any URL

### Safe Functions

- ✅ `wp_safe_redirect($url)` - validates redirect to same domain
- ✅ URL allowlist validation

### Common Vulnerability Patterns

**Pattern: User-Controlled Redirect**
```php
// VULNERABLE
wp_redirect($_GET['redirect_to']);

// VULNERABLE - esc_url doesn't prevent open redirect
wp_redirect(esc_url($_GET['url']));

// SAFE - wp_safe_redirect validates domain
wp_safe_redirect($_GET['url']);

// SAFE - allowlist
$allowed_urls = ['/page1', '/page2'];
if (in_array($_GET['page'], $allowed_urls)) {
    wp_redirect(home_url($_GET['page']));
}
```

### Detection Workflow

1. Search for `wp_redirect(` calls
2. Trace first argument backward to source
3. Check if URL is user-controlled
4. Verify no allowlist or wp_safe_redirect usage

---

## SSRF (Server-Side Request Forgery)

### User Input Sources

- `$_GET`, `$_POST`, `$_REQUEST`
- `filter_input()` without validation

### Dangerous Sinks

- `wp_remote_post($url)`, `wp_remote_get($url)`, `wp_remote_head($url)`, `wp_remote_request($url)`
- `get_headers($url)`

### Common Vulnerability Patterns

**Pattern: User-Controlled URL**
```php
// VULNERABLE
$url = $_GET['url'];
$response = wp_remote_get($url);

// VULNERABLE - esc_url doesn't prevent SSRF
$response = wp_remote_get(esc_url($_GET['url']));
```

### Safe Patterns

```php
// SAFE - allowlist of domains
$allowed_domains = ['api.example.com', 'cdn.example.com'];
$parsed = parse_url($_GET['url']);
if (in_array($parsed['host'], $allowed_domains)) {
    $response = wp_remote_get($_GET['url']);
}

// SAFER - reject private IPs, localhost, internal ranges
```

### Detection Workflow

1. Search for `wp_remote_*` and `get_headers` calls
2. Trace URL parameter to source
3. Check if user-controlled
4. Verify no domain allowlist or IP validation

### Exploitation Impact

- Internal network scanning (localhost, 127.0.0.1, 192.168.x.x, 10.x.x.x)
- Cloud metadata access (169.254.169.254 for AWS credentials)
- Port scanning
- Protocol smuggling (file://, gopher://, etc)

---

## LFI (Local File Inclusion)

### User Input Sources

- `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`
- `filter_input()` without proper filters

### Dangerous Sinks

- `include($path)`, `include_once($path)`, `require($path)`, `require_once($path)`
- `comments_template($file)` with non-literal argument

### Safe Functions

- ✅ `validate_file($path)` - returns 0 if path is safe
- ✅ `sanitize_file_name($name)` - removes directory traversal
- ✅ `sanitize_key($key)` - alphanumeric only
- ✅ File path allowlist with `in_array()`

### Common Vulnerability Patterns

**Pattern 1: Direct User Input in Include**
```php
// VULNERABLE
include($_GET['page'] . '.php');
require('/path/' . $_POST['template']);
```

**Pattern 2: Path Traversal**
```php
// VULNERABLE - can use ../../../etc/passwd
include('templates/' . $_GET['file']);
```

**Pattern 3: comments_template with Variable**
```php
// VULNERABLE
comments_template($custom_template); // If $custom_template is user-controlled
```

### Safe Patterns

```php
// SAFE - allowlist
$allowed = ['page1', 'page2', 'page3'];
$page = $_GET['page'];
if (in_array($page, $allowed)) {
    include($page . '.php');
}

// SAFE - validate_file
if (0 === validate_file($_GET['file'])) {
    include('templates/' . $_GET['file']);
}

// SAFE - sanitize_key (restricts to alphanumeric)
$file = sanitize_key($_GET['template']);
include("templates/$file.php");
```

### WordPress Core Protection

- `$_GET['page']` is validated by WordPress in admin context (usually safe)
- But audit direct file access scenarios

### Detection Workflow

1. Search for `include`, `require`, `include_once`, `require_once`, `comments_template`
2. Trace path argument to source
3. Check if user-controlled
4. Verify allowlist or validate_file usage
5. Check for path concatenation that could enable traversal

---

## Object Injection

### User Input Sources

- `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES`
- `filter_input()` with default filters

### Dangerous Sink

- `unserialize($data)` with user-controlled data

### Safe Patterns

```php
// SAFE - allowed_classes => false prevents object injection
unserialize($data, ['allowed_classes' => false]);

// SAFER - use JSON instead
$data = json_decode($_POST['data'], true);
```

### Common Vulnerability Pattern

```php
// VULNERABLE
$data = unserialize($_COOKIE['user_data']);
$obj = unserialize(base64_decode($_POST['serialized']));
```

### Detection Workflow

1. Search for `unserialize(` calls
2. Trace argument backward to source
3. Check if user-controlled
4. Verify `allowed_classes => false` not used
5. Check if data passes through base64_decode (still tainted)

### Exploitation Requirements

- POP (Property-Oriented Programming) chain must exist in codebase
- Requires analysis of __destruct, __wakeup, __toString magic methods

---

## Command Injection

### User Input Sources

- `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`
- `$_SERVER['REQUEST_URI']`, `$_SERVER['PHP_SELF']`, `$_SERVER['QUERY_STRING']`
- `filter_input()` without proper filters

### Dangerous Sinks

- `system($cmd)`, `exec($cmd)`, `passthru($cmd)`, `shell_exec($cmd)`
- `assert($code)` - can execute PHP code
- `eval($code)` - executes PHP code

### Safe Functions

- ✅ `escapeshellarg($arg)` - escapes a single argument
- ✅ `escapeshellcmd($cmd)` - escapes entire command (less safe, prefer escapeshellarg)
- ✅ `sanitize_key()` - restricts to alphanumeric
- ✅ Allowlist validation

### Common Vulnerability Patterns

**Pattern 1: User Input in Shell Command**
```php
// VULNERABLE
system('ping -c 4 ' . $_GET['host']);
exec('convert ' . $_POST['file'] . ' output.png');

// VULNERABLE - command injection via semicolon
// Input: ; cat /etc/passwd
system('ls ' . $_GET['dir']);
```

### Safe Patterns

```php
// SAFE - escapeshellarg
system('ping -c 4 ' . escapeshellarg($_GET['host']));

// SAFE - allowlist
$allowed_commands = ['status', 'restart', 'stop'];
if (in_array($_GET['cmd'], $allowed_commands)) {
    system('service myapp ' . $_GET['cmd']);
}
```

### Detection Workflow

1. Search for `system`, `exec`, `passthru`, `shell_exec`, `assert`, `eval`
2. Trace arguments backward to source
3. Check if user-controlled
4. Verify escapeshellarg/escapeshellcmd or allowlist usage

---

## Auth Bypass & Options Manipulation

### Auth Bypass Patterns

**Pattern 1: wp_set_auth_cookie with User Input**
```php
// VULNERABLE - user controls which user to authenticate as
wp_set_auth_cookie($_GET['user_id']);
wp_set_auth_cookie($_POST['username']);

// SAFE - only after proper authentication
$user = wp_signon($credentials);
if (!is_wp_error($user)) {
    wp_set_auth_cookie($user->ID);
}
```

**Pattern 2: wp_authenticate_*_password Misuse**
```php
// VULNERABLE - bypassing password check
if ($_GET['admin'] == 'true') {
    wp_set_auth_cookie(1); // Admin user
}
```

**Pattern 3: REST API permission_callback Issues**
```php
// VULNERABLE - returns non-boolean
register_rest_route('ns/v1', '/endpoint', [
    'callback' => 'my_callback',
    'permission_callback' => function() {
        return get_option('allow_access'); // Should return bool
    }
]);

// VULNERABLE - always returns true
'permission_callback' => '__return_true'

// SAFE
'permission_callback' => function() {
    return current_user_can('manage_options');
}
```

### Options Manipulation

**Pattern 1: User-Controlled update_option**
```php
// VULNERABLE - user controls option key
update_option($_POST['option_name'], $_POST['value']);

// VULNERABLE - user controls value, no capability check
if (isset($_POST['setting'])) {
    update_option('my_setting', $_POST['setting']); // No nonce, no capability
}

// SAFE
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
}
if (!wp_verify_nonce($_POST['nonce'], 'update-setting')) {
    wp_die('Invalid nonce');
}
update_option('my_setting', sanitize_text_field($_POST['setting']));
```

**Pattern 2: Arbitrary Plugin Installation**
```php
// VULNERABLE - user controls plugin slug
if (isset($_GET['install_plugin'])) {
    // No nonce, no capability check
    $plugin = $_GET['plugin'];
    // ... proceeds to install
}
```

### User Enumeration

**Pattern 1: wp_user_query Email Disclosure**
```php
// VULNERABLE - exposes whether email exists
$users = new WP_Query([
    'search' => $_GET['email'],
    'search_columns' => ['user_email']
]);
if ($users->get_total()) {
    echo 'Email found'; // Information disclosure
}
```

### User Deletion

**Pattern 1: Unprotected wp_delete_user**
```php
// VULNERABLE - no nonce, no capability check
if (isset($_GET['delete_user'])) {
    wp_delete_user($_GET['user_id']);
}
```

### Detection Workflow for Auth/Options

1. Search for: `wp_set_auth_cookie`, `wp_authenticate_*`, `update_option`, `delete_option`, `wp_delete_user`, `register_rest_route`
2. Check for capability checks: `current_user_can()`, `is_super_admin()`
3. Check for nonce verification
4. Verify user input doesn't control critical parameters (user ID, option keys)

### Capability Functions (Required for Admin Actions)

- `current_user_can('capability')` - check if current user has capability
- `is_super_admin()` - check if network super admin
- `is_user_logged_in()` - check if any user is logged in

Common capabilities: `manage_options`, `edit_posts`, `publish_posts`, `delete_users`, `install_plugins`

---

## Additional Vulnerability Patterns

### extract() with User Input

```php
// VULNERABLE - user can overwrite variables
extract($_POST);
// Now attacker controls all variables from POST data

// Can lead to: XSS, SQLi, auth bypass, logic bugs
```

### XXE (XML External Entity)

```php
// VULNERABLE - LIBXML_NOENT enables entity expansion
simplexml_load_string($xml, null, LIBXML_NOENT);
$dom = new DOMDocument();
$dom->loadXML($xml, LIBXML_NOENT);

// SAFE - disable entities or use proper parsing
libxml_disable_entity_loader(true);
simplexml_load_string($xml);
```

### File Operations

```php
// VULNERABLE - arbitrary file deletion
unlink('/path/' . $_GET['file']);

// VULNERABLE - arbitrary file read
file_get_contents('/path/' . $_GET['file']);

// VULNERABLE - arbitrary file write
file_put_contents('/path/' . $_GET['file'], $_POST['content']);
```

---

## Detection Workflow Summary

For each vulnerability category, subagents should:

1. **Search for Sources** - Use Grep to find user input entry points
2. **Search for Sinks** - Use Grep to find dangerous function calls
3. **Trace Data Flow** - Use Read to examine code and trace variables from source to sink
4. **Check Sanitization** - Verify if appropriate sanitization/escaping is applied
5. **Verify Exploitability** - Check for allowlists, conditionals, validation that may prevent exploitation
6. **Report Findings** - Document file:line, code snippet, and assessment

## Neutral vs Safe Functions

**Neutral** functions pass taint through (data is still dangerous):
- String manipulation: `trim()`, `sprintf()`, `explode()`, `implode()`, `strtolower()`
- Encoding: `base64_decode()`, `json_decode()`, `urldecode()`
- WordPress: `wp_unslash()`, `stripslashes()`

**Safe** functions neutralize taint (context-specific):
- XSS: `esc_attr()`, `esc_html()`, `esc_url()`, `esc_js()`, `intval()`
- SQLi: `$wpdb->prepare()`, `intval()`, `absint()`, `sanitize_key()`
- Command Injection: `escapeshellarg()`, `sanitize_key()`
- LFI: `sanitize_file_name()`, `validate_file()`, allowlist validation

---

## Important Notes for Subagents

1. **Context Matters** - `esc_attr()` is safe for HTML attributes but NOT for HTML content or JavaScript
2. **sanitize_text_field() ≠ Escaping** - It removes tags but doesn't escape quotes; insufficient for XSS prevention in attributes
3. **esc_sql() ≠ Safe** - It's insufficient for SQL injection prevention; always use `$wpdb->prepare()` with placeholders
4. **esc_url() ≠ SSRF/Open Redirect Protection** - It validates format but doesn't prevent malicious URLs
5. **Always Verify Full Context** - Read surrounding code to check for validation, allowlists, or early returns
6. **WordPress Core Validation** - `$_GET['page']` in admin is typically validated; but still audit direct file access
7. **Check for False Positives** - Verify variable is truly user-controlled, not hardcoded or validated to safe values

---

**End of Detection Patterns Document**
