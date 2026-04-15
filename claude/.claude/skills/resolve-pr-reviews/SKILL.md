---
name: resolve-pr-reviews
description: >
  Resolve review comments on GitHub PRs — fetches unresolved review threads, evaluates each suggestion
  against the codebase, applies valid fixes in a single commit, replies to every comment explaining what
  was done and why, and resolves them all. Works with any reviewer: Copilot, Dependabot, human teammates,
  or any other source. Use this skill whenever the user mentions PR feedback, review comments, copilot
  suggestions, or wants to process/address/resolve review threads. Also trigger when the user says things
  like "handle the review comments", "address the PR feedback", "deal with the review suggestions",
  "clean up the PR reviews", "review the comments on my PR", "check the PR feedback", "look at the
  review comments on this PR", or "resolve the review threads".
---

# Resolve PR Reviews

Process and resolve review comments on a GitHub PR: evaluate each suggestion, fix what's valid, explain what's not, and close them all out.

## Arguments

Accepts an optional PR identifier — a number, URL, or `owner/repo#number`. If omitted, auto-detect from the current branch:

```bash
gh pr view --json number,url
```

If that fails (no PR for the current branch), ask the user for the PR.

The user may also specify a filter — e.g., "just the copilot comments" or "only @username's review." If they do, apply that filter in Step 1. Otherwise, process all unresolved review threads.

## Step 0: Check out the PR branch

Ensure you're on the PR's head branch with the code locally. If you're not already on it:

```bash
gh pr checkout {number}
```

This matters because you'll need to read the actual files as they exist on the PR branch, make changes, and push back to it.

## Step 1: Fetch review comments

Use the GraphQL API to fetch all review threads with their resolution status and author info in a single query:

```graphql
query($owner: String!, $repo: String!, $number: Int!) {
  repository(owner: $owner, name: $repo) {
    pullRequest(number: $number) {
      reviewThreads(first: 100) {
        nodes {
          isResolved
          id
          comments(first: 10) {
            nodes {
              id
              databaseId
              body
              path
              line
              originalLine
              diffHunk
              author {
                login
                __typename
              }
            }
          }
        }
      }
    }
  }
}
```

Filter to threads where `isResolved` is `false`. If the user requested a specific reviewer or author type, also filter by:
- `author.login` for a specific user (e.g., "copilot-pull-request-reviewer")
- `author.__typename` for a category (`"Bot"` vs `"User"` vs `"Organization"`)

If the query returns more than 100 threads, paginate using `after` cursors. In practice most PRs won't hit this.

If there are no unresolved comments matching the filter, report that and stop.

Group the unresolved comments by file path for efficient processing.

## Step 2: Evaluate each comment

For each comment:

1. **Read the file** at the referenced path and line range. Look at enough surrounding context (at least 20 lines each direction) to understand the code's intent.
2. **Read the suggestion** in the comment body. Many comments include a `suggestion` code block (fenced with triple backticks and the language tag `suggestion`) — parse that as the proposed change.
3. **Decide if the suggestion is valid.** A suggestion is valid when it:
   - Actually fixes a real issue (bug, style violation, performance problem)
   - Is consistent with the project's coding standards and existing patterns
   - Doesn't introduce regressions or break behavior
   - Improves readability or correctness without unnecessary churn

   A suggestion is **invalid** when it:
   - Contradicts the project's conventions (check CLAUDE.md, .editorconfig, linting config)
   - Is a stylistic preference that conflicts with the codebase's established patterns
   - Would introduce a bug or behavioral change
   - Is factually wrong or based on a misunderstanding of the code
   - Is trivial churn that adds no value

Keep a record of each comment's evaluation: comment ID, thread ID, file, valid/invalid, and your reasoning.

### Verify concrete claims

Suggestions sometimes include specific values — version numbers, commit SHAs, function signatures, dependency names — that may have been correct when written but could be stale or wrong. When a suggestion references a concrete, checkable fact, verify it before applying. If the value is outdated, the suggestion's direction may still be valid — correct the specifics rather than rejecting the whole thing.

## Step 3: Apply valid fixes

For each valid suggestion, apply the change to the file. Batch all changes — don't commit between each one.

If a suggestion includes a fenced `suggestion` code block, apply that exact code. If the suggestion is descriptive ("consider using X instead of Y"), implement the described change yourself.

After applying all fixes, review the changed files to make sure nothing conflicts (two suggestions editing the same lines, etc.). If there are conflicts, use your judgment to reconcile them.

## Step 4: Commit and push

Stage only the files that were changed by valid suggestions. Create a single commit with a message that describes *what* changed and *why*. The commit message should read like any other intentional commit, making the changes understandable to someone reading `git log` who has no context about the review.

**Example — good:**
```
Pin actions/checkout to commit SHA for supply-chain safety

The v4 floating tag was a regression from the previously pinned SHA.
Pin to v4.3.1 (34e1148) to ensure reproducible builds.
```

**Example — bad:**
```
Address review feedback
```

If multiple unrelated fixes are batched, use a summary subject line and list each fix in the body with its rationale.

Never use `--no-verify`. If pre-commit hooks fail, fix the issues and commit again.

Push the commit to the PR branch:

```bash
git push
```

## Step 5: Reply to each comment

All review comments on PRs are "pull request review comments" — use the review comment reply endpoint.

### Valid comments (fix applied)

Reply on the review thread confirming the fix. Be specific about what was changed:

```bash
gh api repos/{owner}/{repo}/pulls/comments/{comment_id}/replies \
  -f body="Fixed — <brief description of what was applied>."
```

Then resolve the thread using GraphQL:

```graphql
mutation($threadId: ID!) {
  resolveReviewThread(input: {threadId: $threadId}) {
    thread {
      isResolved
    }
  }
}
```

### Invalid comments (not applied)

Reply explaining why the suggestion wasn't applied. Be respectful but direct — give a clear technical reason:

```bash
gh api repos/{owner}/{repo}/pulls/comments/{comment_id}/replies \
  -f body="Not applied — <specific reason: e.g., 'this follows the project's existing convention of using X per .editorconfig', 'this would change the method signature which is part of the public API'>."
```

Then resolve the thread the same way.

## Step 6: Flag related issues

After processing all comments, scan the changed files for the same classes of issues that reviewers flagged. Reviewers often comment on one instance but miss others — if you fixed something in one place, check whether the same pattern exists nearby.

Report these to the user as observations — don't auto-fix them. Keep it brief: state what you found and where, and ask if they want it addressed. This keeps the user informed without overstepping scope.

## Step 7: Request a new Copilot review

If any changes were committed and pushed (i.e., at least one valid fix was applied), request a fresh Copilot code review on the PR so the new changes get reviewed too:

```bash
gh pr edit {number} --add-reviewer @copilot
```

If this fails (e.g., Copilot review isn't enabled on the repo), note it in the summary but don't treat it as an error.

## Step 8: Summarize

End with a brief recap so the user knows exactly what happened:

- How many review comments were found
- How many were fixed (with a one-liner per fix)
- How many were rejected (with the reason)
- Any related issues flagged
- The commit SHA that was pushed
- Whether a new Copilot review was requested

## Important notes

- Always use `gh api` for GitHub interactions, never raw curl
- Read actual code context before evaluating — don't judge suggestions in a vacuum
- Respect the project's CLAUDE.md, .editorconfig, and linting configuration
- If the PR has no unresolved review comments, say so and stop
