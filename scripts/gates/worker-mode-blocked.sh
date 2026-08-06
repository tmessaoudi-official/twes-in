#!/usr/bin/env bash
#
# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# ==================================================================================================================
# FRANKENPHP WORKER MODE IS REFUSED ACROSS THE WHOLE TRACKED CONFIGURATION SURFACE.
#
# WHY THIS CONSTRAINT EXISTS. A tenancy ruling (`CLAUDE.md` § Gotchas, 2026-08-05): `UuidV7` seeds its generator
# state once per PROCESS, a worker process serves many requests, and a seed recoverable from about two dozen
# observed identifiers therefore stops being confined to one tenant. Until the client portal (Wave 10) has its own
# `random_bytes(32)` token, worker mode must not be enabled.
#
# WHY THIS GATE IS SHAPED THE WAY IT IS. Three successive versions of this control were defeated, and every defeat
# was the SAME POLARITY ERROR at a different level of abstraction — each version enumerated what is FORBIDDEN and
# was beaten by something nobody had thought to enumerate:
#
#   Version 1 enumerated VALUES.    Beaten by `export APP_RUNTIME=…` and by two class names that exist in no
#                                   package while the spelling this repository prescribes walked straight through.
#   Version 2 enumerated LOCATIONS. Beaten by the Dockerfile `ENV` that a comment named and no code read, and by
#                                   two of the Caddyfile's four seams being unchecked.
#   Version 3 enumerated PATHS.     Beaten by `api/.env.prod` (the committed half of Symfony's own dotenv cascade,
#                                   invisible to a pattern anchored `\.env$`), by `infra/api/Dockerfile.dev`, by
#                                   `composer.json`'s `extra.runtime.class`, by a YAML block scalar carrying the
#                                   directive on lines the key does not appear on, by a FIFTH Caddyfile seam, and
#                                   by the legacy `ENV KEY value` form that has no `=` in it at all.
#
# So this version enumerates NOTHING that is forbidden. All three axes are INVERTED, and each inversion is closed
# by construction rather than by completeness of a list:
#
#   1. SCOPE.  Every tracked file is in scope unless it matches `EXCLUDED_PATTERNS`. A new configuration file, a
#              new dotenv layer, a new Dockerfile variant, a new tier — all in scope the moment they are tracked.
#              The old direction was fail-OPEN for anything unlisted; this one is fail-CLOSED.
#   2. KNOBS.  `SEAM_VARIABLES` is DERIVED from the Caddyfile's own `{$…}` placeholders, so a fifth seam scopes
#              itself in. Nothing is hand-listed except the one placeholder that is not a config seam.
#   3. VALUES. No dialect is parsed. A line mentioning `APP_RUNTIME` must normalise to one of THREE committed
#              literals, and a seam must be EMPTY — not "must not say `worker`". That is what makes the block
#              scalar, the legacy `ENV` form, the quoted YAML key and the split continuation all fail at once:
#              the gate stopped asking what a value MEANS and started asking whether it is one of the few
#              spellings this repository has approved.
#
# The seam rule deserves its own note, because it is the one that KEEPS a capability rather than removing it. The
# four seams are FrankenPHP's own image's variable names, not ours (`infra/api/Caddyfile` says so), and they have
# legitimate non-worker uses. Deleting them was considered and refused: inventing a project-specific spelling of a
# conventional thing is the marker § Gotchas 2026-08-02 records for wiring reasoned from first principles instead
# of taken from the ecosystem. Requiring them EMPTY keeps the names, keeps the convention, and still closes the
# route — and using one legitimately later means amending this gate, which is a visible diff. That is the ratchet
# direction this project mandates for a permission list.
#
# It is deliberately NOT a replacement for `compose-config.sh`'s rendered check, which sees composition this cannot:
# an overlay, an anchor, an `env_file:`, a value assembled from two files. This one needs no Docker, no rendering
# and no daemon, which is why it can never skip.
#
# DELETE THIS GATE IN WAVE 10, when the portal token lands, and not before.
# ==================================================================================================================
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly REPO_ROOT

# THE ONLY PERMITTED RUNTIME. An allow-list, not a block-list of worker classes: version 1 matched two class names
# that exist in no package, and the next runtime class has a name nobody has written yet. An unrecognised value
# fails closed — the polarity rule `PostgresRowLevelSecurityIsolation::isFalse()` states for catalogue flags,
# applied to a configuration value.
readonly PERMITTED_RUNTIME='Symfony\Component\Runtime\SymfonyRuntime'

# THE PERMITTED `APP_RUNTIME` LINES, normalised. Not a value allow-list — a LINE allow-list, which is the whole
# point: comparing the assignment TEXT means no dialect has to be parsed, so `ENV APP_RUNTIME <value>`,
# `"APP_RUNTIME": "<value>"`, `APP_RUNTIME: {…}` and a `\`-split assignment are all refused without the gate
# knowing they exist. Three entries, each derived by enumerating the real tree; adding a fourth is an argument to
# be made in a commit message.
readonly -a PERMITTED_RUNTIME_LINES=(
    # api/.env and infra/.env — the dotenv form.
    'APP_RUNTIME=Symfony\Component\Runtime\SymfonyRuntime'
    # infra/api/Dockerfile, both targets — inside a multi-line `ENV`, so double-escaped.
    'APP_RUNTIME="Symfony\\Component\\Runtime\\SymfonyRuntime"'
    # infra/compose.yaml — the outer occurrence, interpolated with a default.
    "APP_RUNTIME: '\${APP_RUNTIME:-Symfony\\Component\\Runtime\\SymfonyRuntime}'"
)

# THE PERMITTED SEAM DECLARATIONS, as templates instantiated per derived seam. Two forms, because two exist:
# `infra/.env` writes the bare empty assignment and `infra/api/Dockerfile` writes the quoted one. A seam declaration
# that is not one of these is refused, WITHOUT the gate extracting or interpreting a value — which is what retires
# `ENV KEY value`, `KEY+=`, `"KEY":` and a `\`-split assignment in a single rule instead of four.
readonly -a PERMITTED_SEAM_TEMPLATES=(
    '%s='
    '%s=""'
    # The YAML forms. No compose file in this tree declares a seam today, but an EMPTY declaration is a legitimate
    # thing to write and refusing it would be a false positive -- a meta-suite probe that adds one caught this.
    # None of these can carry a value, which is the only property that matters.
    '%s: ""'
    "%s: ''"
)

# THE ONE PLACEHOLDER THAT CANNOT BE PINNED TO A LITERAL, and it is NOT an exemption any more. `{$SERVER_NAME}` is
# the public hostname, so an operator must be able to set it — but it splices at SITE-BLOCK position, immediately
# before the `{` that opens the block. Version 4 exempted it from the emptiness rule and a certification round then
# closed the site block from the tracked `infra/.env`, opened its own containing `php_server { worker ... }`, and
# reopened the original; FrankenPHP's own `adapt` reported the workers. § Gotchas 2026-07-30: an exemption inside a
# cross-check is where the drift hides.
#
# So it gets a STRUCTURAL rule instead of an exemption: no brace outside a `${...}` interpolation. Every hostname
# stays legal and everything that could change the Caddyfile's grammar is refused.
readonly STRUCTURAL_PLACEHOLDER='SERVER_NAME'

# WHAT IS OUT OF SCOPE — and this list is the ONLY thing that is. Documentation is excluded because prose about
# `APP_RUNTIME` is how the constraint gets explained, and a gate that forbade explaining it would be deleted; this
# gate and its meta-suite are excluded because naming every forbidden spelling is their job. A permission list, so
# the meta-suite asserts a MAXIMUM on it: widening it is how a route would be reopened.
readonly -a EXCLUDED_PATTERNS=(
    '\.md$'
    '^docs/'
    '(^|/)tests?/'
    '^scripts/gates/'
    '^\.claude/'
)

# ------------------------------------------------------------------------------------------------------------------
# `git ls-files -z` and not a filesystem walk: a parallel certification round places reviewer worktrees INSIDE the
# working tree, and a recursive walk then reads several repositories at once (§ Gotchas, 2026-07-31). `-z` and not
# a bare listing, because `core.quotePath` C-quotes any non-ASCII path and a quoted name reaches no file.
mapfile -d '' -t tracked < <(git -C "$REPO_ROOT" ls-files -z)

in_scope=()
excluded=0
for relative in "${tracked[@]}"; do
    skip=0
    for pattern in "${EXCLUDED_PATTERNS[@]}"; do
        if [[ "$relative" =~ $pattern ]]; then
            skip=1
            break
        fi
    done
    if (( skip )); then
        excluded=$((excluded + 1))
        continue
    fi
    [[ -f "$REPO_ROOT/$relative" ]] && in_scope+=("$relative")
done

# THE SEAM SET, DERIVED. Every `{$NAME}` the Caddyfiles themselves splice, minus the one that is a hostname. A
# hand-written list was version 3's defeat: a fifth seam added to the Caddyfile was silently out of scope while the
# gate's own header claimed the knob set was derived from the Caddyfile's enumeration. It is now true.
# WHICH FILES ARE CADDY CONFIGS, AND WHICH SEAMS THEY SPLICE — from `lib/caddy-configs.sh`, SHARED with
# `compose-config.sh`. It lived here and a near-copy lived there, and the two derived DIFFERENT sets: a tracked
# `admin/Caddyfile` yielded five seams from this gate and four from the sibling, so the rendered half never checked
# the fifth. Two gates asking the same question of the same knob set must derive it the same way.
# shellcheck source=lib/caddy-configs.sh
source "$REPO_ROOT/scripts/gates/lib/caddy-configs.sh"

caddy_relatives=()
mapfile -t caddy_relatives < <(caddy_config_paths "$REPO_ROOT")

caddyfiles=()
for relative in "${caddy_relatives[@]:-}"; do
    [[ -n "$relative" ]] && caddyfiles+=("$REPO_ROOT/$relative")
done

SEAM_VARIABLES=()
mapfile -t SEAM_VARIABLES < <(caddy_seam_variables "$REPO_ROOT" "$STRUCTURAL_PLACEHOLDER")
# `mapfile` on empty input yields one empty element; drop it so the anti-vacuity guard sees a true zero.
(( ${#SEAM_VARIABLES[@]} == 1 )) && [[ -z "${SEAM_VARIABLES[0]}" ]] && SEAM_VARIABLES=()

if [[ "${1:-}" == '--dump-rules' ]]; then
    printf 'permitted_runtime %s\n' "$PERMITTED_RUNTIME"
    printf 'permitted_runtime_line %s\n' "${PERMITTED_RUNTIME_LINES[@]}"
    printf 'permitted_seam_template %s\n' "${PERMITTED_SEAM_TEMPLATES[@]}"
    (( ${#SEAM_VARIABLES[@]} > 0 )) && printf 'seam_variable %s\n' "${SEAM_VARIABLES[@]}"
    printf 'structural_placeholder %s\n' "$STRUCTURAL_PLACEHOLDER"
    printf 'excluded_pattern %s\n' "${EXCLUDED_PATTERNS[@]}"
    exit 0
fi

inspected=0
runtime_declarations=0
violations=()

# COMMENT HANDLING IS DECIDED ONCE, HERE, and every check below reads the result. The previous version tested the
# raw line for three of its four checks and stripped comments only for the fourth, which produced three verified
# false positives on plausible documentation — including a message asserting `APP_RUNTIME is "prod"` about a line
# that set a different variable entirely. § Gotchas 2026-07-29: decide a condition ONCE into a variable that every
# path reads. `#` is the comment leader in every dialect in scope (dotenv, Dockerfile, YAML, Caddy, Make) and `*`,
# `//` and `<!--` cover the PHP and HTML files the inverted scope brought in.
# THE ANALYSIS ITSELF LIVES IN PHP, in `lib/worker-mode-analyse.php`, and the reason is a finding rather than a
# preference. Every rule here used to be a chain of bash parameter expansions, and a certification round defeated
# three of them at once by attacking the CHAIN instead of the rules: a continuation after `="` normalised a non-empty
# value to empty, a continuation inside the variable NAME meant no keyword appeared on any physical line, and a value
# beginning `#` collapsed through the inline-comment strip -- the last a REGRESSION, since the version before tested
# the raw line. A transformation set is an enumeration too, and this one had grown to five strips.
#
# The replacement does two TOTAL operations -- join continuations into logical lines, then one quote-aware scan per
# line -- and then compares text against committed literals without extracting any value. Bash cannot do the scan
# without a character loop, and a character loop in bash over every tracked file is not viable; PHP is what the other
# gates here are written in and needs nothing installed.
#
# The request goes over STDIN as JSON so nothing is escaped through an argument list. A mutation harness in this
# repository was already fooled once by shell escaping that silently did not apply.
caddy_relative_list=""
for absolute in "${caddyfiles[@]:-}"; do
    [[ -z "$absolute" ]] && continue
    caddy_relative_list+="${absolute#"$REPO_ROOT/"}"$'\n'
done

# THE ENVIRONMENT IS SET ON THIS INVOCATION, not on the driver's. The first version set it on the driver and the
# request-building step read an empty environment -- so `files` was `[]`, `inspected` was 0, and the gate's own
# anti-vacuity guard caught it on the first run. Exactly what that guard is for.
request="$(
    WM_SEAMS="$(printf '%s\n' "${SEAM_VARIABLES[@]:-}")" \
    WM_TEMPLATES="$(printf '%s\n' "${PERMITTED_SEAM_TEMPLATES[@]}")" \
    WM_RUNTIME_LINES="$(printf '%s\n' "${PERMITTED_RUNTIME_LINES[@]}")" \
    WM_FILES="$(printf '%s\n' "${in_scope[@]:-}")" \
    WM_CADDYFILES="$caddy_relative_list" \
    python3 - "$REPO_ROOT" "$PERMITTED_RUNTIME" "$STRUCTURAL_PLACEHOLDER" <<'PYREQ'
import json
import os
import sys

root, permitted_runtime, structural = sys.argv[1], sys.argv[2], sys.argv[3]


def lines(name):
    return [value for value in os.environ.get(name, '').split('\n') if value]


seams = lines('WM_SEAMS')
templates = lines('WM_TEMPLATES')
print(json.dumps({
    'root': root,
    'permitted_runtime': permitted_runtime,
    'structural_placeholder': structural,
    'permitted_runtime_lines': lines('WM_RUNTIME_LINES'),
    # Instantiated per seam HERE rather than in PHP, so `--dump-rules` and the rules cannot disagree about the set.
    'permitted_seam_lines': [t.replace('%s', seam) for seam in seams for t in templates],
    'seams': seams,
    'files': lines('WM_FILES'),
    'caddyfiles': lines('WM_CADDYFILES'),
}))
PYREQ
)"

response="$(printf '%s' "$request" | php "$REPO_ROOT/scripts/gates/lib/worker-mode-run.php")" || {
    echo "worker-mode-blocked: FAIL — the analysis driver did not run, so nothing was asserted." >&2
    exit 1
}

mapfile -t violations < <(printf '%s' "$response" | python3 -c 'import json,sys; d=json.load(sys.stdin); print("\n".join(d["violations"]))')
inspected="$(printf '%s' "$response" | python3 -c 'import json,sys; print(json.load(sys.stdin)["inspected"])')"
scanned="$(printf '%s' "$response" | python3 -c 'import json,sys; print(json.load(sys.stdin)["scanned"])')"
declarations="$(printf '%s' "$response" | python3 -c 'import json,sys; print(json.load(sys.stdin)["declarations"])')"
# `mapfile` on an empty string yields one empty element; drop it so the count is honest.
(( ${#violations[@]} == 1 )) && [[ -z "${violations[0]}" ]] && violations=()

# Anti-vacuity, printed unconditionally and BEFORE the verdict, for the reason every gate here has one: a loop over
# nothing prints no violations and exits 0, indistinguishable from a clean sweep.
printf 'worker-mode-blocked: counts — inspected=%d excluded=%d scanned=%d declarations=%d violations=%d seams=%d caddyfiles=%d permitted_lines=%d\n' \
    "$inspected" "$excluded" "$scanned" "$declarations" "${#violations[@]}" "${#SEAM_VARIABLES[@]}" \
    "${#caddyfiles[@]}" "${#PERMITTED_RUNTIME_LINES[@]}"

if (( inspected == 0 )); then
    echo "worker-mode-blocked: FAIL — inspected NO files, so this gate asserted nothing." >&2
    exit 1
fi

# A DERIVED set that came back empty means the derivation broke, not that there are no seams. Version 3's knob list
# was hand-written and could not fail this way; the price of deriving it is having to notice when it yields nothing.
if (( ${#SEAM_VARIABLES[@]} == 0 )); then
    echo "worker-mode-blocked: FAIL — derived NO seam variables from any Caddyfile, so the seam rule asserted nothing." >&2
    exit 1
fi

# ANTI-VACUITY ON THE RULE'S OWN SUBJECT. `scanned == 0` was tried first and is NOT REACHABLE while a Caddyfile
# exists: the seam names are DERIVED from that file's own `{$...}` placeholders, so the file always contains the
# keywords and always reaches the analysis. A guard that cannot fire is what this repository files as a defect, so it
# was replaced rather than kept as reassurance.
#
# `declarations` counts APPROVED runtime and seam declarations the analysis actually saw. Zero means the central rule
# matched nothing anywhere -- which is what a renamed variable, a moved file or a broken derivation looks like, and
# is indistinguishable from a clean sweep without this check.
if (( declarations == 0 )); then
    echo "worker-mode-blocked: FAIL — saw NO approved runtime or seam declaration anywhere, so the value rules" >&2
    echo "  asserted nothing. A renamed variable or a moved file looks exactly like a clean sweep otherwise." >&2
    exit 1
fi


if (( ${#violations[@]} > 0 )); then
    echo "worker-mode-blocked: FAIL — FrankenPHP worker mode is enabled or enable-able." >&2
    printf '  %s\n' "${violations[@]}" >&2
    cat >&2 <<'WHY'

  Worker mode is blocked until the client portal (Wave 10) has its own random_bytes(32) token.
  `UuidV7` seeds its generator state once per PROCESS, and a worker process serves many requests, so a
  seed recoverable from about two dozen observed identifiers stops being confined to one tenant — a
  certification round computed a later identifier exactly, across two generator instances. One process
  per request is what confines it. See CLAUDE.md § Gotchas, 2026-08-05.
WHY
    exit 1
fi

printf 'worker-mode-blocked: OK — %d tracked file(s) carry no worker-mode switch; %d seam(s) empty.\n' \
    "$inspected" "${#SEAM_VARIABLES[@]}"
