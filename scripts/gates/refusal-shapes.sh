#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A refusal the screen words is a violation with its reason: `DomainRule::refused` or `::conflict`, on the field it
# names or on the whole request, read by the web's one reader (`refusalsIn`). A body of its own, `{code, params,
# message}`, needs a reader of its own and drifts from the rest: a product photo and an attachment put back answered
# that way while the same file's upload answered a violation. Every `new JsonResponse([...'code' => ...` in api/src is
# refused, but where its line or the one above says why the shape is its own: `// refusal-shape: <why>` (a declaration
# answering the list of what is missing beside its reason). A floor of JSON answers read reds a gate gone blind.
# Usage: refusal-shapes.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
[[ -d "$root/api/src" ]] || { echo "refusal-shapes: FAIL — found no api/src under $root"; exit 1; }
REFUSAL_SHAPES_ROOT=$root REFUSAL_SHAPES_FLOOR=${REFUSAL_SHAPES_FLOOR:-160} python3 - <<'PY'
import os
import re
import sys

root = os.environ['REFUSAL_SHAPES_ROOT']
floor = int(os.environ['REFUSAL_SHAPES_FLOOR'])
ANSWER = re.compile(r'new\s+JsonResponse\(')
CODE_BODY = re.compile(r'new\s+JsonResponse\(\s*\[\s*([\'"])code\1\s*=>')
SAID = re.compile(r'//\s*refusal-shape:\s*\S')


def comment(line: str) -> bool:
    code = line.lstrip()
    return code.startswith(('*', '/*', '//')) or (code.startswith('#') and not code.startswith('#['))


problems, read = [], 0
for directory, _, names in os.walk(os.path.join(root, 'api/src')):
    for name in names:
        if not name.endswith('.php'):
            continue
        path = os.path.join(directory, name)
        src = open(path, encoding='utf-8').read()
        lines = src.split('\n')
        for m in ANSWER.finditer(src):
            n = src.count('\n', 0, m.start())
            if comment(lines[n]):
                continue
            read += 1
            if not CODE_BODY.match(src, m.start()):
                continue
            if SAID.search(lines[n]) or (n > 0 and SAID.search(lines[n - 1])):
                continue
            problems.append(f'{os.path.relpath(path, root)}:{n + 1}: {lines[n].strip()[:100]}')

if read < floor:
    problems.append(f'read {read} JSON answers in api/src, under the floor of {floor}: the answers moved somewhere this gate does not read')
if problems:
    print(f'refusal-shapes: FAIL — {len(problems)} refusal(s) answer a body of their own (throw DomainRule::refused or ::conflict, a violation with its reason, or say why with // refusal-shape: <why>):')
    print('\n'.join('  ' + p for p in problems))
    sys.exit(1)
print(f'refusal-shapes: OK — every refusal with a code is a violation with its reason, or says why not ({read} JSON answers read)')
PY
