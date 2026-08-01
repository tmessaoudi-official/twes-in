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
# the source can still be wrong in the RENDERED result, which is what actually runs. The four assertions below are
# each a security property that a careless overlay edit would silently undo:
#
#   1. the OWNER database credential appears on the migration service and NOWHERE else;
#   2. the scheduler is pinned to exactly ONE replica;
#   3. the database and the document renderer are not on the externally-reachable network;
#   4. the internal network has no route out.
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
TWES_SERVER_NAME=:80
POSTGRES_DB=twes
POSTGRES_SUPERUSER=postgres
POSTGRES_PASSWORD=GATE-RENDERING-ONLY
TWES_DB_RUNTIME_ROLE=twes
TWES_DB_RUNTIME_PASSWORD=GATE-RENDERING-ONLY
TWES_DB_OWNER_ROLE=twes_owner
TWES_DB_OWNER_PASSWORD=GATE-RENDERING-ONLY
EOF

failures=0
checked=0

# The per-service inspector, written once to a temp file. See the call site for why it is not a heredoc.
readonly INSPECTOR="$(mktemp --suffix=.py)"
trap 'rm -f "$RENDER_ENV" "$INSPECTOR"' EXIT

cat > "$INSPECTOR" <<'PYEOF'
import sys, yaml

overlay = sys.argv[1]
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
for name in ('postgres', 'valkey', 'gotenberg', 'worker', 'scheduler'):
    nets = (services.get(name) or {}).get('networks') or {}
    if 'edge' in nets:
        problems.append('%s is on the `edge` network. Only the API may be externally reachable.' % name)

# 4. The internal network really is internal.
if not (cfg.get('networks', {}).get('internal', {}) or {}).get('internal'):
    problems.append(
        'the `internal` network is not marked `internal: true`, so a compromised worker or the document renderer '
        'would have a route to the outside world.')

for p in problems:
    print('  FAIL — %s: %s' % (overlay, p))

sys.exit(1 if problems else 0)
PYEOF

render() {
    docker compose --env-file "$RENDER_ENV" -f "$INFRA/compose.yaml" -f "$INFRA/$1" config 2>&1
}

for overlay in compose.dev.yaml compose.prod.yaml; do
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
    if ! printf '%s' "$rendered" | python3 "$INSPECTOR" "$overlay"
    then
        failures=$((failures + 1))
    else
        printf '  ok   — %s: owner credential confined, scheduler pinned to 1, edge network minimal\n' "$overlay"
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
