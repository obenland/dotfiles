#!/bin/bash
#
# Nightly Claude Code retro: review the past day's session transcripts and
# write a report to ~/claude-retro/reports/YYYY-MM-DD.md.
#
# Launched by launchd (macOS) or cron (Linux), neither of which loads the
# shell profile, so PATH and the claude binary are set explicitly.

set -euo pipefail

export PATH="$HOME/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin"

# claude needs USER to find its Keychain sign-in; launchd and env -i may not set it.
export USER="${USER:-$(id -un)}"

CLAUDE_BIN="$HOME/.local/bin/claude"
RETRO_DIR="$HOME/claude-retro"
PROJECTS_DIR="$HOME/.claude/projects"
TODAY="$(date +%F)"
SESSIONS_FILE="$RETRO_DIR/work/sessions-$TODAY.txt"
REPORT="$RETRO_DIR/reports/$TODAY.md"
GUARD="$RETRO_DIR/bin/guard-bash.sh"

# Posts a desktop notification (macOS, or notify-send on Linux).
notify() {
	local msg="$1"
	if command -v osascript >/dev/null 2>&1; then
		osascript -e "display notification \"${msg//\"/\\\"}\" with title \"Claude retro\"" || true
	elif command -v notify-send >/dev/null 2>&1; then
		notify-send "Claude retro" "$msg" || true
	fi
}

trap 'notify "Retro run failed. See ~/claude-retro/logs/."' ERR

mkdir -p "$RETRO_DIR/work" "$RETRO_DIR/reports" "$RETRO_DIR/worktrees" "$RETRO_DIR/logs"

# Fail closed: never start the headless run without the push/PR guard hook.
[ -x "$GUARD" ] || { echo "Missing or non-executable $GUARD" >&2; exit 1; }

echo "[$(date '+%F %T')] retro start"

# Use the long-lived token from `claude setup-token`, stored in the login Keychain,
# so the run doesn't depend on the interactive sign-in. Without it, fall back to that sign-in.
if command -v security >/dev/null 2>&1 \
	&& TOKEN="$(security find-generic-password -s claude-retro-oauth-token -a "$(id -un)" -w 2>/dev/null)" \
	&& [ -n "$TOKEN" ]; then
	export CLAUDE_CODE_OAUTH_TOKEN="$TOKEN"
else
	echo "No Keychain token (service claude-retro-oauth-token); using the regular sign-in." >&2
fi
unset TOKEN

# Strip credentials, including the token above, from Bash and hook subprocesses.
export CLAUDE_CODE_SUBPROCESS_ENV_SCRUB=1

# Claude Code names a project folder after its path, with every non-alphanumeric character turned into '-'.
SELF_PROJECT="$PROJECTS_DIR/$(printf '%s' "$RETRO_DIR" | sed 's/[^A-Za-z0-9]/-/g')"

# Claude Code rewrites bookkeeping lines into old transcripts, so mtime alone lists stale sessions.
# Keep only transcripts whose newest event timestamp (ISO 8601 UTC) falls in the last 24 hours.
CUTOFF="$(date -u -v-24H '+%Y-%m-%dT%H:%M:%S' 2>/dev/null || date -u -d '24 hours ago' '+%Y-%m-%dT%H:%M:%S')"

find "$PROJECTS_DIR" -path "$SELF_PROJECT" -prune -o -name '*.jsonl' -mtime -1 -print | sort |
	while IFS= read -r f; do
		newest="$(grep -o '"timestamp":"[^"]*"' "$f" | cut -d'"' -f4 | sort | tail -1)"
		[[ "$newest" > "$CUTOFF" ]] && printf '%s\n' "$f"
	done > "$SESSIONS_FILE" || true

if [ ! -s "$SESSIONS_FILE" ]; then
	echo "No sessions in the last 24 hours."
	exit 0
fi

echo "$(wc -l < "$SESSIONS_FILE" | tr -d ' ') transcripts listed in $SESSIONS_FILE"

# Prints the main checkout of the git repo containing a directory, without running git there.
repo_root() {
	local d="$1" gitdir
	while [ -n "$d" ] && [ "$d" != "/" ]; do
		if [ -d "$d/.git" ]; then
			printf '%s\n' "$d"
			return
		fi
		if [ -f "$d/.git" ]; then
			gitdir="$(sed -n 's/^gitdir: //p' "$d/.git")"
			# A linked worktree points at <main checkout>/.git/worktrees/<name>.
			case "$gitdir" in
				/*/.git/worktrees/*) printf '%s\n' "${gitdir%/.git/worktrees/*}" ;;
				*) printf '%s\n' "$d" ;;
			esac
			return
		fi
		d="$(dirname "$d")"
	done
}

# Repos the day's sessions ran in, added as working directories so Claude can cd into
# them for git worktree/fetch. Edit rules still cover only ~/claude-retro, so they stay read-only.
ADD_DIRS=()
while IFS= read -r dir; do
	[ -d "$dir" ] || continue
	root="$(repo_root "$dir")"
	[ -n "$root" ] && [ -d "$root" ] || continue
	case "$root/" in "$RETRO_DIR"/*) continue ;; esac
	case " ${ADD_DIRS[*]-} " in *" $root "*) continue ;; esac
	ADD_DIRS+=("$root")
done < <(tr '\n' '\0' < "$SESSIONS_FILE" | xargs -0 grep -ho '"cwd":"[^"]*"' 2>/dev/null | sed 's/^"cwd":"//; s/"$//' | sort -u)

echo "Repos added as working directories: ${ADD_DIRS[*]-none}"

# The guard hook trusts only these checkouts for git commands. The agent can't change the hook's environment.
RETRO_TRUSTED_REPOS="$(printf '%s\n' "${ADD_DIRS[@]-}")"
export RETRO_TRUSTED_REPOS

# With CLAUDE_CODE_SUBPROCESS_ENV_SCRUB set, allow rules must be passed on the command line;
# the copy in config/settings.json alone is not applied.
ALLOWED_TOOLS=()
while IFS= read -r rule; do
	ALLOWED_TOOLS+=("$rule")
done < <(jq -r '.permissions.allow[]' "$RETRO_DIR/config/settings.json")

PROMPT="$(cat "$RETRO_DIR/prompt.md")

---
Run details (added by run.sh):
- Date: $TODAY
- Transcript list: $SESSIONS_FILE
- Write the report to: $REPORT"

cd "$RETRO_DIR"

# --allowedTools: the allow rules from config/settings.json (see above).
# --add-dir: the day's repos, read-only (see above).
# --settings: the retro's own allow/deny rules and guard hook. Passed explicitly
#   because a project .claude/settings.json is ignored until the folder is trusted.
# --setting-sources project: skip ~/.claude/settings.json, so its broad allow
#   rules, MCP servers and plugins don't apply.
# --strict-mcp-config with no --mcp-config: no MCP servers at all.
# --tools: only these built-in tools exist in the session (no WebFetch/WebSearch).
# --permission-mode dontAsk: anything not in the allow list is denied, never prompted.
"$CLAUDE_BIN" -p "$PROMPT" \
	--settings "$RETRO_DIR/config/settings.json" \
	--setting-sources project \
	--strict-mcp-config \
	--tools "Read,Grep,Glob,Edit,Write,Bash" \
	--disallowedTools "WebFetch" "WebSearch" "mcp__*" \
	--allowedTools "${ALLOWED_TOOLS[@]}" \
	${ADD_DIRS[@]+--add-dir "${ADD_DIRS[@]}"} \
	--permission-mode dontAsk \
	--max-turns 200 \
	--output-format text \
	< /dev/null

if [ ! -f "$REPORT" ]; then
	notify "Retro finished but wrote no report. See ~/claude-retro/logs/."
	exit 1
fi

# The prompt asks for a first line like: <!-- retro: findings=3 prs=1 -->
FINDINGS="$(sed -n '1s/.*findings=\([0-9]*\).*/\1/p' "$REPORT")"
PRS="$(sed -n '1s/.*prs=\([0-9]*\).*/\1/p' "$REPORT")"

if [ "${FINDINGS:-0}" -gt 0 ]; then
	notify "${FINDINGS} finding(s), ${PRS:-0} draft PR(s). Report: reports/$TODAY.md"
else
	notify "Quiet day, no findings. Report: reports/$TODAY.md"
fi

echo "[$(date '+%F %T')] retro done: findings=${FINDINGS:-?} prs=${PRS:-?}"
