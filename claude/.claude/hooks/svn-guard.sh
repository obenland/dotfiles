#!/bin/bash
# Blocks git commands when the working directory is an SVN repository.

INPUT=$(cat)

# Single jq call to extract both fields.
eval "$(echo "$INPUT" | jq -r '@sh "COMMAND=\(.tool_input.command // "") CWD=\(.cwd // "")"')"

# Only intercept git commands (bash builtin, no subprocess).
[[ "$COMMAND" =~ git ]] || exit 0

# Resolve effective directory from cd prefix.
if [[ "$COMMAND" =~ ^[[:space:]]*cd[[:space:]]+\"?([^\"[:space:]]+) ]]; then
  CD_TARGET="${BASH_REMATCH[1]}"
  [[ "$CD_TARGET" == /* ]] && CWD="$CD_TARGET" || CWD="$CWD/$CD_TARGET"
fi

# Resolve effective directory from git -C <dir>.
if [[ "$COMMAND" =~ git[[:space:]]+-C[[:space:]]+\"?([^\"[:space:]]+) ]]; then
  GIT_C_DIR="${BASH_REMATCH[1]}"
  [[ "$GIT_C_DIR" == /* ]] && CWD="$GIT_C_DIR" || CWD="$CWD/$GIT_C_DIR"
fi

# Check if effective directory is an SVN repo (builtin test, no subprocess).
[[ -d "$CWD/.svn" ]] || exit 0

jq -n '{
  "hookSpecificOutput": {
    "hookEventName": "PreToolUse",
    "permissionDecision": "deny",
    "permissionDecisionReason": "This is an SVN repository \u2014 use svn commands instead (svn status, svn diff, svn log, svn up, svn ci, svn blame)."
  }
}'
