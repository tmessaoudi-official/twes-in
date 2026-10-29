#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A build behind a proxy that re-terminates TLS (a claude.ai cloud session) is handed that proxy's CA as an optional
# BuildKit secret, `extra_ca`, and TLS checks are never switched off. What this holds:
#   - every RUN of a Dockerfile under infra/ that downloads (npm, composer, install-php-extensions, Install-Module, curl,
#     wget, pip) mounts the secret: `--mount=type=secret,id=extra_ca`, absent on a computer and in CI, so their builds are
#     unchanged;
#   - no Dockerfile, compose file or the Makefile switches a TLS check off (strict-ssl false, NODE_TLS_REJECT_UNAUTHORIZED,
#     --insecure, secure-http false, sslverify false);
#   - compose.extra-ca.yaml, which the Makefile adds when CCS_EXTRA_CA_FILE names a file, gives the secret to every
#     service compose.yaml builds;
#   - every `docker build` the Makefile runs passes $(EXTRA_CA_SECRET).
# Not seen, by design: an `ADD <url>` (infra/postgres's supercronic), which BuildKit fetches itself, outside any RUN, so
# no secret mount reaches it; that image is built only by the backup overlays, never by make check or make gate.
# Usage: build-extra-ca.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2

mapfile -t problems < <(python3 -I - "$root" <<'PY'
import os, re, sys
root = sys.argv[1]
FETCH = re.compile(r'\bnpm\s+(ci|install)\b|\bcomposer\s+(install|update|require)\b|\binstall-php-extensions\b'
                   r'|\bInstall-Module\b|\bcurl\s|\bwget\s|\bpip3?\s+install\b')
TLS_OFF = re.compile(r'strict-ssl\s*(=|\s)\s*false|NODE_TLS_REJECT_UNAUTHORIZED|--insecure\b|secure-http\s*(=|\s)\s*false'
                     r'|sslverify\s*(=|\s)\s*(false|0)', re.I)

def dockerfiles():
    out = []
    for dp, dn, fn in os.walk(os.path.join(root, 'infra')):
        out += [os.path.join(dp, f) for f in fn if f == 'Dockerfile' or f.endswith('.Dockerfile')]
    return sorted(out)

files = dockerfiles()
if not files:
    print('found no Dockerfile under infra/')
for path in files:
    rel = os.path.relpath(path, root)
    lines = open(path, encoding='utf-8').read().split('\n')
    i = 0
    while i < len(lines):
        start = i
        instr = lines[i]
        while instr.rstrip().endswith('\\') and i + 1 < len(lines):
            i += 1
            instr = instr.rstrip()[:-1] + ' ' + lines[i]
        i += 1
        code = instr.split('#')[0] if not instr.lstrip().startswith('RUN') else instr
        if TLS_OFF.search(code) and not instr.lstrip().startswith('#'):
            print('%s:%d switches a TLS check off: hand the CA to the build instead (extra_ca)' % (rel, start + 1))
        if re.match(r'\s*RUN\b', instr) and FETCH.search(instr) and 'type=secret,id=extra_ca' not in instr:
            print('%s:%d downloads without --mount=type=secret,id=extra_ca: a proxy re-terminating TLS refuses it' % (rel, start + 1))

for name in ('compose.yaml', 'compose.extra-ca.yaml', 'Makefile'):
    p = os.path.join(root, name)
    if not os.path.exists(p):
        continue
    for n, line in enumerate(open(p, encoding='utf-8'), 1):
        if not line.lstrip().startswith('#') and TLS_OFF.search(line.split(' #')[0]):
            print('%s:%d switches a TLS check off: hand the CA to the build instead (extra_ca)' % (name, n))

# compose.yaml's built services, read by indentation: a service at two spaces, its `build:` at four.
# The services the Makefile's default COMPOSE_FILE builds: compose.yaml and compose.live.yaml. A service builds when it
# says `build:`, merges a built one (`<<: *worker`, an anchor on a service's name) or extends one (`extends: service:`).
built, anchors, inherits = [], {}, {}
for name in ('compose.yaml', 'compose.live.yaml'):
    cp = os.path.join(root, name)
    if not os.path.exists(cp):
        continue
    section, service, in_extends = None, None, False
    for line in open(cp, encoding='utf-8'):
        if re.match(r'^[A-Za-z]', line):
            section = line.split(':')[0]
        if section != 'services':
            continue
        m = re.match(r'^  ([A-Za-z0-9_-]+):\s*(?:&([A-Za-z0-9_-]+))?\s*$', line)
        if m:
            service, in_extends = m.group(1), False
            if m.group(2):
                anchors[m.group(2)] = service
            continue
        if not service:
            continue
        if re.match(r'^    build:', line) and service not in built:
            built.append(service)
        mm = re.match(r'^    <<:\s*\*([A-Za-z0-9_-]+)', line)
        if mm:
            inherits[service] = ('anchor', mm.group(1))
        if re.match(r'^    extends:\s*$', line):
            in_extends = True
        elif in_extends and re.match(r'^      service:\s*([A-Za-z0-9_-]+)', line):
            inherits[service] = ('service', re.match(r'^      service:\s*([A-Za-z0-9_-]+)', line).group(1))
        elif re.match(r'^    \S', line):
            in_extends = False
changed = True
while changed:
    changed = False
    for svc, (kind, ref) in inherits.items():
        parent = anchors.get(ref) if kind == 'anchor' else ref
        if parent in built and svc not in built:
            built.append(svc); changed = True
op = os.path.join(root, 'compose.extra-ca.yaml')
if not os.path.exists(op):
    print('compose.extra-ca.yaml is missing: a cloud build would get no CA')
else:
    text = open(op, encoding='utf-8').read()
    given = set(re.findall(r'^  ([A-Za-z0-9_-]+):\s*\n    build:\s*\n      secrets:\s*\[\s*extra_ca\s*\]', text, re.M))
    for s in built:
        if s not in given:
            print('compose.extra-ca.yaml gives the service %s, which compose.yaml or compose.live.yaml builds, no extra_ca build secret' % s)
    if not re.search(r'^secrets:\s*\n  extra_ca:\s*\n    file:', text, re.M):
        print('compose.extra-ca.yaml declares no extra_ca secret read from a file')

mp = os.path.join(root, 'Makefile')
if os.path.exists(mp):
    lines = open(mp, encoding='utf-8').read().split('\n')
    for n, line in enumerate(lines, 1):
        if re.search(r'\bdocker build\b', line) and not line.lstrip().startswith('#') and '$(EXTRA_CA_SECRET)' not in line:
            print('Makefile:%d runs docker build without $(EXTRA_CA_SECRET)' % n)
PY
)

if ((${#problems[@]})); then
  printf 'build-extra-ca: FAIL — %d problem(s) with how builds trust a proxy CA (CLAUDE.md § Git, a cloud session):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'build-extra-ca: OK — every download in a build mounts the optional proxy CA, every built service and docker build is handed it, no TLS check is off\n'
