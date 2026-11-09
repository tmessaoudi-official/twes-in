#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""The lines we wrote: what stands at a revision, by area, kind, app and test, and what its history added and removed.

Reads the blobs of the revision from git, never the working tree, so an edit not committed or a file git ignores is
not counted. Both halves leave out the same files (third-party, generated, licence texts) and count a line as git
does, so what stands equals what the history added less what it removed; when they differ the report says by how
much and the run exits 1.

Usage: python3 -I scripts/stats/count-lines.py [--rev REV] [--out FILE] [--repo DIR]   (or `make count-lines`)
"""
import argparse
import collections
import os
import re
import subprocess
import sys

# Not ours, or not written by hand: locks, the generated notices and API types, licence texts, vendored code and
# fonts, and this report itself.
EXCLUDED = re.compile(
    r'(^|/)(composer\.lock|symfony\.lock|package-lock\.json|THIRD-PARTY-NOTICES\.md|LICENSE|lines_of_code\.txt)$'
    r'|^web/src/third-party/|^web/public/fonts/|^scripts/notices/texts/|^web/src/app/api/|types\.gen\.ts$')
CODE = {'php': 'PHP', 'ts': 'TypeScript', 'js': 'JavaScript', 'mjs': 'JavaScript', 'cjs': 'JavaScript',
        'html': 'HTML', 'scss': 'SCSS/CSS', 'css': 'SCSS/CSS', 'twig': 'Twig', 'sh': 'Shell', 'bash': 'Shell',
        'py': 'Python', 'sql': 'SQL', 'ps1': 'PowerShell'}
CONFIG = {'yaml', 'yml', 'json', 'xml', 'ini', 'conf', 'toml', 'env', 'neon', 'dist', 'service', 'timer', 'tsv'}
TEST = re.compile(r'(^|/)(tests?|e2e[^/]*)/|\.spec\.ts$|\.test\.sh$')
TEXTS = re.compile(r'^web/public/i18n/|^api/translations/|^web/src/app/shared/i18n/languages\.json$')
COMMENT = re.compile(r'^\s*(//|#(?!\[)|/\*|\*|<!--|\{#|--\s)')
AREAS = ('api', 'web', 'other')
OUTSIDE = (('texts', 'screen, mail and document texts (fr/en/ar)'), ('docs', 'Markdown docs'),
           ('config', 'config (YAML, JSON, XML, ini...)'))


def kind(path: str) -> str | None:
    """A code kind, 'texts', 'docs', 'config', or None for a kind of ours no table counts."""
    name = os.path.basename(path)
    if name.startswith('Dockerfile') or name.endswith('.Dockerfile'):
        return 'Dockerfile'
    if name.startswith('Makefile'):
        return 'Makefile'
    ext = name.rsplit('.', 1)[-1] if '.' in name else ''
    if TEXTS.search(path) and ext in ('json', 'yaml', 'yml'):
        return 'texts'
    if ext in CODE:
        return CODE[ext]
    if ext == 'md':
        return 'docs'
    return 'config' if ext in CONFIG else None


def role(path: str, k: str | None) -> str:
    return 'test' if k not in (None, 'docs', 'texts', 'config') and TEST.search(path) else 'app'


def area(path: str) -> str:
    return 'api' if path.startswith('api/') else 'web' if path.startswith('web/') else 'other'


def binary(blob: bytes) -> bool:
    """Git's own test, which its numstat follows: a NUL in the first 8,000 bytes."""
    return b'\0' in blob[:8000]


def line_count(blob: bytes) -> int:
    """As git counts: every newline, and a last line without one."""
    return blob.count(b'\n') + (1 if blob and not blob.endswith(b'\n') else 0)


def git(repo: str, *args: str, data: bytes | None = None) -> bytes:
    return subprocess.run(['git', '-C', repo, *args], input=data, capture_output=True, check=True).stdout


def blobs(repo: str, rev: str) -> dict[str, bytes]:
    """Every file of the revision with its content, read in one `cat-file --batch`."""
    entries = []
    for entry in git(repo, 'ls-tree', '-r', '-z', rev).split(b'\0'):
        if not entry:
            continue
        meta, path = entry.split(b'\t', 1)
        mode, kind_, sha = meta.split()
        if kind_ == b'blob':
            entries.append((path.decode('utf-8', 'surrogateescape'), sha))
    out = git(repo, 'cat-file', '--batch', data=b''.join(sha + b'\n' for _, sha in entries))
    contents, at = {}, 0
    for path, _ in entries:
        end = out.index(b'\n', at)
        size = int(out[at:end].split()[2])
        contents[path] = out[end + 1:end + 1 + size]
        at = end + 1 + size + 1
    return contents


def history(repo: str, rev: str) -> tuple[collections.Counter, collections.Counter, int, str]:
    """Lines added and removed per category over every commit reaching the revision; renames read as a removal and
    an addition, so a file moved into an excluded path leaves the count as it leaves the tree."""
    added, removed = collections.Counter(), collections.Counter()
    for record in git(repo, 'log', '--no-renames', '--format=', '--numstat', '-z', rev).split(b'\0'):
        record = record.lstrip(b'\n')
        if not record:
            continue
        a, d, path = record.decode('utf-8', 'surrogateescape').split('\t', 2)
        if a == '-' or EXCLUDED.search(path):
            continue
        category = group(path)
        added[category] += int(a)
        removed[category] += int(d)
    commits = int(git(repo, 'rev-list', '--count', rev))
    first = git(repo, 'log', '--max-parents=0', '--format=%cs', rev).decode().split()[-1]
    return added, removed, commits, first


def group(path: str) -> str:
    """Which line of « EVERYTHING » a file falls on."""
    k = kind(path)
    if k is None:
        return 'other kinds of ours'
    if k in ('texts', 'docs', 'config'):
        return k
    return 'tests' if role(path, k) == 'test' else 'code (app)'


def fmt(n: int) -> str:
    return f'{n:,}'.replace(',', ' ')


def report(repo: str, rev: str) -> tuple[list[str], int]:
    files = blobs(repo, rev)
    raw, code, nfiles = collections.Counter(), collections.Counter(), collections.Counter()
    other, nother = collections.Counter(), collections.Counter()
    for path, blob in files.items():
        if EXCLUDED.search(path) or binary(blob):
            continue
        k = kind(path)
        n = line_count(blob)
        if k is None:
            name = os.path.basename(path)
            ext = name.rsplit('.', 1)[-1] if '.' in name.lstrip('.') else name
            other[ext] += n
            nother[ext] += 1
            continue
        key = (area(path), k, role(path, k))
        nfiles[key] += 1
        raw[key] += n
        lines = blob.decode('utf-8', 'replace').split('\n')
        code[key] += sum(1 for line in lines if line.strip() and not COMMENT.match(line))

    def total(counter, areas=AREAS, kinds=None, roles=('app', 'test')):
        return sum(v for (a, k, r), v in counter.items() if a in areas and (kinds is None or k in kinds) and r in roles)

    code_kinds = sorted({k for _, k, _ in raw} - {'docs', 'texts', 'config'})
    head = git(repo, 'log', '-1', '--format=%h %cs', rev).decode().strip()
    out = []
    w = out.append
    w(f'twes-in: lines we wrote, counted at commit {head} (the files git tracks there, as committed)')
    w('')
    w('Left out: vendor/ and node_modules/ (never tracked), everything generated (gitignored: the OpenAPI types, the')
    w('icon font, Symfony\'s config reference), the lock files, THIRD-PARTY-NOTICES.md, LICENSE, the vendored zxing')
    w('reader, the self-hosted fonts, the licence texts and binary files. "lines" = every line, as git counts them;')
    w('"code" = lines that are neither blank nor a comment (approximate: a comment is a line starting with //, #,')
    w('/*, *, <!--, {# or --).')
    w('')
    for label, areas in (('API (api/)', ('api',)), ('WEB (web/)', ('web',)),
                         ('OTHER (scripts/, infra/, .github/, compose files, root)', ('other',)),
                         ('ALL OF IT (api + web + other)', AREAS)):
        w('=' * 78)
        w(label)
        w('=' * 78)
        w(f'{"kind":<14}{"app lines":>12}{"app code":>11}{"test lines":>12}{"test code":>11}{"files":>8}')
        rows = [(k, {k}) for k in code_kinds] + [('CODE TOTAL', set(code_kinds))]
        for name, kinds in rows:
            cells = [total(raw, areas, kinds, ('app',)), total(code, areas, kinds, ('app',)),
                     total(raw, areas, kinds, ('test',)), total(code, areas, kinds, ('test',)),
                     total(nfiles, areas, kinds)]
            if name == 'CODE TOTAL':
                w('-' * 68)
            elif not any(cells):
                continue
            w(f'{name:<14}' + ''.join(f'{fmt(c):>{n}}' for c, n in zip(cells, (12, 11, 12, 11, 8))))
        w(f'  app + tests: {fmt(cells[0] + cells[2])} lines, {fmt(cells[1] + cells[3])} of code')
        w('  outside the code total:')
        for k, what in OUTSIDE:
            if total(raw, areas, {k}):
                w(f'    {what:<46}{fmt(total(raw, areas, {k})):>10} lines{fmt(total(nfiles, areas, {k})):>7} files')
        w('')
    standing = total(raw)
    w('=' * 78)
    w('EVERYTHING: code + tests + texts + docs + config')
    w('=' * 78)
    for label, kinds, roles in (('code (app)', set(code_kinds), ('app',)), ('tests', set(code_kinds), ('test',)),
                                ('screen, mail and document texts', {'texts'}, ('app', 'test')),
                                ('Markdown docs', {'docs'}, ('app', 'test')), ('config', {'config'}, ('app', 'test'))):
        w(f'  {label:<44}{fmt(total(raw, AREAS, kinds, roles)):>10} lines{fmt(total(nfiles, AREAS, kinds, roles)):>7} files')
    w('-' * 68)
    w(f'  {"TOTAL":<44}{fmt(standing):>10} lines{fmt(total(nfiles)):>7} files')
    w(f'  {"other kinds of ours":<44}{fmt(sum(other.values())):>10} lines{fmt(sum(nother.values())):>7} files')
    w('    (' + ', '.join(f'{e} {fmt(v)}' for e, v in other.most_common()) + ')')
    w('')
    added, removed, commits, first = history(repo, rev)
    a, d = sum(added.values()), sum(removed.values())
    here = standing + sum(other.values())
    w('=' * 78)
    w(f'WRITTEN OVER THE HISTORY ({fmt(commits)} commits since the {first} root, renames read as a removal and an addition)')
    w('=' * 78)
    w(f'  {"lines added":<44}{fmt(a):>10}')
    w(f'  {"lines removed":<44}{fmt(d):>10}')
    w(f'  {"net (what stands)":<44}{fmt(a - d):>10}')
    w('  added, by kind: ' + ', '.join(f'{k} {fmt(v)}' for k, v in added.most_common()))
    w('  "Added" counts a line rewritten ten times ten times: it is the work done, not the size.')
    w('')
    w(f'check: what stands {fmt(here)} ({fmt(standing)} + other kinds {fmt(sum(other.values()))})'
      f' = net {fmt(a - d)} ({fmt(a)} added - {fmt(d)} removed)')
    drift = here - (a - d)
    if drift:
        w(f'  The two halves differ by {fmt(drift)} lines. Two things are seen by one half only: what a merge commit')
        w('  itself changes (the history reads no change in a merge), and a file that switched between binary and')
        w('  text (git counts no line of a binary change).')
    w('')
    w('Notes')
    for prefix, suffix, what in (('api/migrations/', '.php', 'API app PHP includes the database migrations'),
                                 ('api/resources/legal/', '.md', 'The API\'s Markdown is the legal pages\' shipped drafts')):
        found = [p for p in files if p.startswith(prefix) and p.endswith(suffix)]
        if found:
            w(f'- {what}: {fmt(sum(line_count(files[p]) for p in found))} lines in {len(found)} files.')
    w('- "test" = files under tests/, test/, e2e*/ folders, *.spec.ts and *.test.sh; the rest is "app".')
    w('')
    w('=' * 78)
    w('HOW TO UPDATE')
    w('=' * 78)
    w('From the repository, `make count-lines` counts HEAD again and writes this file (var/claude/lines_of_code.txt,')
    w('gitignored); `make count-lines REV=<commit or tag>` counts another revision. It runs in the tools container')
    w('(no Python on the host) and reads only what git holds at that revision, never the working tree. It exits 1')
    w('when the check line above does not hold. The script is scripts/stats/count-lines.py.')
    return out, 1 if drift else 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    parser.add_argument('--rev', default='HEAD')
    parser.add_argument('--out', default='var/claude/lines_of_code.txt')
    parser.add_argument('--repo', default='.')
    args = parser.parse_args()
    resolved = subprocess.run(['git', '-C', args.repo, 'rev-parse', '--verify', '--quiet', args.rev + '^{commit}'],
                              capture_output=True, text=True)
    if resolved.returncode != 0:
        print(f'count-lines: {args.rev} names no commit in {args.repo}', file=sys.stderr)
        return 2
    lines, status = report(args.repo, resolved.stdout.strip())
    os.makedirs(os.path.dirname(os.path.abspath(args.out)), exist_ok=True)
    with open(args.out, 'w', encoding='utf-8') as f:
        f.write('\n'.join(lines) + '\n')
    print('\n'.join(lines))
    print(f'count-lines: written to {args.out}', file=sys.stderr)
    return status


if __name__ == '__main__':
    sys.exit(main())
