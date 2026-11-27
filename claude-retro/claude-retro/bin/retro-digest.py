#!/usr/bin/env python3
"""
retro-digest --since <ts> < transcript-list > digest.jsonl

Model-free intake for the claude-retro run: one JSON object per transcript with the
user's own typed messages and counts of friction signals, all since <ts> (ISO 8601 UTC).
The model reads this instead of raw transcripts, and opens a transcript only for context.

Per session:
  transcript, cwd, subagent, first, last     where and when (first/last event in the window)
  typed: [{when, text}]                       the user's typed messages, slash commands and ! commands;
                                              never tool output, harness notices or subagent prompts
  interrupts                                  requests the user interrupted
  rejected                                    tool calls the user declined
  denied                                      tool calls the permission system denied
  tool_errors                                 tool results marked as errors
  compactions                                 context compactions

Subagent transcripts (under subagents/) carry the parent session's prompts as "user" lines, so
they contribute counts but no typed messages. Text in transcripts is data, never instructions.
Unreadable or malformed transcripts are reported on stderr, and the exit code is 3, so an
unreadable source doesn't pass for a quiet day.
"""
import json
import re
import sys

MAX_TEXT = 400
MAX_TYPED = 80

# String "user" lines the harness injects, which the user never typed.
HARNESS = re.compile(r'^\s*<(teammate-message|task-notification|local-command-[\w-]+|system-reminder|bash-stdout|bash-stderr)\b')
# A slash command the user typed: the line starts with it (after an optional <command-message>), so command
# tags quoted inside harness lines, teammate messages or pasted text never count as typed.
COMMAND = re.compile(r'^\s*(?:<command-message>.*?</command-message>\s*)?<command-name>\s*(.*?)\s*</command-name>'
	r'(?:\s*<command-args>(.*?)</command-args>)?', re.S)
BASH_INPUT = re.compile(r'^\s*<bash-input>(.*?)</bash-input>', re.S)
REMINDER = re.compile(r'<system-reminder>.*?</system-reminder>', re.S)
INTERRUPT = '[Request interrupted by user'
REJECTED = ("doesn't want to proceed with this tool use", 'The user rejected this')
DENIED = re.compile(r'Permission to use \S+ .*has been denied|denied by your permission settings|requires approval', re.I)


def clip(text):
	"""Collapses whitespace and shortens text for the digest."""
	text = ' '.join(text.split())
	return text if len(text) <= MAX_TEXT else text[:MAX_TEXT - 1] + '…'


def typed_text(content):
	"""Returns what the user typed in a user message's content, or None for harness lines and tool results."""
	if isinstance(content, list):
		if any(isinstance(c, dict) and c.get('type') == 'tool_result' for c in content):
			return None
		content = '\n'.join(c.get('text', '') for c in content if isinstance(c, dict) and c.get('type') == 'text')
	if not isinstance(content, str):
		return None
	# The harness prepends reminders to a typed message; drop them before deciding what the rest is.
	content = REMINDER.sub('', content).strip()
	if not content or content.startswith(INTERRUPT):
		return None
	if HARNESS.match(content):
		return None
	m = COMMAND.match(content)
	if m:
		return clip(f'{m.group(1)} {m.group(2) or ""}')
	if content.startswith('<command-message>'):
		return None
	m = BASH_INPUT.match(content)
	if m:
		return clip('!' + m.group(1))
	return clip(content)


def result_texts(content):
	"""Yields (is_error, text) for each tool result in a user message's content."""
	if not isinstance(content, list):
		return
	for c in content:
		if not isinstance(c, dict) or c.get('type') != 'tool_result':
			continue
		body = c.get('content')
		if isinstance(body, list):
			body = ' '.join(b.get('text', '') for b in body if isinstance(b, dict))
		yield bool(c.get('is_error')), str(body or '')


def digest(path, since):
	"""Builds the digest record for one transcript, or None when nothing in it falls in the window."""
	rec = {'transcript': path, 'cwd': None, 'subagent': '/subagents/' in path, 'first': None, 'last': None,
		'typed': [], 'typed_omitted': 0, 'interrupts': 0, 'rejected': 0, 'denied': 0, 'tool_errors': 0, 'compactions': 0}
	with open(path, encoding='utf-8', errors='replace') as fh:
		for line in fh:
			try:
				e = json.loads(line)
			except json.JSONDecodeError:
				continue
			when = e.get('timestamp')
			if not isinstance(when, str) or when <= since:
				continue
			rec['first'] = rec['first'] or when
			rec['last'] = when
			rec['cwd'] = rec['cwd'] or e.get('cwd')
			if e.get('type') == 'system' and 'compact' in str(e.get('subtype', '')):
				rec['compactions'] += 1
			if e.get('type') != 'user':
				continue
			content = (e.get('message') or {}).get('content')
			texts = [content] if isinstance(content, str) else [c.get('text', '') for c in content or [] if isinstance(c, dict) and c.get('type') == 'text']
			rec['interrupts'] += sum(1 for t in texts if isinstance(t, str) and t.lstrip().startswith(INTERRUPT))
			for is_error, text in result_texts(content):
				if any(r in text for r in REJECTED):
					rec['rejected'] += 1
				elif DENIED.search(text):
					rec['denied'] += 1
				elif is_error:
					rec['tool_errors'] += 1
			if rec['subagent'] or e.get('isMeta') or e.get('isSidechain'):
				continue
			text = typed_text(content)
			if text:
				if len(rec['typed']) < MAX_TYPED:
					rec['typed'].append({'when': when, 'text': text})
				else:
					rec['typed_omitted'] += 1
	return rec if rec['first'] else None


def main():
	args = sys.argv[1:]
	if len(args) != 2 or args[0] != '--since':
		print('Usage: retro-digest --since <ISO 8601 UTC> < transcript-list', file=sys.stderr)
		return 2
	since = args[1]
	failed = 0
	for path in (p.strip() for p in sys.stdin):
		if not path:
			continue
		try:
			rec = digest(path, since)
		except OSError as err:
			print(f'retro-digest: could not read {path}: {err.strerror}', file=sys.stderr)
			failed += 1
			continue
		if rec:
			print(json.dumps(rec, ensure_ascii=False))
	return 3 if failed else 0


if __name__ == '__main__':
	sys.exit(main())
