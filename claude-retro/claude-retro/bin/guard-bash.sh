#!/bin/bash
#
# PreToolUse hook for the retro's headless run.
#
# Permission rules match command text, not where a command runs or where a push
# goes, so this hook enforces what they can't. It reads the shell's current
# directory from the hook input's `cwd`, which follows earlier `cd` calls.
#
#   - git add / commit / checkout / push run only inside ~/claude-retro/worktrees/<dir>,
#     never in the user's own checkouts.
#   - git push only as `git push [-u] <remote> claude-retro/<branch>`, to a remote owned by
#     FORK_OWNER in a repo that also has a remote owned by someone else (a real fork).
#   - git worktree add/remove only for paths under ~/claude-retro/worktrees/, on claude-retro/* branches.
#   - gh pr create only as a draft, from FORK_OWNER:claude-retro/*, with no shell operators.
#   - no `git -C` and no compound git commands (they sidestep the checks above).
#
# Exit 2 blocks the call and shows stderr to Claude.

set -uo pipefail

FORK_OWNER="obenland"
WORKTREES="$(cd "$HOME/claude-retro/worktrees" 2>/dev/null && pwd -P)"

INPUT="$(cat)"
CMD="$(jq -r '.tool_input.command // ""' <<<"$INPUT")"
CWD="$(jq -r '.cwd // ""' <<<"$INPUT")"

# Blocks the tool call with a reason.
block() {
	echo "Blocked by retro guard: $1" >&2
	exit 2
}

# Succeeds when the given path resolves to a directory strictly inside the worktrees folder.
in_worktrees() {
	local real
	real="$(cd "${1/#\~/$HOME}" 2>/dev/null && pwd -P)" || return 1
	[ -n "$WORKTREES" ] && [[ "$real/" == "$WORKTREES"/?* ]]
}

# Succeeds when the given (possibly not yet existing) path sits strictly inside the worktrees folder.
target_in_worktrees() {
	local path="${1/#\~/$HOME}" parent
	parent="$(cd "$(dirname "$path")" 2>/dev/null && pwd -P)" || return 1
	[[ "$(basename "$path")" != "." && "$(basename "$path")" != ".." ]] || return 1
	[ -n "$WORKTREES" ] && [[ "$parent/" == "$WORKTREES"/* ]]
}

# Prints the GitHub owner from a remote URL (git@github.com:owner/repo.git or https://github.com/owner/repo).
owner_of() {
	sed -nE 's#^(git@github\.com:|https://github\.com/|ssh://git@github\.com/)([^/]+)/.*#\2#p' <<<"$1"
}

# Prints the repo name from a remote URL, without .git.
repo_of() {
	basename "$1" .git
}

case "$CMD" in
	*"git -C"*) block "don't use git -C. Run cd <dir> as its own Bash call, then the git command." ;;
esac

if [[ "$CMD" =~ (^|[^a-z])git[[:space:]] ]]; then
	[[ "$CMD" =~ [\;\&\|\`] || "$CMD" == *'$('* ]] && block "run each git command as its own Bash call, with no shell operators."
fi

if [[ "$CMD" =~ ^git[[:space:]]+(add|commit|checkout)([[:space:]]|$) ]]; then
	in_worktrees "$CWD" || block "git ${BASH_REMATCH[1]} only inside ~/claude-retro/worktrees/<dir>. cd there first (current dir: $CWD)."
	exit 0
fi

if [[ "$CMD" =~ ^git[[:space:]]+worktree([[:space:]]|$) ]]; then
	add_re='^git worktree add -[bB] claude-retro/[A-Za-z0-9._/-]+ ([^ ]+) ([A-Za-z0-9._/-]+)$'
	# --force only discards changes inside the retro's own worktree, e.g. after a failed commit hook.
	remove_re='^git worktree remove (--force )?([^ ]+)$'
	if [[ "$CMD" =~ $add_re ]]; then
		target_in_worktrees "${BASH_REMATCH[1]}" || block "worktrees go under ~/claude-retro/worktrees/<name>."
	elif [[ "$CMD" =~ $remove_re ]]; then
		target_in_worktrees "${BASH_REMATCH[2]}" || block "only remove worktrees under ~/claude-retro/worktrees/."
	elif [[ "$CMD" != "git worktree list" && "$CMD" != "git worktree prune" ]]; then
		block "allowed: git worktree add -b|-B claude-retro/<slug> ~/claude-retro/worktrees/<name> <base>, git worktree remove [--force] <path>, list, prune."
	fi
	exit 0
fi

if [[ "$CMD" =~ ^git[[:space:]]+push([[:space:]]|$) ]]; then
	re='^git push (-u |--set-upstream )?([A-Za-z0-9._-]+) (claude-retro/[A-Za-z0-9._/-]+)$'
	[[ "$CMD" =~ $re ]] || block "push must be exactly: git push -u <fork-remote> claude-retro/<branch>, run from inside the worktree."
	remote="${BASH_REMATCH[2]}"

	in_worktrees "$CWD" || block "push only from a worktree under ~/claude-retro/worktrees/. cd there first (current dir: $CWD)."

	url="$(cd "$CWD" && git remote get-url --push "$remote" 2>/dev/null)" || block "unknown remote '$remote'."
	[ "$(owner_of "$url")" = "$FORK_OWNER" ] || block "remote '$remote' ($url) isn't owned by $FORK_OWNER. Push only to your fork."

	name="$(repo_of "$url")"
	has_upstream=""
	for r in $(cd "$CWD" && git remote); do
		other="$(cd "$CWD" && git remote get-url "$r" 2>/dev/null)"
		if [ "$(repo_of "$other")" = "$name" ] && [ -n "$(owner_of "$other")" ] && [ "$(owner_of "$other")" != "$FORK_OWNER" ]; then
			has_upstream=1
		fi
	done
	[ -n "$has_upstream" ] || block "'$remote' isn't a fork (no upstream remote with another owner). Skip the PR and put the diff in the report."
	exit 0
fi

if [[ "$CMD" == *"gh pr create"* ]]; then
	[[ "$CMD" =~ [\;\&\|\`] || "$CMD" == *'$('* ]] && block "run gh pr create on its own, with --body-file and no shell operators."
	[[ "$CMD" == "gh pr create --draft "* ]] || block "gh pr create must start with: gh pr create --draft"
	[[ "$CMD" == *"--draft=false"* || "$CMD" == *"--web"* ]] && block "PRs must be drafts and created from the CLI."
	[[ "$CMD" == *"--head $FORK_OWNER:claude-retro/"* ]] || block "pass --head $FORK_OWNER:claude-retro/<branch>."
	exit 0
fi

exit 0
