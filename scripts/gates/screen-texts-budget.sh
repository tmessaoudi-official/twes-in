#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Each language's screen texts stay within 60 KiB once compressed (docs/SPEC.md § 7): the whole file is read before
# the first screen shows its words, and on a counter's phone over a weak connection every kilobyte is waited for.
# Measured at gzip level 6, the level infra/web/nginx.conf serves at, which this checks too: measured at one level
# and served at another, the number would say nothing about what a phone receives.
# A page's own texts, fetched when it first opens, are measured each on their own.
# Every language the interface speaks (web/src/app/shared/i18n/languages.json) has its file.
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

# The languages the interface speaks are the catalogue's: each has its texts, or its screens would show keys. A file
# for a language not spoken yet, written before it is switched on, is measured all the same.
catalogue="$root/web/src/app/shared/i18n/languages.json"
spoken=()
if [[ -f "$catalogue" ]]; then
  mapfile -t spoken < <(jq -r '.languages[]? | select(.interface == true) | .tag' "$catalogue")
fi
if ((${#spoken[@]} == 0)); then
  printf 'screen-texts-budget: FAIL — read no language the interface speaks from %s\n' "${catalogue#"$root/"}"
  exit 1
fi
unwritten=()
for language in "${spoken[@]}"; do
  [[ -f "$root/web/public/i18n/$language.json" ]] || unwritten+=("web/public/i18n/$language.json")
done
if ((${#unwritten[@]})); then
  printf 'screen-texts-budget: FAIL — the interface speaks a language whose texts are missing:\n'
  printf '  %s\n' "${unwritten[@]}"
  exit 1
fi

shopt -s nullglob
files=("$root"/web/public/i18n/*.json)

over=()
sizes=()
missing=()
for file in "${files[@]}"; do
  language=$(basename "$file")
  size=$(gzip -c -"$level" "$file" | wc -c)
  sizes+=("$language $size B")
  ((size > budget)) && over+=("$language: $size B")
  # A page's own texts (web/public/i18n/<page>/<language>.json, shared/i18n/texts-part.ts) are fetched when that page
  # first opens, on their own request, once the language's file is there: each stays within the budget by itself.
  for part in "$root"/web/public/i18n/*/; do
    name=$(basename "$part")
    if [[ ! -f "$part$language" ]]; then
      missing+=("$name/$language")
      continue
    fi
    own=$(gzip -c -"$level" "$part$language" | wc -c)
    sizes+=("$name/$language $own B")
    ((own > budget)) && over+=("$name/$language: $own B")
  done
done

if ((${#missing[@]})); then
  printf 'screen-texts-budget: FAIL — a page'"'"'s texts are missing in a language, where it would show their keys:\n'
  printf '  %s\n' "${missing[@]}"
  exit 1
fi

if ((${#over[@]})); then
  printf 'screen-texts-budget: FAIL — over %d B at gzip level %d (split the texts per module rather than raise the budget):\n' "$budget" "$level"
  printf '  %s\n' "${over[@]}"
  exit 1
fi
printf 'screen-texts-budget: OK — %d languages within %d B at gzip level %d (%s)\n' "${#files[@]}" "$budget" "$level" "$(IFS=,; echo "${sizes[*]}" | sed 's/,/, /g')"
