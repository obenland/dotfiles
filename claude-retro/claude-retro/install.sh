#!/bin/bash
#
# Installs the nightly retro schedule: a LaunchAgent on macOS, a crontab entry on Linux.
# Re-running it replaces the existing schedule.

set -euo pipefail

RETRO_DIR="$HOME/claude-retro"

# Per-machine opt-out: `touch ~/claude-retro/disabled` removes the schedule here and keeps later pulls from re-adding it.
if [ -e "$RETRO_DIR/disabled" ]; then
	case "$(uname -s)" in
		Darwin)
			launchctl bootout "gui/$(id -u)/local.claude-retro" 2>/dev/null || true
			rm -f "$HOME/Library/LaunchAgents/local.claude-retro.plist"
			;;
		Linux)
			{ crontab -l 2>/dev/null | grep -v 'claude-retro' || true; } | crontab -
			;;
	esac
	echo "claude-retro is disabled on this machine (~/claude-retro/disabled); schedule removed."
	exit 0
fi

chmod +x "$RETRO_DIR/run.sh" "$RETRO_DIR/bin/apply-proposals.sh" "$RETRO_DIR/bin/repo-remotes.sh"
mkdir -p "$RETRO_DIR/logs" "$RETRO_DIR/work" "$RETRO_DIR/reports" "$RETRO_DIR/worktrees"

# shellcheck source=config/schedule
. "$RETRO_DIR/config/schedule"
HOUR=$((10#$RETRO_HOUR))
MINUTE=$((10#$RETRO_MINUTE))

case "$(uname -s)" in
	Darwin)
		# Build the launcher launchd starts, so Full Disk Access can be granted to it alone. It lives outside
		# ~/claude-retro, where the headless run can't write. Rebuilding changes its signature and drops the
		# grant, so only rebuild when the source changes.
		launcher_dir="$HOME/Library/Application Support/claude-retro"
		launcher="$launcher_dir/claude-retro-launcher"
		src="$RETRO_DIR/launcher/claude-retro-launcher.c"
		want="$(shasum -a 256 "$src" | cut -d' ' -f1)"
		if [ ! -x "$launcher" ] || [ "$(cat "$launcher.sha256" 2>/dev/null)" != "$want" ]; then
			mkdir -p "$launcher_dir"
			cc -O2 -Wall -o "$launcher.tmp" "$src"
			codesign --force --sign - --identifier local.claude-retro.launcher "$launcher.tmp"
			mv -f "$launcher.tmp" "$launcher"
			echo "$want" > "$launcher.sha256"
			echo "Built $launcher." >&2
			echo "Grant it Full Disk Access so the nightly run can read repos in ~/Documents:" >&2
			echo "  System Settings > Privacy & Security > Full Disk Access > + > Cmd-Shift-G > $launcher" >&2
			open "x-apple.systempreferences:com.apple.preference.security?Privacy_AllFiles" 2>/dev/null || true
		fi

		plist="$HOME/Library/LaunchAgents/local.claude-retro.plist"
		mkdir -p "$HOME/Library/LaunchAgents"
		# Render a real file (not a symlink into dotfiles) with this machine's home and user.
		sed -e "s#@HOME@#$HOME#g" -e "s#@USER@#$(id -un)#g" \
			-e "s#@HOUR@#$HOUR#g" -e "s#@MINUTE@#$MINUTE#g" \
			"$RETRO_DIR/launchd/local.claude-retro.plist.template" > "$plist"
		plutil -lint "$plist"
		launchctl bootout "gui/$(id -u)" "$plist" 2>/dev/null || true
		launchctl bootstrap "gui/$(id -u)" "$plist"
		launchctl list | grep retro
		;;
	Linux)
		# cron can't relaunch on failure, so it fires every 30 minutes from the nightly time, and run.sh
		# exits at once unless last night's run is unfinished. TZ too, so run.sh dates the slot in cron's zone.
		line="$MINUTE,$(( (MINUTE + 30) % 60 )) * * * * TZ=America/Chicago PATH=$HOME/.local/bin:/usr/local/bin:/usr/bin:/bin /bin/bash $RETRO_DIR/run.sh >> $RETRO_DIR/logs/retro.out.log 2>> $RETRO_DIR/logs/retro.err.log"
		{
			crontab -l 2>/dev/null | grep -v 'claude-retro' || true
			echo "CRON_TZ=America/Chicago # claude-retro"
			echo "$line # claude-retro"
		} | crontab -
		crontab -l | grep retro
		;;
	*)
		echo "Unsupported OS" >&2
		exit 1
		;;
esac
