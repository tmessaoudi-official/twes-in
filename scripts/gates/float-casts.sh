#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Money never goes through a float (docs/SPEC.md § 7, audit E-1): amounts are decimal strings, compared and summed with
# bcmath, and the database's NUMERIC comes back as an exact string. A float cast loses digits past 2^53 and rounds the
# rest, so none is written in api/src unless its own line says why it carries no money: `// float: <why>`.
# Usage: float-casts.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
problems=()

if [[ ! -d "$root/api/src" ]]; then problems+=("found no api/src")
else
  while IFS=: read -r file n line; do
    code=${line#"${line%%[![:space:]]*}"}
    # A comment that names a cast is not one.
    [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* ]] && continue
    [[ "$code" =~ //[[:space:]]*float:[[:space:]]*[^[:space:]] ]] && continue
    problems+=("${file#"$root/"}:$n: ${code:0:110}")
  done < <(grep -rnE --include='*.php' '\(\s*(float|double|real)\s*\)|\bfloatval\s*\(' "$root/api/src")
fi

if ((${#problems[@]})); then
  printf 'float-casts: FAIL — %d float cast(s) in api/src (money is a decimal string: Decimal, bcmath; a cast that carries no money says so with // float: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'float-casts: OK — no float cast in api/src but the ones that say why they carry no money\n'
