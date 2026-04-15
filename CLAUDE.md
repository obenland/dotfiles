# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository purpose

Personal dotfiles managed with **GNU Stow**. Each top-level directory (`claude/`, `git/`, `zsh/`, `vim/`) is a stow "package" whose contents mirror paths relative to `$HOME`. `stow <pkg>` from the repo root creates the symlinks; `stow -D <pkg>` removes them; `stow -R <pkg>` restows after adding or renaming files inside a package.

There is no build, lint, or test suite — this repo is pure configuration.

## The files you edit here are the live config

Every tracked file is symlinked into `$HOME` once stowed. Editing `claude/.claude/settings.json` is the same as editing `~/.claude/settings.json`; editing `git/.gitconfig` is the same as editing `~/.gitconfig`. Changes take effect immediately for existing symlinks — no restow needed unless you *add* a new file to a package.

When adding a new file to a package, run `stow -R <pkg>` from the repo root so the new symlink gets created. Moving a file between packages requires `stow -D` on the old package first.

## The stowed Claude config affects all sessions globally

- `claude/.claude/CLAUDE.md` → `~/.claude/CLAUDE.md` — user-level instructions loaded into **every** Claude Code session on this machine. Treat edits as global behavior changes.
- `claude/.claude/settings.json` → `~/.claude/settings.json` — permissions, hooks, enabled plugins, default mode. Use the `update-config` skill for non-trivial changes; it knows the schema.
- `claude/.claude/hooks/*.sh` — referenced from `settings.json` via `$HOME/.claude/hooks/...`. They run as PreToolUse/Notification hooks and must stay executable.
- `claude/.claude/plugins/installed_plugins.json` is **gitignored** — it's runtime cache, not config. Don't re-add it.

## Pre-commit hook gotcha

`pre-commit-review.sh` intercepts `git commit` via a PreToolUse hook and **blocks it** unless a sentinel file `.claude/.reviews-passed` exists in the project directory. The hook deletes the sentinel on success, so it must be recreated for each commit. The intended flow is: run the `pr-review-toolkit` agents, then create the sentinel, then commit. This applies to **any** repo where Claude runs `git commit`, not just this one, because the hook is installed globally.

## SVN guard

`svn-guard.sh` blocks `git` commands when the effective working directory (including `cd` prefixes and `git -C <dir>`) contains a `.svn/` directory, and tells Claude to use `svn` equivalents instead. Parses `cd` and `-C` from the command string to catch indirect cases. Keep this in mind when debugging "why did my git command get denied?" — check for `.svn/`.

## Git config specifics

- Default branch for `git init` is `trunk`, not `main`. New repos created locally will be on `trunk`.
- `git/.gitconfig` conditionally includes `~/.gitconfig-a8c` for any repo with an `git@github.a8c.com:` remote, which swaps the identity, signing key, and GPG program for Automattic work. If a commit's signing behavior looks off, check which identity is active with `git config user.email`.
- Commits are GPG-signed by default (`commit.gpgsign = true`). Never bypass signing.

## Zsh loading order

`.zshenv` (every invocation — PATH, env) → `.zprofile` (login shells — Homebrew init) → `.zshrc` (interactive shells — NVM, pnpm, aliases, functions). Put new env vars in `.zshenv`; put interactive-only things like aliases in `.zshrc`.

## Secrets

Never commit `.env`, keys, tokens, or anything under `~/.ssh`, `~/.aws`, `~/.gnupg`. `settings.json` already denies reads from those paths, but the repo itself has no such enforcement — check before staging.
