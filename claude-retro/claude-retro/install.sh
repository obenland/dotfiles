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

chmod +x "$RETRO_DIR/run.sh" "$RETRO_DIR/bin/guard-bash.sh"
mkdir -p "$RETRO_DIR/logs" "$RETRO_DIR/work" "$RETRO_DIR/reports" "$RETRO_DIR/worktrees"

case "$(uname -s)" in
	Darwin)
		plist="$HOME/Library/LaunchAgents/local.claude-retro.plist"
		mkdir -p "$HOME/Library/LaunchAgents"
		# Render a real file (not a symlink into dotfiles) with this machine's home and user.
		sed -e "s#@HOME@#$HOME#g" -e "s#@USER@#$(id -un)#g" \
			"$RETRO_DIR/launchd/local.claude-retro.plist.template" > "$plist"
		plutil -lint "$plist"
		launchctl bootout "gui/$(id -u)" "$plist" 2>/dev/null || true
		launchctl bootstrap "gui/$(id -u)" "$plist"
		launchctl list | grep retro
		;;
	Linux)
		line="17 23 * * * PATH=$HOME/.local/bin:/usr/local/bin:/usr/bin:/bin /bin/bash $RETRO_DIR/run.sh >> $RETRO_DIR/logs/retro.out.log 2>> $RETRO_DIR/logs/retro.err.log"
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
