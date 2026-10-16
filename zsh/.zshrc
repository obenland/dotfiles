export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"
[ -s "$NVM_DIR/bash_completion" ] && . "$NVM_DIR/bash_completion"

# Docker CLI completions
fpath=($HOME/.docker/completions $fpath)
autoload -Uz compinit
compinit

# PHP
export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"
export PATH="/opt/homebrew/opt/php@8.4/sbin:$PATH"

# Aliases
alias sup='svn up'
alias dev='cd ~/Documents/code/'
alias sync-wpcom='unison -ui text -repeat watch automattic-sandbox'
alias sync-woa="unison -ui text -repeat watch woa"

function h() { sudo vim /etc/hosts; kill $(ps -Ao pid,command | awk '/obenland@proxy\.automattic\.com/ { print $1 }'); }

function svn() {
	if [[ "$1" == "diff" ]]; then
		command svn "$@" | colordiff
	else
		command svn "$@"
	fi
}

# pnpm
export PNPM_HOME="~/Library/pnpm"
case ":$PATH:" in
  *":$PNPM_HOME:"*) ;;
  *) export PATH="$PNPM_HOME:$PATH" ;;
esac
# pnpm end

# dotorg pipeline (~/.claude/skills/dotorg)
export PATH="$HOME/.claude/skills/dotorg/bin:$PATH"
# Open a wordpress.org PR and start its review-and-test loop right away; the loop logs to ~/.cache/wporg-pipeline/run-<PR>.log.
function wporg-pr() {
	local url pr
	url=$(gh pr create --repo WordPress/wordpress.org --base trunk "$@") || return $?
	echo "$url"
	pr=${url##*/}
	[[ $pr == <-> ]] || return 0
	wporg-pipeline run "$pr" > "$HOME/.cache/wporg-pipeline/run-$pr.log" 2>&1 &!
	echo "wporg-pipeline run $pr started; log: ~/.cache/wporg-pipeline/run-$pr.log"
}
