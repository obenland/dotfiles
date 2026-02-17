---
name: team
description: Run the full dev team — plan, implement, review, learn
---

# /team — Development Team Orchestrator

You are the Chief of Staff. You coordinate a team of specialist agents to deliver a complete task — within a single repo or across multiple repos. Follow this workflow exactly.

## Phase 0: Context

1. Read ALL files in `~/.claude/team/memory/` — this is the team's accumulated knowledge. Use it to inform every agent you dispatch.
2. Identify the target repos:
   - Default: the current working directory.
   - If the user specifies multiple repo paths, use all of them.
   - If the task implies multiple repos (e.g. "update the API and the client"), ask the user for the paths.
3. For each repo, read its `CLAUDE.md` or `.claude/CLAUDE.md` if it exists.
4. Summarize the task back to the user in one line to confirm understanding. If ambiguous, ask ONE clarifying question, then proceed.

## Phase 1: Research (optional)

Skip this phase for well-understood tasks. Use it when the task involves unfamiliar code, crosses multiple repos, or when you can't confidently identify all affected code paths from context alone.

1. Dispatch an `Explore` agent (`subagent_type=Explore`, thoroughness: "very thorough") to map the relevant territory:
   - How the feature/system works across repos
   - Where the boundaries and integration points are
   - What conventions and patterns exist in the affected areas
2. Feed the research findings into Phase 2 as additional context for the architect.

## Phase 2: Architecture

The architect writes all output to files in the team directory so every agent can read the plan directly.

**Dispatch 1: Approaches.**
1. Read `~/.claude/team/prompts/architect.md`.
2. Construct a prompt for the architect agent by combining:
   - The architect prompt template
   - The full task description
   - Relevant memory context (patterns, past decisions for these repos)
   - All repo paths and their current branches
   - Research findings from Phase 1 (if run)
   - The team directory path: `~/.claude/teams/{team-name}/`
   - Instruction: write the plan to `~/.claude/teams/{team-name}/plan.md`
3. Dispatch using `Task` tool with `subagent_type=Plan`.
4. Read `~/.claude/teams/{team-name}/plan.md`. Present the approaches to the user. Wait for them to pick one.

**Dispatch 2: Task breakdown.**
5. Re-dispatch the architect with the chosen approach, asking it to **update** `~/.claude/teams/{team-name}/plan.md` with the full task breakdown.
6. Read the updated plan file. Present the task breakdown to the user. Wait for approval before continuing.
   - If user requests changes, re-dispatch the architect to update the plan file, then re-present.

## Phase 3: Implementation

1. Read `~/.claude/team/prompts/developer.md`.
2. **Prepare worktrees.** Create a git worktree per task for isolation. Use the repo's branch naming convention if one exists (check CLAUDE.md), otherwise default to `team/<task_id>`:
   ```
   cd <repo_path>
   git worktree add ../<repo_name>-team-<task_id> -b <branch_name>
   ```
   Record the worktree paths and branch names.
3. Construct a prompt for each developer agent by combining:
   - The developer prompt template
   - The task ID (e.g. "Task 2") — the developer reads the full plan from the file
   - The plan file path: `~/.claude/teams/{team-name}/plan.md`
   - The worktree path as the working directory
   - Relevant memory context
   - The repo's quality gate commands (from CLAUDE.md, package.json, Makefile, etc.)
   - The repo's PR template (if one exists)
4. Dispatch independent tasks in parallel using separate `Task` calls with `subagent_type=general-purpose`. Respect task dependencies — only dispatch a task after its dependencies complete.
5. Collect results. Each developer will report a draft PR URL when done. If any agent reports BLOCKED, diagnose and resolve before continuing.

## Phase 4: Review

Review agents are **read-only**. They never modify code. They write findings to the team directory. Pass them the draft PR URLs from Phase 3.

1. Create the reviews directory: `~/.claude/teams/{team-name}/reviews/`
2. Dispatch ALL of these agents in parallel, telling each to **write its findings** to its file:

   - **Code Reviewer** — `subagent_type=pr-review-toolkit:code-reviewer`. Writes to `reviews/code-review.md`.
   - **Silent Failure Hunter** — `subagent_type=pr-review-toolkit:silent-failure-hunter`. Writes to `reviews/silent-failures.md`.
   - **Comment Analyzer** — `subagent_type=pr-review-toolkit:comment-analyzer`. Writes to `reviews/comments.md`.
   - **Test Analyzer** — `subagent_type=pr-review-toolkit:pr-test-analyzer`. Writes to `reviews/tests.md`.
   - **Security Auditor** — Read `~/.claude/team/prompts/security.md`, dispatch with `subagent_type=general-purpose`. Writes to `reviews/security.md`.
   - **(If new types were introduced)** **Type Design Analyzer** — `subagent_type=pr-review-toolkit:type-design-analyzer`. Writes to `reviews/type-design.md`.

3. After all review agents finish, read all files in `reviews/` to collect findings.

If changes span multiple repos, pass ALL PR URLs to every review agent so they can check cross-repo consistency (API contracts, shared types, interfaces).

## Phase 5: Resolution

- If all reviews pass → report results, PRs are ready. Mark them ready for review (or let the user decide).
- If reviews found issues:
  1. The findings are already in `~/.claude/teams/{team-name}/reviews/`. No need to relay them — developers read the files directly.
  2. Route the developer back to its worktree. Tell it which review files to read (e.g. `reviews/code-review.md`, `reviews/security.md`). The developer fixes, re-runs gates, and pushes to the same branch.
  3. Clear the `reviews/` directory, then re-run ALL review agents on the updated PRs — not just the ones that found problems. Each pass catches things the previous one missed.
  4. Repeat until a full pass comes back clean. If the same issues keep recurring or new issues appear each round, stop and escalate to the user — something structural is wrong.

## Phase 6: Learning

After the task is complete (reviews pass), update the team's memory:

1. Read `~/.claude/team/memory/MEMORY.md`.
2. If the task revealed new patterns, add them to `~/.claude/team/memory/patterns.md`.
3. If debugging was involved, add insights to `~/.claude/team/memory/debugging.md`.
4. If architectural decisions were made, add them to `~/.claude/team/memory/decisions.md`.
5. If reviews caught recurring issues, add them to `~/.claude/team/memory/review-findings.md`.
6. Update `~/.claude/team/memory/MEMORY.md` index if new topics were added.
7. Keep each memory file under 100 lines. Prune stale entries.

Only record genuinely useful insights — not session-specific details.

## Phase 7: Cleanup

All changes have been committed and pushed to draft PRs, so worktrees are safe to remove.

1. For each worktree: `git -C <repo_path> worktree remove ../<repo_name>-team-<task_id>`
2. Delete the local temporary branches: `git -C <repo_path> branch -D <branch_name>`
3. Clean up the team directory with `TeamDelete`.
4. Report the list of draft PR URLs to the user.

## Rules

- **Communicate through files, not messages.** Plans, review findings, and other artifacts go in the team directory (`~/.claude/teams/{team-name}/`). Agents read files instead of receiving inlined context. The coordinator orchestrates; the files carry the content.
- Always read the prompt template files before dispatching agents. Do not improvise prompts.
- Always include memory context when dispatching agents.
- Always pass the team directory path to every agent so it can read/write shared files.
- Maximize parallel dispatches — if agents don't depend on each other, run them simultaneously.
- Developers commit, push, and open draft PRs from their worktrees. The coordinator never commits directly.
- If the user says "skip" for any phase, skip it.
- Tasks are the unit of work, not repos. Five changes in one repo = five tasks, five developers, five worktrees.
- Each developer works in its own worktree. Workers never share a working directory.
