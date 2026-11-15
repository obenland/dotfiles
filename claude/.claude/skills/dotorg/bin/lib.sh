#!/usr/bin/env bash
# Shared helpers for the dotorg pipeline scripts. Sourced, not executed.

WPORG_REPO="${WPORG_REPO:-$HOME/Documents/code/wordpress.org}"
WPORG_GH_REPO="${WPORG_GH_REPO:-WordPress/wordpress.org}"
WPORG_EVENT_LOG="${WPORG_EVENT_LOG:-$HOME/.cache/wporg-pipeline/events.jsonl}"
# The headless sessions' model, pinned so the pipeline does not follow whatever /model last saved.
WPORG_MODEL="${WPORG_MODEL:-claude-opus-5-5}"

if [[ -t 2 ]]; then
	_BOLD=$'\e[1m'; _RED=$'\e[31m'; _GREEN=$'\e[32m'; _YELLOW=$'\e[33m'; _RESET=$'\e[0m'
else
	_BOLD=''; _RED=''; _GREEN=''; _YELLOW=''; _RESET=''
fi

log()     { echo "${_BOLD}▸${_RESET} $*" >&2; }
ok()      { echo "${_GREEN}✔${_RESET} $*" >&2; }
warn()    { echo "${_YELLOW}!${_RESET} $*" >&2; }
die()     { echo "${_RED}✖${_RESET} $*" >&2; exit 1; }

# event <step> <status> [pr] [detail] — one JSON line per pipeline step, so a stalled run can be located later.
event() {
	local step=$1 status=$2 pr=${3:-} detail=${4:-}
	mkdir -p "$(dirname "$WPORG_EVENT_LOG")"
	printf '{"ts":"%s","repo":"%s","step":"%s","status":"%s","pr":"%s","detail":"%s"}\n' \
		"$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$WPORG_REPO_NAME" "$step" "$status" "$pr" "${detail//\"/\\\"}" >> "$WPORG_EVENT_LOG"
}

# ── Repo profiles ─────────────────────────────────────────────────────────────
# Every repo the pipeline covers has a profile in the wporg-bug-pipeline repo's repos.json: default branch,
# push target, site, dependency links, and the checks CI runs. That checkout also holds the shared review
# policy and wordpress.org's suite selection. Checkouts live at $WPORG_CODE_DIR/<repo>.
WPORG_CODE_DIR="${WPORG_CODE_DIR:-$HOME/Documents/code}"
WPORG_PIPELINE_DIR="${WPORG_PIPELINE_DIR:-$WPORG_CODE_DIR/wporg-bug-pipeline}"
WPORG_REPO_NAME="wordpress.org"

# profile_get <repo> <jq path> — one field of a repo's profile; empty when unset.
profile_get() { jq -r --arg r "$1" ".repos[\$r]$2 // empty" "$WPORG_PIPELINE_DIR/repos.json" 2>/dev/null || true; }

# repo_of_dir <dir> — the name of the WordPress/* repo a checkout's origin points at, or nothing.
repo_of_dir() {
	local url
	url=$(git -C "$1" remote get-url origin 2>/dev/null) || return 0
	if [[ $url =~ github\.com[:/]WordPress/([A-Za-z0-9._-]+)$ ]]; then echo "${BASH_REMATCH[1]%.git}"; fi
}

# use_repo [<name>] — work on <name>, else the current directory's repo, else wordpress.org. Sets WPORG_REPO_NAME,
# WPORG_REPO (its main checkout), WPORG_GH_REPO, WPORG_BRANCH, WPORG_PUSH_REMOTE, WPORG_LINKS and WPORG_SITE.
use_repo() {
	local name=${1:-}
	[[ -n $name ]] || name=$(repo_of_dir "$PWD")
	[[ -n $name ]] || name=wordpress.org
	[[ $name =~ ^[A-Za-z0-9._-]+$ ]] || die "Bad repo name: $name"
	WPORG_REPO_NAME=$name
	WPORG_REPO="$WPORG_CODE_DIR/$name"
	WPORG_GH_REPO="WordPress/$name"
	WPORG_LINKS=()
	if jq -e --arg r "$name" '.repos[$r]' "$WPORG_PIPELINE_DIR/repos.json" >/dev/null 2>&1; then
		WPORG_BRANCH=$(profile_get "$name" .branch)
		WPORG_PUSH_REMOTE=$([[ $(profile_get "$name" .push) == fork ]] && echo fork || echo origin)
		read -ra WPORG_LINKS <<< "$(profile_get "$name" '.links // [] | join(" ")')"
		WPORG_SITE=$(profile_get "$name" .site)
		# A repos.json from before the profiles has the branch and push target only.
		if [[ $name == wordpress.org ]] && ! jq -e '.repos["wordpress.org"] | has("links")' "$WPORG_PIPELINE_DIR/repos.json" >/dev/null; then
			WPORG_LINKS=(vendor environments/node_modules); WPORG_SITE=https://wordpress.org
		fi
	elif [[ $name == wordpress.org ]]; then
		warn "No wordpress.org profile in $WPORG_PIPELINE_DIR/repos.json; using built-in defaults. Set WPORG_PIPELINE_DIR to your wporg-bug-pipeline checkout."
		WPORG_BRANCH=trunk; WPORG_PUSH_REMOTE=fork; WPORG_LINKS=(vendor environments/node_modules); WPORG_SITE=https://wordpress.org
	else
		die "No profile for $name in $WPORG_PIPELINE_DIR/repos.json. Add one there, or set WPORG_PIPELINE_DIR to a wporg-bug-pipeline checkout that has it."
	fi
	[[ -n $WPORG_BRANCH ]] || die "The $name profile has no default branch."
	[[ -d $WPORG_REPO/.git ]] || die "No checkout of $name at $WPORG_REPO."
}

# profiled_repos — every profiled repo that has a checkout under $WPORG_CODE_DIR.
profiled_repos() {
	local r
	while read -r r; do
		[[ $r =~ ^[A-Za-z0-9._-]+$ && -d $WPORG_CODE_DIR/$r/.git ]] && echo "$r"
	done < <(jq -r '.repos | keys[]' "$WPORG_PIPELINE_DIR/repos.json" 2>/dev/null)
	return 0
}

# pipeline_file <path> — a file of the wporg-bug-pipeline checkout. Assign it (f=$(pipeline_file …)) so a missing file stops the script.
pipeline_file() {
	local f="$WPORG_PIPELINE_DIR/$1"
	[[ -f $f ]] || die "$f not found: that checkout predates the repo profiles. Pull it once they have merged, or set WPORG_PIPELINE_DIR to a checkout that has them."
	echo "$f"
}

# repo_arg <args...> — split --repo <name> off a command line: prints the name (maybe empty), then each other arg, one per line.
# Use: { read -r repo; mapfile -t args; } < <(repo_arg "$@")
repo_arg() {
	local repo="" rest=()
	while [[ $# -gt 0 ]]; do
		case $1 in
			--repo)   repo=${2:-}; shift; [[ $# -gt 0 ]] && shift ;;
			--repo=*) repo=${1#--repo=}; shift ;;
			*)        rest+=("$1"); shift ;;
		esac
	done
	printf '%s\n' "$repo" "${rest[@]+"${rest[@]}"}"
}

# pr_key <pr> — the name of a PR's state, review, and lock files: <repo>-<PR>.
pr_key() { echo "$WPORG_REPO_NAME-$1"; }

# migrate_legacy <dir> <suffix>... — wordpress.org files from before repo profiles were named <PR><suffix>; rename them to wordpress.org-<PR><suffix>.
migrate_legacy() {
	local dir=$1 f n sfx
	shift
	[[ -d $dir ]] || return 0
	for sfx in "$@"; do
		for f in "$dir"/[0-9]*"$sfx"; do
			[[ -f $f ]] || continue
			n=$(basename "$f" "$sfx")
			if [[ $n =~ ^[0-9]+$ && ! -e $dir/wordpress.org-$n$sfx ]]; then mv -n "$f" "$dir/wordpress.org-$n$sfx" 2>/dev/null || true; fi
		done
	done
}

# links_re — an extended regex matching exactly the profile's dependency links; it matches nothing when there are none.
links_re() {
	local alt
	alt=$(IFS='|'; echo "${WPORG_LINKS[*]+"${WPORG_LINKS[*]}"}")
	if [[ -n $alt ]]; then echo "^(${alt//./\\.})/?\$"; else echo '^$.'; fi
}

# untracked_links — drop the dependency links from `git status --porcelain` output; they are ours, not the branch's.
untracked_links() { local re; re=$(links_re); grep -vE "^\?\? ${re#^}" || true; }

# trust_of <path> — "own" or "other", as `wporg-worktree pr` recorded it for the worktree's branch. Branches from
# `wporg-worktree new` are yours; a pr-<N> branch from before the record is someone else's.
trust_of() {
	local b t
	b=$(git -C "$1" branch --show-current 2>/dev/null)
	t=$(git -C "$1" config "branch.$b.wporgTrust" 2>/dev/null || true)
	[[ -n $t ]] || t=$([[ $b == pr-* ]] && echo other || echo own)
	echo "$t"
}

# Files that drive the tools rather than the site: CI and test config, dependency manifests, agent and editor
# instructions. Someone else's PR that changes one runs only with --trust-config, since installs and checks run them.
TOOLING_RE='^\.github/|(^|/)(composer\.(json|lock)|package(-lock)?\.json|npm-shrinkwrap\.json|yarn\.lock|pnpm-lock\.yaml|\.npmrc|\.yarnrc(\.yml)?|\.wp-env(\.[a-z]+)?\.json|phpcs\.xml(\.dist)?|\.phpcs\.xml(\.dist)?|phpunit\.xml(\.dist)?|AGENTS\.md|CLAUDE\.md|\.mcp\.json|Makefile)$|(^|/)\.(claude|husky)/|^environments/'

# tooling_changes <path> — the files the worktree's branch changes since the default branch that match TOOLING_RE.
tooling_changes() { git -C "$1" diff --name-only "origin/$WPORG_BRANCH...HEAD" | grep -E "$TOOLING_RE" || true; }

# Headless sessions load the user's settings only, and no MCP servers: a checkout's .claude/, CLAUDE.md, hooks and
# .mcp.json are the PR's to change. repo_context puts the repo's own instructions back, from the default branch.
WPORG_ISOLATION=(--setting-sources user --strict-mcp-config)

# repo_context [<changed file>...] — AGENTS.md at the root and in every directory above a changed file, as the
# default branch has them, never the PR's copies.
repo_context() {
	local f d dirs="" out=""
	for f in "$@"; do
		d=$(dirname "$f")
		while [[ $d != . && $d != / ]]; do dirs+="$d"$'\n'; d=$(dirname "$d"); done
	done
	while IFS= read -r d; do
		local p=${d:+$d/}AGENTS.md text
		text=$(git -C "$WPORG_REPO" show "origin/$WPORG_BRANCH:$p" 2>/dev/null) || continue
		out+=$'\n\n'"# $WPORG_GH_REPO: $p (from origin/$WPORG_BRANCH)"$'\n\n'"$text"
	done < <({ echo ""; sort -u <<< "$dirs" | grep -v '^$'; } || true)
	printf '%s' "$out"
}

# review_policy — the shared review policy, with the repo's site in its realism test.
review_policy() {
	local f
	f=$(pipeline_file review-policy.md)
	if [[ -n ${WPORG_SITE:-} ]]; then sed "s|the site this repo serves|the site this repo serves ($WPORG_SITE)|g" "$f"; else cat "$f"; fi
}

require() {
	local c
	for c in "$@"; do
		command -v "$c" >/dev/null 2>&1 || die "Required command not found: $c"
	done
}

# git_retry <args...> — a git command that may contend for a shared ref lock when runs overlap; retried briefly.
git_retry() {
	local n
	for n in 1 2 3 4 5; do
		git "$@" 2>/dev/null && return 0
		sleep $(( n * 2 ))
	done
	git "$@"
}
