---
description: Comprehensive WordPress security audit detecting XSS, SQLi, CSRF, SSRF, LFI, Object Injection, Command Injection, Auth Bypass, and more
allowed-tools:
  - Read
  - Glob
  - Grep
  - Bash
  - Skill
  - Task
---

# WordPress Security Review

Perform a comprehensive security audit of WordPress code, detecting vulnerabilities across multiple categories:

**Detected Vulnerabilities:**
- **XSS** (Cross-Site Scripting) - reflected, stored, DOM-based
- **SQL Injection** - database queries with user input
- **CSRF** (Cross-Site Request Forgery) - missing/flawed nonce checks
- **Open Redirect** - user-controlled redirects
- **SSRF** (Server-Side Request Forgery) - server-side HTTP requests with user input
- **LFI** (Local File Inclusion) - file inclusion with user input
- **Object Injection** - unserialize with user input
- **Command Injection** - system commands with user input
- **Auth Bypass** - authentication/authorization flaws
- **Options Manipulation** - insecure option updates

## Usage

Audit entire codebase:
```
/wp-security-review
```

Audit specific files or directories:
```
/wp-security-review path/to/file.php
/wp-security-review includes/
/wp-security-review src/ lib/
```

## Scope

$ARGUMENTS

If no arguments provided, the agent will audit all PHP files in common plugin/theme locations (inc/, includes/, lib/, src/, classes/, and root .php files).

## Detection Methodology

The agent uses **parallel category-specific subagents** for efficient analysis:

1. **Scope Definition** - Identifies files to audit
2. **Parallel Subagent Launch** - Spawns 9-10 concurrent detection agents, each focusing on one vulnerability category
3. **Result Consolidation** - Collects findings from all subagents
4. **False Positive Verification** - Manually verifies each finding by reading full context
5. **PoC Generation** - Creates proof-of-concept exploits for confirmed vulnerabilities
6. **Explanation** - Explains why vulnerable, impact, and severity
7. **Remediation** - Provides corrected code
8. **Report Generation** - Produces comprehensive security report

## Detection Categories

Each category is analyzed by a dedicated subagent:

1. **XSS Detection** - Tracks user input (GET/POST/cookies/options) to output (echo/print/return) and verifies context-appropriate escaping (esc_attr, esc_html, esc_url)

2. **SQL Injection Detection** - Finds database queries ($wpdb) with user input and verifies proper preparation (wpdb->prepare with placeholders)

3. **CSRF Detection** - Locates sensitive actions (POST handlers, AJAX, admin actions) and checks for nonce verification (wp_verify_nonce, check_ajax_referer)

4. **Open Redirect Detection** - Finds wp_redirect calls with user-controlled URLs

5. **SSRF Detection** - Locates wp_remote_* calls with user-controlled URLs

6. **LFI Detection** - Finds include/require statements with user-controlled paths

7. **Object Injection Detection** - Locates unserialize calls with user-controlled data

8. **Command Injection Detection** - Finds system/exec calls with user-controlled commands

9. **Auth/Options Detection** - Locates wp_set_auth_cookie, update_option, register_rest_route and verifies capability checks and nonce protection

10. **Block XSS Detection** (specialized) - If Gutenberg blocks with render_callback exist, invokes wp-block-security for specialized block XSS analysis

## Integration with wp-block-security

If Gutenberg blocks with `render_callback` are detected, the agent automatically invokes the **wp-block-security** specialized agent for in-depth block XSS analysis. This runs concurrently with other category agents for maximum efficiency.

## Output Format

The agent generates a comprehensive security report including:

- **Summary** - Total findings, severity breakdown, category breakdown
- **Per-Finding Details:**
  - Severity (Critical/High/Medium/Low)
  - Vulnerability category
  - File path and line number
  - Vulnerable code snippet
  - Explanation (why vulnerable, what attacker can do, impact)
  - Proof-of-concept payload
  - Recommended fix (corrected code)
- **Summary Table** - All findings in tabular format
- **Clean Files** - List of audited files with no issues
- **General Recommendations** - Best practices for each vulnerability category

## Severity Levels

**Critical:**
- Remote Code Execution (Command Injection)
- Authentication Bypass
- Local File Inclusion enabling sensitive file read
- Stored XSS with attribute breakout

**High:**
- Reflected XSS
- SQL Injection
- CSRF on admin actions
- SSRF enabling internal network access

**Medium:**
- Limited XSS (wrong context escaping)
- CSRF on non-critical actions
- Open Redirect

**Low:**
- Information disclosure
- Theoretical vulnerabilities

## Detection Patterns

The agent uses detection patterns derived from comprehensive WordPress security analysis, covering:
- Common user input sources (superglobals, WordPress functions)
- Dangerous sinks (output functions, database queries, file operations)
- Safe sanitization/escaping functions by context
- Common vulnerability patterns and mistakes
- WordPress-specific security patterns

## Performance

By using **parallel subagents**, the agent achieves:
- Fast execution: 9-10 category agents run concurrently
- Total time: ~max(subagent_times) instead of sum(subagent_times)
- Comprehensive coverage: Each agent focuses deeply on its specialty

## Example Report Entry

```markdown
## CRITICAL — Stored XSS — Unescaped Block Attribute in HTML

**File:** `blocks/custom-block/render.php:42`
**Category:** XSS

**Vulnerable Code:**
​```php
function render_custom_block($attributes) {
    $className = $attributes['className'];
    return '<div class="' . $className . '">Content</div>';
}
​```

**Explanation:**
The `className` attribute is output directly in an HTML class attribute without `esc_attr()` escaping. An attacker can inject `" onload="alert(document.cookie)` to break out of the attribute and execute JavaScript. This is a Critical stored XSS allowing full account takeover via session hijacking.

**Proof-of-Concept:**
​```html
<!-- wp:namespace/custom-block {"className":"\" onload=\"alert(document.cookie)"} /-->
​```

**Recommended Fix:**
​```php
function render_custom_block($attributes) {
    $className = $attributes['className'];
    return '<div class="' . esc_attr($className) . '">Content</div>';
}
​```
```

## Notes

- The agent performs **read-only analysis** - it does not modify code
- All findings are manually verified to minimize false positives
- Detection patterns abstract proprietary security rules while maintaining effectiveness
- The agent follows WordPress coding standards and security best practices

---

For more information on WordPress security best practices, refer to:
- `references/detection-patterns.md` - Detection patterns by vulnerability category
- `references/poc-templates.md` - Proof-of-concept templates
- `references/escaping-guide.md` - WordPress escaping and sanitization reference
