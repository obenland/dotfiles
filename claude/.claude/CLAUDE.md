Be direct. Move forward. Don't over-explain.
Bias toward action — ask only when the decision is genuinely ambiguous.
Review ~/.claude/settings.json for global settings.

## Version Control

- Some projects use SVN, not git. Check for `.svn/` before using git commands. Use `svn` equivalents in SVN repos (a PreToolUse hook enforces this too).

## Git Commits

- NEVER use `--no-verify` when committing. Always fix linting/hook issues first.

## Testing

- NEVER use `remove_all_filters()` or `remove_all_actions()` in tests — it may inadvertently remove production callbacks. Always save closures to a variable and add/remove that specific callback.

## Standards

- WordPress coding standards. Follow each project's `.editorconfig`.
- Comment style (PHP + JS, including tests):
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
