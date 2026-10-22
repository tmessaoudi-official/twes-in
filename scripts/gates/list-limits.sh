#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every list a request hands the API is bounded: a document of 10,000 lines held a worker for 15 seconds. A property of an
# API Platform resource holding an array that a request writes (a write group, or a constraint, which runs only on a
# write) carries `#[ListLimit(…)]`, the operator's limit (`app.list_limits`), or `#[Assert\Count(max: …)]`, or a line in
# its block saying why it carries none: `// count: <why>`. A list inside each entry of another (a line's taxes) is the
# entry constraint's own and is not seen here. A floor reds when the resources are no longer read.
# Usage: list-limits.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
FLOOR=${LIST_LIMITS_FLOOR:-35}

cd "$root" && FLOOR=$FLOOR python3 -I - <<'PY'
import glob, os, re, sys

floor = int(os.environ['FLOOR'])
files = sorted(glob.glob('api/src/**/ApiPlatform/Resource/**/*.php', recursive=True))
written = re.compile(r"Groups\(\[[^\]]*(WRITE|CREATE|REVISE|UPDATE|INPUT|write|create|revise)")
problems, checked, reasons = [], 0, 0
for path in files:
    lines = open(path, encoding='utf-8').read().split('\n')
    for i, line in enumerate(lines):
        if not re.match(r'\s*public \??array \$\w+', line):
            continue
        # The property's block: its attributes and docblock, back to the previous member.
        j, block = i - 1, []
        while j >= 0 and not re.match(r'\s*(public|private|protected|const|\}|\{)|\s*$', lines[j]):
            block.append(lines[j])
            j -= 1
        text = '\n'.join(block)
        if not (written.search(text) or 'Assert\\' in text):
            continue
        checked += 1
        if re.search(r'#\[ListLimit\(|Assert\\Count\([^)]*\bmax:', text):
            continue
        if re.search(r'//[ \t]*count:[ \t]*\S', text):
            reasons += 1
            continue
        name = re.match(r'\s*public \??array \$(\w+)', line).group(1)
        problems.append(f'{path}:{i + 1} ${name}')

if checked < floor:
    problems.append(f'found {checked} written lists on {len(files)} resources, fewer than the floor of {floor}: the resources are no longer read')
if problems:
    print(f'list-limits: FAIL — {len(problems)} written list(s) without a limit (#[ListLimit(…)], Assert\\Count(max: …), or // count: <why> in the block):')
    for problem in problems:
        print(f'  {problem}')
    sys.exit(1)
print(f'list-limits: OK — {checked} written lists on {len(files)} resources: {checked - reasons} bounded, {reasons} saying why not (// count:)')
PY
