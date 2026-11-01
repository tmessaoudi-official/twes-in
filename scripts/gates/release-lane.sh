#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every workflow a push to master runs also runs on a release's own branch (`release/YYYY.MM.DD`, docs/RUNBOOK.md « Patch
# a release »): a patch for the shops is built there, and a check only master runs would let the patch reach the shops
# unproved (the P0 lens's F11: deploy-check, restore-drill and monitor-check named master alone). Reads each workflow's
# push branch filter, written on one line as a list.
# Usage: release-lane.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
problems=()
checked=0
for path in "$root"/.github/workflows/*.yml; do
  [[ -f "$path" ]] || continue
  file=${path#"$root/"}
  # The branches of the push trigger: the first `branches:` line after `push:`, before the next trigger at its depth.
  branches=$(awk '/^  push:/ { inside = 1; next } inside && /^  [a-z_]+:/ { inside = 0 } inside && /^    branches:/ { print; exit }' "$path")
  [[ "$branches" =~ [^a-z/]master[^a-z/] ]] || continue
  checked=$((checked + 1))
  [[ "$branches" =~ [\'\"]release/\*\*[\'\"] ]] \
    || problems+=("$file runs on master's pushes but not on a release's branch: add 'release/**' to its push branches")
done
((checked)) || problems+=("found no workflow that a push to master runs: the gate reads nothing")

if ((${#problems[@]})); then
  printf 'release-lane: FAIL — %d workflow(s) left out of the release lane:\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'release-lane: OK — %d workflows run on master and on a release branch alike\n' "$checked"
