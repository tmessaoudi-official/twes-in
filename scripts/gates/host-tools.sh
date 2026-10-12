#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every stage, command and gate runs in Docker, never on the host's PHP, Composer or Node (docs/SPEC.md § 7, 2026-10-03).
# Nothing else stops that coming back: a setup-php step in CI, a bare `npm` recipe in the Makefile. This refuses both.
#   CI        in every workflow: no setup-php / setup-node / apt-get, and no bare composer / npm / npx / php / node run step
#   Makefile  a recipe line that starts with a host tool (cd api|web &&, php, composer, npm, npx, node, bin/console,
#             vendor/bin) is allowed only in an `in-*` target, which runs inside the tools container
# Usage: host-tools.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
problems=()
problem() { problems+=("$1"); }

# ci.yml must be there; every other workflow is held to the same rule, since each one runs on a runner of its own.
[[ -f "$root/.github/workflows/ci.yml" ]] || problem "found no .github/workflows/ci.yml"
for path in "$root"/.github/workflows/*.yml; do
  [[ -f "$path" ]] || continue
  ci=${path#"$root/"}
  while IFS=: read -r n line; do problem "$ci:$n names a host toolchain step: ${line#"${line%%[![:space:]]*}"}"; done \
    < <(grep -nE 'setup-php|setup-node|apt-get|^\s*(-\s+)?run:\s+(composer|npm|npx|php|node)\b' "$path")
done

if [[ ! -f "$root/Makefile" ]]; then problem "found no Makefile"
else
  target=
  while IFS= read -r line || [[ -n "$line" ]]; do
    n=$((${n:-0} + 1))
    if [[ "$line" =~ ^([A-Za-z0-9_.-]+):($|[^=]) ]]; then target=${BASH_REMATCH[1]}; continue; fi
    [[ "$line" == $'\t'* ]] || continue
    body=${line#$'\t'}; body=${body#@}
    if [[ "$body" =~ ^(cd[[:space:]]+(api|web)[[:space:]]+\&\&|php|composer|npm|npx|node|bin/console|vendor/bin/)([[:space:]]|$|/) && "$target" != in-* ]]; then
      problem "Makefile:$n, target ${target:-?}, runs a host tool outside an in-* target: ${body:0:70}"
    fi
  done < "$root/Makefile"
fi

if ((${#problems[@]})); then
  printf 'host-tools: FAIL — %d host-toolchain use(s) (the project runs in Docker: CLAUDE.md § Run everything in Docker):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'host-tools: OK — CI names no host toolchain, and the Makefile runs host tools only inside in-* targets\n'
