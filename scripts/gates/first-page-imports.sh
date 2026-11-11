#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What the first page holds is what `web/src/main.ts` reaches through static value imports: esbuild puts every one of
# them in the bundle each visit loads before anything is drawn, under `web/angular.json`'s ceiling. A package the
# application only opens when a person asks for it stays off that page: a service the start reaches fetches it with
# `await import()` when asked. Four such services kept Material's dialog (about 35 kB) on every first page only to inject
# it, while each already fetched its dialog's component on demand. Walks the relative value imports from the start (a
# type import is erased, an `import()` is a chunk of its own) and refuses any file reached that imports a package listed
# below. Fewer files walked than the floor reds, so a walk that stopped reading is seen. Packages are not walked: one
# reaching a listed package through another package is the ceiling's to catch.
# Usage: first-page-imports.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
# The walk read 124 files, every one the build's own metafile lists for the first page and two its minifier dropped;
# a walk reading a fifth fewer stopped somewhere.
floor=100
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "first-page-imports: unknown argument $1"; exit 2 ;;
  esac
done
[[ -f "$root/web/src/main.ts" ]] || { echo "first-page-imports: FAIL — no web/src/main.ts under $root"; exit 1; }
python3 -I - "$root" "$floor" <<'PY'
import os, re, sys

root, floor = sys.argv[1], int(sys.argv[2])
# Each package opened only when asked for, with how the first page's code reaches it instead.
ON_DEMAND = {
    '@angular/material/dialog': 'a dialog opens when asked: open it through LazyDialog (shared/ui/lazy-dialog.ts)',
    '@angular/cdk/dialog': 'a dialog opens when asked: open it through LazyDialog (shared/ui/lazy-dialog.ts)',
}
CLAUSE = r"(\{[^}]*\}|\*(?:\s+as\s+\w+)?|[\w$]+(?:\s*,\s*(?:\{[^}]*\}|\*\s+as\s+\w+))?)"
STATEMENT = re.compile(
    r"^[ \t]*(?P<kind>import|export)\s+(?P<type>type\s+)?" + CLAUSE + r"\s*from\s*['\"](?P<spec>[^'\"]+)['\"]",
    re.M,
)
BARE = re.compile(r"^[ \t]*import\s*['\"](?P<spec>[^'\"]+)['\"]", re.M)

def erased(match):
    """A type import leaves nothing in the compiled page; so does a list naming types only."""
    if match.group('type'):
        return True
    clause = match.group(3).strip()
    if not clause.startswith('{'):
        return False
    names = [name.strip() for name in clause[1:-1].split(',') if name.strip()]
    return bool(names) and all(name.startswith('type ') for name in names)

def resolve(source, spec):
    base = os.path.normpath(os.path.join(os.path.dirname(source), spec))
    for candidate in (base + '.ts', os.path.join(base, 'index.ts'), base):
        if os.path.isfile(os.path.join(root, candidate)):
            return candidate
    return None

start = 'web/src/main.ts'
seen, queue, problems = {start}, [start], []
while queue:
    source = queue.pop()
    if not source.endswith('.ts'):
        continue
    with open(os.path.join(root, source), encoding='utf-8') as handle:
        text = handle.read()
    imports = [(m.group('spec'), erased(m)) for m in STATEMENT.finditer(text)]
    imports += [(m.group('spec'), False) for m in BARE.finditer(text)]
    for spec, type_only in imports:
        if type_only:
            continue
        if spec.startswith('.'):
            target = resolve(source, spec)
            if target is None:
                problems.append(f'{source}: {spec} resolves to no file')
            elif target not in seen:
                seen.add(target)
                queue.append(target)
            continue
        for package, why in ON_DEMAND.items():
            if spec == package or spec.startswith(package + '/'):
                problems.append(f'{source}: {spec}: on the first page, {why}')

if len(seen) < floor:
    problems.append(f'walked {len(seen)} files from {start}, fewer than the floor of {floor}: the walk stopped reading')
if problems:
    print(f'first-page-imports: FAIL — {len(problems)} problem(s):')
    for problem in sorted(problems):
        print(f'  {problem}')
    sys.exit(1)
print(f'first-page-imports: OK — {len(seen)} files on the first page, none imports a package it opens only when asked')
PY
