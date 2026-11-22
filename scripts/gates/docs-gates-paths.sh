#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A push touching only what ci.yml's paths-ignore lists runs none of ci.yml's jobs, yet gates read those files (the
# Decisions Log stamps, coming-texts' roadmap rows): decision-stamps.yml runs `make gate-docs` on exactly those paths. The
# two lists must be equal, or a path is either checked by nobody or taken from ci.yml for nothing. snapshot-gate.sh reads
# ci.yml's list too, so a docs-only change is gated locally as CI gates it.
# Usage: docs-gates-paths.sh [--root <tree>]
set -uo pipefail
export LC_ALL=C  # sort and comm must order alike
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == --root ]] && root=$2
ci=$root/.github/workflows/ci.yml
docs=$root/.github/workflows/decision-stamps.yml
for f in "$ci" "$docs"; do [[ -f "$f" ]] || { printf 'docs-gates-paths: FAIL — found no %s\n' "${f#"$root"/}"; exit 1; }; done

# The items under a key of the push trigger, read as snapshot-gate.sh reads them: four spaces for the key, six for an item.
items() { awk -v key="    $2:" '$0 == key { on = 1; next } on && /^      - / { sub(/^      - /, ""); print; next } on && /^      #/ { next } on { exit }' "$1" | sort; }
skipped=$(items "$ci" paths-ignore)
gated=$(items "$docs" paths)
[[ -n "$skipped" ]] || { printf 'docs-gates-paths: FAIL — reads no paths-ignore in ci.yml: the list moved or was lost\n'; exit 1; }

problems=()
while IFS= read -r p; do [[ -n "$p" ]] && problems+=("ci.yml skips $p, which decision-stamps.yml does not gate"); done < <(comm -23 <(echo "$skipped") <(echo "$gated"))
while IFS= read -r p; do [[ -n "$p" ]] && problems+=("decision-stamps.yml gates $p, which ci.yml already runs everything for"); done < <(comm -13 <(echo "$skipped") <(echo "$gated"))
grep -qE '^ +- run: make gate-docs$' "$docs" || problems+=("decision-stamps.yml does not run make gate-docs")

if ((${#problems[@]})); then
  printf 'docs-gates-paths: FAIL — %d problem(s):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'docs-gates-paths: OK — %d paths ci.yml skips, every one gated by make gate-docs in decision-stamps.yml\n' "$(wc -l <<<"$skipped")"
