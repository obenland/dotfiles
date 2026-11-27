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

Stowing installs the code and config into `~/claude-retro/`. `install.sh` schedules it: a LaunchAgent at 11:17 pm (macOS) or a crontab entry (Linux). Run it once on a new machine; after that the `post-merge` hook re-runs it on every pull, so schedule changes propagate.

- `~/claude-retro` must be a real directory before stowing, so stow links its files instead of folding the whole directory into this repo. The `post-merge` hook creates it.
- Runtime data stays on each machine and out of git: `reports/`, `work/`, `worktrees/`, `logs/`.
- Each machine reviews only its own sessions and writes its own report. If two machines propose the same repo fix the same night, the second push or draft PR fails harmlessly (no force push), and its diff stays in that machine's report.
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
