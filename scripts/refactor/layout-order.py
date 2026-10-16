#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Whether two oracle snapshots that differ byte for byte hold the same OpenAPI document and routes in another order.

A class moved into a folder of its own is discovered at another moment, so API Platform lists its tags and routes
elsewhere: the same content, which is no loss. Route order is not cosmetic, though: Symfony matches routes in order,
so two routes that changed places and can both match one request would change which answers. Exit 0 when the content
is the same and no such pair exists; 1 otherwise, naming what differs.

    python3 -I scripts/refactor/layout-order.py <before> <after>
"""

import json
import re
import sys


def normal(value):
    """A list of named objects (OpenAPI's tags) compared as a set; everything else as it is, objects by their keys."""
    if isinstance(value, dict):
        return {key: normal(item) for key, item in value.items()}
    if isinstance(value, list):
        items = [normal(item) for item in value]
        if items and all(isinstance(item, dict) and 'name' in item for item in value):
            return sorted(items, key=lambda item: json.dumps(item, sort_keys=True))
        return items
    return value


def pattern(route: dict) -> str:
    requirements = route.get('requirements') or {}
    return '^' + re.sub(r'\\\{(\w+)\\\}', lambda m: '(?:' + requirements.get(m.group(1), '[^/]+') + ')',
                        re.escape(route['path'])) + '$'


def methods(route: dict) -> set[str]:
    method = route.get('method') or 'ANY'
    return {'ANY'} if method == 'ANY' else set(method.split('|'))


def overlap(one: dict, other: dict) -> bool:
    """Whether a request one route answers could reach the other: a shared method, and a path of one the other matches."""
    first, second = methods(one), methods(other)
    if not (first & second or 'ANY' in first | second):
        return False
    for route, rival in ((one, other), (other, one)):
        for filling in ('x', '00000000-0000-0000-0000-000000000000'):
            if re.match(pattern(rival), re.sub(r'\{\w+\}', filling, route['path'])):
                return True
    return False


def main() -> int:
    before, after = sys.argv[1:3]
    same = True
    for name in ('openapi.json', 'routes.json'):
        with open(f'{before}/{name}', encoding='utf-8') as a, open(f'{after}/{name}', encoding='utf-8') as b:
            if normal(json.load(a)) != normal(json.load(b)):
                print(f'layout-order: {name} holds other content, not only another order')
                same = False
    with open(f'{before}/routes.json', encoding='utf-8') as a, open(f'{after}/routes.json', encoding='utf-8') as b:
        old, new = json.load(a), json.load(b)
    place = {key: index for index, key in enumerate(new)}
    names = [key for key in old if key in place]
    moved = clashing = 0
    for index, one in enumerate(names):
        for other in names[index + 1:]:
            if place[one] < place[other]:
                continue
            moved += 1
            if overlap(old[one], old[other]):
                clashing += 1
                print(f'layout-order: {one} and {other} changed places and can match the same request')
    print(f'layout-order: {moved} route pairs changed places, {clashing} of them can match the same request')
    return 0 if same and clashing == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
