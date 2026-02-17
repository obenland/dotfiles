# Team Memory

Accumulated knowledge from past development sessions. Read this at the start of every `/team` run.

## Index

- [patterns.md](patterns.md) — Codebase conventions and reusable approaches
- [debugging.md](debugging.md) — Bug causes, solutions, and gotchas
- [decisions.md](decisions.md) — Architectural choices and rationale
- [review-findings.md](review-findings.md) — Common review issues to watch for

## Key Facts

- **Sandbox:** Cannot `rm -rf` or `git worktree remove` directories outside the working tree. User must do this manually.
- **GPG signing:** User has GPG signing configured. NEVER disable it. Sandbox blocks `~/.gnupg`, so commits from Claude Code will fail — user must amend in their terminal to sign.
- **Teammate permissions:** Always dispatch agents with `mode: "bypassPermissions"` — the user's allowlist doesn't propagate to teammates.
