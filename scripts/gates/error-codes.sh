#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Two refusal canons (docs/SPEC.md § 7): what was sent is refused by a violation with its reason, on the field it names
# (`DomainRule::refused`, read by the web's `refusalsIn`); the act or the session's state is refused by `{error: <code>}`,
# read by the screen of that act. A code of the second kind is DECLARED, in `error-codes.allowed` beside this gate, with
# what it refuses: `act`, it stays an `{error}`; `moving`, it refuses a field and is on its way to a violation. A new
# `{error}` code is one more line there, read in review; a code nothing answers any more leaves it; the moving ones are
# held under a ceiling that only comes down.
# Every `new JsonResponse([ 'error' => <code>` in api/src is read, over as many lines as it is written: a literal, a
# constant of the class (`self::X`) or of a class the tree holds once (`Class::X`) is resolved here; a code made at run
# time (`$refused->reason`) names what it may be in a comment on its line or the one above, `// error-codes: <tokens>`,
# each token a code, `Class::NAME`, or a `Class` standing for every string constant it declares. A comment naming codes
# goes stale when the exception gains a reason and the comment does not: naming the class keeps it current.
# It reads `error` as the body's FIRST key, as every such body here is written; a body naming it later escapes it.
# A floor of answers read reds a gate gone blind.
# Usage: error-codes.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
[[ -d "$root/api/src" ]] || { echo "error-codes: FAIL — found no api/src under $root"; exit 1; }
[[ -f "$root/scripts/gates/error-codes.allowed" ]] || { echo "error-codes: FAIL — found no scripts/gates/error-codes.allowed under $root"; exit 1; }
ERROR_CODES_ROOT=$root ERROR_CODES_FLOOR=${ERROR_CODES_FLOOR:-90} ERROR_CODES_MOVING_CEILING=${ERROR_CODES_MOVING_CEILING:-6} python3 - <<'PY'
import os
import re
import sys

root = os.environ['ERROR_CODES_ROOT']
floor = int(os.environ['ERROR_CODES_FLOOR'])
ceiling = int(os.environ['ERROR_CODES_MOVING_CEILING'])
ANSWER = re.compile(r'new\s+JsonResponse\(\s*\[\s*([\'"])error\1\s*=>\s*([^,\]]+)')
LITERAL = re.compile(r'''^(['"])([a-z][a-z0-9_]*)\1$''')
CONSTANT = re.compile(r'^(self|static|[A-Z]\w*)::([A-Z][A-Z0-9_]*)$')
SAID = re.compile(r'//\s*error-codes:\s*(.+)$')
problems = []


def comment(line: str) -> bool:
    code = line.lstrip()
    return code.startswith(('*', '/*', '//')) or (code.startswith('#') and not code.startswith('#['))


sources = {}
for directory, _, names in os.walk(os.path.join(root, 'api/src')):
    for name in names:
        if name.endswith('.php'):
            path = os.path.join(directory, name)
            sources[path] = open(path, encoding='utf-8').read()
by_class = {}
for path in sources:
    by_class.setdefault(os.path.basename(path)[:-4], []).append(path)


def constants_of(src: str) -> dict:
    return dict(re.findall(r'const\s+(?:string\s+)?([A-Z][A-Z0-9_]*)\s*=\s*[\'"]([^\'"]*)[\'"]', src))


def class_constants(name: str):
    paths = by_class.get(name, [])
    return constants_of(sources[paths[0]]) if len(paths) == 1 else None


def resolve(expression: str, src: str):
    """The codes an expression stands for, or None when only a comment can say."""
    literal = LITERAL.match(expression)
    if literal:
        return [literal.group(2)]
    constant = CONSTANT.match(expression)
    if constant:
        owner, name = constant.groups()
        table = constants_of(src) if owner in ('self', 'static') else class_constants(owner)
        if table is not None and name in table:
            return [table[name]]
    return None


def said(tokens: str, src: str, where: str):
    codes = []
    for token in re.split(r'[\s,]+', tokens.strip()):
        if not token:
            continue
        if re.fullmatch(r'[a-z][a-z0-9_]*', token):
            codes.append(token)
        elif re.fullmatch(r'[A-Z]\w*', token):
            table = class_constants(token)
            if not table:
                problems.append(f'{where}: {token} names no class the tree holds once, with string constants')
                continue
            codes.extend(table.values())
        else:
            found = resolve(token, src)
            if found is None:
                problems.append(f'{where}: {token} names no constant the tree holds')
                continue
            codes.extend(found)
    return codes


declared, kinds = {}, {}
allowed = os.path.join(root, 'scripts/gates/error-codes.allowed')
for number, line in enumerate(open(allowed, encoding='utf-8').read().split('\n'), 1):
    text = line.strip()
    if not text or text.startswith('#'):
        continue
    parts = text.split(None, 2)
    if len(parts) < 3 or parts[1] not in ('act', 'moving') or not re.fullmatch(r'[a-z][a-z0-9_]*', parts[0]):
        problems.append(f'scripts/gates/error-codes.allowed:{number}: write <code> act|moving <what it refuses>')
        continue
    if parts[0] in declared:
        problems.append(f'scripts/gates/error-codes.allowed:{number}: {parts[0]} is declared twice')
        continue
    declared[parts[0]] = 0
    kinds[parts[0]] = parts[1]

read = 0
for path, src in sorted(sources.items()):
    lines = src.split('\n')
    relative = os.path.relpath(path, root)
    for m in ANSWER.finditer(src):
        n = src.count('\n', 0, m.start())
        if comment(lines[n]):
            continue
        read += 1
        where = f'{relative}:{n + 1}'
        expression = m.group(2).strip()
        codes = resolve(expression, src)
        if codes is None:
            last = src.count('\n', 0, m.end())
            told = next((s for s in (SAID.search(lines[i]) for i in (n - 1, n, last) if i >= 0) if s), None)
            if told is None:
                problems.append(f'{where}: {expression} is made at run time: name the codes it may be, // error-codes: <codes or Class>')
                continue
            codes = said(told.group(1), src, where)
        for code in codes:
            if code in declared:
                declared[code] += 1
            else:
                problems.append(f'{where}: {code} is no code of scripts/gates/error-codes.allowed (a field refused is a violation: DomainRule::refused; an act refused, declare it there)')

for code, uses in declared.items():
    if uses == 0:
        problems.append(f'{code} is declared in scripts/gates/error-codes.allowed and no answer gives it: take it out')
moving = sorted(code for code, kind in kinds.items() if kind == 'moving')
if len(moving) > ceiling:
    problems.append(f'{len(moving)} codes are moving to violations, over the ceiling of {ceiling}: a field refused is a violation from the start')
if read < floor:
    problems.append(f'read {read} {{error}} answers in api/src, under the floor of {floor}: the answers moved somewhere this gate does not read')
if problems:
    print(f'error-codes: FAIL — {len(problems)} problem(s):')
    print('\n'.join('  ' + p for p in problems))
    sys.exit(1)
print(f'error-codes: OK — {read} {{error}} answers read, {len(declared) - len(moving)} codes refusing an act, {len(moving)} moving to violations ({", ".join(moving) or "none"})')
PY
