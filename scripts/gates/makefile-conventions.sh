#!/usr/bin/env bash
#
# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# ==============================================================================================================
# THE MAKEFILE'S OWN NAMING CONVENTION, enforced rather than remembered.
#
#     A BARE TARGET NAME ACTS ON DEVELOPMENT.  `-prod` ACTS ON PRODUCTION.
#
# WHY THIS GATE EXISTS. The convention was violated by the Makefile that introduced it. `up`/`up-prod` marked which
# STACK a target drove, while `build-front`/`build-front-dev` marked which ARTEFACT FLAVOUR it produced — so a bare
# name meant "dev" in one family and "prod" in the other, and `build-front` was BOTH at once: it ran on the dev
# stack and produced a production bundle. Because both wrote to the same shared volume that the production stack
# also serves, `make build-front-dev && make up-prod` served an unminified bundle carrying our TypeScript source
# maps out of "production", and neither name warned anybody.
#
# The developer found that by reading the file. This gate is what stops the next one needing to.
#
# It is a NAME-VERSUS-BEHAVIOUR check, which is the only kind worth having here: it does not read the convention
# from a list of expected names (that list would drift with the Makefile, and a drifted expectation is worse than
# none). It DERIVES what each target actually does — which compose stack its recipe drives — and compares that with
# what the target is called. So a new target is covered the moment it is written, with no list to update.
# ==============================================================================================================

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MAKEFILE="${1:-$REPO_ROOT/Makefile}"

# Targets that touch NEITHER stack, or that are deliberately single-environment. Each entry is a decision, so each
# gets a reason — an unexplained exemption is where this kind of check rots.
#
#   help/env/urls/check-env  — no stack at all.
#   deps                     — a guard on `up`; installs onto the host tree, not into a stack.
#   install/composer/test    — DEV-ONLY BY NATURE. Composer and PHPUnit are absent from the production image on
#                              purpose, so a `-prod` twin could not run even if it existed.
#   debug-on/off/status      — Xdebug is not in the production image, by design.
#   config-prod              — the one PROD-ONLY target: rendering the DEV config is plain `docker compose config`,
#                              and `gate-infra` already checks both.
#   destroy                  — NO `destroy-prod`, deliberately. A one-word command that deletes the production
#                              database is a foot-gun no convention should demand for symmetry.
#   e2e                      — same reason as `test`, one step further: the suite runs THROUGH the container's own
#                              `composer gate:e2e`, and neither Composer nor the PHPUnit phar is in the production
#                              image. Worth stating rather than filing away, because the served surface is exactly
#                              what one would most want to assert against the PRODUCTION stack — its overlay is the
#                              one that hardens — and doing so needs a host-side runner pointed at a published port
#                              rather than an `exec`. Recorded as owed in `build-waves.plan.md`, not as unwanted.
#   gate*                    — the SCOPE axis, not the environment axis: bare `gate` means every tier, a suffix
#                              narrows it. Checked separately below.
readonly -a ENV_EXEMPT=(
  help env urls check-env deps
  install composer test e2e
  debug-on debug-off debug-status
  config-prod destroy
  gate gate-api gate-infra gate-make
)

# The SCOPE axis. Bare `gate` must be the WHOLE thing; anything narrower carries a suffix.
readonly -a SCOPE_SUFFIXED=(gate-api gate-infra gate-make)

if [[ "${1:-}" == "--dump-rules" ]]; then
  printf 'convention bare=dev suffix=-prod\n'
  printf 'env-exempt %s\n' "${ENV_EXEMPT[*]}"
  printf 'scope-suffixed %s\n' "${SCOPE_SUFFIXED[*]}"
  exit 0
fi

[[ -f "$MAKEFILE" ]] || { printf 'makefile-conventions: FAIL — no Makefile at %s\n' "$MAKEFILE" >&2; exit 1; }

# --------------------------------------------------------------------------------------------------------------
# DERIVE, don't declare: for every target, which stack does its recipe actually drive?
#
# `$(DC)` is the development stack, `$(DC_PROD)` production, and `$(DCX)` is the target-scoped variable that lets one
# recipe serve both — so a `DCX` recipe is resolved through the `name: DCX := ...` assignment lines instead.
# --------------------------------------------------------------------------------------------------------------
mapfile -t findings < <(
  python3 - "$MAKEFILE" "${ENV_EXEMPT[*]}" "${SCOPE_SUFFIXED[*]}" <<'PY'
import re, sys

makefile, exempt_raw, scoped_raw = sys.argv[1], sys.argv[2], sys.argv[3]
exempt = set(exempt_raw.split())
scoped = set(scoped_raw.split())
text = open(makefile).read()
lines = text.split('\n')

# name -> {'stacks': set(), 'has_recipe': bool}
targets, order = {}, []
# `name: DCX := $(DC)` target-scoped assignments
dcx = {}
cur = []
for ln in lines:
    m = re.match(r'^([A-Za-z0-9_ -]+):\s*DCX\s*:?=\s*\$\((DC|DC_PROD)\)\s*$', ln)
    if m:
        for n in m.group(1).split():
            dcx.setdefault(n, set()).add('PROD' if m.group(2) == 'DC_PROD' else 'DEV')
        continue
    m = re.match(r'^([A-Za-z0-9_-][A-Za-z0-9_ -]*):(?!=)(.*)$', ln)
    if m and not ln.startswith('\t'):
        cur = [n for n in m.group(1).split() if not n.startswith('.')]
        for n in cur:
            targets.setdefault(n, {'stacks': set()})
            if n not in order:
                order.append(n)
        continue
    if cur and ln.startswith('\t'):
        for n in cur:
            if '$(DC_PROD)' in ln:
                targets[n]['stacks'].add('PROD')
            elif '$(DCX)' in ln:
                targets[n]['stacks'] |= dcx.get(n, set())
            elif '$(DC)' in ln:
                targets[n]['stacks'].add('DEV')

problems = []
for name in order:
    stacks = targets[name]['stacks']

    # THE `-dev` SUFFIX IS CHECKED FIRST, BEFORE THE no-stack SKIP BELOW — and that ordering was a real bug in this
    # gate's first version. A `-dev` target whose recipe line happens not to mention a compose variable (because it
    # shares a recipe with a sibling, which is exactly how `build-front-dev` was written) has no DETECTED stack, so
    # the skip swallowed it and the gate passed the ORIGINAL defect it was written to catch. The suffix is a violation
    # on its own terms: it needs no behavioural evidence, because bare already means development.
    if name.endswith('-dev'):
        problems.append(
            "`%s` uses a `-dev` suffix. Bare names already mean development, so `-dev` is a second spelling of the "
            "same thing — which is exactly how `build-front` and `build-front-dev` came to mean opposite things."
            % name)

    if name in exempt or not stacks:
        continue
    is_prod_named = name.endswith('-prod')
    if is_prod_named and stacks != {'PROD'}:
        problems.append(
            "`%s` is named for production but its recipe drives %s. A `-prod` target must act on the production "
            "stack and nothing else." % (name, '+'.join(sorted(stacks))))
    if not is_prod_named and 'PROD' in stacks:
        problems.append(
            "`%s` drives the PRODUCTION stack but carries no `-prod` suffix. A bare name must act on development: "
            "muscle memory types the short name, so the short name has to be the harmless one." % name)

# Every dev target should have a production twin, or be exempt with a stated reason.
for name in order:
    if name in exempt or name.endswith('-prod'):
        continue
    if targets[name]['stacks'] == {'DEV'} and (name + '-prod') not in targets:
        problems.append(
            "`%s` acts on the development stack and has no `%s-prod` twin. Either add one (the recipe can be shared "
            "through the target-scoped `DCX`) or add it to ENV_EXEMPT in this gate with the reason." % (name, name))

# SCOPE axis: a bare aggregate must actually invoke its narrower siblings.
for narrow in sorted(scoped):
    base = narrow.split('-')[0]
    if base in targets:
        body_ok = re.search(r'^%s:.*\n(?:.*\n)*?\t.*%s' % (re.escape(base), re.escape(narrow)), text, re.M)
        if not body_ok:
            problems.append(
                "`%s` exists but bare `%s` does not invoke it. A bare name must be the WHOLE thing and a suffix must "
                "narrow it — `gate` meaning 'the API tier only' was the third conflicting meaning of a bare name."
                % (narrow, base))

for pr in problems:
    print(pr)
PY
)

printf 'makefile-conventions: counts — findings=%s\n' "${#findings[@]}"

if ((${#findings[@]} > 0)); then
  printf 'makefile-conventions: FAIL\n' >&2
  for f in "${findings[@]}"; do printf '  - %s\n' "$f" >&2; done
  exit 1
fi

printf 'makefile-conventions: OK — every target that drives a stack is named for the stack it drives.\n'
