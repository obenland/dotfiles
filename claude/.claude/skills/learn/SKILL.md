---
name: learn
description: Record a learning to the team's persistent memory
---

# /learn — Record Team Learning

Record insights to the team's persistent memory at `~/.claude/team/memory/`.

## Usage

`/learn <what to remember>`

## Process

1. Read `~/.claude/team/memory/MEMORY.md` to understand what's already recorded.
2. Categorize the learning:
   - **Pattern** → `patterns.md` (codebase conventions, reusable approaches)
   - **Debug insight** → `debugging.md` (bug causes, solutions, gotchas)
   - **Decision** → `decisions.md` (architectural choices, rationale)
   - **Review finding** → `review-findings.md` (common mistakes, things to check)
3. Read the target file. Append the new entry. Keep entries concise (1-3 lines each).
4. If this is a new topic not covered by existing files, create a new file and add it to the MEMORY.md index.
5. Keep each file under 100 lines. If approaching the limit, prune the least useful entries.
6. Confirm what was recorded and where.

## Rules

- Don't duplicate existing entries.
- Don't record session-specific context (current branch, temp paths, etc.).
- Entries should be useful to any future session, not just the current one.
