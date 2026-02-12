---
name: wrap
description: End session — commit and write session memo
---

# /wrap — Session Closure

1. **Check changes**
   ```bash
   git status
   git diff --stat
   ```

2. **Stage and commit** (if changes exist)
   - Clear commit message following project conventions
   - Include Co-Authored-By line

3. **Write session memo** to `.claude/_NEXT_SESSION_MEMO.md`
   - What shipped this session
   - Current state (branch, tests passing, build status)
   - In-progress items
   - Next session priorities

4. **Report**
   ```
   Wrapped:
   - [What was completed]
   - Commit: [hash] [message]
   - Session memo updated
   ```

## Skip When

- No changes to commit and nothing to note
- Mid-session checkpoint — just note where you are
