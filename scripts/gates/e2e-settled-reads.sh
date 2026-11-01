#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A browser test reads what the page shows only once it has settled: `evaluateAll`, `allTextContents` and
# `allInnerTexts` wait for nothing, and `textContent()` and `innerText()` wait for the element, not for its content, so
# a read straight after a reload or a click returns `[]` or the text before the answer came, and the case passes or
# fails by the machine's load. Before such a read, in the same test, the same locator is asserted with a waiting
# assertion: `expect(<locator>).toHave…` (a count, a text, a value), `.toContainText`, `.not.toBeEmpty`, or an
# `expect.poll` over it. `toBeVisible` does not count: a visible title can still be empty. A read whose settling the
# gate cannot see (a helper called later, a list read whole on purpose) says why on its line or the one above:
# `// settled-read: <why>`.
# Usage: e2e-settled-reads.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
# The reads the specs make today, less a few: losing a whole spec file's worth reds.
floor=35
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "e2e-settled-reads: unknown argument $1" >&2; exit 2 ;;
  esac
done
[[ -d "$root/web/e2e" ]] || { echo "e2e-settled-reads: FAIL — found no web/e2e under $root"; exit 1; }

python3 - "$root" "$floor" <<'PY'
import pathlib, re, sys

root, floor = pathlib.Path(sys.argv[1]), int(sys.argv[2])
read = re.compile(r'\.(evaluateAll|textContent|innerText|allTextContents|allInnerTexts)\(')
boundary = re.compile(r'^[ \t]*(?:(?:test|it)(?:\.(?:only|skip|fixme|fail|slow))?\(|(?:export\s+)?(?:async\s+)?function\b)', re.M)
said = re.compile(r'//\s*settled-read:\s*\S')
stops = set('=,;{}!&|?:+')


def receiver(text, end):
    """The locator expression a read is called on, walking back over a chain that may span lines."""
    i, depth = end - 1, 0
    while i >= 0:
        c = text[i]
        if c in '\'"`':
            j = i - 1
            while j >= 0 and not (text[j] == c and text[j - 1] != '\\'):
                j -= 1
            i = j - 1
            continue
        if c in ')]':
            depth += 1
        elif c in '([':
            if depth == 0:
                break
            depth -= 1
        elif depth == 0 and c in stops:
            break
        elif depth == 0 and c.isspace():
            j = i
            while j >= 0 and text[j].isspace():
                j -= 1
            if text[i + 1:].lstrip().startswith('.') or (j >= 0 and text[j] == '.'):
                i = j
                continue
            break
        i -= 1
    return re.sub(r'\s+', '', text[i + 1:end]), i + 1


def settled(before, recv):
    flat = re.sub(r'\s+', '', before)
    r = re.escape(recv)
    asserted = re.compile(r'expect\(' + r + r'(?:,(?:[^()]|\([^()]*\))*)?\)(?:\.not)?\.(?:toHave\w*|toContainText|toBeEmpty)\(')
    polled = re.compile(r'expect\.poll\((?:(?!expect[.(]).){0,600}?' + r)
    return bool(asserted.search(flat) or polled.search(flat))


problems, found = [], 0
for spec in sorted((root / 'web/e2e').rglob('*.ts')):
    text = spec.read_text(encoding='utf-8')
    lines = text.split('\n')
    name = spec.relative_to(root).as_posix()
    starts = [m.start() for m in boundary.finditer(text)]
    for m in read.finditer(text):
        found += 1
        n = text.count('\n', 0, m.start()) + 1
        recv, recv_start = receiver(text, m.start())
        # A reason on the read's line, on any line of its chain, or on the line above where the chain starts.
        first = text.count('\n', 0, recv_start) + 1
        if any(said.search(lines[k - 1]) for k in range(max(first - 1, 1), n + 1)):
            continue
        begin = max([s for s in starts if s < m.start()], default=0)
        if not recv or not settled(text[begin:m.start()], recv):
            problems.append(f'{name}:{n}: {recv or "?"}.{m.group(1)}()')

if problems:
    print(f'e2e-settled-reads: FAIL — {len(problems)} read(s) of a locator nothing settled first in the test '
          '(assert it with expect(...).toHave…/.not.toBeEmpty or expect.poll before reading, or say why with // settled-read: <why>):')
    print('\n'.join('  ' + p for p in problems))
    sys.exit(1)
if found < floor:
    print(f'e2e-settled-reads: FAIL — found {found} reads, under the floor of {floor}: the reading has stopped finding them')
    sys.exit(1)
print(f'e2e-settled-reads: OK — {found} reads in web/e2e, each after its locator settled or saying why')
PY
