#!/usr/bin/env bash
#
# SessionStart hook: prune stale wp-env instances at most once a day, in the background.
#
# wp-env leaves an instance per worktree and environment in ~/.wp-env; without
# this they reached 130 GB. See ~/.claude/skills/dotorg/bin/wp-env-prune.
#
stamp="$HOME/.cache/wp-env-prune.stamp"
prune="$HOME/.claude/skills/dotorg/bin/wp-env-prune"

[[ -x $prune ]] || exit 0
if [[ -f $stamp ]] && (( $(date +%s) - $(stat -f %m "$stamp") < 86400 )); then
	exit 0
fi
mkdir -p "$(dirname "$stamp")"
touch "$stamp"
nohup "$prune" --quiet --builder-cache >>"$HOME/.cache/wp-env-prune.log" 2>&1 </dev/null &
exit 0
