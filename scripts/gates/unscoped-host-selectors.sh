#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# A component declared ViewEncapsulation.None hands its styles to the page as written: Angular rewrites `:host`,
# `:host-context()` and `::ng-deep` only for an encapsulated component, so in an unscoped stylesheet they reach the
# browser untouched and match nothing. The signed-out layout's phone padding vanished that way, unseen until a phone
# e2e measured the brand. An unscoped stylesheet says `:root[…] &` or a class of its own instead.
#
# Usage: unscoped-host-selectors.sh [--root DIR] [--min N]
set -euo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
# Two components are unscoped today, the signed-out layout and the record columns: losing either one reds. Lowered, with
# its reason, in the change that encapsulates one of them.
MIN=2
while [[ $# -gt 0 ]]; do
  case "$1" in
    --root) ROOT=$2; shift 2 ;;
    --min) MIN=$2; shift 2 ;;
    *) echo "unscoped-host-selectors: unknown argument $1" >&2; exit 2 ;;
  esac
done

python3 - "$ROOT" "$MIN" <<'PY'
import os, re, subprocess, sys

root, floor = sys.argv[1], int(sys.argv[2])
files = subprocess.run(['git', '-C', root, 'ls-files', '--cached', '--others', '--exclude-standard', 'web/src/app'],
                       capture_output=True, text=True, check=True).stdout.split()
components = [f for f in files if f.endswith('.ts') and not f.endswith('.spec.ts')]
selector = re.compile(r'(:host-context\b|:host\b|::ng-deep\b)')
found, unscoped = [], 0

def scan(path, text, first_line=1):
    for number, line in enumerate(text.splitlines(), first_line):
        code = re.sub(r'//.*$', '', line)
        for match in selector.finditer(code):
            found.append(f'{path}:{number} {match.group(1)}')

for path in components:
    full = os.path.join(root, path)
    text = open(full, encoding='utf-8').read()
    if not re.search(r'encapsulation\s*:\s*ViewEncapsulation\.None', text):
        continue
    unscoped += 1
    folder = os.path.dirname(path)
    urls = re.findall(r"styleUrl\s*:\s*'([^']+)'", text)
    for block in re.findall(r'styleUrls\s*:\s*\[([^\]]*)\]', text):
        urls += re.findall(r"'([^']+)'", block)
    for url in urls:
        sheet = os.path.normpath(os.path.join(folder, url))
        scan(sheet, open(os.path.join(root, sheet), encoding='utf-8').read())
    for match in re.finditer(r'styles\s*:\s*(\[[^\]]*\]|`[^`]*`)', text, re.S):
        scan(path, match.group(1), text.count('\n', 0, match.start()) + 1)

if found:
    print('unscoped-host-selectors: a selector Angular leaves as written in an unscoped stylesheet, which matches nothing:')
    for line in found:
        print(f'  {line}')
    print('  An unscoped stylesheet reads the page itself: `:root[data-window=\'compact\'] &`, or a class of its own.')
    sys.exit(1)
if unscoped < floor:
    print(f'unscoped-host-selectors: {unscoped} unscoped component(s) found, fewer than the {floor} expected: the reading lost them.')
    sys.exit(1)
print(f'unscoped-host-selectors: OK — {unscoped} unscoped component(s), no :host, :host-context or ::ng-deep in their styles')
PY
