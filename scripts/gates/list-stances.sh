#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What one list gains, every list of its kind is judged for (docs/SPEC.md § 7, 2026-10-16 09:47): only the invoices list
# had a selection among 27, and nothing asked the others. Every list a template draws (`<app-data-list>`) offers, or
# says below why it does not, five things: selection, bulk actions (an `appDataListSelection` template), row actions
# (its descriptor's `actions`, or a button in one of its cells), import (a link to `/imports/…`) and export
# (`<app-list-export>`); the last two belong to the page, the first three to each list on it.
#
# A list is keyed `<template path under web/src/app>#<the descriptor it is given>`, `()` dropped and an `@if (x; as y)`
# alias read as `x`. Its descriptor is read from the component beside the template, following what it names into the
# feature's own files (never `shared/`), three levels deep; a descriptor that cannot be read reds, since nothing could
# then be judged. A list whose descriptor is chosen at run time (the watch page's, one per subject) is judged on all
# of them at once. Red too on an aspect with neither, on a reason left for an aspect that is offered, and on a reason
# naming no list. Usage: list-stances.sh [--root DIR] [--stances FILE]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
stances_file=
while (($#)); do
  case "$1" in
    --root) root=$2; shift 2 ;;
    --stances) stances_file=$2; shift 2 ;;
    *) echo "list-stances: unknown argument $1"; exit 2 ;;
  esac
done
# One line per aspect not offered: `<key>|<aspect>=<reason>`, the reason written out or named from the list in the
# Python below. « not-ruled » is the list × action matrix's to settle (plan step 7); each ruling replaces its lines.
read -r -d '' STANCES <<'EOF'
activity/activity-page|import=not-ruled
activity/activity-page#list|bulk=not-ruled
activity/activity-page#list|row-actions=not-ruled
activity/activity-page#list|selection=not-ruled
company/custom-fields-page|export=not-ruled
company/custom-fields-page|import=not-ruled
company/custom-fields-page#list|bulk=not-ruled
company/custom-fields-page#list|selection=not-ruled
company/establishments-page|export=not-ruled
company/establishments-page|import=not-ruled
company/establishments-page#list|bulk=not-ruled
company/establishments-page#list|selection=not-ruled
company/members-page|export=not-ruled
company/members-page|import=not-ruled
company/members-page#list|bulk=not-ruled
company/members-page#list|selection=not-ruled
company/numbering-page|export=not-ruled
company/numbering-page|import=not-ruled
company/numbering-page#list|bulk=not-ruled
company/numbering-page#list|selection=not-ruled
customers/customer-groups-page|export=not-ruled
customers/customer-groups-page|import=not-ruled
customers/customer-groups-page#list|bulk=not-ruled
customers/customer-groups-page#list|selection=not-ruled
customers/customer-page|export=not-ruled
customers/customer-page|import=not-ruled
customers/customer-page#contactList|bulk=not-ruled
customers/customer-page#contactList|selection=not-ruled
customers/customers-page#list|bulk=not-ruled
customers/customers-page#list|row-actions=row-260
customers/customers-page#list|selection=not-ruled
delivery-notes/delivery-notes-page|import=not-ruled
delivery-notes/delivery-notes-page#list|bulk=bulk-ruled
delivery-notes/delivery-notes-page#list|row-actions=row-260
delivery-notes/delivery-notes-page#list|selection=bulk-ruled
expenses/expense-categories-page|export=not-ruled
expenses/expense-categories-page|import=not-ruled
expenses/expense-categories-page#list|bulk=not-ruled
expenses/expense-categories-page#list|selection=not-ruled
expenses/expenses-page|import=not-ruled
expenses/expenses-page#list|bulk=not-ruled
expenses/expenses-page#list|row-actions=row-260
expenses/expenses-page#list|selection=not-ruled
fiscal/fiscal-taxes-page|export=not-ruled
fiscal/fiscal-taxes-page|import=not-ruled
fiscal/fiscal-taxes-page#list|bulk=not-ruled
fiscal/fiscal-taxes-page#list|selection=not-ruled
fiscal/fiscal-taxes-page#regimeList|bulk=not-ruled
fiscal/fiscal-taxes-page#regimeList|row-actions=not-ruled
fiscal/fiscal-taxes-page#regimeList|selection=not-ruled
fiscal/fiscal-units-page|export=not-ruled
fiscal/fiscal-units-page|import=not-ruled
fiscal/fiscal-units-page#list|bulk=not-ruled
fiscal/fiscal-units-page#list|selection=not-ruled
inventory/stock-locations-page|export=not-ruled
inventory/stock-locations-page|import=not-ruled
inventory/stock-locations-page#list|bulk=not-ruled
inventory/stock-locations-page#list|selection=not-ruled
inventory/stock-movements-page|import=not-ruled
inventory/stock-movements-page#list|bulk=not-ruled
inventory/stock-movements-page#list|selection=not-ruled
inventory/stock-page#list|bulk=not-ruled
inventory/stock-page#list|selection=not-ruled
invoices/invoices-page|import=not-ruled
invoices/invoices-page#list|row-actions=row-260
invoices/portfolio-page|export=not-ruled
invoices/portfolio-page|import=not-ruled
invoices/portfolio-page#list|bulk=not-ruled
invoices/portfolio-page#list|row-actions=not-ruled
invoices/portfolio-page#list|selection=not-ruled
platform/platform-page|export=not-ruled
platform/platform-page|import=not-ruled
platform/platform-page#accountsList|bulk=not-ruled
platform/platform-page#accountsList|selection=not-ruled
platform/platform-page#companiesList|bulk=not-ruled
platform/platform-page#companiesList|row-actions=not-ruled
platform/platform-page#companiesList|selection=not-ruled
price-lists/price-lists-page|export=not-ruled
price-lists/price-lists-page|import=not-ruled
price-lists/price-lists-page#list|bulk=not-ruled
price-lists/price-lists-page#list|selection=not-ruled
products/product-categories-page|export=not-ruled
products/product-categories-page|import=not-ruled
products/product-categories-page#list|bulk=not-ruled
products/product-categories-page#list|selection=not-ruled
products/products-page#list|bulk=bulk-ruled
products/products-page#list|row-actions=row-260
products/products-page#list|selection=bulk-ruled
quotes/quotes-page|export=not-ruled
quotes/quotes-page|import=not-ruled
quotes/quotes-page#list|bulk=not-ruled
quotes/quotes-page#list|row-actions=row-260
quotes/quotes-page#list|selection=not-ruled
recurring-expenses/recurring-expenses-page|export=not-ruled
recurring-expenses/recurring-expenses-page|import=not-ruled
recurring-expenses/recurring-expenses-page#list|bulk=not-ruled
recurring-expenses/recurring-expenses-page#list|selection=not-ruled
recurring/recurring-page|export=not-ruled
recurring/recurring-page|import=not-ruled
recurring/recurring-page#list|bulk=not-ruled
recurring/recurring-page#list|selection=not-ruled
vendors/vendors-page#list|bulk=not-ruled
vendors/vendors-page#list|row-actions=row-260
vendors/vendors-page#list|selection=not-ruled
watch/watch-subject-page|export=not-ruled
watch/watch-subject-page|import=not-ruled
watch/watch-subject-page#list|bulk=not-ruled
watch/watch-subject-page#list|selection=not-ruled
EOF
[[ -n "$stances_file" ]] && STANCES=$(cat "$stances_file")
export STANCES
cd "$root" && python3 -I - <<'PY'
import os, re, subprocess, sys

REASONS = {
    'not-ruled': 'not ruled yet: the list × action matrix (docs/plans/source-layout.plan.md step 7)',
    'bulk-ruled': 'ruled 2026-10-11 08:26 (its first bulk actions, each previewed per record): built at plan step 8',
    'row-260': 'ruled 2026-10-11 01:24 (row 260: every action of a record reachable from its list row): not built here yet',
}
ASPECTS_OF_LIST = ('selection', 'bulk', 'row-actions')
ASPECTS_OF_PAGE = ('import', 'export')
APP = 'web/src/app/'


def read(path):
    with open(path, encoding='utf-8') as handle:
        return handle.read()


def balanced(text, start):
    """The declaration from `start`: a function to its body's closing brace, anything else to its `;` at depth 0."""
    head = re.match(r'(?:export\s+)?(?:async\s+)?function\b', text[start:])
    depth = 0
    for index in range(start, len(text)):
        char = text[index]
        if char in '([{':
            depth += 1
        elif char in ')]}':
            depth -= 1
            if head and char == '}' and depth == 0:
                return text[start:index + 1]
        elif char == ';' and depth == 0 and not head:
            return text[start:index]
    return text[start:]


def imported(path, text):
    """The names a file takes from the feature's own files, each with the file it comes from."""
    names = {}
    for listed, module in re.findall(r"import\s*(?:type\s*)?\{([^}]*)\}\s*from\s*'(\.{1,2}/[^']+)'", text):
        target = os.path.normpath(os.path.join(os.path.dirname(path), module + '.ts'))
        if target.startswith(APP + 'shared/') or not os.path.isfile(target):
            continue
        for name in listed.split(','):
            name = name.strip().split(' as ')[-1].strip()
            if name:
                names[name] = target
    return names


DECLARED = r'(?m)(?:^|(?<=[{;]))[ \t]*(?:export\s+)?(?:(?:protected|private|public)\s+)?(?:readonly\s+)?(?:const\s+|(?:async\s+)?function\s+)?'


def descriptor(path, name, depth=0):
    """What `name` is declared as in `path`, with every declaration it names in the feature's files after it."""
    text = read(path)
    found = re.search(DECLARED + re.escape(name) + r'\b\s*[:=(<]', text)
    if not found:
        return ''
    body = balanced(text, found.start() + len(found.group(0)) - len(found.group(0).lstrip()))
    if depth >= 3:
        return body
    for other, target in imported(path, text).items():
        if re.search(r'\b' + re.escape(other) + r'\b', body):
            body += '\n' + descriptor(target, other, depth + 1)
    for other in set(re.findall(r'\b[A-Za-z_]\w*\b', body)) - {name}:
        if re.search(r'(?m)^(?:export\s+)?(?:const|(?:async\s+)?function)\s+' + re.escape(other) + r'\b', text):
            body += '\n' + descriptor(path, other, depth + 1)
    return body


templates = subprocess.run(['git', 'ls-files', '--', APP + '*.html'], capture_output=True, text=True, check=True).stdout.split()
offered = {}
unread = []
pages = set()
for template in templates:
    text = read(template)
    tags = list(re.finditer(r'<app-data-list\b(.*?)/?>', text, re.S))
    if not tags:
        continue
    page = template[len(APP):-len('.html')]
    component = template[:-len('.html')] + '.ts'
    pages.add(page)
    aliases = dict((alias, source) for source, alias in re.findall(r'@if\s*\(\s*([\w.]+)\(\)\s*;\s*as\s+(\w+)\s*\)', text))
    for tag in tags:
        bound = re.search(r'\[descriptor\]="([^"]+)"', tag.group(1))
        expression = bound.group(1) if bound else ''
        head = expression.split('.')[0].removesuffix('()')
        name = aliases.get(head, head)
        key = f'{page}#{expression.split(".")[-1].removesuffix("()") if head in aliases else name}'
        closing = text.find('</app-data-list>', tag.end())
        inner = text[tag.end():closing] if closing != -1 and not tag.group(0).endswith('/>') else ''
        body = descriptor(component, name) if name and os.path.isfile(component) else ''
        if not re.search(r'\bid\s*:', body):  # every descriptor names its list's id; without one it was not reached
            unread.append(key)
            continue
        cells = ''.join(re.findall(r'<ng-template\s+appDataListCell\b.*?</ng-template>', inner, re.S))
        offered[f'{key}|selection'] = bool(re.search(r'\[\(selected\)\]|\(selectedChange\)', tag.group(1)) or re.search(r'\bselection\s*:', body))
        offered[f'{key}|bulk'] = 'appDataListSelection' in inner
        offered[f'{key}|row-actions'] = bool(re.search(r'\bactions\s*:\s*\[(?!\s*\])', body) or re.search(r'<button\b|<a\s[^>]*\bmat-', cells))
    offered[f'{page}|import'] = 'routerLink="/imports/' in text
    offered[f'{page}|export'] = '<app-list-export' in text

status = 0
if not pages:
    print('list-stances: FAIL — no template draws a list: the gate would pass over nothing')
    sys.exit(1)
for key in unread:
    print(f'list-stances: FAIL — {key}: its descriptor could not be read, so no stance can be judged')
    status = 1

reasons = {}
for line in os.environ.get('STANCES', '').splitlines():
    if not line.strip():
        continue
    aspect_key, _, reason = line.partition('=')
    reasons[aspect_key.strip()] = REASONS.get(reason.strip(), reason.strip())
for aspect_key, reason in sorted(reasons.items()):
    if aspect_key not in offered:
        if aspect_key.split('|')[0] not in unread:
            print(f'list-stances: FAIL — {aspect_key} names no list')
            status = 1
    elif not reason:
        print(f'list-stances: FAIL — {aspect_key} has an empty reason')
        status = 1
    elif offered.get(aspect_key):
        print(f'list-stances: FAIL — {aspect_key} is listed with a reason but offered: remove its line')
        status = 1
for aspect_key, has in sorted(offered.items()):
    if not has and aspect_key not in reasons:
        print(f'list-stances: FAIL — {aspect_key} is neither offered nor given a reason')
        status = 1
if status:
    sys.exit(1)
lists = len({key.split('|')[0] for key in offered if '#' in key})
print(f'list-stances: OK — {lists} lists on {len(pages)} pages: {sum(offered.values())} offered, {len(reasons)} not, each with its reason')
PY
