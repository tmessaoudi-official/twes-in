#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every `docker compose … run` a page asks a person to type reads no input: pasted as one block with the lines after it,
# a run without `< /dev/null` takes those lines as its own input and they never run, while the block reads as done. A
# shop's restore is pasted exactly so. Read here: the pages under docs/ (not the plans and research below them) and
# TODO.md; a command is what sits in an inline code span (wrapped lines joined) or a fenced block (lines ending in `\`
# joined), and the docs' elisions count (`… run`, `$B run`). A run fed by a pipe already has its input. One that is a
# shape named in prose, never pasted, says why on its line or the one above: `<!-- run-input: <why> -->`.
# Usage: docs-run-lines.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
# The restore steps of RUNBOOK.md and START.md alone make more than this: losing them reds.
floor=6
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "docs-run-lines: unknown argument $1" >&2; exit 2 ;;
  esac
done
[[ -d "$root/docs" ]] || { echo "docs-run-lines: FAIL — found no docs/ under $root"; exit 1; }

python3 - "$root" "$floor" <<'PY'
import pathlib, re, sys

root, floor = pathlib.Path(sys.argv[1]), int(sys.argv[2])
pages = sorted(root.glob('docs/*.md')) + ([root / 'TODO.md'] if (root / 'TODO.md').is_file() else [])
# What starts a compose command as the docs write it, then its global options, then `run`.
run = re.compile(r'(?:docker[ -]compose|…|\$\{?[A-Za-z_]\w*\}?)(?:\s+-{1,2}[\w-]+(?:[ =](?!-)\S+)?)*\s+run\b')
reads_nothing = re.compile(r'(?:^|\s)<\s*/dev/null')
said = re.compile(r'<!--\s*run-input:\s*[^\s-]')

def commands(lines):
    """Yields (first line number, text) for each code span or fenced logical line."""
    fenced, held, start = False, '', 0
    open_span, span_start = None, 0
    for n, line in enumerate(lines, 1):
        if line.lstrip().startswith('```'):
            fenced = not fenced
            open_span = None
            continue
        if fenced:
            body = line.rstrip()
            if not held and body.lstrip().startswith('#'):
                continue
            if not held:
                start = n
            if body.endswith('\\'):
                held += body[:-1] + ' '
                continue
            yield start, held + body
            held = ''
            continue
        if not line.strip():
            open_span = None
            continue
        rest = line
        while rest:
            if open_span is None:
                at = rest.find('`')
                if at < 0:
                    break
                open_span, span_start, rest = '', n, rest[at + 1:]
            else:
                at = rest.find('`')
                if at < 0:
                    open_span += rest.rstrip('\n') + ' '
                    break
                yield span_start, open_span + rest[:at]
                open_span, rest = None, rest[at + 1:]

problems, found = [], 0
for page in pages:
    lines = page.read_text(encoding='utf-8').splitlines()
    name = page.relative_to(root).as_posix()
    for n, text in commands(lines):
        for segment in re.split(r';|&&|\|\|', text):
            match = run.search(segment)
            if not match:
                continue
            found += 1
            piped = '|' in segment[:match.start()]
            marked = said.search(lines[n - 1]) or (n > 1 and said.search(lines[n - 2]))
            if not (piped or reads_nothing.search(segment[match.end():]) or marked):
                problems.append(f'{name}:{n}: {segment.strip()[:110]}')

if problems:
    print(f'docs-run-lines: FAIL — {len(problems)} compose run line(s) that would read the lines pasted after them '
          '(end it with < /dev/null, or say why with <!-- run-input: <why> -->):')
    print('\n'.join('  ' + p for p in problems))
    sys.exit(1)
if found < floor:
    print(f'docs-run-lines: FAIL — found {found} compose run lines, under the floor of {floor}: the reading has stopped finding them')
    sys.exit(1)
print(f'docs-run-lines: OK — {found} compose run lines in {len(pages)} pages, each reading no input or saying why')
PY
