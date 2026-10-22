#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every action a workflow uses is pinned by a full commit SHA, its release named beside it (`@<40 hex> # v7.0.1`), as
# GitHub's security hardening guide recommends: a tag can be moved to other code, a commit cannot (docs/UPDATE.md).
# A local action (`./…`) carries no version and is let through.
# Usage: action-pins.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
problems=()
problem() { problems+=("$1"); }

checked=0
for path in "$root"/.github/workflows/*.yml "$root"/.github/actions/*/action.yml; do
  [[ -f "$path" ]] || continue
  file=${path#"$root/"}
  while IFS=: read -r n line; do
    use=${line#*uses:}; use=${use#"${use%%[![:space:]]*}"}
    [[ "$use" == ./* ]] && continue
    checked=$((checked + 1))
    [[ "$use" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+@[0-9a-f]{40}[[:space:]]+#[[:space:]]+v[0-9][^[:space:]]*$ ]] \
      || problem "$file:$n uses $use: pin it by the release's full commit SHA, the tag as a comment (@<sha> # vX.Y.Z)"
  done < <(grep -nE '^\s*(-\s+)?uses:' "$path")
done
((checked)) || problem "found no action used under .github: the gate reads nothing"

if ((${#problems[@]})); then
  printf 'action-pins: FAIL — %d action(s) not pinned by commit:\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'action-pins: OK — %d uses, each pinned by a commit SHA with its release named\n' "$checked"
