#!/usr/bin/env bash
#
# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# ==============================================================================================================
# INFRA GATE: every compose configuration renders, and the security properties survive rendering.
#
# `CLAUDE.md` § "Quality gate" has carried a row for `docker compose config` since Wave 0. This is that row.
#
# **It asserts more than "the YAML parses", and the difference is the point.** `docker compose config` performs
# variable interpolation, merges the overlay onto the base and resolves anchors — so a property that is correct in
# the source can still be wrong in the RENDERED result, which is what actually runs. Each assertion below is a
# security property that a careless overlay edit would silently undo:
#
#   - the OWNER database credential appears on the migration service and NOWHERE else;
#   - the scheduler is pinned to exactly ONE replica;
#   - the database and the document renderer are not on the externally-reachable network;
#   - the internal network has no route out;
#   - every Messenger receiver a service consumes is a transport the application actually defines;
#   - in PRODUCTION ONLY, every service drops ALL Linux capabilities.
#
# NO COUNT IS WRITTEN HERE, deliberately: this list said "the four assertions below" while there were five, and then
# six. A number written beside the thing it counts is the first thing to drift, and CLAUDE.md records that shape
# repeatedly. Count the entries if you need a number.
#
# Why each matters is in `infra/compose.yaml` beside the thing itself. The short version: `FORCE ROW LEVEL
# SECURITY` stops an owner SKIPPING policies, not REMOVING them, so an owner credential in a serving container is
# one statement from every tenant's data; and two schedulers issue every recurring invoice twice, which in a
# billing product means charging a customer twice.
#
# It needs `docker compose` but NOT a running daemon — `config` is a pure rendering operation. Where the binary is
# absent it SKIPS with a message rather than failing, which is the one place this repository tolerates a skip: a
# machine without Docker can still run every other gate, and CI has Docker.
# ==============================================================================================================

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly REPO_ROOT
readonly INFRA="$REPO_ROOT/infra"

if ! docker compose version >/dev/null 2>&1; then
    echo "compose-config: SKIPPED — \`docker compose\` is not available on this machine."
    echo "  Every other gate still runs. CI has Docker, so this is checked there."
    exit 0
fi

# A rendering-only environment. These are NOT credentials and never reach a container: `docker compose config`
# refuses to render at all when a `${VAR:?...}` is unset, which is the behaviour we want at run time and an
# obstacle at lint time. Deliberately obvious values, so a copy-paste into a real `.env` is self-evidently wrong.
readonly RENDER_ENV="$(mktemp)"

cat > "$RENDER_ENV" <<'EOF'
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=GATE-RENDERING-ONLY-NOT-A-SECRET
SERVER_NAME=:80
POSTGRES_DB=twes
POSTGRES_USER=postgres
POSTGRES_PASSWORD=GATE-RENDERING-ONLY
TWES_DB_RUNTIME_ROLE=twes
TWES_DB_RUNTIME_PASSWORD=GATE-RENDERING-ONLY
TWES_DB_OWNER_ROLE=twes_owner
TWES_DB_OWNER_PASSWORD=GATE-RENDERING-ONLY
EOF

# THE RECEIVERS THE APPLICATION ACTUALLY DEFINES, DERIVED rather than written down here. A second hand-maintained
# list is the drift this repository has been bitten by repeatedly; these come from the only two places a receiver
# can come from:
#
#   * `framework.messenger.transports.*` keys in `api/config/packages/messenger.yaml`;
#   * `scheduler_<name>` for every `#[AsSchedule('<name>')]` provider under `api/src/`, which is how Symfony
#     Scheduler names the transport it synthesises.
#
# If `messenger.yaml` is absent the set is EMPTY and every `messenger:consume` in compose is flagged — which is
# exactly the state that shipped a crash-looping worker, so the gate must be loud rather than lenient about it.
readonly RECEIVERS="$(
    python3 - "$REPO_ROOT" <<'PYEOF'
import pathlib, re, sys, yaml

root = pathlib.Path(sys.argv[1])
names = set()

cfg = root / 'api' / 'config' / 'packages' / 'messenger.yaml'
if cfg.is_file():
    doc = yaml.safe_load(cfg.read_text()) or {}
    names |= set((((doc.get('framework') or {}).get('messenger') or {}).get('transports') or {}).keys())

# ANCHORED TO THE START OF A LINE, which is not cosmetic: the unanchored pattern also matched the literal
# `#[AsSchedule('<name>')]` written inside DefaultSchedule.php's own docblock, and `scheduler_<name>` duly
# appeared in the derived set. A prose mention is not a declaration -- the same reason
# `no-orm-attributes-in-domain` reads tokens rather than grepping. A docblock line begins ` * `, so requiring
# the attribute to open its own line excludes every comment continuation.
for php in sorted((root / 'api' / 'src').rglob('*.php')):
    for m in re.finditer(r"^[ \t]*#\[AsSchedule\(\s*'([^']+)'", php.read_text(), re.MULTILINE):
        names.add('scheduler_' + m.group(1))

print(','.join(sorted(names)))
PYEOF
)"

failures=0
checked=0

# The per-service inspector, written once to a temp file. See the call site for why it is not a heredoc.
readonly INSPECTOR="$(mktemp --suffix=.py)"
trap 'rm -f "$RENDER_ENV" "$INSPECTOR"' EXIT

cat > "$INSPECTOR" <<'PYEOF'
import re, sys, yaml

overlay = sys.argv[1]
KNOWN_RECEIVERS = set(sys.argv[2].split(',')) if len(sys.argv) > 2 and sys.argv[2] else set()
cfg = yaml.safe_load(sys.stdin)
services = cfg.get('services', {})
problems = []

# 1. The owner credential belongs to the migration service alone.
holders = sorted(n for n, s in services.items() if 'DATABASE_URL_OWNER' in (s.get('environment') or {}))
if holders != ['migrate']:
    problems.append(
        'DATABASE_URL_OWNER is held by %s; it must be held by `migrate` and nothing else. A serving container '
        'that can reach the owning role can ALTER TABLE ... DISABLE ROW LEVEL SECURITY on every tenant table.'
        % (', '.join(holders) or 'no service'))

# 2. Exactly one scheduler.
replicas = ((services.get('scheduler') or {}).get('deploy') or {}).get('replicas')
if replicas is not None and int(replicas) != 1:
    problems.append(
        'the scheduler declares %s replicas. It must be ONE: two schedulers issue every recurring invoice twice, '
        'which in a billing product means charging a customer twice.' % replicas)

# 3. Nothing but the API is reachable from outside.
for name in ('database', 'valkey', 'gotenberg', 'worker', 'scheduler'):
    nets = (services.get(name) or {}).get('networks') or {}
    if 'edge' in nets:
        problems.append('%s is on the `edge` network. Only the API may be externally reachable.' % name)

# 4. The internal network really is internal.
if not (cfg.get('networks', {}).get('internal', {}) or {}).get('internal'):
    problems.append(
        'the `internal` network is not marked `internal: true`, so a compromised worker or the document renderer '
        'would have a route to the outside world.')

# 6. IN PRODUCTION, EVERY SERVICE DROPS ALL LINUX CAPABILITIES.
#    PROD ONLY, and the scoping is the point rather than an exemption: the dev overlay deliberately does NOT harden,
#    because dev optimises the developer's loop. Asserting it there would either fail permanently or push hardening
#    into dev, and both are wrong.
#
#    WHY IT NEEDS ASSERTING AT ALL, given `read_only` and `no-new-privileges` were already checked by eye:
#    capabilities are ORTHOGONAL to both and work fine on a read-only filesystem. Docker's default set includes
#    NET_RAW -- raw sockets, so a compromised process can sniff and spoof on the very network the database sits on.
#
#    IT IS ALSO A FULL-SET CHECK, which is how the gap that prompted it was found: hardening was applied to the six
#    LONG-RUNNING services and `migrate` was missed, because a one-shot does not look like a service. That was the
#    worst possible omission -- `migrate` is the only container holding the OWNER credential, the role that can
#    `DROP POLICY` on every tenant table. A per-service list would have had the same blind spot, so this iterates
#    whatever services the rendered configuration actually contains.
if 'prod' in overlay:
    undropped = sorted(n for n, svc in services.items() if 'ALL' not in (svc.get('cap_drop') or []))
    if undropped:
        problems.append(
            '%s do(es) not declare `cap_drop: [ALL]` in the production configuration. Docker grants roughly '
            'fourteen capabilities by default, including NET_RAW -- raw sockets on the internal network, which is '
            'packet sniffing and spoofing against the database. Drop ALL and add back only what the service '
            'provably needs, established by test rather than by guess.' % ', '.join(undropped))

    # 6b. AND `cap_add` MUST STAY INSIDE A MAXIMUM, because `cap_drop: [ALL]` ALONE IS TRIVIALLY HOLLOW.
    #     A certification reviewer defeated the check above by adding `cap_add: [SYS_ADMIN, NET_RAW, SYS_PTRACE,
    #     DAC_READ_SEARCH]` to `migrate` -- the container holding the OWNER credential -- and the gate reported
    #     `OK ... capabilities dropped where required`. `SYS_ADMIN` there is a container-escape primitive, and
    #     `NET_RAW` is the exact capability the comment above names as the reason any of this exists.
    #
    #     A MAXIMUM over the union rather than a per-service map, deliberately: a per-service map drifts from the
    #     services, and this list is short enough to justify entry by entry. Every member is here because a service
    #     provably fails without it (see `compose.prod.yaml`, where each carries its own evidence):
    #       NET_BIND_SERVICE -- `frankenphp` carries it as a FILE capability with the effective bit, so `execve`
    #                           itself fails with EPERM without it. Not about binding at all.
    #       CHOWN/SETUID/SETGID/DAC_OVERRIDE -- postgres chowns its data directory then drops privilege; valkey's
    #                           `setpriv` needs the two id-setting ones. DAC_OVERRIDE is only detectable on RESTART.
    #     Adding an identifier here is a security decision: state the service, the failure without it, and how that
    #     failure was observed.
    PERMITTED_CAP_ADDS = {'NET_BIND_SERVICE', 'CHOWN', 'SETUID', 'SETGID', 'DAC_OVERRIDE'}
    for name, svc in sorted(services.items()):
        excess = sorted(c for c in (svc.get('cap_add') or []) if c.upper() not in PERMITTED_CAP_ADDS)
        if excess:
            problems.append(
                '%s re-grants %s via `cap_add`, which is outside the permitted maximum (%s). `cap_drop: [ALL]` '
                'followed by an unrestricted `cap_add` is not hardening -- SYS_ADMIN is a container-escape '
                'primitive and NET_RAW is packet sniffing and spoofing on the network the database sits on.'
                % (name, ', '.join(excess), ', '.join(sorted(PERMITTED_CAP_ADDS))))

    # 6c. AND THE OTHER TWO PROD PROPERTIES MUST BE ASSERTED RATHER THAN "checked by eye", which is what the header of
    #     this file used to claim. The same reviewer removed `read_only`, all three `tmpfs` entries AND
    #     `security_opt: no-new-privileges:true` from `migrate` and the gate stayed green -- so the one container that
    #     can `DROP POLICY` on every tenant table could lose its read-only root filesystem silently.
    #
    #     `read_only` is required of the PHP services and of valkey. `database` and `gotenberg` are EXEMPT and each
    #     exemption is a real requirement rather than a concession: PostgreSQL writes its data directory, and
    #     Chromium needs a writable profile and shared memory. Naming them here means adding a third exemption is a
    #     visible edit rather than a silent omission.
    READ_ONLY_EXEMPT = {'database', 'gotenberg'}
    for name, svc in sorted(services.items()):
        if name not in READ_ONLY_EXEMPT and not svc.get('read_only'):
            problems.append(
                '%s does not set `read_only: true` in the production configuration. A writable root filesystem lets '
                'a PHP remote-code-execution write a webshell somewhere that will be executed; only `database` and '
                '`gotenberg` are exempt, and each because it genuinely must write.' % name)
        if 'no-new-privileges:true' not in (svc.get('security_opt') or []):
            problems.append(
                '%s does not set `security_opt: [no-new-privileges:true]` in the production configuration. Without '
                'it a setuid binary inside the image can still raise privilege, which defeats the point of dropping '
                'capabilities in the first place.' % name)

# 7. THE DOCUMENT RENDERER MUST BE ABLE TO RENDER. Gotenberg takes an allow-list and a deny-list and applies them as
#    a CONJUNCTION -- a deny match is absolute -- so `--chromium-deny-list=.*` refuses EVERY conversion including the
#    local ones the allow-list was meant to permit, and no allow-list can override it. That shipped: the renderer was
#    configured to render nothing, and the `/health` endpoint reported `chromium: up` throughout, so the compose gate's
#    own evidence for the service could not tell a working renderer from a broken one.
#
#    Checked as a RELATIONSHIP between the two flags rather than as a banned string: a deny-list is legitimate, and an
#    allow-list is legitimate; what is never right is a deny-list that matches everything the allow-list permits.
for name, svc in sorted(services.items()):
    cmd = svc.get('command') or []
    if isinstance(cmd, str):
        cmd = cmd.split()
    deny = next((a.split('=', 1)[1] for a in cmd if str(a).startswith('--chromium-deny-list=')), None)
    allow = next((a.split('=', 1)[1] for a in cmd if str(a).startswith('--chromium-allow-list=')), None)
    if deny is not None and allow is not None and re.fullmatch(r'\.\*|\^?\.\*\$?', deny.strip()):
        problems.append(
            '%s sets `--chromium-deny-list=%s` alongside an allow-list. Gotenberg applies the two as a conjunction '
            'and a deny match is ABSOLUTE, so this refuses every conversion including the local ones the allow-list '
            'permits -- the renderer would render nothing while still reporting healthy. Express the restriction as '
            'the allow-list alone.' % (name, deny))

# 5. Every Messenger receiver a service consumes must be a transport the application actually defines.
#    This is the assertion that was missing when `worker` and `scheduler` crash-looped on `docker compose up`:
#    compose said `messenger:consume async` and no `config/packages/messenger.yaml` existed, so the command
#    exited with `The receiver "async" does not exist.` Compose cannot know that -- but it CAN be told which
#    receivers the application defines, and the two lists can be compared here.
for name, svc in services.items():
    cmd = svc.get('command') or []
    if isinstance(cmd, str):
        cmd = cmd.split()
    if 'messenger:consume' not in cmd:
        continue
    consumed = [a for a in cmd[cmd.index('messenger:consume') + 1:] if not a.startswith('-')]
    unknown = [r for r in consumed if r not in KNOWN_RECEIVERS]
    if unknown:
        problems.append(
            'service `%s` consumes Messenger receiver(s) %s, which the application does not define. '
            '`messenger:consume` exits with "The receiver ... does not exist." and the container crash-loops. '
            'Known receivers: %s.' % (name, ', '.join(unknown), ', '.join(sorted(KNOWN_RECEIVERS))))

for p in problems:
    print('  FAIL — %s: %s' % (overlay, p))

sys.exit(1 if problems else 0)
PYEOF

render() {
    docker compose --env-file "$RENDER_ENV" -f "$INFRA/compose.yaml" -f "$INFRA/$1" config 2>&1
}

for overlay in compose.override.yaml compose.prod.yaml; do
    checked=$((checked + 1))

    if ! rendered="$(render "$overlay")"; then
        printf '  FAIL — %s does not render:\n%s\n' "$overlay" "$rendered"
        failures=$((failures + 1))
        continue
    fi

    printf '  ok   — %s renders\n' "$overlay"

    # The rendered document is inspected with Python rather than grep: `grep -q DATABASE_URL_OWNER` would match the
    # string anywhere in the file, including in a comment or in the migration service where it BELONGS. The
    # question is per-service, so it has to be asked of the parsed structure.
    #
    # The inspector is written to a FILE rather than heredoc'd, because `python3 - <<'PY' <<<"$rendered"` has TWO
    # stdin redirections and the last one wins -- so Python received the rendered YAML as its own source and died
    # with `SyntaxError: invalid decimal literal` on `timeout: 3s`. Script and data must arrive by different routes.
    if ! printf '%s' "$rendered" | python3 "$INSPECTOR" "$overlay" "$RECEIVERS"
    then
        failures=$((failures + 1))
    else
        printf '  ok   — %s: owner credential confined, scheduler pinned to 1, edge network minimal, every consumed receiver defined, capabilities dropped where required\n' "$overlay"
    fi
done

# Anti-vacuity, for the reason every other gate here has one: a loop that iterated over nothing prints no failures
# and exits 0, which is indistinguishable from a clean run.
printf 'compose-config: counts — configurations=%d failures=%d\n' "$checked" "$failures"

if [[ "$checked" -eq 0 ]]; then
    echo "compose-config: FAIL — no configuration was checked, so this gate asserted nothing." >&2
    exit 1
fi

if [[ "$failures" -ne 0 ]]; then
    echo "compose-config: FAIL — $failures configuration(s) are wrong." >&2
    exit 1
fi

echo "compose-config: OK — $checked configuration(s) render and hold their security properties."
