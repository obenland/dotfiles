#!/bin/bash
#
# Tests for guard-bash.sh. Builds a throwaway HOME with fake repos and checks
# that each sample command is allowed (exit 0) or blocked (exit 2).
#
# Usage: bash ~/claude-retro/bin/test-guard.sh

set -uo pipefail

GUARD="$(cd "$(dirname "$0")" && pwd)/guard-bash.sh"
FAKE="$(mktemp -d)"
trap 'rm -rf "$FAKE"' EXIT

W="$FAKE/claude-retro/worktrees"
mkdir -p "$W" "$FAKE/code"

# A fork setup like wordpress.org: upstream origin plus an obenland fork remote.
git init -q "$W/wporg"
git -C "$W/wporg" remote add origin git@github.com:WordPress/wordpress.org.git
git -C "$W/wporg" remote add fork git@github.com:obenland/wordpress.org.git

# A repo the user owns outright: no upstream, so not a fork.
git init -q "$W/own"
git -C "$W/own" remote add origin git@github.com:obenland/wp-last-login.git

# The user's own checkout, outside the worktrees folder.
git init -q "$FAKE/code/wporg"

PASS=0
FAIL=0

# Runs one case: expected exit code, cwd, command.
t() {
	local want="$1" cwd="$2" cmd="$3" got
	jq -n --arg c "$cmd" --arg d "$cwd" '{cwd: $d, tool_input: {command: $c}}' | HOME="$FAKE" "$GUARD" >/dev/null 2>&1
	got=$?
	if [ "$got" = "$want" ]; then
		PASS=$((PASS + 1))
	else
		FAIL=$((FAIL + 1))
		echo "FAIL (want $want, got $got): [$cwd] $cmd"
	fi
}

# Pushes.
t 0 "$W/wporg" "git push -u fork claude-retro/agents-md"
t 0 "$W/wporg" "git push fork claude-retro/agents-md"
t 2 "$W/wporg" "git push -u origin claude-retro/agents-md"
t 2 "$W/wporg" "git push --force fork claude-retro/x"
t 2 "$W/wporg" "git push fork claude-retro/x:trunk"
t 2 "$W/wporg" "git push fork trunk"
t 2 "$W/own" "git push -u origin claude-retro/x"
t 2 "$FAKE/code/wporg" "git push fork claude-retro/x"
t 2 "$FAKE/claude-retro" "git push fork claude-retro/x"
t 2 "$W" "git push fork claude-retro/x"
t 2 "$W/wporg" "git push fork claude-retro/x && rm -rf ~"
t 2 "$FAKE/claude-retro" "cd $W/wporg && git push fork claude-retro/x"
t 2 "$FAKE/claude-retro" "git -C $W/wporg push fork claude-retro/x"

# add / commit / checkout.
t 0 "$W/wporg" "git add AGENTS.md"
t 0 "$W/wporg" "git commit -F $FAKE/claude-retro/work/msg.txt"
t 0 "$W/wporg" "git checkout -b claude-retro/x"
t 2 "$FAKE/code/wporg" "git add AGENTS.md"
t 2 "$FAKE/code/wporg" "git commit -m x"
t 2 "$FAKE/code/wporg" "git checkout -b claude-retro/x"
t 2 "$W/wporg" "git commit -m \"\$(cat msg)\""

# Worktrees.
t 0 "$FAKE/code/wporg" "git worktree add -b claude-retro/x $W/wporg-2026-11-26 origin/trunk"
t 0 "$FAKE/code/wporg" "git worktree add -B claude-retro/x $W/wporg-2026-11-26 fork/claude-retro/x"
t 0 "$FAKE/code/wporg" "git worktree remove $W/wporg"
t 0 "$FAKE/code/wporg" "git worktree list"
t 0 "$FAKE/code/wporg" "git worktree prune"
t 2 "$FAKE/code/wporg" "git worktree add -b claude-retro/x $FAKE/elsewhere origin/trunk"
t 2 "$FAKE/code/wporg" "git worktree add -b feature/x $W/wporg-2 origin/trunk"
t 2 "$FAKE/code/wporg" "git worktree add $W/wporg-2 origin/trunk"
t 2 "$FAKE/code/wporg" "git worktree add -b claude-retro/x $W/../escape origin/trunk"
t 2 "$FAKE/code/wporg" "git worktree remove $FAKE/code/wporg"
t 0 "$FAKE/code/wporg" "git worktree remove --force $W/wporg"
t 2 "$FAKE/code/wporg" "git worktree remove --force $FAKE/code/wporg"
t 2 "$FAKE/code/wporg" "git worktree move $W/wporg $FAKE/x"

# Other git commands pass through to the permission rules.
t 0 "$FAKE/code/wporg" "git fetch origin"

# gh.
t 0 "$W/wporg" "gh pr create --draft --repo WordPress/wordpress.org --base trunk --head obenland:claude-retro/x --title \"Claude retro: x\" --body-file /tmp/b.md"
t 2 "$W/wporg" "gh pr create --repo WordPress/wordpress.org --head obenland:claude-retro/x --draft"
t 2 "$W/wporg" "gh pr create --draft --head obenland:claude-retro/x --title a; rm -rf /"
t 2 "$W/wporg" "gh pr create --draft --draft=false --head obenland:claude-retro/x"
t 2 "$W/wporg" "gh pr create --draft --head WordPress:trunk"
t 0 "$W/wporg" "gh pr list --repo WordPress/wordpress.org --author @me"

echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
