#!/bin/bash
# Pre-commit hook: blocks git commit unless PR review toolkit agents have been run.
# Uses a sentinel file to track review completion.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // empty')

# Only intercept git commit commands.
if ! echo "$COMMAND" | grep -qE '^git commit'; then
  exit 0
fi

# Check for sentinel file indicating reviews have been completed.
PROJECT_DIR=$(echo "$INPUT" | jq -r '.cwd // empty')
SENTINEL="$PROJECT_DIR/.claude/.reviews-passed"

if [ -f "$SENTINEL" ]; then
  rm -f "$SENTINEL"
  exit 0
fi

cat >&2 <<'MSG'
BLOCKED: Pre-commit review required.

Run all PR review toolkit agents on the staged changes before committing:
  1. pr-review-toolkit:code-reviewer
  2. pr-review-toolkit:silent-failure-hunter
  3. pr-review-toolkit:code-simplifier
  4. pr-review-toolkit:comment-analyzer
  5. pr-review-toolkit:pr-test-analyzer
  6. pr-review-toolkit:type-design-analyzer

After all reviews pass, create the sentinel file at .claude/.reviews-passed and retry the commit.
MSG
exit 2
