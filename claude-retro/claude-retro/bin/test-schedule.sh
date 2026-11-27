#!/bin/bash
#
# Tests for run.sh's schedule gate: when a half-hourly firing starts a run, waits, or does nothing.
# Runs run.sh against a fake home with stubbed pmset, curl, security, osascript and claude, and no
# sessions, so a run that starts ends at "No sessions in the window" without calling the API.
#
# Usage: bash ~/claude-retro/bin/test-schedule.sh

set -uo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
FAKE="$(mktemp -d)"
trap 'rm -rf "$FAKE"' EXIT

mkdir -p "$FAKE/claude-retro/bin" "$FAKE/.local/bin" "$FAKE/.claude/projects"
cp "$SRC/run.sh" "$FAKE/claude-retro/"
cp -R "$SRC/config" "$FAKE/claude-retro/"
for script in apply-proposals.sh repo-remotes.sh; do
	printf '#!/bin/bash\nexit 0\n' > "$FAKE/claude-retro/bin/$script"
done

# Stubs: pmset reports a dark wake when FAKE_DARK is set, curl fails when FAKE_OFFLINE is set.
cat > "$FAKE/.local/bin/pmset" <<'EOF'
#!/bin/bash
if [ -n "${FAKE_DARK:-}" ]; then echo "Current System Capabilities are: CPU Network"; else echo "Current System Capabilities are: CPU Graphics Audio Network"; fi
EOF
cat > "$FAKE/.local/bin/curl" <<'EOF'
#!/bin/bash
[ -z "${FAKE_OFFLINE:-}" ]
EOF
printf '#!/bin/bash\nexit 1\n' > "$FAKE/.local/bin/security"
printf '#!/bin/bash\nexit 0\n' > "$FAKE/.local/bin/osascript"
printf '#!/bin/bash\necho "claude must not run" >&2\nexit 1\n' > "$FAKE/.local/bin/claude"
chmod +x "$FAKE"/.local/bin/* "$FAKE"/claude-retro/bin/* "$FAKE/claude-retro/run.sh"

STATE="$FAKE/Library/Application Support/claude-retro"
[ "$(uname -s)" = "Darwin" ] || STATE="$FAKE/.local/state/claude-retro"

# The slot run.sh should compute: today once the scheduled time has passed, else yesterday.
. "$SRC/config/schedule"
if [ "$(date +%H%M)" \< "$(printf '%02d%02d' "$RETRO_HOUR" "$RETRO_MINUTE")" ]; then
	SLOT="$(date -v-1d +%F 2>/dev/null || date -d yesterday +%F)"
	PREV="$(date -v-2d +%F 2>/dev/null || date -d '2 days ago' +%F)"
else
	SLOT="$(date +%F)"
	PREV="$(date -v-1d +%F 2>/dev/null || date -d yesterday +%F)"
fi

PASS=0
FAIL=0

# Resets the state, applies "file=content" pairs, then runs run.sh with the given environment.
run() {
	rm -rf "$STATE"
	mkdir -p "$STATE"
	while [ $# -gt 0 ] && [[ "$1" == *=* ]] && [[ "$1" != FAKE_* ]] && [[ "$1" != RETRO_* ]]; do
		printf '%s\n' "${1#*=}" > "$STATE/${1%%=*}"
		shift
	done
	OUT="$(env -i HOME="$FAKE" USER=test "$@" /bin/bash "$FAKE/claude-retro/run.sh" 2>&1)"
	CODE=$?
}

# Checks a condition, by name.
check() {
	local name="$1"
	shift
	if "$@"; then
		PASS=$((PASS + 1))
	else
		FAIL=$((FAIL + 1))
		echo "FAIL $name"
		echo "  exit $CODE, output: $OUT"
		echo "  state: $(cd "$STATE" && grep -r . . 2>/dev/null | tr '\n' ' ')"
	fi
}

has() { grep -q -- "$1" <<<"$OUT"; }
state_is() { [ "$(cat "$STATE/$1" 2>/dev/null)" = "$2" ]; }

run "last-slot=$SLOT"
check "slot already done: silent" [ "$CODE" = 0 -a -z "$OUT" ]

run "last-slot=$PREV" "attempts=$SLOT 3"
check "three tries used: silent" [ "$CODE" = 0 -a -z "$OUT" ]

run "last-slot=$PREV" FAKE_DARK=1
check "dark wake: waits" has "slot $SLOT due; waiting for a full wake"
check "dark wake: exit 75, so launchd retries" [ "$CODE" = 75 ]
check "dark wake: no try counted" [ ! -e "$STATE/attempts" ]

run "last-slot=$PREV" FAKE_OFFLINE=1
check "offline: waits" has "slot $SLOT due; waiting for the network"
check "offline: exit 75, so launchd retries" [ "$CODE" = 75 ]
check "offline: no try counted" [ ! -e "$STATE/attempts" ]

run "last-slot=$PREV"
check "due: runs" has "No sessions in the window"
check "due: exit 0" [ "$CODE" = 0 ]
check "due: slot done" state_is last-slot "$SLOT"
check "due: try counted" state_is attempts "$SLOT 1"
check "due: window advanced" [ -s "$STATE/last-run" ]

run "last-slot=$PREV" "attempts=$SLOT 1"
check "second try counted" state_is attempts "$SLOT 2"

run "attempts=$PREV 3"
check "first run, old tries ignored" state_is attempts "$SLOT 1"

run "last-slot=$SLOT" FAKE_DARK=1 RETRO_FORCE=1
check "forced: runs anyway" has "No sessions in the window"

run "last-slot=$PREV" RETRO_DAYS=3
check "manual review: no slot change" state_is last-slot "$PREV"
check "manual review: no try counted" [ ! -e "$STATE/attempts" ]

echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
