#!/bin/bash
#
# apply-proposals.sh <proposals dir> <trusted repos file> <report> <PR list file>
#
# Turns the review run's proposals into draft PRs on my forks. The headless run has no shell, so it
# writes each proposal as <proposals dir>/<name>/{meta.json,change.diff} and this script does every git
# and GitHub step, which keeps these rules whatever the model wrote:
#   - only AGENTS.md or CLAUDE.md files, or files under the repo's .claude/, change, with no mode
#     changes, renames, deletions or symlinks;
#   - only repos from tonight's sessions (the trusted repos file), never ~/dotfiles (personal setup);
#   - the change lands in a new detached worktree from the remote default branch, or from the branch
#     of an open retro draft PR, which then gets a commit and a comment instead of a second PR;
#   - pushes go only to the fork (see repo-remotes.sh), never forced; one PR per repo per night;
#   - nothing is merged or marked ready, and the worktree is removed.
# Draft PR links and skipped proposals go at the top of the report; PR URLs also go to the PR list file.

set -uo pipefail

PROPOSALS="$1"
TRUSTED="$2"
REPORT="$3"
PRS="$4"
BIN="$(cd "$(dirname "$0")" && pwd)"
TODAY="$(date +%F)"
WORKTREES="$HOME/claude-retro/worktrees"

export GIT_TERMINAL_PROMPT=0 GH_PROMPT_DISABLED=1 GIT_SSH_COMMAND="ssh -o BatchMode=yes"

lines=()

# Records one result line for the report and the log.
note() {
	lines+=("- $*")
	printf '%s apply-proposals: %s\n' "$(date '+%F %T')" "$*"
}

# limit <seconds> <command...>: a time limit, so a passphrase, pinentry or network prompt can't hang the night.
limit() {
	local secs="$1"
	shift
	perl -e 'alarm shift @ARGV; exec @ARGV or die "exec failed: $!\n"' "$secs" "$@"
}

# The first 200 characters of a command's stderr, on one line.
err_text() {
	head -c 200 "$1" | tr '\n' ' '
}

seen=" "

# Applies, commits, pushes and opens (or updates) the PR inside worktree $1.
publish() {
	local wt="$1" err="$1.err" hooks text url p

	# A relative hooksPath is read from the checkout, so a change under it would run at commit.
	hooks="$(git -C "$wt" config --get core.hooksPath 2>/dev/null)"
	if [ -n "$hooks" ] && [[ "$hooks" != /* && "$hooks" != "~"* ]]; then
		for p in "${paths[@]}"; do
			if [[ "$p/" == "${hooks%/}"/* ]]; then
				note "\`$name\` ($base_slug): skipped, \`$p\` is in the repo's hooks folder."
				return 1
			fi
		done
	fi

	if ! git -C "$wt" apply --recount "$diff" 2>"$err"; then
		note "\`$name\` ($base_slug): the diff didn't apply to $base_label: $(err_text "$err")"
		return 1
	fi

	text="$(jq -r '.commit_message // empty' "$meta")"
	[ -n "$text" ] || text="Claude retro: $summary"
	printf '%s\n\nCo-Authored-By: Claude <noreply@anthropic.com>\n' "$text" > "$wt.msg"
	git -C "$wt" add -- "${paths[@]}"
	if ! limit 120 git -C "$wt" commit --quiet -F "$wt.msg" 2>"$err"; then
		note "\`$name\` ($base_slug): commit failed (a hook, or GPG signing?): $(err_text "$err")"
		return 1
	fi

	if ! limit 180 git -C "$wt" push --quiet "$fork_remote" "HEAD:refs/heads/$branch" 2>"$err"; then
		note "\`$name\` ($base_slug): push to $fork_slug failed: $(err_text "$err")"
		return 1
	fi

	if [ -n "$pr_number" ]; then
		text="$(jq -r '.pr_comment // empty' "$meta")"
		[ -n "$text" ] || text="Added from the $TODAY retro: $summary"
		printf '%s\n' "$text" > "$wt.body"
		if ! limit 60 gh pr comment "$pr_number" --repo "$base_slug" --body-file "$wt.body" >/dev/null 2>"$err"; then
			note "\`$name\` ($base_slug): pushed to $pr_url, but the comment failed: $(err_text "$err")"
		fi
		url="$pr_url"
		note "Draft PR (updated): $url, $summary"
	else
		{
			jq -r '.pr_body // empty' "$meta"
			printf '\n🤖 Generated with [Claude Code](https://claude.com/claude-code)\n'
		} > "$wt.body"
		if ! url="$(limit 120 gh pr create --draft --repo "$base_slug" --base "$default" \
			--head "${fork_slug%%/*}:$branch" --title "Claude retro: $summary" --body-file "$wt.body" 2>"$err")"; then
			note "\`$name\` ($base_slug): pushed ${fork_slug%%/*}:$branch, but \`gh pr create\` failed: $(err_text "$err")"
			return 1
		fi
		url="$(tail -n 1 <<<"$url")"
		note "Draft PR (new): $url, $summary"
	fi
	printf '%s\n' "$url" >> "$PRS"
}

# Validates one proposal directory, sets up the worktree, publishes and cleans up.
apply_one() {
	local dir="$1" repo remotes numstat summary_out existing sha wt p slug
	name="${dir##*/}"
	meta="$dir/meta.json"
	diff="$dir/change.diff"

	if [ ! -f "$meta" ] || [ ! -f "$diff" ]; then
		note "\`$name\`: skipped, it needs both meta.json and change.diff."
		return
	fi
	if ! repo="$(jq -er '.repo' "$meta" 2>/dev/null)"; then
		note "\`$name\`: skipped, meta.json has no \"repo\" or isn't valid JSON."
		return
	fi
	summary="$(jq -r '.title_summary // empty' "$meta" | tr -s '\r\n\t' ' ' | sed -E 's/^ *(Claude retro: *)?//; s/ *$//' | cut -c1-90)"
	[ -n "$summary" ] || summary="guidance update"

	if ! grep -qxF -- "$repo" "$TRUSTED"; then
		note "\`$name\`: skipped, $repo isn't one of tonight's session repos."
		return
	fi
	if [ "$repo" = "$HOME/dotfiles" ]; then
		note "\`$name\`: skipped, ~/dotfiles is personal setup; its changes stay in the report as diffs."
		return
	fi
	if [[ "$seen" == *" $repo "* ]]; then
		note "\`$name\`: skipped, $repo already has a proposal tonight (one PR per repo per night)."
		return
	fi

	if ! remotes="$("$BIN/repo-remotes.sh" "$repo" 2>&1)"; then
		note "\`$name\`: skipped, $repo has no fork: $remotes. The diff is in the report."
		return
	fi
	IFS=$'\t' read -r base_remote base_slug fork_remote fork_slug <<<"$remotes"

	if ! numstat="$(git -C "$repo" apply --recount --numstat "$diff" 2>&1)"; then
		note "\`$name\` ($base_slug): skipped, change.diff doesn't parse: ${numstat:0:200}"
		return
	fi
	paths=()
	while IFS=$'\t' read -r _ _ p; do
		if [[ "$p" == /* || "$p" == *..* || "$p" == *" => "* ]] ||
			! [[ "$p" =~ ^(.+/)?(AGENTS|CLAUDE)\.md$ || "$p" == .claude/?* ]]; then
			note "\`$name\` ($base_slug): skipped, the diff touches \`$p\`; only AGENTS.md, CLAUDE.md and .claude/ are allowed."
			return
		fi
		paths+=("$p")
	done <<<"$numstat"
	if [ ${#paths[@]} -eq 0 ]; then
		note "\`$name\` ($base_slug): skipped, change.diff changes nothing."
		return
	fi
	# New regular files are fine; mode changes, renames, copies, deletions and symlinks are not.
	summary_out="$(git -C "$repo" apply --recount --summary "$diff" | grep -v '^ create mode 100644 ')"
	if [ -n "$summary_out" ]; then
		note "\`$name\` ($base_slug): skipped, the diff changes file modes, renames, deletes or links files."
		return
	fi

	default="$(limit 60 git -C "$repo" ls-remote --symref "$base_remote" HEAD 2>/dev/null |
		awk '$1 == "ref:" { sub("^refs/heads/", "", $2); print $2; exit }')"
	if [ -z "$default" ]; then
		note "\`$name\` ($base_slug): skipped, couldn't read the default branch of $base_remote."
		return
	fi

	if ! existing="$(limit 60 gh pr list --repo "$base_slug" --author "@me" --state open --draft \
		--search 'in:title "Claude retro:"' --json number,url,headRefName,headRepositoryOwner \
		--jq "map(select((.headRefName | startswith(\"claude-retro/\")) and .headRepositoryOwner.login == \"${fork_slug%%/*}\")) | first // empty | [.number, .url, .headRefName] | @tsv" \
		2>"$PROPOSALS/.gh.err")"; then
		note "\`$name\` ($base_slug): skipped, couldn't check for an open retro PR: $(err_text "$PROPOSALS/.gh.err")"
		return
	fi

	pr_number=""
	pr_url=""
	if [ -n "$existing" ]; then
		IFS=$'\t' read -r pr_number pr_url branch <<<"$existing"
		base_label="the branch of $pr_url"
		limit 180 git -C "$repo" fetch --quiet "$fork_remote" "refs/heads/$branch" 2>/dev/null
	else
		slug="$(LC_ALL=C tr '[:upper:]' '[:lower:]' <<<"$name" | LC_ALL=C tr -cs 'a-z0-9' '-' | sed 's/^-*//; s/-*$//' | cut -c1-40)"
		branch="claude-retro/$TODAY${slug:+-$slug}"
		base_label="$default"
		limit 180 git -C "$repo" fetch --quiet "$base_remote" "refs/heads/$default" 2>/dev/null
	fi
	if ! sha="$(git -C "$repo" rev-parse --verify --quiet FETCH_HEAD)"; then
		note "\`$name\` ($base_slug): skipped, the fetch failed."
		return
	fi

	seen+="$repo "
	mkdir -p "$WORKTREES"
	wt="$WORKTREES/${repo##*/}-$TODAY-$$"
	if ! git -C "$repo" worktree add --quiet --detach "$wt" "$sha" 2>/dev/null; then
		note "\`$name\` ($base_slug): skipped, \`git worktree add\` failed."
		return
	fi

	publish "$wt"

	git -C "$repo" worktree remove --force "$wt" 2>/dev/null ||
		note "Worktree $wt wasn't removed; remove it with \`git -C $repo worktree remove --force $wt\`."
	rm -f "$wt.err" "$wt.msg" "$wt.body"
}

for dir in "$PROPOSALS"/*/; do
	[ -d "$dir" ] && apply_one "${dir%/}"
done
rm -f "$PROPOSALS/.gh.err"

# The block goes below the marker line and the title, so the marker stays the first line.
if [ ${#lines[@]} -gt 0 ]; then
	BLOCK="$(printf '## Draft PRs\n\n'; printf '%s\n' "${lines[@]}")" awk '
		!done && NR <= 3 && /^<!-- retro:/ { print; next }
		!done && NR <= 3 && /^$/ { print; next }
		!done && NR <= 3 && /^# / { print; print ""; print ENVIRON["BLOCK"]; done = 1; next }
		!done { print ENVIRON["BLOCK"]; print ""; done = 1 }
		{ print }
		END { if (!done) print ENVIRON["BLOCK"] }
	' "$REPORT" > "$REPORT.tmp" && mv "$REPORT.tmp" "$REPORT"
fi
