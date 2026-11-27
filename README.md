# dotfiles

Personal configuration managed with [GNU Stow](https://www.gnu.org/software/stow/).

## Setup on a new machine

```bash
# Install stow
brew install stow

# Clone this repo
git clone git@github.com:obenland/dotfiles.git ~/dotfiles

# Install all packages
cd ~/dotfiles
git config core.hooksPath .githooks
mkdir -p ~/claude-retro   # keep it a real dir; see claude-retro below
stow claude claude-retro git zsh vim
~/claude-retro/install.sh # schedule the nightly retro; later pulls refresh it via post-merge

# Or install selectively
stow git zsh
```

## Packages

| Package | Files | What it configures |
|---------|-------|--------------------|
| `claude` | `.claude/` | Claude Code — settings, skills, hooks, team prompts |
| `git` | `.gitconfig`, `.gitignore_global` | Git identity, aliases, signing, global ignores |
| `zsh` | `.zshenv`, `.zprofile`, `.zshrc` | Shell environment, login setup, interactive config |
| `vim` | `.vimrc` | Vim editor settings |
| `claude-retro` | `claude-retro/` | Nightly headless review of the day's Claude Code sessions |

### claude-retro

Stowing installs the code and config into `~/claude-retro/`. `install.sh` schedules it: a LaunchAgent (macOS) or a crontab entry (Linux). Run it once on a new machine; after that the `post-merge` hook re-runs it on every pull, so schedule changes propagate.

- The nightly run is at 11:17 pm (`config/schedule`). If it fails, or the Mac isn't fully awake (a dark wake) or can't reach the API, `run.sh` exits non-zero and launchd tries again every 30 minutes until a run succeeds; then nothing fires until the next 11:17 pm. A night slept through runs at the next real wake, under that night's date. A failing run gets three tries per night. On Linux, cron fires every 30 minutes and `run.sh` exits at once unless the night's run is unfinished. `RETRO_FORCE=1 ~/claude-retro/run.sh` runs now regardless; tests: `bin/test-schedule.sh`.

- `~/claude-retro` must be a real directory before stowing, so stow links its files instead of folding the whole directory into this repo. The `post-merge` hook creates it.
- Runtime data stays on each machine and out of git: `reports/`, `work/`, `worktrees/`, `logs/`.
- The headless review has no shell. It writes PR proposals (a diff plus PR text) to `work/`, and `bin/apply-proposals.sh` then checks each one (only AGENTS.md, CLAUDE.md and `.claude/` files, only repos from that night's sessions), commits it in a fresh worktree, pushes to the fork and opens the draft PR, or adds to an open retro draft. Tests: `bin/test-apply.sh` and `bin/test-digest.sh`.
- Each machine reviews only its own sessions and writes its own report. If two machines propose a fix for the same repo the same night, the second one adds to the first one's draft PR, or its push fails harmlessly (no force push) and its diff stays in that machine's report.
- To keep a machine from running it: `touch ~/claude-retro/disabled && ~/claude-retro/install.sh`. That removes the schedule there, and later pulls leave it off. Delete the file and re-run `install.sh` to turn it back on.
- On macOS, launchd starts a small launcher (`~/Library/Application Support/claude-retro/claude-retro-launcher`, built by `install.sh` from `launcher/`) instead of `/bin/bash`. Grant that one binary Full Disk Access so the run can read repos in `~/Documents`; `install.sh` opens the pane when it builds it. It's rebuilt only when its source changes, since a rebuild drops the grant.
- For unattended auth, run `claude setup-token`, then store the token: `security add-generic-password -U -s claude-retro-oauth-token -a "$(id -un)" -w`. Without it, the job uses the regular sign-in.

### Zsh file loading order

- `.zshenv` — every invocation. Environment variables and PATH.
- `.zprofile` — login shells only. Homebrew init.
- `.zshrc` — interactive shells only. NVM, aliases, functions.

## Adding a new package

```bash
# Create the package directory mirroring ~/
mkdir -p ~/dotfiles/newpkg

# Move config files in (preserving the path relative to ~)
mv ~/.someconfig ~/dotfiles/newpkg/.someconfig

# Stow it
cd ~/dotfiles && stow newpkg
```

## Removing a stowed package

```bash
cd ~/dotfiles && stow -D packagename
```

This removes the symlinks without deleting the files from the repo.

## Notes

- Claude Code's generated data (sessions, cache, debug logs) is gitignored via `claude/.claude/.gitignore`.
- Never commit secrets. Check files for tokens/keys before adding.
