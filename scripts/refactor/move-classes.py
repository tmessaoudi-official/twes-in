#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Moves PHP classes under api/src to other folders, and every name and path that points at them with them.

    move-classes.py [--root DIR] [--apply] MAPPING     dry run unless --apply
    move-classes.py [--root DIR] --draft CONTEXT        a mapping to curate, from what each class declares

A mapping line is `<path under api/src> -> <folder under api/src>`; `#` starts a comment. A class keeps its short
name, which API Platform's resource names, IRIs and routes come from, so a line names a folder, never a file. Applied,
each file moves through `git mv`, its namespace line follows it, and across the tracked text of api/, scripts/,
.github/ and infra/ every fully-qualified name (written with single or doubled backslashes) and every `src/<path>` is
rewritten. A class that named a moved one by its short name alone, sharing its old namespace, gains a `use`, and so
does a moved class for what it named that way; an import of a file's own namespace is dropped. A missing file, a
target that exists, a class SourceLayoutTest keeps by its name, or a line that is no mapping refuses the whole run
before anything is touched. PHPStan, php-cs-fixer (`make check FIX=1`) and the layout oracle check what this wrote.
"""

from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys

TEXT_ROOTS = ('api/', 'scripts/', '.github/', 'infra/', 'Makefile')
LINE = re.compile(r'^(\S+\.php)\s*->\s*(\S+?)/?$')
NAMESPACE = re.compile(r'^namespace ([^;]+);', re.M)
USE = re.compile(r'^use ([A-Za-z0-9_\\]+)(?: as (\w+))?;\n', re.M)
IDENT = r'[A-Za-z_][A-Za-z0-9_]*'


def fail(message: str) -> None:
    print(f'move-classes: {message}', file=sys.stderr)
    sys.exit(1)


def fqcn(relative: str) -> str:
    return 'App\\' + relative[:-4].replace('/', '\\')


def namespace_of(name: str) -> str:
    return name.rsplit('\\', 1)[0]


def kept(root: str) -> set[str]:
    """The classes SourceLayoutTest keeps by their names: a queued message, the session's user, a scheduled task."""
    path = os.path.join(root, 'api/tests/Architecture/SourceLayoutTest.php')
    if not os.path.isfile(path):
        return set()
    code = open(path, encoding='utf-8').read()
    block = re.search(r'const array KEPT = \[(.*?)\];', code, re.S)
    if block is None:
        fail('SourceLayoutTest declares no KEPT list: what may not move is unknown')
    return {name.replace('\\\\', '\\') for name in re.findall(r"'(App\\[^']+)'", block.group(1))}


def read_mapping(root: str, path: str) -> list[tuple[str, str]]:
    src = os.path.join(root, 'api/src')
    moves, problems, kept_names = [], [], kept(root)
    for raw in open(path, encoding='utf-8'):
        line = raw.split('#', 1)[0].strip()
        if not line:
            continue
        match = LINE.match(line)
        if match is None:
            problems.append(f'not a mapping: {line}')
            continue
        old, folder = match.group(1), match.group(2)
        new = f'{folder}/{os.path.basename(old)}'
        if not os.path.isfile(os.path.join(src, old)):
            problems.append(f'{old} is no file under api/src')
        elif os.path.exists(os.path.join(src, new)):
            problems.append(f'{new} exists already')
        elif fqcn(old) in kept_names:
            problems.append(f'{fqcn(old)} is kept by its name (SourceLayoutTest::KEPT): it does not move')
        elif old == new:
            problems.append(f'{old} is already in {folder}')
        else:
            moves.append((old, new))
    if problems:
        fail('nothing moved:\n  ' + '\n  '.join(problems))
    if not moves:
        fail(f'{path} maps nothing')
    return moves


def tracked_text(root: str) -> dict[str, str]:
    listed = subprocess.run(['git', '-C', root, 'ls-files', '-z', '--', *TEXT_ROOTS], capture_output=True, check=True)
    files = {}
    for name in listed.stdout.decode().split('\0'):
        if not name or not os.path.isfile(os.path.join(root, name)):
            continue
        try:
            files[name] = open(os.path.join(root, name), encoding='utf-8').read()
        except UnicodeDecodeError:
            continue
    return files


def code_only(text: str) -> str:
    """The file without its namespace and use lines and its whole-line `//` comments: where a short name counts."""
    text = NAMESPACE.sub('', text)
    text = USE.sub('', text)
    return re.sub(r'(?m)^\s*//.*$', '', text)


def names_bare(text: str, short: str) -> bool:
    pattern = r'(?<![\w\\$])(?<!->)(?<!::)' + re.escape(short) + r'(?![\w\\])'
    return re.search(pattern, code_only(text)) is not None


def add_uses(text: str, names: list[str]) -> str:
    if not names:
        return text
    block = ''.join(f'use {name};\n' for name in sorted(names))
    uses = list(USE.finditer(text))
    if uses:
        at = uses[-1].end()
        return text[:at] + block + text[at:]
    match = NAMESPACE.search(text)
    if match is None:
        return text
    return text[:match.end()] + '\n\n' + block.rstrip('\n') + text[match.end():]


def drop_own_namespace_uses(text: str) -> str:
    match = NAMESPACE.search(text)
    if match is None:
        return text
    own = match.group(1)
    return USE.sub(lambda use: '' if use.group(2) is None and namespace_of(use.group(1)) == own else use.group(0), text)


def apply(root: str, moves: list[tuple[str, str]]) -> None:
    files = tracked_text(root)
    renamed = {fqcn(old): fqcn(new) for old, new in moves}
    by_path = {f'api/src/{old}': f'api/src/{new}' for old, new in moves}

    # The imports a short name may now need, read from the tree as it stands, before any rewrite. One that names the
    # file's own new namespace (classes moved together) goes again with the file's other imports of its own namespace.
    folders: dict[str, list[str]] = {}
    for name in files:
        if name.startswith('api/src/') and name.endswith('.php'):
            folders.setdefault(os.path.dirname(name), []).append(name)
    needed: dict[str, list[str]] = {}
    touched_folders = {os.path.dirname(path) for path in by_path}
    for folder in touched_folders:
        for name in folders.get(folder, []):
            text = files[name]
            imported = {use.group(2) or use.group(1).rsplit('\\', 1)[1] for use in USE.finditer(text)}
            for sibling in folders[folder]:
                short = os.path.basename(sibling)[:-4]
                target = fqcn(sibling[len('api/src/'):])
                target = renamed.get(target, target)
                if sibling == name or short in imported:
                    continue
                if names_bare(text, short):
                    needed.setdefault(name, []).append(target)

    changed: dict[str, str] = {}
    for name, text in files.items():
        new_text = text
        for old, new in renamed.items():
            for written_old, written_new in ((old, new), (old.replace('\\', '\\\\'), new.replace('\\', '\\\\'))):
                new_text = re.sub(r'(?<!\w)' + re.escape(written_old) + r'(?!\w)', lambda _m, w=written_new: w, new_text)
        for old_path, new_path in by_path.items():
            old_rel, new_rel = old_path[len('api/'):], new_path[len('api/'):]
            new_text = re.sub(r'(?<![\w.])' + re.escape(old_rel) + r'(?!\w)', lambda _m, w=new_rel: w, new_text)
        if name in by_path:
            new_namespace = namespace_of(fqcn(by_path[name][len('api/src/'):]))
            new_text = NAMESPACE.sub(lambda _m: f'namespace {new_namespace};', new_text, count=1)
        if name in needed:
            new_text = add_uses(new_text, needed[name])
        if name in by_path or name in needed:
            new_text = drop_own_namespace_uses(new_text)
        if new_text != text:
            changed[name] = new_text

    for old_path, new_path in by_path.items():
        os.makedirs(os.path.join(root, os.path.dirname(new_path)), exist_ok=True)
        subprocess.run(['git', '-C', root, 'mv', old_path, new_path], check=True)
    for name, text in changed.items():
        target = by_path.get(name, name)
        with open(os.path.join(root, target), 'w', encoding='utf-8') as handle:
            handle.write(text)
    print(f'moved {len(moves)}, rewrote {len(changed)} files')


def role(text: str, path: str) -> str | None:
    """The folder a class's role calls for, read as SourceLayoutTest reads it, for a draft to curate."""
    layer = path.split('/')
    if re.search(r'#\[ApiResource\b', text) or re.search(r'^#\[(Get|GetCollection|Post|Put|Patch|Delete)\(', text, re.M):
        return 'Infrastructure/ApiPlatform/Resource'
    if 'use ApiPlatform\\State\\ProcessorInterface;' in text:
        return 'Infrastructure/ApiPlatform/State/Processor'
    if 'use ApiPlatform\\State\\ProviderInterface;' in text:
        return 'Infrastructure/ApiPlatform/State/Provider'
    if 'OpenApiFactoryInterface' in text and re.search(r'implements\s+OpenApiFactoryInterface', text):
        return 'Infrastructure/ApiPlatform/OpenApi'
    if 'Domain' in layer:
        if re.search(r'#\[ORM\\(Entity|Embeddable)\b', text):
            return 'Domain/Model'
        if re.search(r'^interface \w+Repository\b', text, re.M):
            return 'Domain/Repository'
        if re.search(r'^(final |abstract )*(readonly )?class \w+ extends \\?[\w\\]*(Exception|Error)\b', text, re.M) \
                or re.search(r'^(final |abstract )*class \w+ extends (Invalid|\w*Refused|\w*Exception)\w*', text, re.M):
            return 'Domain/Exception'
        if re.search(r'^enum ', text, re.M):
            return 'Domain/ValueObject'
        if re.search(r'implements [^{]*\bDomainEvent\b', text):
            return 'Domain/Event'
        if re.search(r'^(final )?readonly class|^final readonly class', text, re.M):
            return 'Domain/ValueObject'
        return 'Domain/Service'
    if 'ApiPlatform' in layer:
        name = os.path.basename(path)
        if name.endswith(('SearchReader.php', 'Path.php', 'Field.php')):
            return 'Infrastructure/ApiPlatform/Payload'
        if name.endswith(('Permission.php', 'Guard.php', 'Ownership.php', 'Access.php', 'Right.php')):
            return 'Infrastructure/ApiPlatform/Security'
        if 'MetadataCollectionFactoryInterface' in text:
            return 'Infrastructure/ApiPlatform/Metadata'
        return 'Infrastructure/ApiPlatform/Output'
    return None


def draft(root: str, context: str) -> None:
    base = os.path.join(root, 'api/src', context)
    if not os.path.isdir(base):
        fail(f'{context} is no context under api/src')
    for directory, _, names in sorted(os.walk(base)):
        for name in sorted(names):
            if not name.endswith('.php'):
                continue
            relative = os.path.relpath(os.path.join(directory, name), os.path.join(root, 'api/src'))
            inside = relative[len(context) + 1:]
            folder = os.path.dirname(inside)
            text = open(os.path.join(directory, name), encoding='utf-8').read()
            wanted = role(text, inside)
            flat = folder in ('Domain', 'Infrastructure/ApiPlatform')
            if wanted is None or (not flat and not wanted.startswith('Infrastructure/ApiPlatform/')):
                continue
            if wanted.startswith('Infrastructure/ApiPlatform/') and folder.startswith('Infrastructure/ApiPlatform/'):
                continue
            if wanted.startswith('Infrastructure/ApiPlatform/') and not flat and not wanted.endswith(('Resource', 'Processor', 'Provider', 'OpenApi')):
                continue
            print(f'{relative} -> {context}/{wanted}')


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    parser.add_argument('--root', default=os.path.normpath(os.path.join(os.path.dirname(__file__), '../..')))
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--draft', metavar='CONTEXT')
    parser.add_argument('mapping', nargs='?')
    args = parser.parse_args()
    root = os.path.abspath(args.root)
    if args.draft:
        draft(root, args.draft)
        return
    if not args.mapping:
        fail('a mapping file is needed, or --draft CONTEXT')
    moves = read_mapping(root, args.mapping)
    if not args.apply:
        for old, new in moves:
            print(f'{old} -> {new}')
        print(f'dry run: {len(moves)} to move; --apply moves them')
        return
    apply(root, moves)


if __name__ == '__main__':
    main()
