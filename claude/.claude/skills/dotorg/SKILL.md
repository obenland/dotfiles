---
name: dotorg
description: Use when working on a WordPress/wordpress.org change end to end — a Meta Trac ticket, a HackerOne report, or a feature — from worktree to PR to the local review and test cycle, and when asked to run or check the dotorg pipeline (wporg-pipeline, wporg-test, wporg-review, wporg-worktree).
---

# dotorg — one change, start to finish

The scripts in `bin/` are the pipeline. This skill says when to call them and the rules around them. Paths below assume `~/.claude/skills/dotorg/bin`.

## Workflow

1. **Worktree.** `wporg-worktree new <branch>` creates `../wordpress.org-<slug>` from a fresh `origin/trunk`, links `vendor` and `environments/node_modules`, and points the branch's push remote at the fork. Branch prefixes in use: `update/`, `fix/`, `add/`. Never work on `trunk` in the main checkout.
2. **Implement.** Commits describe the change; the body carries `Fixes #1234.` or `See #1234.` for a Meta Trac ticket. Keep review-fix commits separate from the original work.
3. **Check locally.** `wporg-test` (from inside the worktree, or `wporg-test <branch>`) runs phpcs against the merge-base and the CI-selected PHPUnit suites in wp-env. Fix until clean; CI will fail on what it fails on.
4. **Open the PR.** `git push fork <branch>` (never `origin`, which is upstream), then `gh pr create --base trunk --repo WordPress/wordpress.org`. The body's first line is `Fixes https://meta.trac.wordpress.org/ticket/1234` when there is a Trac ticket. Always the full URL: a bare `#1234` on GitHub links the repo's own issue/PR, not the ticket. A PR that depends on another targets `trunk` and says "Builds on #N"; never base it on the other branch, the sync bot deletes those. The title follows the title rule below.
5. **Review and triage.** `wporg-pipeline run <PR>` reviews the PR into `~/.cache/wporg-pipeline/reviews/<PR>.md` and triages the findings under `review-policy.md` (must-fixes only, one fix commit, dispositions recorded in the file). It runs in rounds: round 1 reviews the PR at high effort; each later round resumes the same reviewer and triager at medium effort, looks only at the fix commit's diff, and folds any further must-fix into that same commit. It stops when a round changes nothing, or at three rounds. Then it runs `wporg-test` on the result. It never pushes. Read the Triage sections, then `git push fork <branch>` if there is a fix commit.
6. **Commit to SVN.** On the sandbox, `wporgpr <PR>` applies the PR and commits it to meta.svn when you choose `c`. The mirror closes the PR. This step is yours.

## PR titles

The PR title becomes the first line of the SVN commit message, so it says why the change exists, not only what the diff does. The first commit's subject usually becomes the title, so write that one the same way.

- `Component: Summary`, imperative, no trailing period, under 100 characters.
- Take the why from the Meta Trac ticket (the reporter's problem and the thread), not from the diff. The summary names the problem solved or the outcome for the people who use the site. Keep a mechanism (a function, a query var, a block) only when it's what people will search for.
- Not "Fix #1234", "Update X" or "Changes to X", and not the ticket title when it only describes the symptom.

| Describes only what | Says why |
| --- | --- |
| Translate Site & Plugins: Split project contributors into current and past | Translate Site & Plugins: Show active translators apart from past contributors |
| Support Forums: Remove the PHP Compatibility Checker link | Support Forums: Stop recommending a PHP compatibility plugin that no longer covers current PHP |
| WordPress.tv: Request video thumbnails at the size they are shown | WordPress.tv: Load video grids faster by fetching thumbnails at display size |

## Rules the scripts cannot enforce

- The dotorg sandbox shares the production database and object cache. Nothing automated sends requests to it or applies code to it; `wporg-sandbox` is for you, by hand.
- No design docs, specs, or notes in the repo. The PR body is where a design lives.
- American English everywhere: strings, comments, commit messages, PR bodies.
- New test files use long `array()` syntax. Other code follows its file.
- A FreeScout module change bumps `version` in its `module.json`.
- Subtrees that mirror another repo (`wporg-learn`, `wporg-learn-2024`, and anything whose log shows "Sync with git" commits) are fixed upstream, not here.
- HackerOne report numbers never appear in public PRs, commits, or comments.
- Never `--no-verify`. Never `remove_all_filters()` or `remove_all_actions()` in tests.

## Reading the pipeline

- `wporg-pipeline status [PR]` summarizes the event log in `~/.cache/wporg-pipeline/events.jsonl`.
- `wporg-worktree ls` shows every worktree with its PR state; remove the ones whose PR is closed with `wporg-worktree rm <branch>`.
- A review is keyed to the PR head it saw; a new push triggers a new review on the next run, nothing else does.
