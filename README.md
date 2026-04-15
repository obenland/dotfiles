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
stow claude git zsh vim

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
