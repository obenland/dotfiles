#!/bin/bash
#
# PreToolUse hook for the retro's headless run.
#
# Permission rules match command text, not where a command runs or what it
# reads, so this hook enforces what they can't. It reads the shell's current
# directory from the hook input's `cwd`, which follows earlier `cd` calls.
#
# Only cd, git and gh commands pass, starting with the program name; everything else is blocked.
#
# Every git command must run in a trusted place, decided by reading files, never by running git there:
#   - a user checkout: a repo root outside ~/claude-retro that run.sh listed in RETRO_TRUSTED_REPOS, or
#   - a genuine linked worktree under ~/claude-retro/worktrees/ whose main repo is a user checkout.
# Git reads config and hooks from where it runs, so a hand-made .git under the agent-writable
# worktrees folder could otherwise run arbitrary commands (core.fsmonitor, hooks, uploadpack).
#
# Each allowed command also has a fixed shape:
#   - git fetch <remote>                                    (a GitHub remote; no options)
#   - git worktree add -b|-B claude-retro/<slug> <wt> <base> (user checkout; <wt> under worktrees/)
#   - git worktree remove [--force] <wt> | list | prune
#   - git checkout -b claude-retro/<slug>                   (worktree)
#   - git add [--] <paths>                                  (worktree; no options)
#   - git commit -F <file under ~/claude-retro/work/>       (worktree; commit and push also need an absolute core.hooksPath, if any)
#   - git push [-u] <fork-remote> claude-retro/<slug>       (worktree; remote owned by FORK_OWNER in a real fork)
#   - gh pr create --draft --repo <upstream> --base <b> --head FORK_OWNER:claude-retro/<slug>
#       --title "<text>" --body-file <file under ~/claude-retro/work/>  (worktree; <upstream> is one of its remotes)
#   - no `git -C`, no shell operators, no newlines.
#
# Exit 2 blocks the call and shows stderr to Claude.

set -uo pipefail

FORK_OWNER="obenland"
RETRO="$(cd "$HOME/claude-retro" 2>/dev/null && pwd -P)"
WORKTREES="$(cd "$HOME/claude-retro/worktrees" 2>/dev/null && pwd -P)"
WORK="$(cd "$HOME/claude-retro/work" 2>/dev/null && pwd -P)"

INPUT="$(cat)"
CMD="$(jq -r '.tool_input.command // ""' <<<"$INPUT")"
CWD="$(jq -r '.cwd // ""' <<<"$INPUT")"

# Blocks the tool call with a reason.
block() {
	echo "Blocked by retro guard: $1" >&2
	exit 2
}

# Prints a path as the shell would see it: a leading ~ expanded, and relative paths resolved against the command's cwd.
abs_path() {
	local path="${1/#\~\//$HOME/}"
	[ "$path" = "~" ] && path="$HOME"
	[[ "$path" == /* ]] || path="$CWD/$path"
	printf '%s\n' "$path"
}

# Prints the physical path of an existing directory.
real_dir() {
	(cd "$(abs_path "$1")" 2>/dev/null && pwd -P)
}

# Prints the physical path of an existing regular file.
real_file() {
	local path dir
	path="$(abs_path "$1")"
	[ -f "$path" ] && [ ! -L "$path" ] || return 1
	dir="$(real_dir "$(dirname "$path")")" || return 1
	printf '%s/%s\n' "$dir" "$(basename "$path")"
}

# Succeeds when a physical path is strictly inside a directory.
inside() {
	[ -n "$2" ] && [[ "$1/" == "$2"/?* ]]
}

# Succeeds when a directory is a user checkout: a repo root outside ~/claude-retro that run.sh
# listed in RETRO_TRUSTED_REPOS (the repos from today's sessions; the agent can't change the hook's env).
is_user_checkout() {
	local dir trusted
	dir="$(real_dir "$1")" || return 1
	[ -n "$RETRO" ] || return 1
	[[ "$dir" == "$RETRO" ]] || inside "$dir" "$RETRO" && return 1
	[ -d "$dir/.git" ] && [ ! -L "$dir/.git" ] && [ -f "$dir/.git/HEAD" ] || return 1
	while IFS= read -r trusted; do
		[ -n "$trusted" ] && [ "$(real_dir "$trusted")" = "$dir" ] && return 0
	done <<<"${RETRO_TRUSTED_REPOS-}"
	return 1
}

# Succeeds when commit and push in the current worktree can't run any git hook. A relative core.hooksPath
# resolves inside the agent-writable worktree, and even a hook in the user's own hooks dir (pre-commit,
# lefthook, husky) reads its config from the worktree, which the agent can edit to run any command.
# The retro never skips hooks (no --no-verify), so a repo with active hooks gets no commit: the diff goes
# in the report instead. Reads config files with --file; never runs git in the worktree.
hooks_safe() {
	local gitdir common value hooks_dir hook
	gitdir="$(real_dir "$(sed -n '1s/^gitdir: //p' "$(real_dir "$CWD")/.git")")" || return 1
	common="$(dirname "$(dirname "$gitdir")")"
	hooks_dir="$common/hooks"
	for value in \
		"$(git config --global --get core.hooksPath 2>/dev/null)" \
		"$(git config --file "$common/config" --get core.hooksPath 2>/dev/null)" \
		"$(git config --file "$gitdir/config.worktree" --get core.hooksPath 2>/dev/null)"; do
		[ -z "$value" ] && continue
		value="${value/#\~\//$HOME/}"
		[[ "$value" == /* ]] || return 1
		[[ "$value/" == "$RETRO"/* ]] && return 1
		hooks_dir="$value"
	done
	[ -d "$hooks_dir" ] || return 0
	for hook in "$hooks_dir"/*; do
		[ -e "$hook" ] || continue
		[[ "$hook" == *.sample ]] && continue
		[ -x "$hook" ] && return 1
	done
	return 0
}

# Succeeds when a directory is the root of a genuine linked worktree under worktrees/ whose main repo is a user checkout.
is_retro_worktree() {
	local dir gitfile gitdir common back
	dir="$(real_dir "$1")" || return 1
	inside "$dir" "$WORKTREES" || return 1
	[ "$(dirname "$dir")" = "$WORKTREES" ] || return 1

	gitfile="$dir/.git"
	[ -f "$gitfile" ] && [ ! -L "$gitfile" ] || return 1
	gitdir="$(sed -n '1s/^gitdir: //p' "$gitfile")"
	gitdir="$(real_dir "$gitdir")" || return 1

	# Expect <main>/.git/worktrees/<name>, pointing back at this worktree.
	[ "$(basename "$(dirname "$gitdir")")" = "worktrees" ] || return 1
	common="$(dirname "$(dirname "$gitdir")")"
	[ "$(basename "$common")" = ".git" ] || return 1
	[ -f "$gitdir/gitdir" ] || return 1
	back="$(real_file "$(cat "$gitdir/gitdir")")" || return 1
	[ "$back" = "$gitfile" ] || return 1

	is_user_checkout "$(dirname "$common")"
}

# Prints the GitHub owner from a remote URL (git@github.com:owner/repo.git or https://github.com/owner/repo).
owner_of() {
	sed -nE 's#^(git@github\.com:|https://github\.com/|ssh://git@github\.com/)([^/]+)/.*#\2#p' <<<"$1"
}

# Prints owner/repo from a GitHub remote URL.
slug_of() {
	sed -nE 's#^(git@github\.com:|https://github\.com/|ssh://git@github\.com/)([^/]+/[^/]+)$#\2#p' <<<"$1" | sed 's/\.git$//'
}

# Succeeds when a file path resolves to a regular file inside ~/claude-retro/work/.
in_work() {
	local f
	f="$(real_file "$1")" || return 1
	inside "$f" "$WORK"
}

# Prints a remote's URL in the current directory. Only call after the directory passed a trust check.
remote_url() {
	(cd "$CWD" && git remote get-url "${2:---}" "$1" 2>/dev/null)
}

case "$CMD" in
	*$'\n'* | *$'\r'*) block "run one command per Bash call, on a single line." ;;
esac

# Fail closed: only cd, git and gh run, with the program name first and unquoted, so the checks below see exactly what the shell runs.
[[ "$CMD" =~ ^(cd|git|gh)(\ |$) ]] || block "only cd, git and gh commands are allowed, each starting with the program name. Use Read, Grep and Glob for files."
[[ "$CMD" =~ [\;\&\|\`\<\>\$\\] ]] && block "run each command as its own Bash call, with no shell operators, redirects, variables or backslashes."
[[ "$CMD" == *"git -C"* ]] && block "don't use git -C. Run cd <dir> as its own Bash call, then the git command."
if [[ "$CMD" =~ ^cd ]]; then
	[[ "$CMD" =~ ^cd\ [^\ \'\"]+$ ]] || block "cd takes one plain path, with no quotes or spaces."
	exit 0
fi

if [[ "$CMD" =~ ^git([[:space:]]|$) ]]; then
	if is_user_checkout "$CWD"; then
		PLACE="checkout"
	elif is_retro_worktree "$CWD"; then
		PLACE="worktree"
	else
		block "git runs only at the root of one of the user's checkouts, or of a retro worktree under ~/claude-retro/worktrees/<dir> (current dir: $CWD)."
	fi
fi

slug='claude-retro/[A-Za-z0-9._/-]+'

if [[ "$CMD" =~ ^git[[:space:]]+fetch([[:space:]]|$) ]]; then
	[[ "$CMD" =~ ^git\ fetch\ ([A-Za-z0-9._-]+)$ ]] || block "fetch must be exactly: git fetch <remote>"
	[ -n "$(owner_of "$(remote_url "${BASH_REMATCH[1]}")")" ] || block "only fetch named GitHub remotes."
	exit 0
fi

if [[ "$CMD" =~ ^git[[:space:]]+worktree([[:space:]]|$) ]]; then
	add_re="^git worktree add -[bB] $slug ([^ ]+) ([A-Za-z0-9._/-]+)$"
	# --force only discards changes inside the retro's own worktree, e.g. after a failed commit hook.
	remove_re='^git worktree remove (--force )?([^ ]+)$'
	if [[ "$CMD" =~ $add_re ]]; then
		[ "$PLACE" = "checkout" ] || block "run git worktree add from the user's checkout."
		target="$(abs_path "${BASH_REMATCH[1]}")"
		parent="$(real_dir "$(dirname "$target")")" || block "worktrees go under ~/claude-retro/worktrees/<name>."
		[ "$parent" = "$WORKTREES" ] && [[ "$(basename "$target")" =~ ^[A-Za-z0-9._-]+$ ]] && [[ "$(basename "$target")" != .* ]] \
			|| block "worktrees go directly under ~/claude-retro/worktrees/<name>."
		[ ! -e "$target" ] || block "$target already exists."
	elif [[ "$CMD" =~ $remove_re ]]; then
		[ "$PLACE" = "checkout" ] || block "run git worktree remove from the user's checkout."
		is_retro_worktree "${BASH_REMATCH[2]}" || block "only remove retro worktrees under ~/claude-retro/worktrees/."
	elif [[ "$CMD" != "git worktree list" && "$CMD" != "git worktree prune" ]]; then
		block "allowed: git worktree add -b|-B claude-retro/<slug> ~/claude-retro/worktrees/<name> <base>, git worktree remove [--force] <path>, list, prune."
	fi
	exit 0
fi

if [[ "$CMD" =~ ^git[[:space:]]+(checkout|add|commit|push)([[:space:]]|$) ]]; then
	[ "$PLACE" = "worktree" ] || block "git ${BASH_REMATCH[1]} only inside a retro worktree under ~/claude-retro/worktrees/<dir>. cd there first (current dir: $CWD)."
	if [[ "${BASH_REMATCH[1]}" == "commit" || "${BASH_REMATCH[1]}" == "push" ]]; then
		hooks_safe || block "this repo has git hooks (or a relative core.hooksPath) that could run files from the editable worktree. Skip the PR and put the diff in the report."
	fi
fi

if [[ "$CMD" =~ ^git[[:space:]]+checkout([[:space:]]|$) ]]; then
	[[ "$CMD" =~ ^git\ checkout\ -b\ $slug$ ]] || block "checkout must be exactly: git checkout -b claude-retro/<slug>"
	exit 0
fi

if [[ "$CMD" =~ ^git[[:space:]]+add([[:space:]]|$) ]]; then
	[[ "$CMD" =~ ^git\ add(\ --)?(\ [A-Za-z0-9._/@+-]+)+$ ]] || block "add must be: git add [--] <paths>, with plain relative paths."
	[[ "$CMD" =~ \ -[^-] || "$CMD" =~ \ --[^\ ] ]] && block "no options to git add."
	exit 0
fi

if [[ "$CMD" =~ ^git[[:space:]]+commit([[:space:]]|$) ]]; then
	[[ "$CMD" =~ ^git\ commit\ -F\ ([^ ]+)$ ]] || block "commit must be exactly: git commit -F ~/claude-retro/work/<file>"
	in_work "${BASH_REMATCH[1]}" || block "the commit message file must be under ~/claude-retro/work/."
	exit 0
fi

if [[ "$CMD" =~ ^git[[:space:]]+push([[:space:]]|$) ]]; then
	[[ "$CMD" =~ ^git\ push\ (-u\ |--set-upstream\ )?([A-Za-z0-9._-]+)\ ($slug)$ ]] \
		|| block "push must be exactly: git push -u <fork-remote> claude-retro/<branch>, run from inside the worktree."
	remote="${BASH_REMATCH[2]}"

	url="$(remote_url "$remote" --push)" || block "unknown remote '$remote'."
	[ "$(owner_of "$url")" = "$FORK_OWNER" ] || block "remote '$remote' ($url) isn't owned by $FORK_OWNER. Push only to your fork."

	name="$(basename "$(slug_of "$url")")"
	has_upstream=""
	for r in $(cd "$CWD" && git remote); do
		other="$(remote_url "$r")"
		if [ "$(basename "$(slug_of "$other")")" = "$name" ] && [ -n "$(owner_of "$other")" ] && [ "$(owner_of "$other")" != "$FORK_OWNER" ]; then
			has_upstream=1
		fi
	done
	[ -n "$has_upstream" ] || block "'$remote' isn't a fork (no upstream remote with another owner). Skip the PR and put the diff in the report."
	exit 0
fi

if [[ "$CMD" =~ ^git([[:space:]]|$) ]]; then
	block "this git command isn't allowed."
fi

if [[ "$CMD" =~ ^gh[[:space:]] ]]; then
	[[ "$CMD" =~ ^gh\ (pr\ create|pr\ list|repo\ view)(\ |$) ]] || block "only gh pr create, gh pr list and gh repo view are allowed."
	[[ "$CMD" == *"--web"* || "$CMD" =~ (^|\ )-w(\ |$) ]] && block "no --web."
fi

if [[ "$CMD" == *"gh pr create"* ]]; then
	re="^gh pr create --draft --repo ([A-Za-z0-9._-]+/[A-Za-z0-9._-]+) --base ([A-Za-z0-9._/-]+) --head $FORK_OWNER:$slug --title \"([^\"\\\\\$]*)\" --body-file ([^ ]+)$"
	[[ "$CMD" =~ $re ]] || block "gh pr create must be exactly: gh pr create --draft --repo <owner>/<repo> --base <branch> --head $FORK_OWNER:claude-retro/<slug> --title \"<title>\" --body-file ~/claude-retro/work/<file>"
	repo="${BASH_REMATCH[1]}"
	body="${BASH_REMATCH[4]}"

	is_retro_worktree "$CWD" || block "run gh pr create from inside the retro worktree."
	in_work "$body" || block "the PR body file must be under ~/claude-retro/work/."

	found=""
	for r in $(cd "$CWD" && git remote); do
		[ "$(slug_of "$(remote_url "$r")")" = "$repo" ] && [ "$(owner_of "$(remote_url "$r")")" != "$FORK_OWNER" ] && found=1
	done
	[ -n "$found" ] || block "--repo must be the worktree's upstream remote, not $repo."
	exit 0
fi

exit 0
