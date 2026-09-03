#!/usr/bin/env python3
"""Block edits that write two or more consecutive `//` comment lines.

Enforces the CLAUDE.md comment policy ("Never stack two or more `//` lines —
if you need more than one line, use `/* */`"). Only the edit payload is
inspected, so pre-existing stacked comments elsewhere in the file are ignored.
"""

import json
import re
import sys

CODE_EXTENSIONS = (
    '.php', '.js', '.jsx', '.ts', '.tsx', '.mjs', '.cjs', '.mts', '.cts',
)

LINE_COMMENT = re.compile(r'\s*//')


def payload_texts(tool_name, tool_input):
    """Return the newly-written text blocks for the given tool call."""
    if tool_name == 'Write':
        return [tool_input.get('content') or '']
    if tool_name == 'Edit':
        return [tool_input.get('new_string') or '']
    if tool_name == 'MultiEdit':
        return [edit.get('new_string') or '' for edit in tool_input.get('edits') or []]
    return []


def first_stacked_run(text):
    """Return the (start, end) 1-based line numbers of the first `//` stack, or None."""
    run_start = None
    for index, line in enumerate(text.split('\n'), start=1):
        if LINE_COMMENT.match(line):
            if run_start is None:
                run_start = index
            elif index - run_start >= 1:
                return run_start, index
        else:
            run_start = None
    return None


def main():
    try:
        event = json.load(sys.stdin)
    except (json.JSONDecodeError, ValueError):
        sys.exit(0)

    tool_input = event.get('tool_input') or {}
    file_path = (tool_input.get('file_path') or '').lower()
    if not file_path.endswith(CODE_EXTENSIONS):
        sys.exit(0)

    for text in payload_texts(event.get('tool_name', ''), tool_input):
        hit = first_stacked_run(text)
        if hit:
            sys.stderr.write(
                'Blocked by comment policy: two consecutive `//` comment lines '
                f'(lines {hit[0]} and {hit[1]} of this edit). '
                'Never stack `//` lines — use one `//` line, or `/* ... */` for '
                'a multi-line comment.\n'
                'Before reformatting, reconsider the comment itself: keep one '
                'only when the code cannot speak for itself — a constraint, a '
                'non-obvious "why", or a gotcha — never to restate what the code '
                'does. When one is warranted, distill it to its essence, ideally '
                'a single line.\n'
            )
            sys.exit(2)

    sys.exit(0)


if __name__ == '__main__':
    main()
