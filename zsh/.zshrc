export NVM_DIR="/usr/local/opt/nvm"
[ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"
[ -s "$NVM_DIR/bash_completion" ] && . "$NVM_DIR/bash_completion"

alias sup='svn up'
alias dev='cd ~/Documents/code/'
alias sync-wpcom='unison -ui text -repeat watch automattic-sandbox'
alias sync-woa="unison -ui text -repeat watch woa"

function h() { sudo vim /etc/hosts; kill $(ps -Ao pid,command | awk '/obenland@proxy\.automattic\.com/ { print $1 }'); }
