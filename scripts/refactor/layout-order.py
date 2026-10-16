#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Whether two oracle snapshots that differ byte for byte hold the same OpenAPI document and routes in another order.

A class moved into a folder of its own is discovered at another moment, so API Platform lists its tags and routes
elsewhere: the same content, which is no loss. Route order is not cosmetic, though: Symfony matches routes in order,
so two routes that changed places and can both match one request would change which answers. Each such pair is tried
with real requests, each route's placeholders filled with samples and the other's words, against the other's own
compiled regex; a pair no request reaches passes only when its paths are provably apart (other lengths, or a word one
of them never takes), and is reported otherwise. Exit 0 when the content is the same and no pair is reported; 1
otherwise, naming what differs.

    python3 -I scripts/refactor/layout-order.py <before> <after>
"""

import itertools
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


# Values a placeholder may take, tried in turn: words, numbers, an identifier, the languages and formats in use.
SAMPLES = ('x', 'abc', '1', '2026', 'fr', 'en', 'csv', 'json', 'jsonld', 'index', 'a.b',
           '01890a5d-ac96-774b-bcce-b302099a8057')
PLACEHOLDER = re.compile(r'\{(\w+)\}')
FORMAT = '.{_format}'


def matcher(route: dict) -> re.Pattern:
    """Symfony's own compiled path regex, as debug:router writes it: `{^…$}sDu`."""
    written = route['pathRegex']
    return re.compile(written[1:written.rindex('}')], re.S)


def methods(route: dict) -> set[str]:
    method = route.get('method') or 'ANY'
    return {'ANY'} if method == 'ANY' else set(method.split('|'))


def requests(route: dict, literals: set[str]) -> list[str]:
    """Paths this route answers, its placeholders filled with the samples and with the other route's own words."""
    found = []
    own = matcher(route)
    names = PLACEHOLDER.findall(route['path'])
    for values in itertools.product(SAMPLES + tuple(sorted(literals)), repeat=len(names)):
        filled = route['path']
        for name, value in zip(names, values):
            filled = filled.replace('{' + name + '}', value, 1)
        if own.fullmatch(filled):
            found.append(filled)
    return found


def segments(route: dict) -> list[str]:
    path = route['path']
    return (path[:-len(FORMAT)] if path.endswith(FORMAT) else path).strip('/').split('/')


def apart(one: dict, other: dict) -> bool:
    """Whether the paths cannot meet: of other lengths, or a word of one that a segment of the other never takes."""
    first, second = segments(one), segments(other)
    if len(first) != len(second):
        return True
    for mine, theirs in zip(first, second):
        for word, rival, route in ((mine, theirs, other), (theirs, mine, one)):
            if '{' in word:
                continue
            if '{' not in rival:
                if word != rival:
                    return True
                continue
            names = PLACEHOLDER.fullmatch(rival)
            requirement = (route.get('requirements') or {}).get(names.group(1)) if names else None
            if requirement is not None and not re.fullmatch(requirement, word):
                return True
    return False


def meeting(one: dict, other: dict) -> str | None:
    """A request both routes would answer, '' when none was found but nothing keeps them apart, None when apart."""
    first, second = methods(one), methods(other)
    if not (first & second or 'ANY' in first | second):
        return None
    words = {word for route in (one, other) for word in segments(route) if '{' not in word}
    for route, rival in ((one, other), (other, one)):
        for request in requests(route, words):
            if matcher(rival).fullmatch(request):
                return request
    return None if apart(one, other) else ''


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
            request = meeting(old[one], old[other])
            if request is None:
                continue
            clashing += 1
            if request:
                print(f'layout-order: {one} and {other} changed places and can match {request}')
            else:
                print(f'layout-order: {one} and {other} changed places and could not be told apart')
    print(f'layout-order: {moved} route pairs changed places, {clashing} of them can match the same request or could not be told apart')
    return 0 if same and clashing == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
