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
#     service compose.yaml builds, and names no service compose.yaml does not define (it also goes with compose.yaml
#     alone, `make up-images`, where such a service is built from ./Dockerfile and the stack does not start);
#   - compose.live-extra-ca.yaml, which the Makefile adds beside compose.live.yaml only, gives the secret to what only
#     compose.live.yaml builds, and hands the CA at run time to every live service that downloads as it starts (a mounted
#     start script that runs composer install or npm ci, or an image's own start script declared in IMAGE_STARTS, at
#     least three), each declared one running as the host user, since its image fixes uid 1000 and a cloud tree is root's;
#   - every `docker build` the Makefile runs passes $(EXTRA_CA_SECRET);
#   - every `docker run` the Makefile starts by hand, outside compose (Playwright's browser fetch, the RC lane), passes
#     $(EXTRA_CA_RUN), the same file mounted at run time, unless it says `--network none`;
#   - every toolchain service (compose.yaml, profile `tools`) is handed the file at run time too, mounted read-only at
#     /etc/ccs-ca.crt with the variable each tool reads pointed at it, since composer install, npm ci and git run there
#     (git through GIT_SSL_CAINFO: Debian builds it on GnuTLS, which never reads SSL_CERT_FILE).
# A script's own `docker run` is not read here: most reach nothing; scripts/security/image-scan.sh's trivy, which does,
# mounts the file itself (its test holds it).
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
defined = {'compose.yaml': set(), 'compose.live.yaml': set()}
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
            defined[name].add(service)
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
def blocks_of(text):
    """Each service's lines in an overlay, by indentation: a service at two spaces, its keys deeper."""
    blocks, service, section = {}, None, None
    for line in text.split('\n'):
        if re.match(r'^\S', line):
            section, service = line.split(':')[0], None
        m = re.match(r'^  ([A-Za-z0-9_-]+):', line)
        if section == 'services' and m:
            service = m.group(1); blocks[service] = ''
        elif service:
            blocks[service] += line + '\n'
    return blocks

def gives_secret(text):
    return set(re.findall(r'^  ([A-Za-z0-9_-]+):\s*\n(?:    (?!build:).*\n)*    build:\s*\n      secrets:\s*\[\s*extra_ca\s*\]', text, re.M))

CA_VARS = ('SSL_CERT_FILE', 'NODE_EXTRA_CA_CERTS', 'COMPOSER_CAFILE', 'REQUESTS_CA_BUNDLE', 'GIT_SSL_CAINFO')
def ca_at_run_time(overlay, svc, block):
    """The CA mounted read-only where every tool's variable points, as the toolchain's runs have it."""
    if not re.search(r'^\s+- \$\{CCS_EXTRA_CA_FILE[^}]*\}:/etc/ccs-ca\.crt:ro\s*$', block, re.M):
        print('%s: %s: the CA is not mounted read-only at /etc/ccs-ca.crt' % (overlay, svc))
    for var in CA_VARS:
        if not re.search(r'^\s+' + var + r':\s*/etc/ccs-ca\.crt\s*$', block, re.M):
            print('%s: %s: %s does not name the mounted CA' % (overlay, svc, var))

# The images' overlay goes with compose.yaml alone too (`make up-images`): a service it names that compose.yaml does not
# define becomes one built from ./Dockerfile, and the stack does not start. What only compose.live.yaml builds takes its
# secret from the live overlay, which the Makefile adds beside compose.live.yaml only.
images_built = [s for s in built if s in defined['compose.yaml']]
live_built = [s for s in built if s not in defined['compose.yaml']]
op = os.path.join(root, 'compose.extra-ca.yaml')
if not os.path.exists(op):
    print('compose.extra-ca.yaml is missing: a cloud build would get no CA')
else:
    text = open(op, encoding='utf-8').read()
    given = gives_secret(text)
    for s in images_built:
        if s not in given:
            print('compose.extra-ca.yaml gives the service %s, which compose.yaml builds, no extra_ca build secret' % s)
    for s in sorted(blocks_of(text)):
        if s not in defined['compose.yaml']:
            print('compose.extra-ca.yaml names %s, which compose.yaml does not define: with compose.yaml alone (make up-images) compose builds it from ./Dockerfile; it belongs in compose.live-extra-ca.yaml' % s)
    if not re.search(r'^secrets:\s*\n  extra_ca:\s*\n    file:', text, re.M):
        print('compose.extra-ca.yaml declares no extra_ca secret read from a file')
    # The toolchain's services, by their profile, and what the overlay says for each, by indentation.
    toolchain, service = [], None
    cp = os.path.join(root, 'compose.yaml')
    for line in (open(cp, encoding='utf-8') if os.path.exists(cp) else []):
        m = re.match(r'^  ([A-Za-z0-9_-]+):', line)
        if m:
            service = m.group(1)
        elif service and re.match(r'^    profiles:.*\btools\b', line) and service not in toolchain:
            toolchain.append(service)
    blocks = blocks_of(text)
    if not toolchain:
        print('compose.yaml has no service under the tools profile: the toolchain runs could not be checked')
    for svc in toolchain:
        block = blocks.get(svc)
        if block is None:
            print('compose.extra-ca.yaml hands the toolchain service %s no CA at run time' % svc)
            continue
        ca_at_run_time('compose.extra-ca.yaml', svc, block)

# Live development downloads as it starts, not only at build: the api and migrate through the mounted live entrypoint
# (composer install), the web through its image's own start script (npm ci). Found from compose.live.yaml where the
# start is a mounted script; the image's own start scripts are declared here, each read so the list stays true.
IMAGE_STARTS = {'web': 'infra/web/live.sh'}  # their image fixes a user (uid 1000), which a cloud tree (root's) refuses
FLOOR = 3
live_path = os.path.join(root, 'compose.live.yaml')
lp = os.path.join(root, 'compose.live-extra-ca.yaml')
if not os.path.exists(live_path):
    print('compose.live.yaml is missing: the live stack could not be checked')
elif not os.path.exists(lp):
    print('compose.live-extra-ca.yaml is missing: a cloud session\'s live stack would download without the CA')
else:
    live_blocks = blocks_of(open(live_path, encoding='utf-8').read())
    def downloads(rel):
        p = os.path.join(root, rel)
        return os.path.exists(p) and any(FETCH.search(l) for l in open(p, encoding='utf-8') if not l.lstrip().startswith('#'))
    starters = {}
    for svc, block in live_blocks.items():
        ep = re.search(r'^    entrypoint:\s*\[\s*"([^"]+)"', block, re.M)
        if not ep:
            continue
        mount = re.search(r'^\s+- \./([^:\s]+):/usr/local/bin/' + re.escape(ep.group(1)) + r'(:ro)?\s*$', block, re.M)
        if mount and downloads(mount.group(1)):
            starters[svc] = mount.group(1)
    for svc, rel in IMAGE_STARTS.items():
        if svc not in live_blocks:
            print('build-extra-ca.sh declares %s, which compose.live.yaml does not define: update IMAGE_STARTS' % svc)
        elif not downloads(rel):
            print('%s no longer downloads as %s starts: update IMAGE_STARTS in build-extra-ca.sh' % (rel, svc))
        else:
            starters[svc] = rel
    if len(starters) < FLOOR:
        print('found %d live service(s) downloading as they start, fewer than %d: the reading of compose.live.yaml broke, or the list is out of date' % (len(starters), FLOOR))
    ltext = open(lp, encoding='utf-8').read()
    lblocks = blocks_of(ltext)
    lgiven = gives_secret(ltext)
    for s in live_built:
        if s not in lgiven:
            print('compose.live-extra-ca.yaml gives the service %s, which only compose.live.yaml builds, no extra_ca build secret' % s)
    for s in sorted(lblocks):
        if s not in defined['compose.yaml'] | defined['compose.live.yaml']:
            print('compose.live-extra-ca.yaml names %s, which neither compose.yaml nor compose.live.yaml defines' % s)
    for svc, rel in sorted(starters.items()):
        block = lblocks.get(svc)
        if block is None:
            print('compose.live-extra-ca.yaml: %s downloads as it starts (%s) but is handed no CA at run time' % (svc, rel))
            continue
        ca_at_run_time('compose.live-extra-ca.yaml', svc, block)
    for svc in IMAGE_STARTS:
        block = lblocks.get(svc, '')
        if not re.search(r'^    user:\s*"\$\{HOST_UID[^}]*\}:\$\{HOST_GID[^}]*\}"\s*$', block, re.M):
            print('compose.live-extra-ca.yaml: %s does not run as the host user (user: "${HOST_UID}:${HOST_GID}"): its image fixes uid 1000, and a cloud session\'s tree is root\'s' % svc)

mp = os.path.join(root, 'Makefile')
if os.path.exists(mp):
    lines = open(mp, encoding='utf-8').read().split('\n')
    if not any('compose.live-extra-ca.yaml' in l and not l.lstrip().startswith('#') for l in lines):
        print('Makefile never adds compose.live-extra-ca.yaml: a cloud session\'s live stack would download without the CA')
    for n, line in enumerate(lines, 1):
        if re.search(r'\bdocker build\b', line) and not line.lstrip().startswith('#') and '$(EXTRA_CA_SECRET)' not in line:
            print('Makefile:%d runs docker build without $(EXTRA_CA_SECRET)' % n)
    # A `docker run` is read with its continuation lines, since its options often follow on the next ones.
    n = 0
    while n < len(lines):
        start, run = n + 1, lines[n]
        while run.rstrip().endswith('\\') and n + 1 < len(lines):
            n += 1
            run = run.rstrip()[:-1] + ' ' + lines[n]
        n += 1
        if re.search(r'\bdocker run\b', run) and not run.lstrip().startswith('#') \
                and '$(EXTRA_CA_RUN)' not in run and not re.search(r'--network[ =]none\b', run):
            print('Makefile:%d runs docker run without $(EXTRA_CA_RUN): a proxy re-terminating TLS refuses what it downloads' % start)
PY
)

if ((${#problems[@]})); then
  printf 'build-extra-ca: FAIL — %d problem(s) with how builds trust a proxy CA (CLAUDE.md § Git, a cloud session):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'build-extra-ca: OK — every download in a build mounts the optional proxy CA, every built service, docker build and docker run is handed it, every toolchain run too, no TLS check is off\n'
