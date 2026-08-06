#!/usr/bin/env bash
#
# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# ==================================================================================================================
# WHICH FILES ARE CADDY CONFIGS, AND WHICH SEAMS THEY SPLICE — derived once, for BOTH gates.
#
# This exists because the two halves of the worker-mode control disagreed. `worker-mode-blocked.sh` derived its seam
# set from every tracked path containing `Caddyfile` plus the SOURCE of every `COPY` served via `--config`;
# `compose-config.sh` used `find "$INFRA" -name '*Caddyfile*'`. A certification round added a tracked
# `admin/Caddyfile` and got FIVE seams from one gate and FOUR from the other — so the rendered half, the only half
# that can see an `env_file:`, a YAML anchor or a value assembled across an overlay, never checked the fifth seam.
# Both commit messages meanwhile asserted the sets were the same.
#
# Two gates asking the same question of the same knob set must derive it the same way, so the derivation lives here
# and neither gate owns a copy. `--dump-rules` on both reads this, which is what makes the assertion checkable.
#
# THE ENUMERATION SOURCE IS `git ls-files`, never a filesystem walk: a parallel certification round places reviewer
# worktrees at `.claude/worktrees/`, INSIDE the working tree, and a recursive walk then reads several repositories at
# once (§ Gotchas, 2026-07-31). Callers whose fixture has no git index must say so explicitly by setting
# `TWES_CADDY_NO_INDEX=1`, which falls back to a walk SCOPED to `infra/` — narrow enough that it cannot reach the
# worktrees. That is a declared degradation rather than a silent one.
# ==================================================================================================================

# caddy_config_paths <repo-root>
#
# Prints, one per line, every repo-relative path that is a Caddy configuration: the union of
#   (a) every tracked path whose name contains `Caddyfile`, and
#   (b) the SOURCE of every `COPY` in any Dockerfile whose DESTINATION is passed to `--config`.
#
# (b) is what ties the set to the artefact rather than to a naming convention. Without it, renaming the served file
# to `infra/api/frankenphp.conf` retired the `worker` and `import` rules entirely while a decoy `Caddyfile` kept the
# seam derivation non-empty, so anti-vacuity stayed satisfied and both gates reported OK.
caddy_config_paths() {
    local root="$1"
    local -a tracked=()

    if [[ "${TWES_CADDY_NO_INDEX:-0}" == '1' ]]; then
        # DECLARED degradation, scoped to `infra/` so it cannot reach `.claude/worktrees/`.
        mapfile -t tracked < <(cd "$root" && find infra -type f 2>/dev/null | sort || true)
    else
        mapfile -d '' -t tracked < <(git -C "$root" ls-files -z)
    fi

    {
        local relative
        for relative in "${tracked[@]}"; do
            [[ "$relative" == *Caddyfile* ]] && printf '%s\n' "$relative"
        done

        # The `--config` destinations any Dockerfile serves, then the COPY sources landing on them.
        local destinations
        destinations="$(
            for relative in "${tracked[@]}"; do
                [[ "$(basename "$relative")" == Dockerfile* ]] || continue
                grep -ohE -- '--config[",[:space:]]+[^",[:space:]]+' "$root/$relative" 2>/dev/null || true
            done | grep -ohE '/[^",[:space:]]+' | sort -u || true
        )"

        local destination
        while IFS= read -r destination; do
            [[ -z "$destination" ]] && continue
            for relative in "${tracked[@]}"; do
                [[ "$(basename "$relative")" == Dockerfile* ]] || continue
                grep -E "^COPY[[:space:]]+[^[:space:]]+[[:space:]]+${destination}([[:space:]]|$)" \
                    "$root/$relative" 2>/dev/null | awk '{print $2}' || true
            done
        done <<< "$destinations"
    } | sort -u | while IFS= read -r relative; do
        [[ -n "$relative" && -f "$root/$relative" ]] && printf '%s\n' "$relative"
    done
}

# caddy_seam_variables <repo-root> <not-a-seam>
#
# The `{$NAME}` placeholders every Caddy config splices, minus the one that is a hostname. `$2` is the structural
# placeholder: it MUST be non-empty (it is the public hostname), so the emptiness rule cannot apply to it and it is
# governed by a structural rule instead. Excluding it here is not an exemption — see `worker-mode-blocked.sh`.
caddy_seam_variables() {
    local root="$1" structural="$2"
    local -a configs=()
    mapfile -t configs < <(caddy_config_paths "$root")

    (( ${#configs[@]} == 0 )) && return 0

    local -a absolute=()
    local relative
    for relative in "${configs[@]}"; do
        absolute+=("$root/$relative")
    done

    # `|| true` because `grep` exits 1 on no match and this runs under `set -euo pipefail` in both callers, where an
    # aborted assignment produced an exit with NO diagnostic at all. The empty result is REFUSED by each caller's own
    # anti-vacuity guard, with a message — the root cause is handled, not suppressed.
    grep -ohE '\{\$[A-Za-z_][A-Za-z0-9_]*' "${absolute[@]}" 2>/dev/null \
        | sed 's/^{\$//' | grep -v "^${structural}\$" | sort -u || true
}
