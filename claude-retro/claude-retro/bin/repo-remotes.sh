#!/bin/bash
#
# repo-remotes.sh <repo>
#
# Prints "<base remote>\t<base owner/repo>\t<fork remote>\t<fork owner/repo>" for a checkout whose
# GitHub remotes include a fork: a remote owned by FORK_OWNER in a repo that also has a remote owned
# by someone else. The base prefers `upstream`, then `origin`; the fork prefers `fork`, then `origin`.
# Exits 1 with a reason on stderr when there's no such pair.

set -uo pipefail

FORK_OWNER="obenland"
REPO="$1"

# owner/repo from a GitHub remote URL; empty for other hosts.
gh_slug() {
	sed -nE 's#^(git@github\.com:|ssh://git@github\.com/|https://github\.com/)([^/]+/[^/]+)$#\2#p' <<<"$1" | sed 's/\.git$//'
}

forks=()
bases=()
while read -r key url; do
	name="${key#remote.}"
	name="${name%.url}"
	slug="$(gh_slug "$url")"
	[ -n "$slug" ] || continue
	if [ "${slug%%/*}" = "$FORK_OWNER" ]; then
		forks+=("$name"$'\t'"$slug")
	else
		bases+=("$name"$'\t'"$slug")
	fi
done < <(git -C "$REPO" config --get-regexp '^remote\..*\.url$' 2>/dev/null)

# pick <preferred name> <second choice> <entries...>: prints the entry for the first name present, else the first entry.
pick() {
	local first="$1" second="$2" want entry
	shift 2
	for want in "$first" "$second"; do
		for entry in "$@"; do
			[ "${entry%%$'\t'*}" = "$want" ] && { printf '%s\n' "$entry"; return; }
		done
	done
	printf '%s\n' "$1"
}

if [ ${#forks[@]} -eq 0 ] || [ ${#bases[@]} -eq 0 ]; then
	echo "no GitHub remote owned by $FORK_OWNER next to one owned by someone else" >&2
	exit 1
fi

printf '%s\t%s\n' "$(pick upstream origin "${bases[@]}")" "$(pick fork origin "${forks[@]}")"
