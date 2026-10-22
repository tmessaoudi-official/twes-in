#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What a person typed keeps no invisible character at its ends: PHP's one-argument `trim()` takes the ASCII spaces only,
# and a paste brings no-break and zero-width spaces, byte-order and direction marks, so a reference, a number or a
# matricule so written is another one. Every one-argument `trim(` in api/src goes through
# `App\Shared\Domain\Service\TypedText::trimmed()`, or its line says why not: `// trim: <why>`. A `trim()` given its
# own characters (`trim($digits, '0')`) is another operation and is not read. A floor reds when the calls are no
# longer found.
# Usage: typed-trims.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
FLOOR=${TYPED_TRIMS_FLOOR:-150}

cd "$root" && FLOOR=$FLOOR python3 -I - <<'PY'
import glob, os, re, sys

floor = int(os.environ['FLOOR'])
start = re.compile(r'(?<![\w$>:\\])trim\(')
problems, typed, reasons = [], 0, 0


def top_level_comma(src, i):
    depth, j, quote = 0, i, None
    while j < len(src):
        c = src[j]
        if quote:
            if c == '\\':
                j += 2
                continue
            if c == quote:
                quote = None
        elif c in '\'"':
            quote = c
        elif c in '([{':
            depth += 1
        elif c in ')]}':
            depth -= 1
            if depth == 0:
                return False
        elif c == ',' and depth == 1:
            return True
        j += 1
    return False


for path in sorted(glob.glob('api/src/**/*.php', recursive=True)):
    src = open(path, encoding='utf-8').read()
    typed += src.count('TypedText::trimmed(') + src.count('TypedText::trimmedOrNull(')
    for m in start.finditer(src):
        line_start = src.rfind('\n', 0, m.start()) + 1
        line_end = src.find('\n', m.start())
        line = src[line_start:line_end if line_end >= 0 else None]
        if line.lstrip().startswith(('*', '//', '#')) or top_level_comma(src, m.end() - 1):
            continue
        if re.search(r'//[ \t]*trim:[ \t]*\S', line):
            reasons += 1
            continue
        problems.append(f'{path}:{src.count(chr(10), 0, m.start()) + 1} {line.strip()}')

if typed < floor:
    problems.append(f'found {typed} calls to TypedText, fewer than the floor of {floor}: the sources are no longer read')
if problems:
    print(f'typed-trims: FAIL — {len(problems)} trim() leaving invisible characters at a typed value\'s ends (TypedText::trimmed(), or // trim: <why>):')
    for problem in problems:
        print(f'  {problem}')
    sys.exit(1)
print(f'typed-trims: OK — {typed} typed values trimmed through TypedText, {reasons} plain trim() saying why')
PY
