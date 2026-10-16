#!/usr/bin/env bash
# Shared helpers for the dotorg pipeline scripts. Sourced, not executed.

WPORG_REPO="${WPORG_REPO:-$HOME/Documents/code/wordpress.org}"
WPORG_GH_REPO="${WPORG_GH_REPO:-WordPress/wordpress.org}"
WPORG_EVENT_LOG="${WPORG_EVENT_LOG:-$HOME/.cache/wporg-pipeline/events.jsonl}"

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
	printf '{"ts":"%s","step":"%s","status":"%s","pr":"%s","detail":"%s"}\n' \
		"$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$step" "$status" "$pr" "${detail//\"/\\\"}" >> "$WPORG_EVENT_LOG"
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
