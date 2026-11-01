#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Each language's screen texts stay within 60 KiB once compressed (docs/SPEC.md § 7): the whole file is read before
# the first screen shows its words, and on a counter's phone over a weak connection every kilobyte is waited for.
# Measured at gzip level 6, the level infra/web/nginx.conf serves at, which this checks too: measured at one level
# and served at another, the number would say nothing about what a phone receives.
# A page's own texts, fetched when it first opens, are measured each on their own.
# Every language the interface speaks (web/src/app/shared/i18n/languages.json) has its file.
# Below the budget, the ruled wall, a reserve (stable-release S0.14) is the line no change crosses: the room above it is
# held for what is ruled to need it, and raising it is a decision with its reason in docs/SPEC.md § 7. Past 98 % of the
# reserve a file still passes, but says so: it is the moment to move a page's words into a texts part, before a change
# needs the room.
# Usage: screen-texts-budget.sh [--root DIR]   (SCREEN_TEXTS_BUDGET and SCREEN_TEXTS_RESERVE set both for its own test)
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
budget=${SCREEN_TEXTS_BUDGET:-61440}
# 58 KiB: Arabic's main file measured 57,468 B when it was set, the largest.
reserve=${SCREEN_TEXTS_RESERVE:-59392}
if [[ -n "${SCREEN_TEXTS_RESERVE:-}" ]] && ((reserve > budget)); then
  printf 'screen-texts-budget: FAIL — the reserve (%d B) is above the budget (%d B), the ruled wall it sits under\n' "$reserve" "$budget"
  exit 1
fi
((reserve > budget)) && reserve=$budget
# A file above this many bytes is warned of: 98 % of the reserve, rounded up.
near=$(((reserve * 98 + 99) / 100))
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
near_full=()
sizes=()
missing=()
for file in "${files[@]}"; do
  language=$(basename "$file")
  size=$(gzip -c -"$level" "$file" | wc -c)
  sizes+=("$language $size B")
  ((size > reserve)) && over+=("$language: $size B")
  ((size >= near && size <= reserve)) && near_full+=("$language $size B ($((reserve - size)) B left)")
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
    ((own > reserve)) && over+=("$name/$language: $own B")
    ((own >= near && own <= reserve)) && near_full+=("$name/$language $own B ($((reserve - own)) B left)")
  done
done

if ((${#missing[@]})); then
  printf 'screen-texts-budget: FAIL — a page'"'"'s texts are missing in a language, where it would show their keys:\n'
  printf '  %s\n' "${missing[@]}"
  exit 1
fi

if ((${#over[@]})); then
  if ((reserve < budget)); then
    printf 'screen-texts-budget: FAIL — over the reserve of %d B at gzip level %d, below the budget of %d B (split the texts per module; the room above the reserve is raised only with its reason in docs/SPEC.md § 7):\n' "$reserve" "$level" "$budget"
  else
    printf 'screen-texts-budget: FAIL — over %d B at gzip level %d (split the texts per module rather than raise the budget):\n' "$budget" "$level"
  fi
  printf '  %s\n' "${over[@]}"
  exit 1
fi
for file in "${near_full[@]}"; do
  printf 'screen-texts-budget: WARN — %s, past 98 %% of the reserve of %d B: move a page'"'"'s words into a texts part before adding any\n' "$file" "$reserve"
done
printf 'screen-texts-budget: OK — %d languages within the reserve of %d B (budget %d B) at gzip level %d (%s)\n' "${#files[@]}" "$reserve" "$budget" "$level" "$(IFS=,; echo "${sizes[*]}" | sed 's/,/, /g')"
