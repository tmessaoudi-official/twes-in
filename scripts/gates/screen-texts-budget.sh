#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Each language's screen texts stay within 60 KiB once compressed (docs/SPEC.md § 7): the whole file is read before
# the first screen shows its words, and on a counter's phone over a weak connection every kilobyte is waited for.
# Measured at gzip level 6, the level infra/web/nginx.conf serves at, which this checks too: measured at one level
# and served at another, the number would say nothing about what a phone receives.
# Usage: screen-texts-budget.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
budget=61440
level=6

if ! grep -Eq "^[[:space:]]*gzip_comp_level[[:space:]]+${level};" "$root/infra/web/nginx.conf"; then
  printf 'screen-texts-budget: FAIL — infra/web/nginx.conf must serve at gzip_comp_level %s;, the level the texts are measured at\n' "$level"
  exit 1
fi

shopt -s nullglob
files=("$root"/web/public/i18n/*.json)
# French and English at least: fewer means this stopped reading where the texts are.
if ((${#files[@]} < 2)); then
  printf 'screen-texts-budget: FAIL — %d languages found in web/public/i18n, at least 2 expected\n' "${#files[@]}"
  exit 1
fi

over=()
sizes=()
for file in "${files[@]}"; do
  size=$(gzip -c -"$level" "$file" | wc -c)
  sizes+=("$(basename "$file") $size B")
  ((size > budget)) && over+=("$(basename "$file"): $size B")
done

if ((${#over[@]})); then
  printf 'screen-texts-budget: FAIL — over %d B at gzip level %d (split the texts per module rather than raise the budget):\n' "$budget" "$level"
  printf '  %s\n' "${over[@]}"
  exit 1
fi
printf 'screen-texts-budget: OK — %d languages within %d B at gzip level %d (%s)\n' "${#files[@]}" "$budget" "$level" "$(IFS=,; echo "${sizes[*]}" | sed 's/,/, /g')"
