#!/bin/bash
#
# Nightly Claude Code retro: review the session transcripts since the last successful run and
# write a report to ~/claude-retro/reports/YYYY-MM-DD.md.
#
# Launched by launchd (macOS) or cron (Linux), neither of which loads the
# shell profile, so PATH and the claude binary are set explicitly.

set -euo pipefail

# MacGPG2 provides the gpg2 that commit signing calls.
export PATH="$HOME/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/local/MacGPG2/bin:/usr/bin:/bin:/usr/sbin:/sbin"

# claude needs USER to find its Keychain sign-in; launchd and env -i may not set it.
export USER="${USER:-$(id -un)}"

CLAUDE_BIN="$HOME/.local/bin/claude"
RETRO_DIR="$HOME/claude-retro"
PROJECTS_DIR="$HOME/.claude/projects"
RUN_STARTED="$(date -u '+%Y-%m-%dT%H:%M:%S')"

# shellcheck source=config/schedule
. "$RETRO_DIR/config/schedule"

# The nightly window starts at the last successful run, so a missed or failed night isn't lost; it's
# recorded only after a clean run, where the headless agent can't write. A first run, or a gap longer
# than MAX_DAYS, falls back to 24 hours or MAX_DAYS.
if [ "$(uname -s)" = "Darwin" ]; then
	STATE_DIR="$HOME/Library/Application Support/claude-retro"
else
	STATE_DIR="${XDG_STATE_HOME:-$HOME/.local/state}/claude-retro"
fi
LAST_RUN_FILE="$STATE_DIR/last-run"
MAX_DAYS=14

# For a one-off longer review, `RETRO_DAYS=7 ~/claude-retro/run.sh` looks back that many days, writes its
# own -7d files instead of tonight's, and leaves the nightly window alone. The launcher passes no environment.
MANUAL_DAYS="${RETRO_DAYS:-}"
[ -z "$MANUAL_DAYS" ] || [[ "$MANUAL_DAYS" =~ ^[1-9][0-9]?$ ]] || { echo "RETRO_DAYS must be a whole number from 1 to 99" >&2; exit 1; }
SUFFIX="${MANUAL_DAYS:+-${MANUAL_DAYS}d}"

# The nightly slot is the night of the latest RETRO_HOUR:RETRO_MINUTE that has passed, named by its date.
# A run starts only while that slot is unfinished; a late one (the Mac slept through) keeps the slot's date.
# Exit codes drive the retries: launchd starts the job again 30 minutes after a non-zero exit (a failure,
# or EX_TEMPFAIL while waiting), and not after 0. cron fires every 30 minutes and relies on the slot check.
EX_TEMPFAIL=75
if [ "$(date +%H%M)" \< "$(printf '%02d%02d' "$RETRO_HOUR" "$RETRO_MINUTE")" ]; then
	SLOT="$(date -v-1d +%F 2>/dev/null || date -d yesterday +%F)"
else
	SLOT="$(date +%F)"
fi
SLOT_FILE="$STATE_DIR/last-slot"
ATTEMPTS_FILE="$STATE_DIR/attempts"
MAX_ATTEMPTS=3
ATTEMPT=""

if [ -n "$MANUAL_DAYS" ]; then
	TODAY="$(date +%F)"
else
	TODAY="$SLOT"
	# `RETRO_FORCE=1 ~/claude-retro/run.sh` runs now, whatever the slot and power state.
	if [ -z "${RETRO_FORCE:-}" ]; then
		[[ "$(cat "$SLOT_FILE" 2>/dev/null || true)" < "$SLOT" ]] || exit 0
		read -r tried_slot tried 2>/dev/null < "$ATTEMPTS_FILE" || true
		[ "${tried_slot:-}" = "$SLOT" ] || tried=0
		[ "${tried:-0}" -lt "$MAX_ATTEMPTS" ] || exit 0
		# launchd also fires during a dark wake, the few seconds a sleeping Mac wakes for upkeep, where the
		# network is usually down and the run is frozen again mid-call. Wait for a full wake (one with graphics).
		if command -v pmset >/dev/null 2>&1 && ! pmset -g systemstate 2>/dev/null | grep -q 'Capabilities are:.*Graphics'; then
			echo "[$(date '+%F %T')] slot $SLOT due; waiting for a full wake"
			exit "$EX_TEMPFAIL"
		fi
		# Any HTTP response will do; only a failure to connect means no network.
		if ! curl -sS -o /dev/null --max-time 15 https://api.anthropic.com/ 2>/dev/null; then
			echo "[$(date '+%F %T')] slot $SLOT due; waiting for the network"
			exit "$EX_TEMPFAIL"
		fi
		ATTEMPT=$(( ${tried:-0} + 1 ))
		mkdir -p "$STATE_DIR"
		printf '%s %s\n' "$SLOT" "$ATTEMPT" > "$ATTEMPTS_FILE"
	fi
fi

# Keep the Mac from idling to sleep mid-run; closing the lid on battery still sleeps it.
if command -v caffeinate >/dev/null 2>&1; then
	caffeinate -i -w $$ >/dev/null 2>&1 &
fi
SESSIONS_FILE="$RETRO_DIR/work/sessions-$TODAY$SUFFIX.txt"
DIGEST_FILE="$RETRO_DIR/work/digest-$TODAY$SUFFIX.jsonl"
HISTORY_FILE="$RETRO_DIR/work/history-$TODAY$SUFFIX.txt"
REPOS_FILE="$RETRO_DIR/work/repos-$TODAY$SUFFIX.txt"
RETRO_PRS_FILE="$RETRO_DIR/work/retro-prs-$TODAY$SUFFIX.txt"
PROPOSALS_DIR="$RETRO_DIR/work/proposals-$TODAY$SUFFIX"
PRS_FILE="$RETRO_DIR/work/prs-$TODAY$SUFFIX.txt"
REPORT="$RETRO_DIR/reports/$TODAY$SUFFIX.md"
BIN="$RETRO_DIR/bin"

# Posts a desktop notification (macOS, or notify-send on Linux).
notify() {
	local msg="$1"
	if command -v osascript >/dev/null 2>&1; then
		# The text goes in as an argument, never into the script itself.
		osascript -e 'on run argv' -e 'display notification (item 1 of argv) with title "Claude retro"' -e 'end run' "$msg" >/dev/null || true
	elif command -v notify-send >/dev/null 2>&1; then
		notify-send "Claude retro" "$msg" || true
	fi
}

# Says whether a failed scheduled run gets another try.
failed() {
	if [ -z "$ATTEMPT" ]; then
		notify "Retro run failed. See ~/claude-retro/logs/."
	elif [ "$ATTEMPT" -lt "$MAX_ATTEMPTS" ]; then
		notify "Retro run failed (try $ATTEMPT of $MAX_ATTEMPTS); retrying in 30 minutes. See ~/claude-retro/logs/."
	else
		notify "Retro run failed $MAX_ATTEMPTS times; next try tomorrow night. See ~/claude-retro/logs/."
	fi
}

trap failed ERR

mkdir -p "$RETRO_DIR/work" "$RETRO_DIR/reports" "$RETRO_DIR/worktrees" "$RETRO_DIR/logs"

for script in apply-proposals.sh repo-remotes.sh; do
	[ -x "$BIN/$script" ] || { echo "Missing or non-executable $BIN/$script" >&2; exit 1; }
done

echo "[$(date '+%F %T')] retro start"

# On macOS, repos in ~/Documents are readable only if the launcher has Full Disk Access. Without it the
# review silently loses repo context, so say so loudly and keep going.
if [ "$(uname -s)" = "Darwin" ] && [ -d "$HOME/Documents" ]; then
	if ls "$HOME/Documents" >/dev/null 2>&1; then
		echo "~/Documents access: ok"
	else
		echo "~/Documents access: denied. Grant Full Disk Access to ~/Library/Application Support/claude-retro/claude-retro-launcher." >&2
		notify "No access to ~/Documents. Grant the retro launcher Full Disk Access."
	fi
fi

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

# Strip credentials, including the token above, from any subprocess Claude starts.
export CLAUDE_CODE_SUBPROCESS_ENV_SCRUB=1

# Claude Code names a project folder after its path, with every non-alphanumeric character turned into '-'.
SELF_PROJECT="$PROJECTS_DIR/$(printf '%s' "$RETRO_DIR" | sed 's/[^A-Za-z0-9]/-/g')"

# Prints the UTC time some hours ago, as YYYY-MM-DDTHH:MM:SS.
hours_ago() {
	date -u -v-"$1"H '+%Y-%m-%dT%H:%M:%S' 2>/dev/null || date -u -d "$1 hours ago" '+%Y-%m-%dT%H:%M:%S'
}

# Prints the epoch seconds of a YYYY-MM-DDTHH:MM:SS UTC time.
epoch() {
	date -j -u -f '%Y-%m-%dT%H:%M:%S' "$1" +%s 2>/dev/null || date -u -d "$1" +%s
}

if [ -n "$MANUAL_DAYS" ]; then
	CUTOFF="$(hours_ago $((MANUAL_DAYS * 24)))"
	WINDOW="the last $MANUAL_DAYS day(s), a one-off review"
else
	LAST_RUN="$(cat "$LAST_RUN_FILE" 2>/dev/null || true)"
	FLOOR="$(hours_ago $((MAX_DAYS * 24)))"
	if [[ "$LAST_RUN" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}$ ]]; then
		if [[ "$LAST_RUN" < "$FLOOR" ]]; then
			CUTOFF="$FLOOR"
			WINDOW="the last $MAX_DAYS days (the last successful run, $LAST_RUN UTC, is older)"
		else
			CUTOFF="$LAST_RUN"
			WINDOW="since the last successful nightly run"
		fi
	else
		CUTOFF="$(hours_ago 24)"
		WINDOW="the last 24 hours (no earlier successful run recorded)"
	fi
fi
echo "Window: $WINDOW, from $CUTOFF UTC"

# Records this run as the start of the next nightly window. Only for a clean nightly run.
record_run() {
	[ -z "$MANUAL_DAYS" ] || return 0
	mkdir -p "$STATE_DIR"
	printf '%s\n' "$RUN_STARTED" > "$LAST_RUN_FILE"
}

# Marks tonight's slot done, so the half-hourly schedule stops starting runs until the next one. Also
# after a run with unreadable transcripts: its window doesn't advance, and the next night looks again.
finish_slot() {
	[ -z "$MANUAL_DAYS" ] || return 0
	mkdir -p "$STATE_DIR"
	printf '%s\n' "$SLOT" > "$SLOT_FILE"
}

# Claude Code rewrites bookkeeping lines into old transcripts, so mtime alone lists stale sessions.
# mtime only narrows the search; keep transcripts whose newest event timestamp (ISO 8601 UTC) is in the window.
MTIME_DAYS=$(( ($(date -u +%s) - $(epoch "$CUTOFF")) / 86400 + 2 ))

find "$PROJECTS_DIR" -path "$SELF_PROJECT" -prune -o -name '*.jsonl' -mtime -"$MTIME_DAYS" -print | sort |
	while IFS= read -r f; do
		newest="$(grep -o '"timestamp":"[^"]*"' "$f" | cut -d'"' -f4 | sort | tail -1)"
		[[ "$newest" > "$CUTOFF" ]] && printf '%s\n' "$f"
	done > "$SESSIONS_FILE" || true

if [ ! -s "$SESSIONS_FILE" ]; then
	echo "No sessions in the window."
	record_run
	finish_slot
	exit 0
fi

echo "$(wc -l < "$SESSIONS_FILE" | tr -d ' ') transcripts listed in $SESSIONS_FILE"

# Model-free intake: typed messages and friction counts per session, without tool output.
# A transcript it can't read keeps the window from advancing, so the next run looks again.
UNREADABLE=""
python3 -I "$RETRO_DIR/bin/retro-digest.py" --since "$CUTOFF" < "$SESSIONS_FILE" > "$DIGEST_FILE" || {
	status=$?
	[ "$status" = 3 ] || exit "$status"
	UNREADABLE="yes"
	echo "Some transcripts could not be read; see the log. The window won't advance." >&2
	notify "Some transcripts could not be read. See ~/claude-retro/logs/."
}

# What changed in the personal setup lately, so the run can tell applied, reverted and still-open
# suggestions apart instead of proposing the same thing every night.
{
	echo "# ~/dotfiles changes to Claude config in the last $MAX_DAYS days (git log -p)"
	git -C "$HOME/dotfiles" log --since="$MAX_DAYS days ago" -p --no-color --no-ext-diff \
		-- claude/.claude/CLAUDE.md claude/.claude/settings.json 2>/dev/null || echo "(no ~/dotfiles history available)"
} > "$HISTORY_FILE"

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

# Repos the day's sessions ran in, added as working directories so Claude can read their AGENTS.md,
# CLAUDE.md and .git/config. Edit rules cover only ~/claude-retro, so they stay read-only. They are also
# the only repos apply-proposals.sh opens PRs for.
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
printf '%s\n' "${ADD_DIRS[@]-}" > "$REPOS_FILE"

# limit <seconds> <command...>: a time limit for network calls.
limit() {
	local secs="$1"
	shift
	perl -e 'alarm shift @ARGV; exec @ARGV or die "exec failed: $!\n"' "$secs" "$@"
}

# Past retro PRs in those repos, so the run can tell open, accepted and declined suggestions apart.
{
	echo "# Retro PRs in tonight's repos (open ones get new changes added; closed unmerged ones were declined)"
	for root in "${ADD_DIRS[@]-}"; do
		[ -n "$root" ] || continue
		IFS=$'\t' read -r _ base_slug _ _ < <("$BIN/repo-remotes.sh" "$root" 2>/dev/null) || continue
		echo
		echo "## $root ($base_slug)"
		limit 60 gh pr list --repo "$base_slug" --author "@me" --state all --limit 30 --search 'in:title "Claude retro:"' \
			--json number,state,isDraft,title,url,createdAt,closedAt \
			--jq '.[] | "#\(.number) \(.state)\(if .isDraft then " draft" else "" end) \(.createdAt[:10]) \(.title) \(.url)"' \
			2>&1 || echo "(couldn't list PRs)"
	done
} > "$RETRO_PRS_FILE"

mkdir -p "$PROPOSALS_DIR"
find "$PROPOSALS_DIR" -mindepth 1 -delete

# With CLAUDE_CODE_SUBPROCESS_ENV_SCRUB set, allow rules must be passed on the command line;
# the copy in config/settings.json alone is not applied.
ALLOWED_TOOLS=()
while IFS= read -r rule; do
	ALLOWED_TOOLS+=("$rule")
done < <(jq -r '.permissions.allow[]' "$RETRO_DIR/config/settings.json")

# A re-run on the same day keeps the earlier report next to the new one, so the model never has to
# move or back up files itself.
if [ -e "$REPORT" ]; then
	mv "$REPORT" "${REPORT%.md}-replaced-$(date +%H%M%S).md"
fi

PROMPT="$(cat "$RETRO_DIR/prompt.md")

---
Run details (added by run.sh):
- Date: $TODAY
- Window: $WINDOW, events since $CUTOFF UTC
- Digest (start here): $DIGEST_FILE
- Transcript list: $SESSIONS_FILE
- Setup history: $HISTORY_FILE
- Past retro PRs: $RETRO_PRS_FILE
- Repos you may propose PRs for: $REPOS_FILE
- PR proposals go in: $PROPOSALS_DIR/
- Earlier reports: $RETRO_DIR/reports/
- Unreadable transcripts: ${UNREADABLE:-none}
- Write the report to: $REPORT"

cd "$RETRO_DIR"

# --allowedTools: the allow rules from config/settings.json (see above).
# --add-dir: the day's repos, read-only (see above).
# --settings: the retro's own allow/deny rules. Passed explicitly
#   because a project .claude/settings.json is ignored until the folder is trusted.
# --setting-sources project: skip ~/.claude/settings.json, so its broad allow
#   rules, MCP servers and plugins don't apply.
# --strict-mcp-config with no --mcp-config: no MCP servers at all.
# --tools: only these built-in tools exist in the session: no Bash, no web, no subagents.
#   apply-proposals.sh does the git and GitHub steps afterwards.
# --permission-mode dontAsk: anything not in the allow list is denied, never prompted.
"$CLAUDE_BIN" -p "$PROMPT" \
	--settings "$RETRO_DIR/config/settings.json" \
	--setting-sources project \
	--strict-mcp-config \
	--tools "Read,Grep,Glob,Edit,Write" \
	--disallowedTools "Bash" "WebFetch" "WebSearch" "mcp__*" \
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

# The prompt asks for a first line like: <!-- retro: findings=3 -->
FINDINGS="$(sed -n '1s/.*findings=\([0-9]*\).*/\1/p' "$REPORT")"

# Proposals become draft PRs here, without the Claude token in the environment of git hooks.
# The run can write anywhere in work/, so the repo list and PR list are rewritten from this script's own state.
unset CLAUDE_CODE_OAUTH_TOKEN
printf '%s\n' "${ADD_DIRS[@]-}" > "$REPOS_FILE"
rm -f "$PRS_FILE"
"$BIN/apply-proposals.sh" "$PROPOSALS_DIR" "$REPOS_FILE" "$REPORT" "$PRS_FILE" ||
	echo "apply-proposals.sh failed; see above." >&2
PRS=0
[ ! -s "$PRS_FILE" ] || PRS="$(wc -l < "$PRS_FILE" | tr -d ' ')"

# Record the PR count in the marker line too, so the report alone says what happened.
if [ -n "$FINDINGS" ]; then
	sed -i.bak "1s/.*/<!-- retro: findings=$FINDINGS prs=$PRS -->/" "$REPORT" && rm -f "$REPORT.bak"
fi

if [ "${FINDINGS:-0}" -gt 0 ]; then
	notify "${FINDINGS} finding(s), ${PRS:-0} draft PR(s). Report: reports/$(basename "$REPORT")"
else
	notify "Quiet day, no findings. Report: reports/$(basename "$REPORT")"
fi

[ -n "$UNREADABLE" ] || record_run
finish_slot

echo "[$(date '+%F %T')] retro done: findings=${FINDINGS:-?} prs=${PRS:-?}"
