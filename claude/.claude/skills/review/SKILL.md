---
name: review
description: Review current changes before committing
allowed-tools: Read, Glob, Grep, Bash(git diff*), Bash(git status*), Bash(git log*)
---

# /review — Code Review

Review all uncommitted changes. Do not modify source code.

1. **Read the diff**
   ```bash
   git diff
   git diff --cached
   ```

2. **Check for issues**
   - Correctness: logic errors, off-by-one, missing edge cases
   - Security: SQL injection, XSS, missing capability checks, missing nonce verification
   - WordPress conventions: coding standards, hook naming, escaping output
   - Performance: unnecessary queries, missing caching, N+1 patterns
   - Incomplete work: TODOs, commented-out code, debug statements

3. **Read `_FRAGILE.md`** if it exists — flag any changes touching danger zones

4. **Report findings**

## Output

```
Review: [X files changed, Y insertions, Z deletions]

Issues:
- [file:line] [severity] [description]

Suggestions:
- [Optional improvements, not blockers]

Verdict: [Ready to commit / Needs fixes]
```

If no issues found, say so briefly and confirm ready to commit.
