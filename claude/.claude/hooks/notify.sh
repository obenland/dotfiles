#!/bin/bash
# Sends a macOS notification when Claude needs attention.
# Passes through the actual prompt message from the hook's stdin JSON.

INPUT=$(cat)
MESSAGE=$(echo "$INPUT" | jq -r '.message // "Claude needs your attention"')
terminal-notifier -title "Claude Code" -message "$MESSAGE" -sound default
