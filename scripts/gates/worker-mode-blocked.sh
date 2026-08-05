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
    # infra/api/Dockerfile, both targets — inside a multi-line `ENV`, so the line is bare and double-escaped.
    'APP_RUNTIME="Symfony\\Component\\Runtime\\SymfonyRuntime"'
    # infra/compose.yaml — interpolated with a default. The DEFAULT is what ships, so it is what is pinned.
    "APP_RUNTIME: '\${APP_RUNTIME:-Symfony\\Component\\Runtime\\SymfonyRuntime}'"
)

# THE ONE CADDYFILE PLACEHOLDER THAT IS NOT A CONFIG SEAM. `{$SERVER_NAME}` is a hostname and MUST be non-empty,
# so the emptiness rule cannot apply to it. Kept to one entry and asserted as a MAXIMUM by the meta-suite, because
# this is a permission list: every name added here is a knob that stops being checked.
readonly -a NOT_A_SEAM=(
    SERVER_NAME
)

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
caddyfiles=()
for relative in "${in_scope[@]}"; do
    [[ "$relative" == *Caddyfile* ]] && caddyfiles+=("$REPO_ROOT/$relative")
done

SEAM_VARIABLES=()
if (( ${#caddyfiles[@]} > 0 )); then
    while IFS= read -r name; do
        [[ -z "$name" ]] && continue
        for not_seam in "${NOT_A_SEAM[@]}"; do
            [[ "$name" == "$not_seam" ]] && continue 2
        done
        SEAM_VARIABLES+=("$name")
    done < <(grep -ohE '\{\$[A-Za-z_][A-Za-z0-9_]*' "${caddyfiles[@]}" | sed 's/^{\$//' | sort -u)
fi

if [[ "${1:-}" == '--dump-rules' ]]; then
    printf 'permitted_runtime %s\n' "$PERMITTED_RUNTIME"
    printf 'permitted_runtime_line %s\n' "${PERMITTED_RUNTIME_LINES[@]}"
    (( ${#SEAM_VARIABLES[@]} > 0 )) && printf 'seam_variable %s\n' "${SEAM_VARIABLES[@]}"
    printf 'not_a_seam %s\n' "${NOT_A_SEAM[@]}"
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
# Both helpers ASSIGN A GLOBAL rather than echoing, and are called bare rather than in `$( )`. That is a measured
# requirement, not a style choice: command substitution forks a subshell, this loop runs over roughly 28,000 lines,
# and the echoing version took 44.8s per invocation — 19 minutes across the meta-suite's 25 cases, which read as a
# hung suite rather than a slow one. [Measured with `time` before and after.]
code_of() {
    local line="${1%$'\r'}" trimmed                       # CRLF stripped first, so a message cannot render
                                                          # identically to the permitted value while asserting
                                                          # it differs.
    trimmed="${line#"${line%%[![:space:]]*}"}"
    case "$trimmed" in
        '#'* | '*'* | '//'* | '/*'* | '<!--'* | '')
            CODE='' ;;                                    # a whole-line comment is not configuration
        *)
            trimmed="${trimmed%%#*}"                      # an inline comment is not configuration either
            trimmed="${trimmed%%//*}"
            CODE="${trimmed%"${trimmed##*[![:space:]]}"}" ;;
    esac
}

# NORMALISE A LINE FOR COMPARISON against PERMITTED_RUNTIME_LINES. Deliberately minimal — only a Dockerfile line
# continuation and surrounding whitespace are removed. Nothing about the VALUE is interpreted, because interpreting
# the value is what failed three times.
normalise() {
    local text="${1%\\}"                                  # a trailing `\` is a line continuation, not content
    text="${text#"${text%%[![:space:]]*}"}"
    NORM="${text%"${text##*[![:space:]]}"}"
}

# A PER-FILE FAST REJECT, built from the DERIVED keyword set so nothing about it is hard-coded. This is not a scope
# hole: `grep` reads the same bytes the line loop would, and a file mentioning none of the keywords cannot violate
# any of the line-level rules. It exists because inverting the scope changed the gate's COMPLEXITY CLASS -- the cost
# is now linear in tracked BYTES, and bash string expansion on a long minified line is expensive enough that a
# single large tracked asset would dominate. Caddyfiles always enter the loop, because their `worker`/`import` rules
# match no keyword; `composer.json` is handled after the loop and is unaffected.
keyword_re='APP_RUNTIME'
for seam in "${SEAM_VARIABLES[@]}"; do
    keyword_re+="|$seam"
done
readonly keyword_re

for relative in "${in_scope[@]}"; do
    file="$REPO_ROOT/$relative"
    inspected=$((inspected + 1))

    # `scan_lines`, NOT `continue`. A `continue` here skipped the WHOLE iteration and took the `composer.json`
    # check below the loop with it -- so `extra.runtime.class` stopped being detected while every other rule still
    # fired. That is the IDENTICAL defect `compose-config.sh` had one round earlier, where `if 'APP_RUNTIME' not in
    # env: continue` killed the seam loop beneath it. Caught here only because a mutant existed for the check the
    # `continue` orphaned. Not a suppression on the `grep`: it exits 1 for "no match" and 2 for an unreadable file,
    # and both mean the line loop has nothing to find -- an unreadable file is already excluded by the `[[ -f ]]`
    # test that built `in_scope`.
    # The fast path chooses the loop's INPUT rather than skipping the iteration, and rather than folding a guard
    # into the `while` condition -- `while (( x )) && IFS= read -r line || ...` parses in a way that never ran the
    # body at all, which the counters caught immediately (`runtime_lines=0` on a tree with five).
    scan_source="$file"
    if [[ "$relative" != *Caddyfile* ]] && ! grep -qE "$keyword_re" "$file" 2>/dev/null; then
        scan_source=/dev/null
    fi

    while IFS= read -r line || [[ -n "$line" ]]; do
        code_of "$line"
        code="$CODE"
        [[ -z "$code" ]] && continue

        # ---- APP_RUNTIME: the LINE must be one this repository has approved ---------------------------------
        # A bare substring test, with no assumption about what follows the name. That is what catches the legacy
        # `ENV KEY value` form (no `=` at all) and the quoted `"APP_RUNTIME":` key (not preceded by whitespace),
        # both of which defeated a rule requiring `[=:]` after the name.
        if [[ "$code" == *APP_RUNTIME* ]]; then
            runtime_declarations=$((runtime_declarations + 1))
            normalise "$code"
            normalised="$NORM"
            permitted=0
            for candidate in "${PERMITTED_RUNTIME_LINES[@]}"; do
                [[ "$normalised" == "$candidate" ]] && permitted=1 && break
            done
            if (( ! permitted )); then
                violations+=("$relative: \`$normalised\` is not one of the permitted APP_RUNTIME lines; the only runtime is \"$PERMITTED_RUNTIME\"")
            fi
        fi

        # ---- THE SEAMS: EMPTY, whatever the content would have said -----------------------------------------
        # Not "must not contain `worker`". A YAML block scalar puts the directive on lines the key never appears
        # on, so a search for the word beside the key cannot see it — but `|` is not empty, and that is enough.
        for seam in "${SEAM_VARIABLES[@]}"; do
            if [[ "$code" =~ (^|[^A-Za-z0-9_])"$seam"[[:space:]]*[=:] ]]; then
                normalise "${code#*"$seam"}"
                value="${NORM#[=:]}"
                normalise "$value"
                value="$NORM"
                value="${value#\"}" ; value="${value%\"}"
                value="${value#\'}" ; value="${value%\'}"
                if [[ -n "$value" ]]; then
                    violations+=("$relative: $seam must be EMPTY while worker mode is blocked, and is \`$value\`; it splices verbatim into the Caddyfile")
                fi
            fi
        done

        # ---- AN ACTIVE `worker` DIRECTIVE, anywhere in a Caddyfile ------------------------------------------
        # Not line-anchored: `frankenphp { worker /app/public/index.php }` on one line escaped the anchored
        # version. Scoped to Caddyfiles by NAME, which is not a scope hole — `infra/compose.yaml` has a service
        # legitimately called `worker`, and refusing the word everywhere would refuse the queue consumer.
        if [[ "$relative" == *Caddyfile* && "${code,,}" =~ (^|[[:space:]{])worker([[:space:]{]|$) ]]; then
            violations+=("$relative: an ACTIVE \`worker\` directive — keep the documented block commented")
        fi

        # ---- AND AN `import`, because the imported file is where the directive would hide -------------------
        if [[ "$relative" == *Caddyfile* && "$code" =~ ^import[[:space:]] ]]; then
            violations+=("$relative: \`$code\` — an imported Caddy config is outside this sweep, so an \`import\` is refused while worker mode is blocked")
        fi
    done < "$scan_source"

    # ---- extra.runtime.class, PARSED rather than grepped ------------------------------------------------------
    # `symfony/runtime`'s ComposerPlugin bakes this key into `vendor/autoload_runtime.php`, so it selects the
    # runtime with no environment variable anywhere in the tree. Parsed as JSON because that is the dialect: a
    # regex over JSON is the same mistake as a regex over the four dotenv forms.
    if [[ "$(basename "$relative")" == 'composer.json' ]]; then
        # NO `2>/dev/null || true` HERE, and the first draft of this block had both. A suppressed error would make
        # an unparseable `composer.json` — or a missing `php` — report NO baked runtime, which is
        # indistinguishable from a clean one. That is fail-OPEN, in the gate whose entire redesign was about
        # inverting fail-open polarity. If the file cannot be read, that IS the violation.
        if ! baked="$(php -r '
            $raw = file_get_contents($argv[1]);
            if (false === $raw) { fwrite(STDERR, "unreadable\n"); exit(1); }
            $data = json_decode($raw, true);
            if (!is_array($data)) { fwrite(STDERR, "not a JSON object: " . json_last_error_msg() . "\n"); exit(1); }
            echo (string) ($data["extra"]["runtime"]["class"] ?? "");
        ' "$file")"; then
            violations+=("$relative: could not be parsed as JSON, so extra.runtime.class could not be ruled out — an unverifiable file is a violation, not a pass")
            baked=''
        fi
        if [[ -n "$baked" && "$baked" != "$PERMITTED_RUNTIME" ]]; then
            violations+=("$relative: extra.runtime.class is \"$baked\"; symfony/runtime BAKES it into vendor/autoload_runtime.php, so it needs no environment variable at all")
        fi
    fi
done

# Anti-vacuity, printed unconditionally and BEFORE the verdict, for the reason every gate here has one: a loop over
# nothing prints no violations and exits 0, indistinguishable from a clean sweep.
printf 'worker-mode-blocked: counts — inspected=%d excluded=%d violations=%d seams=%d runtime_lines=%d permitted_lines=%d\n' \
    "$inspected" "$excluded" "${#violations[@]}" "${#SEAM_VARIABLES[@]}" \
    "$runtime_declarations" "${#PERMITTED_RUNTIME_LINES[@]}"

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

if (( runtime_declarations == 0 )); then
    echo "worker-mode-blocked: FAIL — found NO APP_RUNTIME declaration anywhere, so the runtime rule asserted nothing." >&2
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
