#!/bin/bash
# Blocks `cd <dir> && ...` / `cd <dir>; ...` prefixed commands.
#
# A cd prefix makes the effective working directory undeterminable for the
# permission engine, so every such command falls through to a manual approval
# prompt. Absolute paths (or `git -C`) avoid that entirely.

INPUT=$(cat)

COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // ""')

# Only the prefix form is a problem: `cd` first, then another command.
[[ "$COMMAND" =~ ^[[:space:]]*cd([[:space:]]|$) ]] || exit 0
[[ "$COMMAND" =~ (\&\&|\|\||\;|$'\n') ]] || exit 0

jq -n '{
  "hookSpecificOutput": {
    "hookEventName": "PreToolUse",
    "permissionDecision": "deny",
    "permissionDecisionReason": "Do not prefix commands with `cd` — the effective directory cannot be resolved, so the call needs manual approval. Use absolute paths instead: `git -C /abs/path <cmd>`, `grep -r pattern /abs/path`, `find /abs/path ...`, `npm --prefix /abs/path ...`."
  }
}'
