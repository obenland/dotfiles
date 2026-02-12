---
name: sup
description: Quick status — 5-second context recovery
---

# /sup — Quick Status

1. Read `.claude/_NEXT_SESSION_MEMO.md` if it exists
2. `git status` for uncommitted work
3. `git log --oneline -5` for recent commits

## Output

```
Status:

From last session: [Brief summary from memo, or "no memo"]

Recent:
- [Last few commits or "no recent commits"]

Working tree:
- [Clean / X files modified]

Next: [Priority from memo, or suggested focus]
```

Keep it brief. 5-10 lines max.
