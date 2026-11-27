Be direct. Move forward. Don't over-explain.
Bias toward action — ask only when the decision is genuinely ambiguous.
Review ~/.claude/settings.json for global settings.

## Version Control

- Some projects use SVN, not git. Check for `.svn/` before using git commands. Use `svn` equivalents in SVN repos (a PreToolUse hook enforces this too).

## Git Commits

- NEVER use `--no-verify` when committing. Always fix linting/hook issues first.

## Pull Requests

- After opening or substantially updating a PR, spawn a background subagent that runs `/code-review medium <PR#>` read-only and reports its verified findings. Don't wait to be asked.
- Fixes for review feedback on a PR go on that PR's branch, even if it's already approved. Open a separate PR only when asked.

## Testing

- NEVER use `remove_all_filters()` or `remove_all_actions()` in tests — it may inadvertently remove production callbacks. Always save closures to a variable and add/remove that specific callback.
- For remote or CI runs, report results only once the run reaches a final status. Point test runs at the default branch, not at a PR branch that might be merged or deleted mid-run.

## Triage

- When triaging or proposing a fix, mark each conclusion as **verified** (reproduced, or checked against current code or the live site) or **inferred**. Never recommend closing an issue on inference alone; check it first or list it as "needs check".

## Standards

- WordPress coding standards. Follow each project's `.editorconfig`.
- New PHP files (including tests) must pass `phpcs` against the WordPress standard with zero errors *and* zero warnings, and must declare `declare( strict_types = 1 );` after the file docblock. Give everything they define explicit parameter and return types. Don't retrofit either onto pre-existing files.
- Comment style (PHP, JS, SCSS/CSS, including tests). Prefer a single-line comment; only go multi-line if one line truly can't carry it:
  - `/** */` docblocks on every function, method, class, property, constant, and file header — with aligned `@param`/`@return`/`@since`/`@access` tags as appropriate.
  - `/* */` (single asterisk, ` * ` continuations) for multi-line comments inside a function body.
  - `//` only for single-line comments. Never stack two or more `//` lines — if you need more than one line, use `/* */`.
- Don't refactor code you weren't asked to touch.
- Don't add features beyond what's asked.
- Don't add unnecessary abstractions, type annotations, or docstrings to unchanged code.
- Never factor "tedious" into recommendations — mechanical work is instant for an LLM.
- Prefer technically cleaner solutions over "easier" ones — the effort delta doesn't exist.
- Don't estimate human time — focus on dependencies and risk.
- When choosing "do it right now" vs "do it later", bias toward now if it's cleaner.
- Codemod-style migrations (find/replace across files) are trivial — never defer them for effort reasons.

## Session Hygiene

- Start sessions by reading `.claude/_NEXT_SESSION_MEMO.md` if it exists.
- Use `/wrap` to end sessions properly.
- Use `/sup` for quick context recovery.
