#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A dependency's install script runs only once reviewed (docs/SPEC.md § 7, the install scripts denied): web/package.json's
# allowScripts names each package that brings one, and every project install, npm ci or npm install, in the Makefile,
# an image, a container's script, a workflow or a script, says --strict-allow-scripts, so a package bringing a new one
# stops the install, naming itself, instead of running with a notice. The flag rather than an .npmrc: .gitignore keeps
# every .npmrc out of the tree, since one may hold a registry token.
# Not read: a global install (--global, -g: npm's own docs give it no project policy), a comment, and the tests'
# fixtures (scripts/tests, scripts/gates/tests). An install is read with its continuation lines.
# A floor: the Makefile's, the web image's and the live container's installs, so an install moved out of sight reds.
# Usage: npm-install-scripts.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
floor=3

mapfile -t lines < <(python3 -I - "$root" "$floor" <<'PY'
import json, os, re, sys
root, floor = sys.argv[1], int(sys.argv[2])
INSTALL = re.compile(r'\bnpm\s+(ci|install|i)\b(?P<rest>[^;&|)]*)')

def files():
    out = []
    if os.path.exists(os.path.join(root, 'Makefile')):
        out.append('Makefile')
    for top in ('infra', 'scripts', os.path.join('.github', 'workflows')):
        for dp, dn, fn in os.walk(os.path.join(root, top)):
            rel_dir = os.path.relpath(dp, root)
            if rel_dir.startswith(('scripts/tests', 'scripts/gates/tests')):
                continue
            for f in fn:
                if f == 'Dockerfile' or f.endswith(('.Dockerfile', '.sh', '.yml', '.yaml')):
                    out.append(os.path.join(rel_dir, f))
    return sorted(out)

found, problems = 0, []
for rel in files():
    lines = open(os.path.join(root, rel), encoding='utf-8').read().split('\n')
    for n, line in enumerate(lines, 1):
        code = line.split('#')[0] if not rel.endswith(('.yml', '.yaml')) else line
        if line.lstrip().startswith('#'):
            continue
        for m in INSTALL.finditer(code):
            # The command's own words: up to the next command, read across continuation lines.
            rest, k = m.group('rest'), n
            while rest.rstrip().endswith('\\') and k < len(lines):
                rest = rest.rstrip()[:-1] + ' ' + re.split(r'[;&|)]', lines[k])[0]
                k += 1
            words = rest.split()
            if '--global' in words or '-g' in words:
                continue
            found += 1
            if '--strict-allow-scripts' not in words:
                problems.append('%s:%d runs npm %s without --strict-allow-scripts: an unreviewed install script would run'
                                % (rel, n, m.group(1)))

manifest = os.path.join(root, 'web', 'package.json')
try:
    policy = json.load(open(manifest, encoding='utf-8')).get('allowScripts')
except (OSError, ValueError) as error:
    policy = None
    problems.append('web/package.json could not be read: %s' % error)
if not isinstance(policy, dict) or not policy:
    problems.append('web/package.json declares no allowScripts: --strict-allow-scripts would stop every install that brings a script')
if found < floor:
    problems.append('found %d project install(s), fewer than the floor of %d: an install moved where this gate does not read' % (found, floor))
print('found=%d' % found)
for p in problems:
    print(p)
PY
)

found=${lines[0]#found=}
problems=("${lines[@]:1}")
if ((${#problems[@]})); then
  printf 'npm-install-scripts: FAIL — %d problem(s):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'npm-install-scripts: OK — %s installs, each stopping on an install script web/package.json'"'"'s allowScripts does not name\n' "$found"
