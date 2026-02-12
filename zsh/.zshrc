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
