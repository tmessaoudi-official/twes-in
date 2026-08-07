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
# machine without Docker can still run every other gate. The skip message does NOT claim CI covers it -- it said so
# for two commits and there is no `.github/` in this repository, so the honest statement is that the rendered half
# is unchecked until someone runs it with Docker. Naming a CI that does not exist is how a gap stops being owed.
# ==============================================================================================================

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly REPO_ROOT
readonly INFRA="$REPO_ROOT/infra"

# THE CADDYFILE SEAM KEYS, DERIVED from the Caddyfile's own `{$...}` placeholders rather than written down here.
# A hand-written list held TWO of the four for a commit, and the two it omitted are the worst two to omit:
# `{$CADDY_GLOBAL_OPTIONS}` splices into the global block that CONTAINS `frankenphp { }`, and
# `{$CADDY_EXTRA_CONFIG}` is where an `import` would live. `SERVER_NAME` is excluded because it is a hostname and
# must be non-empty -- the one placeholder the emptiness rule cannot apply to. Same derivation, same exclusion and
# same reasoning as `scripts/gates/worker-mode-blocked.sh`, which is deliberate: two halves of one control asking
# different questions of the same knob set is how the seam gap survived.
# `|| true` HERE IS NOT A BANDAID, and it has the evidence the anti-bandaid gate requires. The failure mode is
# exactly one thing: `grep` exits 1 when it matches nothing, and under `set -euo pipefail` that aborts the
# ASSIGNMENT — so the explicit guard below, written for precisely that case, never ran. [Verified: with every
# placeholder renamed, the gate exited 1 printing NOTHING AT ALL, because `set -e` killed it before the
# diagnostic.] The root cause is not suppressed: an empty derivation is REFUSED three lines down, with a message.
# What `|| true` does is let "no match" become an empty string that a real check can then reject, rather than an
# abort with no explanation.
# THE SEAM KEYS, from `lib/caddy-configs.sh` — the SAME derivation `worker-mode-blocked.sh` uses, and that is the
# fix rather than a tidy-up. This gate walked `find "$INFRA"` while the sibling walked every tracked path containing
# `Caddyfile` plus the source of every served `COPY`; a certification round added a tracked `admin/Caddyfile` and got
# FOUR seams here against FIVE there, so the rendered half — the only half that can see an `env_file:`, a YAML anchor
# or a value assembled across an overlay — never checked the fifth. Both commit messages asserted the sets matched.
#
# `TWES_CADDY_NO_INDEX=1` because THIS gate's meta-suite fixture is a plain directory copy with no git index. That is
# a DECLARED degradation, not a silent one: the fallback walk is scoped to `infra/`, narrow enough that it cannot
# reach the reviewer worktrees at `.claude/worktrees/` that § Gotchas 2026-07-31 forbids walking into. An
# index-based-only version FAILED that fixture, which is how the coupling was found.
# shellcheck source=lib/caddy-configs.sh
source "$REPO_ROOT/scripts/gates/lib/caddy-configs.sh"

SEAM_KEYS="$(
    if git -C "$REPO_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        caddy_seam_variables "$REPO_ROOT" 'SERVER_NAME'
    else
        TWES_CADDY_NO_INDEX=1 caddy_seam_variables "$REPO_ROOT" 'SERVER_NAME'
    fi | paste -sd, -
)"
readonly SEAM_KEYS

# THE CONFIG PATHS THE DOCKERFILES ACTUALLY SERVE, derived from their own `--config` arguments rather than written
# down. Used to refuse a `volumes:` mount that replaces the served config — a route invisible to every text rule and
# to the oracle alike, because a mount is neither a tracked Caddyfile nor a `COPY` source.
SERVED_CONFIG_PATHS="$(
    {
        if git -C "$REPO_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
            git -C "$REPO_ROOT" ls-files -z | tr '\0' '\n' | grep -E '(^|/)Dockerfile' | sed "s|^|$REPO_ROOT/|"
        else
            # No index (the meta-suite fixture is a plain directory copy). Scoped to `infra/`, narrow enough that it
            # cannot reach the reviewer worktrees at `.claude/worktrees/` that § Gotchas 2026-07-31 forbids walking.
            find "$INFRA" -type f -name 'Dockerfile*' 2>/dev/null
        fi
    } | xargs -r grep -ohE -- '--config[=[:space:],"]+[^",[:space:]]+' 2>/dev/null \
        | grep -ohE '/[^",[:space:]]+' | sort -u | paste -sd, - || true
)"
readonly SERVED_CONFIG_PATHS

# A DERIVED SET THAT CAME BACK EMPTY MEANS THE DERIVATION BROKE, not that nothing is served. The first version read
# `git ls-files` only, so in the meta-suite's index-less fixture the set was empty and the mount check silently
# asserted NOTHING — the fail-open shape this whole control has been rewritten to remove, reintroduced in the fix for
# it. Refused with a message instead.
if [[ -z "$SERVED_CONFIG_PATHS" ]]; then
    echo "compose-config: FAIL — derived NO served config path from any Dockerfile, so the mount check would assert" >&2
    echo "  nothing. Either every Dockerfile moved or none passes --config to the server." >&2
    exit 1
fi

# THE PINNED IMAGE, read from the Dockerfile's own ARG rather than written here -- a second copy of a version pin is
# how the two drift, and this project has paid for a hand-written list at every level already.
FRANKENPHP_IMAGE=""
if frankenphp_version="$(grep -m1 -oE 'ARG FRANKENPHP_VERSION=[^[:space:]]+' "$INFRA/api/Dockerfile" 2>/dev/null)"; then
    FRANKENPHP_IMAGE="dunglas/frankenphp:${frankenphp_version#ARG FRANKENPHP_VERSION=}"
fi
readonly FRANKENPHP_IMAGE

# The Caddy configs the oracle adapts, from the SHARED derivation both worker-mode gates use.
CADDY_CONFIGS=()
if git -C "$REPO_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    mapfile -t CADDY_CONFIGS < <(caddy_config_paths "$REPO_ROOT")
else
    mapfile -t CADDY_CONFIGS < <(TWES_CADDY_NO_INDEX=1 caddy_config_paths "$REPO_ROOT")
fi

# A DERIVED set that came back empty means the derivation broke, not that there are no seams -- and the seam loop
# would then check nothing while every other property still reported clean. That is fail-OPEN, which is the exact
# polarity error the sibling gate was rewritten to remove, so it is refused here rather than tolerated. (The first
# draft of this derivation carried `2>/dev/null` on the grep, which would have turned a renamed Caddyfile into a
# silent pass.)
if [[ -z "$SEAM_KEYS" ]]; then
    echo "compose-config: FAIL — derived NO Caddyfile seam keys from any tracked Caddyfile, so the seam check" >&2
    echo "  would assert nothing. Either every Caddyfile moved out of the index or their \`{\$...}\` placeholders" >&2
    echo "  changed spelling." >&2
    exit 1
fi

# INTROSPECTION, so the meta-suite can generate a case per rule and pin the SET SIZE. Round 4 filed the absence:
# this was one of three gates with no `--dump-rules`, and the worker-mode block's four rule entries therefore had
# no floor — dropping half of them left `test-gates.sh` at 400/0. Printed BEFORE the `docker compose` probe, so a
# machine without Docker can still be asked what the rules are; that is the whole reason the meta-suite can pin
# them without a daemon.
if [[ "${1:-}" == '--dump-rules' ]]; then
    printf 'permitted_runtime %s\n' 'Symfony\Component\Runtime\SymfonyRuntime'
    printf 'worker_env_key %s\n' ${SEAM_KEYS//,/ }
    printf 'read_only_exempt %s\n' 'database' 'gotenberg'
    printf 'permitted_cap_add %s\n' 'NET_BIND_SERVICE' 'CHOWN' 'SETUID' 'SETGID' 'DAC_OVERRIDE'
    exit 0
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "compose-config: SKIPPED — \`docker compose\` is not available on this machine."
    echo "  Every other gate still runs, INCLUDING scripts/gates/worker-mode-blocked.sh, which covers the"
    echo "  worker-mode routes that need no daemon. What is NOT covered here is the RENDERED configuration:"
    echo "  an overlay, a YAML anchor, an \`env_file:\`, a value assembled from two files. Nothing else sees those."
    echo "  There is no .github/ in this repository, so no CI run covers it either -- it is genuinely unchecked"
    echo "  until this is run on a machine with Docker."
    exit 0
fi

# A rendering-only environment. These are NOT credentials and never reach a container: `docker compose config`
# refuses to render at all when a `${VAR:?...}` is unset, which is the behaviour we want at run time and an
# obstacle at lint time. Deliberately obvious values, so a copy-paste into a real `.env` is self-evidently wrong.
readonly RENDER_ENV="$(mktemp)"

# SEEDED FROM THE REAL `infra/.env`, then only the SECRETS overridden. The first version wrote a wholly synthetic
# file, and `docker compose --env-file` REPLACES the project env-file rather than layering over it -- so every value
# declared in the tracked `infra/.env` was structurally invisible to this gate. That mattered twice: it made
# `CLAUDE.md`'s claim that "both directions are needed and neither is sufficient" false (the rendered half never
# covered the text half's primary file), and it made the runtime-role check below unable to see the very edit it
# exists to catch -- a reviewer changed `TWES_DB_RUNTIME_ROLE` to the owner role and this gate rendered the
# PLACEHOLDER value instead, reporting OK.
#
# Later assignments win in a compose env-file [Verified: `X=a` then `X=b` renders `b`], so appending the placeholder
# secrets after the real file gives both properties: real values are visible, and `${VAR:?...}` still renders
# without a credential in the repository.
#
# ONLY the four secrets are overridden. Role NAMES are deliberately NOT, because they are what the check reads; and
# `SERVER_NAME` is not, because it splices into the Caddyfile and a tampered value must reach the renderer.
cat "$INFRA/.env" > "$RENDER_ENV"

cat >> "$RENDER_ENV" <<'EOF'
APP_SECRET=GATE-RENDERING-ONLY-NOT-A-SECRET
POSTGRES_PASSWORD=GATE-RENDERING-ONLY-NOT-A-SECRET
TWES_DB_RUNTIME_PASSWORD=GATE-RENDERING-ONLY-NOT-A-SECRET
TWES_DB_OWNER_PASSWORD=GATE-RENDERING-ONLY-NOT-A-SECRET
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
import json
import re
import subprocess
import sys

import yaml

overlay = sys.argv[1]
KNOWN_RECEIVERS = set(sys.argv[2].split(',')) if len(sys.argv) > 2 and sys.argv[2] else set()
# The API tier's root, so DBAL's own DsnParser can be reached, and the config paths the Dockerfiles actually SERVE.
API_DIR = sys.argv[4] if len(sys.argv) > 4 else ''
SERVED_CONFIG_PATHS = {p for p in (sys.argv[5].split(',') if len(sys.argv) > 5 and sys.argv[5] else []) if p}
# Derived by the caller from the Caddyfile, never hard-coded: see the SEAM_KEYS comment in compose-config.sh.
SEAM_KEYS = [k for k in (sys.argv[3].split(',') if len(sys.argv) > 3 and sys.argv[3] else []) if k]
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

# 1b. AND THE ROLE INSIDE `DATABASE_URL` IS THE RESTRICTED ONE. Check 1 above asserts only that the owner
#     *variable* is confined to `migrate`, and a certification round showed that is not the same property: the
#     runtime DSN's user is interpolated from `TWES_DB_RUNTIME_ROLE`, so changing ONE TOKEN in the tracked
#     `infra/.env` gives every serving container the owning role, with every gate green. The reviewer proved the
#     breach against the live migrated database -- `ALTER TABLE document DISABLE ROW LEVEL SECURITY`,
#     `relrowsecurity` t -> f.
#
#     Nothing else could see it: `docker-entrypoint.sh` refuses `DATABASE_URL_OWNER` in the environment and never
#     inspects the role inside `DATABASE_URL`; `no-owner-connection-in-application.php` looks for the named `owner`
#     CONNECTION, not a DSN; `schema-tenancy.php` is TOLD the runtime role's name and believes it.
#
#     RELATIONAL, not an allow-list of role names, so it survives an operator renaming the roles: whatever user the
#     OWNER dsn names, no other DSN in the configuration may use it. That is the same shape as the Gotenberg
#     allow/deny check below -- assert the relationship between two values rather than banning a literal.
def dsn_users(values):
    """Resolve DSN users with DBAL's OWN parser, never a regex.

    A regex here was a P0 twice over, because the gate's parser and the app's disagreed in two ways a certification
    round exploited against the live database:

      `postgresql://twes%5Fowner:pw@db/twes`            DBAL rawurldecodes -> twes_owner; the regex saw `twes%5Fowner`
      `postgresql://twes:pw@db/twes?user=twes_owner`    DBAL merges the QUERY over the userinfo; the regex saw `twes`

    Both handed a serving container the table-OWNING role, which can `ALTER TABLE ... DISABLE ROW LEVEL SECURITY` in
    one statement -- proven live, `relrowsecurity` t -> f. The lesson generalises past DSNs: every check in this file
    that re-implements a parser the real consumer already has will diverge from it eventually, so call the consumer's.

    Failure to reach the parser is a VIOLATION, not a skip: an unverifiable DSN has not been cleared.
    """
    script = (
        'require $argv[1] . "/vendor/autoload.php";'
        '$parser = new Doctrine\\DBAL\\Tools\\DsnParser(["postgresql" => "pdo_pgsql", "postgres" => "pdo_pgsql"]);'
        '$out = [];'
        'foreach (array_slice($argv, 2) as $dsn) {'
        '    try { $out[$dsn] = $parser->parse($dsn)["user"] ?? null; }'
        '    catch (\\Throwable $e) { $out[$dsn] = null; }'
        '}'
        'echo json_encode($out);'
    )
    wanted = sorted({v for v in values if v})
    if not wanted:
        return {}
    done = subprocess.run(['php', '-r', script, API_DIR] + wanted, capture_output=True, text=True)
    if done.returncode != 0:
        raise RuntimeError('DBAL DsnParser unreachable: ' + (done.stderr or '').strip().split('\n')[0])
    return json.loads(done.stdout)


all_dsns = []
for _svc in services.values():
    _env = _svc.get('environment') or {}
    if isinstance(_env, list):
        _env = dict((e.split('=', 1) + [''])[:2] for e in _env)
    for _key in ('DATABASE_URL', 'DATABASE_URL_OWNER'):
        if _env.get(_key):
            all_dsns.append(str(_env[_key]))

try:
    RESOLVED = dsn_users(all_dsns)
except RuntimeError as exc:
    problems.append('could not resolve DSN users with DBAL\'s own parser (%s), so the runtime role was NOT '
                    'verified. An unverifiable credential is a violation, not a pass.' % exc)
    RESOLVED = {}


def dsn_user(value):
    return RESOLVED.get(str(value or ''))


# The superuser's name is configuration, so it is READ rather than guessed. `postgres` and `root` stay as a floor
# for the case where no service declares POSTGRES_USER at all.
superuser_roles = {'postgres', 'root'} | {
    str(env.get('POSTGRES_USER')) for env in ((s.get('environment') or {}) for s in services.values())
    if env.get('POSTGRES_USER')
}

owner_roles = {
    dsn_user(env.get('DATABASE_URL_OWNER'))
    for env in ((s.get('environment') or {}) for s in services.values())
    if dsn_user(env.get('DATABASE_URL_OWNER'))
}

if not owner_roles:
    problems.append(
        'no service declares DATABASE_URL_OWNER, so the runtime role could not be compared against the owning '
        'role and this check asserted NOTHING. A rendered configuration with no owner DSN is not a safe one -- it '
        'means migrations have nowhere safe to run.')

for name, svc in sorted(services.items()):
    env = svc.get('environment') or {}
    runtime_role = dsn_user(env.get('DATABASE_URL'))
    if runtime_role is None:
        continue
    if runtime_role in owner_roles:
        problems.append(
            '%s connects as "%s" in DATABASE_URL, which is the role DATABASE_URL_OWNER names. The runtime role '
            'must own nothing: a table\'s owner can ALTER TABLE ... DISABLE ROW LEVEL SECURITY in one statement, '
            'and FORCE ROW LEVEL SECURITY stops an owner SKIPPING policies, not REMOVING them.'
            % (name, runtime_role))
    # RELATIONAL, like the owner check beside it. This was a literal `('postgres','root')` name list while the
    # superuser's name is CONFIGURATION in the same rendered file (`POSTGRES_USER`) -- so renaming both let the
    # runtime connect as the cluster superuser, which is exempt from row-level security ENTIRELY. The relational
    # data was available and the adjacent clause's own stated principle was simply not applied here.
    if runtime_role and runtime_role in superuser_roles:
        problems.append(
            '%s connects as "%s" in DATABASE_URL, which is the role POSTGRES_USER names — the cluster superuser. A '
            'superuser is exempt from row-level security entirely, so every tenancy assertion in this project would '
            'be vacuous against it.' % (name, runtime_role))

# 1b-bis. EVERY SERVICE THAT CONNECTS AS THE RUNTIME ROLE MUST CARRY `TWES_ASSERT_REVOKED_CAPABILITIES`.
#
#     Round 3's R3S-4: `infra/.env` set it to 1 with a comment saying "TRUE for the containerised stack", and it was
#     absent from the `x-api-environment` anchor, from every Dockerfile and from the entrypoint — so it was only a
#     compose INTERPOLATION variable and never entered a container. With it unset, `api/.env`'s 0 won in
#     `APP_ENV=prod`, and the two capability assertions whose own docblock says *"this is the only place they can run
#     at all"* ran in no place at all.
#
#     Asked of the RENDERED environment per service, not of the file: a value in `.env` that never reaches a container
#     is exactly the defect, so reading `.env` would reproduce it. And asked of the services that connect as the
#     runtime role rather than of all of them, because that is the set the assertions are about — `database` and
#     `valkey` have no `DATABASE_URL` and no PHP process to assert anything.
for name, svc in sorted(services.items()):
    env = svc.get('environment') or {}
    if dsn_user(env.get('DATABASE_URL')) is None:
        continue
    flag = env.get('TWES_ASSERT_REVOKED_CAPABILITIES')
    if flag is None:
        problems.append(
            '%s connects as a runtime role and does not carry TWES_ASSERT_REVOKED_CAPABILITIES in its rendered '
            'environment. The two capability assertions it gates -- no temporary objects, no large objects -- can '
            'run NOWHERE else, and pg_largeobject cannot carry row-level security at any privilege level. A value '
            'set only in infra/.env is a compose interpolation variable and never reaches a container.' % name)
    elif str(flag).strip() not in ('1', 'true', 'True'):
        problems.append(
            '%s carries TWES_ASSERT_REVOKED_CAPABILITIES=%r, which disables the two capability assertions in the '
            'containerised stack -- the one deployment whose database init script performs the revocations they '
            'check.' % (name, flag))

# 1c. NO SERVICE MAY OVERRIDE THE SERVER INVOCATION. FrankenPHP takes `--worker` as a CLI FLAG -- an entire switch
#     class no text rule in this repository had a rule for, found by a certification round and proven resident
#     (four requests, one pid). `docker-entrypoint.sh` ends in `exec "$@"`, so a compose `command:` IS the server
#     invocation.
#
#     An ALLOW-LIST of what a command may name, not a block-list of flags: the next worker spelling is a flag
#     nobody has written yet, and this project has been defeated three times by enumerating the forbidden side.
#     No compose service has any business naming the server binary at all -- the image's own CMD does that.
for name, svc in sorted(services.items()):
    # `entrypoint:` AS WELL AS `command:`. Reading only `command` was a P0 on two lenses at once: `entrypoint:`
    # overrides the image ENTRYPOINT *and discards its CMD*, so it IS the invocation, and it bypasses
    # `docker-entrypoint.sh` altogether. `--worker` is a real flag of `frankenphp php-server`.
    words = []
    for field in ('command', 'entrypoint'):
        value = svc.get(field)
        words += value.split() if isinstance(value, str) else [str(w) for w in (value or [])]
    if any('frankenphp' in word for word in words):
        problems.append(
            '%s overrides the server invocation (`command` names frankenphp). The image CMD is the only place the '
            'server is invoked, because `--worker` is a CLI FLAG: a command override enables worker mode without '
            'touching APP_RUNTIME, the Caddyfile or any seam. Blocked until the client portal (Wave 10) has its '
            'own random_bytes(32) token.' % name)

# 1d. NO MOUNT MAY REPLACE THE SERVED CONFIG. A `volumes:` bind mount over the Caddyfile path is neither a name
#     match nor a `COPY` source, so it is invisible to the config derivation, to the text sweep and to the oracle --
#     which then affirmatively reports "no worker" while a resident worker runs. A reviewer proved it: four requests,
#     one process. Derived from the `--config` destinations the Dockerfiles actually serve, not a written path.
for name, svc in sorted(services.items()):
    for volume in (svc.get('volumes') or []):
        target = volume.get('target') if isinstance(volume, dict) else (
            volume.split(':')[1] if isinstance(volume, str) and ':' in volume else None)
        if target and target in SERVED_CONFIG_PATHS:
            problems.append(
                '%s mounts over %s, the config the server is handed. A mount is not a `COPY` and not a tracked '
                'Caddyfile, so it is invisible to every text rule AND to the adapt oracle — which then reports "no '
                'worker" while a resident worker runs. Blocked until the client portal (Wave 10) has its own '
                'random_bytes(32) token.' % (name, target))

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

# 6d. FRANKENPHP WORKER MODE MUST STAY OFF UNTIL THE PORTAL TOKEN EXISTS (developer ruling, 2026-08-05).
#
#     This is a TENANCY constraint wearing a performance flag's clothes, and it is here because a constraint stated
#     only in prose is the shape CLAUDE.md § Gotchas records four separate times. `UuidV7` keeps its generator state
#     in `static` properties, seeded ONCE PER PROCESS with `random_bytes(16)` and thereafter advanced only by
#     `hash('sha512', ...)`. A certification round showed 21 observed same-millisecond identifiers leak 504 of that
#     seed's 512 bits, brute-forced the last byte, and then COMPUTED a later identifier exactly -- across two
#     generator instances with different clocks.
#
#     Under `SymfonyRuntime` one process serves one request, so that chain cannot leave a tenant. A WORKER process
#     serves many, which is precisely what makes it span tenants. The ruling is that a v7 identifier is an ORDERING
#     artefact and never a credential, so the fix is a separate `random_bytes(32)` token on the unauthenticated
#     client portal (Wave 10) -- and until that exists, worker mode is the one switch that must not be thrown.
#
#     Matched on the RUNTIME CLASS and on ALL FOUR of the Caddyfile's seams, because either alone would be half a
#     check: `APP_RUNTIME` selects the runtime, and a seam can declare a worker without touching it. The seam set is
#     DERIVED (see `SEAM_KEYS` at the top of this file), not the two names this paragraph used to list -- a
#     hand-written pair omitted `CADDY_GLOBAL_OPTIONS`, which splices into the block that CONTAINS `frankenphp { }`,
#     and `CADDY_EXTRA_CONFIG`, where an `import` would live. And the test is EMPTINESS, not the substring `worker`:
#     a YAML block scalar carries the directive on lines the key never appears on.
#
#     This paragraph described the superseded mechanism for two commits after the code changed 40 lines below it,
#     which is the stale-prose defect this gate exists to catch in others. Delete this block when the portal token
#     lands, not before -- and delete `scripts/gates/worker-mode-blocked.sh` with it, which is the half this one
#     cannot see. Say so in the commit.
#     AN ALLOW-LIST, NOT A BLOCK-LIST -- and the first version of this check was a block-list, which is why round 4
#     of the certification filed it P0 on all three lenses at once. It matched `FrankenPhpWorkerRuntime` and
#     `FrankenPHPWorkerRuntime`; NEITHER CLASS EXISTS. The package's runtime is `Runtime\FrankenPhpSymfony\Runtime`,
#     which THIS REPOSITORY prescribes at `api/.env`, `infra/.env`, `api/public/index.php` and `infra/api/Dockerfile`
#     -- so an operator following our own recipe set the exact value the gate was blind to, and the meta-suite mutant
#     passed only because it had been written to the gate's own invented literal. Fixture leakage, in the one check
#     standing behind a tenancy ruling.
#
#     The lesson generalises and is the reason for the inversion: a block-list of worker spellings CANNOT BE
#     COMPLETED, because the next runtime class has a name nobody has written yet. An allow-list of the ONE runtime
#     this project runs is closed by construction, so an unrecognised value reports a violation -- the same polarity
#     rule `PostgresRowLevelSecurityIsolation::isFalse()` states for catalogue flags, applied to a config value.
PERMITTED_RUNTIME = 'Symfony\\Component\\Runtime\\SymfonyRuntime'
for name, svc in sorted(services.items()):
    env = svc.get('environment') or {}
    if isinstance(env, list):
        env = dict((e.split('=', 1) + [''])[:2] for e in env)

    # NO `continue` HERE. There was one — `if 'APP_RUNTIME' not in env: continue` — and it killed the seam loop
    # below for exactly the case that loop's own message describes: "a worker can be declared here without
    # APP_RUNTIME changing at all". A service carrying both seams and no APP_RUNTIME passed, and ADDING a
    # permitted APP_RUNTIME was what made it detectable. The guard was inverted in effect.
    runtime = str(env.get('APP_RUNTIME') or '').strip().lstrip('\\')
    if 'APP_RUNTIME' in env and runtime != PERMITTED_RUNTIME:
        problems.append(
            '%s sets APP_RUNTIME to "%s"; the only permitted value is "%s". Any other runtime is REFUSED rather '
            'than pattern-matched, because a FrankenPHP worker class cannot be enumerated in advance -- the '
            'previous version of this check looked for two class names that exist nowhere. Worker mode is blocked '
            'until the client portal (Wave 10) has its own random_bytes(32) token: UuidV7 seeds its generator '
            'state once per PROCESS, and a worker process serves many requests, so a seed recoverable from ~24 '
            'observed identifiers spans TENANTS. See CLAUDE.md Gotchas 2026-08-05.'
            % (name, runtime, PERMITTED_RUNTIME))

    # ALL FOUR SEAMS, AND `NON-EMPTY` RATHER THAN `SAYS worker`. Both corrections come from one round.
    #
    # Two of four: this loop read `FRANKENPHP_CONFIG` and `CADDY_SERVER_EXTRA_DIRECTIVES` only, while
    # `{$CADDY_GLOBAL_OPTIONS}` sits at `infra/api/Caddyfile:23` INSIDE the global block that contains
    # `frankenphp { }` -- the one place a `worker` directive most naturally goes -- and `{$CADDY_EXTRA_CONFIG}` is
    # where an `import` would live. The set is DERIVED from the Caddyfile rather than written here, so a fifth seam
    # is in scope the moment it is added; a hand-written list is exactly what was defeated.
    #
    # And `worker` as a substring was the wrong test: a YAML BLOCK SCALAR (`CADDY_GLOBAL_OPTIONS: |`) renders to a
    # multi-line value, and while THIS check would see the word in the rendered string, the sibling text sweep could
    # not -- so both halves now ask the same, stronger question. A seam must be EMPTY while worker mode is blocked.
    # That is closed by construction: there is no content to inspect, so no spelling has to be anticipated.
    for key in SEAM_KEYS:
        if str(env.get(key) or '').strip():
            problems.append(
                '%s sets %s to a non-empty value. Every Caddyfile seam must be EMPTY while worker mode is blocked '
                '-- not merely free of the word "worker" -- because a seam splices verbatim into the Caddyfile and '
                'a block scalar can carry the directive on lines the key never appears on. Blocked for the same '
                'reason as APP_RUNTIME above, and this is the half a runtime-only check would miss: a worker can '
                'be declared here without APP_RUNTIME changing at all.' % (name, key))

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

    # THE WORKER ORACLE — see `lib/worker-oracle.py` for why it exists and why it is Python. The short version: five
    # text-scanning versions of this control were each defeated within one round, so the question is put to
    # `frankenphp adapt`, which resolves the effective configuration the way the server will. The environment travels
    # as a dict into a list argv, because the bash version could not carry a multi-line value and two live injections
    # were silently truncated before reaching the server.
    #
    # The image is derived from the Dockerfile's own ARG. If it is absent the oracle SKIPS with a message naming what
    # goes unchecked — the same tolerated skip as the `docker compose` one, and for the same reason: every text rule
    # still runs, and a skip that says what it did not check is honest where a silent pass is not.
    if [[ -n "$FRANKENPHP_IMAGE" ]] && docker image inspect "$FRANKENPHP_IMAGE" >/dev/null 2>&1; then
        oracle_count="$(mktemp)"
        printf '%s' "$rendered" \
            | python3 -c 'import json,sys,yaml; print(json.dumps(yaml.safe_load(sys.stdin)))' \
            | python3 "$REPO_ROOT/scripts/gates/lib/worker-oracle.py" \
                "$FRANKENPHP_IMAGE" "$REPO_ROOT" "$overlay" \
                "$(printf '%s\n' "${CADDY_CONFIGS[@]:-}")" "$oracle_count"
        oracle_failures="$(cat "$oracle_count" 2>/dev/null || echo 1)"
        rm -f "$oracle_count"
        # A missing or unreadable count is treated as ONE FAILURE, not zero: the oracle not reporting is the same
        # class of event as the oracle finding something, and defaulting it to clean is the fail-open polarity this
        # control has been rewritten five times to remove.
        [[ "$oracle_failures" =~ ^[0-9]+$ ]] || oracle_failures=1
        failures=$((failures + oracle_failures))
    else
        printf '  ok   — %s: worker ORACLE SKIPPED, %s is not present locally. `docker pull %s` enables it.\n' \
            "$overlay" "${FRANKENPHP_IMAGE:-<unresolved>}" "${FRANKENPHP_IMAGE:-<unresolved>}"
        printf '         Unchecked without it: any worker reachable through Caddy GRAMMAR rather than a literal — a\n'
        printf '         seam value, a SERVER_NAME that restructures blocks, an `import`. The text rules still cover\n'
        printf '         APP_RUNTIME, extra.runtime and the server invocation.\n'
    fi

    # The rendered document is inspected with Python rather than grep: `grep -q DATABASE_URL_OWNER` would match the
    # string anywhere in the file, including in a comment or in the migration service where it BELONGS. The
    # question is per-service, so it has to be asked of the parsed structure.
    #
    # The inspector is written to a FILE rather than heredoc'd, because `python3 - <<'PY' <<<"$rendered"` has TWO
    # stdin redirections and the last one wins -- so Python received the rendered YAML as its own source and died
    # with `SyntaxError: invalid decimal literal` on `timeout: 3s`. Script and data must arrive by different routes.
    if ! printf '%s' "$rendered" | python3 "$INSPECTOR" "$overlay" "$RECEIVERS" "$SEAM_KEYS" "$REPO_ROOT/api" "$SERVED_CONFIG_PATHS"
    then
        failures=$((failures + 1))
    else
        printf '  ok   — %s: owner credential confined, scheduler pinned to 1, edge network minimal, every consumed receiver defined, capabilities dropped where required, capability assertions enabled on every runtime-role service\n' "$overlay"
    fi
done

# THE TEXT-BASED ROUTES MOVED OUT, to `scripts/gates/worker-mode-blocked.sh`, and that is not tidying.
#
# They lived here for one commit and were wrong in three ways at once, all traceable to living in a COMPOSE gate:
# this gate SKIPS when `docker compose` is absent, so the two checks that need no Docker skipped with it; the
# `.env` grep was anchored `^[[:space:]]*APP_RUNTIME=` and missed `export APP_RUNTIME=`, which both Symfony's
# Dotenv and compose-go accept; and it read two of the Caddyfile's four seam variables while the Dockerfile `ENV`
# that actually sets them went unread. A gate that enumerates locations cannot be completed by adding locations.
#
# What stays here is the half no text sweep can do: the RENDERED configuration, where an overlay, an anchor, an
# `env_file:` or a value assembled from two files becomes visible. Both directions are needed and neither is
# sufficient — which was the finding.

# Anti-vacuity, for the reason every other gate here has one: a loop that iterated over nothing prints no failures
# and exits 0, which is indistinguishable from a clean run. This sentence used to go on to describe a
# `worker_sources` counter that the text-route removal deleted -- a comment describing a variable no longer printed
# by the `printf` directly beneath it, which is the same stale-prose defect this gate exists to catch in others.
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
