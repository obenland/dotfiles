#!/bin/bash
#
# Tests for apply-proposals.sh. Builds a checkout whose GitHub remotes point at local bare repos
# (through url.insteadOf), with a stub gh, and checks which proposals become draft PRs.
#
# Usage: bash ~/claude-retro/bin/test-apply.sh

set -uo pipefail

BIN="$(cd "$(dirname "$0")" && pwd)"
FAKE="$(mktemp -d)"
trap 'rm -rf "$FAKE"' EXIT

# A throwaway HOME, so the fixtures' git runs without the real global config.
export HOME="$FAKE/home"
mkdir -p "$HOME/claude-retro/work" "$FAKE/stub"
printf '[user]\n\tname = Test\n\temail = test@example.com\n[init]\n\tdefaultBranch = trunk\n' > "$HOME/.gitconfig"

# A stub gh: logs each call; `pr list` prints $FAKE/open-pr if it exists, `pr create` prints a URL.
cat > "$FAKE/stub/gh" <<'STUB'
#!/bin/bash
printf '%s\n' "$*" >> "$GH_LOG"
case "$1 $2" in
	"pr list") cat "$GH_OPEN_PR" 2>/dev/null || true ;;
	"pr create") echo "https://github.com/WordPress/example/pull/7" ;;
esac
STUB
chmod +x "$FAKE/stub/gh"
export PATH="$FAKE/stub:$PATH" GH_LOG="$FAKE/gh.log" GH_OPEN_PR="$FAKE/open-pr"

UPSTREAM="$FAKE/upstream.git"
FORK="$FAKE/fork.git"
REPO="$HOME/code/example"
git init --quiet --bare "$UPSTREAM"
git init --quiet --bare "$FORK"
git init --quiet "$REPO"
printf '# Agents\n\nUse tabs.\n' > "$REPO/AGENTS.md"
echo readme > "$REPO/README.md"
git -C "$REPO" add AGENTS.md README.md
git -C "$REPO" commit --quiet -m init
git -C "$REPO" remote add origin git@github.com:WordPress/example.git
git -C "$REPO" remote add fork git@github.com:obenland/example.git
git -C "$REPO" config url."$UPSTREAM".insteadOf git@github.com:WordPress/example.git
git -C "$REPO" config url."$FORK".insteadOf git@github.com:obenland/example.git
git -C "$REPO" push --quiet origin trunk
git -C "$UPSTREAM" symbolic-ref HEAD refs/heads/trunk

TODAY="$(date +%F)"
PASS=0
FAIL=0

# Checks that a command's output equals the expected value.
check() {
	local name="$1" want="$2" got="$3"
	if [ "$got" = "$want" ]; then
		PASS=$((PASS + 1))
	else
		FAIL=$((FAIL + 1))
		printf 'FAIL %s:\n  want: %s\n  got:  %s\n' "$name" "$want" "$got"
	fi
}

# proposal <dir name> <repo> <diff>: writes one proposal.
proposal() {
	mkdir -p "$PROPOSALS/$1"
	jq -n --arg repo "$2" '{repo: $repo, title_summary: "Say to use tabs", commit_message: "Say to use tabs\n\nTwo sessions.",
		pr_body: "Evidence in general terms.", pr_comment: "One more."}' > "$PROPOSALS/$1/meta.json"
	printf '%s' "$3" > "$PROPOSALS/$1/change.diff"
}

# run_apply: runs apply-proposals.sh on a fresh report, with the fixture repo trusted.
run_apply() {
	printf '<!-- retro: findings=1 -->\n# Retro\n\nBody\n' > "$FAKE/report.md"
	printf '%s\n' "$REPO" "$HOME/dotfiles" > "$FAKE/trusted.txt"
	: > "$FAKE/prs.txt"
	: > "$GH_LOG"
	"$BIN/apply-proposals.sh" "$PROPOSALS" "$FAKE/trusted.txt" "$FAKE/report.md" "$FAKE/prs.txt" > "$FAKE/out.log" 2>&1
}

AGENTS_DIFF='--- a/AGENTS.md
+++ b/AGENTS.md
@@ -1,3 +1,4 @@
 # Agents

 Use tabs.
+Never use spaces.
'

# 1. A good proposal next to ones that must be skipped.
PROPOSALS="$FAKE/p1"
proposal good "$REPO" "$AGENTS_DIFF"
proposal second "$REPO" "$AGENTS_DIFF"
proposal elsewhere "$FAKE/other" "$AGENTS_DIFF"
run_apply

branch="claude-retro/$TODAY-good"
check "fork branch has the change" "Never use spaces." "$(git -C "$FORK" show "$branch:AGENTS.md" 2>&1 | tail -n 1)"
check "commit message" "Say to use tabs" "$(git -C "$FORK" log -1 --format=%s "$branch" 2>&1)"
check "upstream untouched" "refs/heads/trunk" "$(git -C "$UPSTREAM" for-each-ref --format='%(refname)')"
check "draft PR shape" "pr create --draft --repo WordPress/example --base trunk --head obenland:$branch --title Claude retro: Say to use tabs" "$(grep '^pr create' "$GH_LOG" | sed 's/ --body-file .*//')"
check "PR list file" "https://github.com/WordPress/example/pull/7" "$(cat "$FAKE/prs.txt")"
check "worktree removed" "1" "$(git -C "$REPO" worktree list | wc -l | tr -d ' ')"
check "skipped: untrusted repo" "1" "$(grep -c "elsewhere.*isn't one of tonight's session repos" "$FAKE/report.md")"
check "skipped: second proposal for a repo" "1" "$(grep -c 'second.*already has a proposal tonight' "$FAKE/report.md")"
check "report keeps the marker first" "<!-- retro: findings=1 -->|# Retro||## Draft PRs" "$(head -4 "$FAKE/report.md" | paste -sd '|' -)"

# 2. Disallowed changes. A skipped proposal doesn't use up the repo's one PR, so each runs alone.
PROPOSALS="$FAKE/p2"
proposal readme "$REPO" '--- a/README.md
+++ b/README.md
@@ -1 +1 @@
-readme
+changed
'
run_apply
check "skipped: README" "1" "$(grep -c 'touches .README.md.' "$FAKE/report.md")"

PROPOSALS="$FAKE/p3"
proposal script "$REPO" 'diff --git a/.claude/hooks/x.sh b/.claude/hooks/x.sh
new file mode 100755
--- /dev/null
+++ b/.claude/hooks/x.sh
@@ -0,0 +1 @@
+echo hi
'
run_apply
check "skipped: executable file" "1" "$(grep -c 'changes file modes' "$FAKE/report.md")"

PROPOSALS="$FAKE/p4"
proposal setup "$HOME/dotfiles" "$AGENTS_DIFF"
run_apply
check "skipped: personal setup" "1" "$(grep -c 'personal setup' "$FAKE/report.md")"
check "nothing pushed for skips" "" "$(git -C "$FORK" for-each-ref --format='%(refname)' | grep -v "$branch")"

# 3. An open retro draft PR gets a commit on its branch and a comment, not a new PR.
PROPOSALS="$FAKE/p5"
proposal more "$REPO" '--- a/AGENTS.md
+++ b/AGENTS.md
@@ -1,2 +1,3 @@
 # Agents

+Read the README first.
 Use tabs.
'
printf '7\thttps://github.com/WordPress/example/pull/7\t%s\n' "$branch" > "$GH_OPEN_PR"
run_apply
check "added to the open PR branch" "3" "$(git -C "$FORK" rev-list --count "$branch")"
check "comment instead of a new PR" "pr comment 7" "$(grep -o '^pr c[a-z]* [0-9]*' "$GH_LOG")"
check "both changes on the branch" "# Agents||Read the README first.|Use tabs.|Never use spaces." "$(git -C "$FORK" show "$branch:AGENTS.md" | paste -sd '|' -)"

echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || { echo "--- log"; cat "$FAKE/out.log"; }
[ "$FAIL" -eq 0 ]
