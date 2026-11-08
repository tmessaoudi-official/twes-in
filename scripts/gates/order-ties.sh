#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# An order whose last key is a moment ties: two rows written in one second, two documents of one day, come back in
# either order, so a list pages them twice or not at all and a test reading the first of them passes one run in four
# (InvoicesFromDeliveryNotesTest). Every order in api/src whose last key is a moment (`createdAt`, `ends_at`, `at`,
# `issue_date`, `day`) is refused: the query builder chain's last orderBy/addOrderBy, and each ORDER BY written in a
# SQL or DQL string, an inner query's on its own. Its id goes last, or the line or the one above says why the moment ties
# nothing: `// order-tie: <why>` (a query grouping by the day it orders by). A floor of orders read reds a gate gone blind.
# Usage: order-ties.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
[[ -d "$root/api/src" ]] || { echo "order-ties: FAIL — found no api/src under $root"; exit 1; }
ORDER_TIES_ROOT=$root ORDER_TIES_FLOOR=${ORDER_TIES_FLOOR:-150} python3 - <<'PY'
import os
import re
import sys

root = os.environ['ORDER_TIES_ROOT']
floor = int(os.environ['ORDER_TIES_FLOOR'])
MOMENT = re.compile(r'(^|\.)([a-z_]*_at|at|[a-z][A-Za-z]*At|[a-z_]*_date|[a-z][A-Za-z]*Date|day)$')
END = re.compile(r'\b(LIMIT|OFFSET|FOR\s+UPDATE|ROWS\s+BETWEEN|RANGE\s+BETWEEN)\b', re.I)
SAID = re.compile(r'//\s*order-tie:\s*\S')


def comment(line: str) -> bool:
    code = line.lstrip()
    return code.startswith(('*', '/*', '//')) or (code.startswith('#') and not code.startswith('#['))


def clause(src: str, start: int) -> str:
    """The keys of one ORDER BY: up to the string's end, its own closing parenthesis or a LIMIT."""
    depth, out = 0, []
    for ch in src[start:]:
        if ch in '\'"':
            break
        if ch == '(':
            depth += 1
        elif ch == ')':
            if depth == 0:
                break
            depth -= 1
        out.append(ch)
    text = ''.join(out)
    end = END.search(text)
    return text[:end.start()] if end else text


def last_key(keys: str) -> str:
    depth, parts, cur = 0, [], ''
    for ch in keys:
        depth += (ch == '(') - (ch == ')')
        if ch == ',' and depth == 0:
            parts.append(cur)
            cur = ''
        else:
            cur += ch
    parts.append(cur)
    key = re.sub(r'\s+(ASC|DESC|NULLS|COLLATE)\b.*$', '', parts[-1].strip(), flags=re.I | re.S)
    return key.strip()


problems, read = [], 0
for directory, _, names in os.walk(os.path.join(root, 'api/src')):
    for name in names:
        if not name.endswith('.php'):
            continue
        path = os.path.join(directory, name)
        src = open(path, encoding='utf-8').read()
        lines = src.split('\n')

        def refuse(offset: int, key: str) -> None:
            n = src.count('\n', 0, offset)
            if comment(lines[n]) or SAID.search(lines[n]) or (n > 0 and SAID.search(lines[n - 1])):
                return
            problems.append(f'{os.path.relpath(path, root)}:{n + 1}: ends on {key}: {lines[n].strip()[:100]}')

        for m in re.finditer(r'ORDER\s+BY\s+', src):
            line = lines[src.count('\n', 0, m.start())]
            if comment(line):
                continue
            read += 1
            key = last_key(clause(src, m.end()))
            if MOMENT.search(key):
                refuse(m.start(), key)
        # A builder chain: its last orderBy or addOrderBy, up to the end of its statement.
        for m in re.finditer(r'->(?:orderBy|addOrderBy)\(\s*([\'"])([^\'"]+)\1', src):
            rest = src[m.end():src.find(';', m.end())]
            if re.search(r'->addOrderBy\(', rest):
                continue
            read += 1
            if MOMENT.search(m.group(2).strip()):
                refuse(m.start(), m.group(2).strip())

if read < floor:
    problems.append(f'read {read} orders in api/src, under the floor of {floor}: the orders moved somewhere this gate does not read')
if problems:
    print(f'order-ties: FAIL — {len(problems)} order(s) end on a moment, which two rows can share (add its id last, or say why it ties nothing with // order-tie: <why>):')
    print('\n'.join('  ' + p for p in problems))
    sys.exit(1)
print(f'order-ties: OK — no order in api/src ends on a moment ({read} orders read)')
PY
