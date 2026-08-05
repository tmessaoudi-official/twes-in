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
# WHY THIS IS ITS OWN GATE, and not three more checks bolted onto `compose-config.sh`.
#
# The constraint comes from a tenancy ruling (`CLAUDE.md` § Gotchas, 2026-08-05): `UuidV7` seeds its generator state
# once per PROCESS, a worker process serves many requests, and a seed recoverable from about two dozen observed
# identifiers therefore stops being confined to one tenant. Until the client portal (Wave 10) has its own
# `random_bytes(32)` token, worker mode must not be enabled.
#
# `compose-config.sh` was that mechanism for two commits and was defeated four separate ways in one certification
# round. Every defeat had the same shape — **it checked NAMED LOCATIONS for NAMED SPELLINGS**:
#
#   1. `export APP_RUNTIME=…` in a tracked `.env`. Symfony's Dotenv and compose-go both accept the `export ` prefix
#      (`api/vendor/symfony/dotenv/Dotenv.php`: `'/(export[ \t]++)?('.self::VARNAME_REGEX.')/A'`); the grep was
#      anchored `^[[:space:]]*APP_RUNTIME=` and missed it. One keyword reopened the whole route.
#   2. `FRANKENPHP_CONFIG` / `CADDY_SERVER_EXTRA_DIRECTIVES` were inspected in the RENDERED COMPOSE ENVIRONMENT,
#      where **no compose file declares them at all** — so that check could never fire on the real configuration.
#      They are set in `infra/api/Dockerfile`'s `ENV`, which the gate named in a comment and never read. Its
#      meta-suite cases passed only because the harness injected the keys into `compose.yaml`, a location the real
#      configuration does not use: fixture leakage, in the fix for a fixture-leakage finding.
#   3. `CADDY_GLOBAL_OPTIONS` and `CADDY_EXTRA_CONFIG` — two of the four seams `infra/api/Caddyfile` itself
#      enumerates — were unchecked, and the first splices into the very global block where a `worker` directive
#      legitimately lives.
#   4. And the whole thing SKIPPED on a machine without `docker compose`, because it is a compose gate. The skip
#      message says CI covers it; there is no `.github/` in this repository.
#
# So this gate takes the other approach: **enumerate the SURFACE, not the spellings.** Every tracked file that can
# configure a process gets read, every occurrence of a worker-capable knob is extracted, and each must satisfy the
# rule. It needs no Docker, no rendering and no daemon, which is why it can never skip — and adding a new
# configuration file puts it in scope automatically, because the file list comes from `git ls-files`.
#
# It is deliberately NOT a replacement for `compose-config.sh`'s rendered check. That one sees composition this
# cannot: an overlay, an anchor, an `env_file:`, a value assembled from two files. Both directions are needed —
# a text sweep sees what no renderer resolves (a tracked `.env`, a Caddyfile), and a renderer sees what no text
# sweep can (the merged result). Neither alone was enough, which is the finding.
#
# DELETE THIS GATE IN WAVE 10, when the portal token lands, and not before.
# ==================================================================================================================
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly REPO_ROOT

# THE ONLY PERMITTED RUNTIME. An allow-list, not a block-list of worker classes: the previous version matched two
# class names that exist in no package while the spelling this repository prescribes walked through, and the next
# runtime class has a name nobody has written yet. An unrecognised value fails closed — the polarity rule
# `PostgresRowLevelSecurityIsolation::isFalse()` states for catalogue flags, applied to a configuration value.
readonly PERMITTED_RUNTIME='Symfony\Component\Runtime\SymfonyRuntime'

# THE SEAM VARIABLES, all four the Caddyfile enumerates rather than the two that were checked. Each is spliced
# verbatim into the Caddyfile: `CADDY_GLOBAL_OPTIONS` into the global block that CONTAINS `frankenphp { }`,
# `FRANKENPHP_CONFIG` into that `frankenphp` block, `CADDY_SERVER_EXTRA_DIRECTIVES` into the site block, and
# `CADDY_EXTRA_CONFIG` at the end — where an `import` can live.
readonly -a SEAM_VARIABLES=(
    FRANKENPHP_CONFIG
    CADDY_SERVER_EXTRA_DIRECTIVES
    CADDY_GLOBAL_OPTIONS
    CADDY_EXTRA_CONFIG
)

# The tracked paths that can CONFIGURE a process. Documentation and tests are excluded deliberately: prose about
# `APP_RUNTIME` is how the constraint gets explained, and a gate that forbids explaining it would be deleted.
readonly -a SCANNED_PATTERNS=(
    '\.env$'
    '^infra/.*\.ya?ml$'
    'Dockerfile$'
    'Caddyfile$'
    '^Makefile$'
    '^api/config/.*\.ya?ml$'
    '^infra/.*\.sh$'
)

if [[ "${1:-}" == '--dump-rules' ]]; then
    printf 'permitted_runtime %s\n' "$PERMITTED_RUNTIME"
    printf 'seam_variable %s\n' "${SEAM_VARIABLES[@]}"
    printf 'scanned_pattern %s\n' "${SCANNED_PATTERNS[@]}"
    exit 0
fi

# `git ls-files` and not a filesystem walk: a parallel certification round places reviewer worktrees INSIDE the
# working tree, and a recursive walk then reads several repositories at once. § Gotchas, 2026-07-31.
mapfile -t candidates < <(
    git -C "$REPO_ROOT" ls-files \
        | grep -E "$(IFS='|'; printf '%s' "${SCANNED_PATTERNS[*]}")" \
        || true
)

inspected=0
violations=()

for relative in "${candidates[@]}"; do
    file="$REPO_ROOT/$relative"
    [[ -f "$file" ]] || continue
    inspected=$((inspected + 1))

    # STRIP CR, so a file saved with CRLF endings does not produce a violation whose message renders identically to
    # the permitted value. That defect shipped in the previous version and is the same one this project fixed in
    # `UuidV7Generator`'s refusal message in the very same commit: a message asserting two strings differ while
    # printing them the same is worse than no message.
    while IFS= read -r line; do
        line="${line%$'\r'}"

        # COMMENTS ARE NOT CONFIGURATION. The Caddyfile documents the worker block as a comment on purpose, and
        # `.env` files explain the constraint in comments. Flagging those would make the honest fix "delete the
        # documentation", which is the false-positive direction this gate has to get right to stay usable.
        stripped="${line#"${line%%[![:space:]]*}"}"
        [[ -z "$stripped" || "$stripped" == '#'* ]] && continue

        # ---- APP_RUNTIME, in every spelling a config file can carry ----------------------------------------
        # dotenv `KEY=` and `export KEY=`, Dockerfile `ENV KEY=`, compose `KEY:`, Makefile `KEY ?=`. Extracted by
        # pattern rather than by file type, because the point of this gate is not to depend on knowing the type.
        if [[ "$stripped" =~ (^|[[:space:]])APP_RUNTIME[[:space:]]*[=:] ]]; then
            value="${stripped#*[=:]}"
            # Strip an inline comment, surrounding quotes and whitespace. A dotenv inline comment is legal and
            # previously produced a false positive whose message printed the permitted value back at the reader.
            value="${value%%#*}"
            # NORMALISED IN THE ORDER THE FORMS NEST, and each step exists because it produced a false positive on
            # the real tree the first time this gate ran:
            #   1. a trailing ` \` is a Dockerfile LINE CONTINUATION, not part of the value — leaving it made the
            #      quote-stripping regex fail to match and the message print the permitted value back at the reader;
            #   2. then trim, then unquote;
            #   3. then collapse `\\` to `\`, because a Dockerfile `ENV` double-escapes every backslash while a
            #      dotenv file does not. A single backslash is unaffected, so this is safe for both forms.
            # A gate that cries wolf gets dismissed, and this project has paid for that once already.
            value="$(printf '%s' "$value" | sed -E '
                s/[[:space:]]*\\$//
                s/^[[:space:]]+//
                s/[[:space:]]+$//
                s/^"(.*)"$/\1/
                s/^'"'"'(.*)'"'"'$/\1/
                s/\\\\/\\/g
            ')"
            # `${APP_RUNTIME:-<default>}` — the interpolation form compose uses. The DEFAULT is what ships, so it
            # is what must be permitted; the variable half is covered by the file that sets it.
            if [[ "$value" =~ ^\$\{APP_RUNTIME:-(.*)\}$ ]]; then
                value="${BASH_REMATCH[1]}"
            fi
            value="${value#\\}"

            if [[ -n "$value" && "$value" != "$PERMITTED_RUNTIME" ]]; then
                violations+=("$relative: APP_RUNTIME is \"$value\"; the only permitted value is \"$PERMITTED_RUNTIME\"")
            fi
        fi

        # ---- THE FOUR SEAM VARIABLES: none may carry a worker directive ------------------------------------
        for seam in "${SEAM_VARIABLES[@]}"; do
            if [[ "$stripped" =~ (^|[[:space:]])"$seam"[[:space:]]*[=:] ]] \
                && [[ "${stripped,,}" == *worker* ]]; then
                violations+=("$relative: $seam declares a worker; it is spliced verbatim into the Caddyfile")
            fi
        done

        # ---- AN ACTIVE `worker` DIRECTIVE, anywhere in a Caddyfile -----------------------------------------
        # Not line-anchored: `frankenphp { worker /app/public/index.php }` on one line escaped the anchored
        # version. Any uncommented `worker` token in a Caddyfile is refused, which is blunt and correct — there is
        # no legitimate use of the word in an active directive while this constraint holds.
        if [[ "$relative" == *Caddyfile* && "${stripped,,}" =~ (^|[[:space:]{])worker([[:space:]{]|$) ]]; then
            violations+=("$relative: an ACTIVE \`worker\` directive — keep the documented block commented")
        fi

        # ---- AND AN `import`, because the imported file is where the directive would hide ------------------
        if [[ "$relative" == *Caddyfile* && "$stripped" =~ ^import[[:space:]] ]]; then
            violations+=("$relative: \`import $((0))\` — an imported Caddy config is outside this sweep, so an \`import\` is refused while worker mode is blocked: ${stripped}")
        fi
    done < "$file"
done

# Anti-vacuity, printed unconditionally and BEFORE the verdict, for the reason every gate here has one: a loop over
# nothing prints no violations and exits 0, indistinguishable from a clean sweep.
printf 'worker-mode-blocked: counts — inspected=%d violations=%d seams=%d\n' \
    "$inspected" "${#violations[@]}" "${#SEAM_VARIABLES[@]}"

if (( inspected == 0 )); then
    echo "worker-mode-blocked: FAIL — inspected NO files, so this gate asserted nothing." >&2
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

printf 'worker-mode-blocked: OK — %d configuration file(s) carry no worker-mode switch.\n' "$inspected"
