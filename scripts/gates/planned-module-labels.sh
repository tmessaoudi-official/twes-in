#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every planned module the API lists has what the modules page draws for it, in both languages: its name
# (`modules.<key>`) and what it will do (`coming.<key>.heading`, `coming.<key>.does`) (docs/SPEC.md § 7,
# 2026-09-26 10:08, row 150).
#
# The planned modules are declared in the API (`PlannedModules`), and the page draws them from the list the API
# answers, so adding one is a line in PHP and nothing else warns that the page will then show a raw dotted key where
# a name and a sentence belong. The keys are discovered from that file rather than listed here, and a floor stops a
# pattern that no longer matches from comparing two empty sets and passing.
# Usage: planned-module-labels.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
floor=${PLANNED_MODULE_LABELS_FLOOR:-20}

# Every wanted key in one language, in one pass: each must be present, a string, and not blank. The dots are nesting,
# as ngx-translate reads them. Prints the keys that are not.
unlabelled() {
  FILE="$1" python3 -c '
import json, os, sys
try:
    root = json.load(open(os.environ["FILE"], encoding="utf-8"))
except (OSError, ValueError):
    root = {}  # an unreadable file labels nothing: every key is reported, never none
for key in sys.stdin.read().split():
    node = root
    for part in key.split("."):
        node = node.get(part) if isinstance(node, dict) else None
    if not (isinstance(node, str) and node.strip()):
        print(key)
'
}

source_file=api/src/ModuleRegistry/Application/PlannedModules.php
if [[ ! -f "$root/$source_file" ]]; then
  printf 'planned-module-labels: FAIL — %s is missing; the planned modules cannot be discovered\n' "$source_file"
  exit 1
fi

mapfile -t keys < <(
  grep -oE "new ModuleManifest\('[a-z][a-z0-9_]*'" "$root/$source_file" | sed -E "s/.*\('//; s/'$//" | sort -u
)

if ((${#keys[@]} < floor)); then
  printf 'planned-module-labels: FAIL — found only %d planned modules, below the floor of %d; the discovery pattern is broken\n' \
    "${#keys[@]}" "$floor"
  exit 1
fi

missing=()
for language in fr en; do
  mapfile -t absent < <(
    for key in "${keys[@]}"; do printf '%s\n' "modules.$key" "coming.$key.heading" "coming.$key.does"; done |
      unlabelled "$root/web/public/i18n/$language.json"
  )
  for label in "${absent[@]}"; do
    missing+=("$language: $label")
  done
done

if ((${#missing[@]})); then
  printf 'planned-module-labels: FAIL — %d label(s) missing (add them to web/public/i18n/<lang>.json):\n' "${#missing[@]}"
  printf '  %s\n' "${missing[@]}"
  exit 1
fi

# Row 150: the menu draws a planned module only from its place in the web; one without a place is never shown. A
# missing file places nothing, so every module is reported.
places_file=web/src/app/shell/planned-nav.ts
unplaced=()
for key in "${keys[@]}"; do
  grep -qE "(^|[{ ])key: '$key'," "$root/$places_file" 2>/dev/null || unplaced+=("$key")
done
if ((${#unplaced[@]})); then
  printf 'planned-module-labels: FAIL — %d planned module(s) with no menu place: %s (add each to PLANNED_NAV in %s)\n' \
    "${#unplaced[@]}" "${unplaced[*]}" "$places_file"
  exit 1
fi

# Row 150 slice 5: what a screen will offer (`PlannedAction`) and what the settings page says a module will let a
# company set (`PlannedSetting`) are declared as `{ module: '<key>', label: '<translation key>' }` and drawn only while
# the API lists that module as planned. A key that is not one — a typo, or a module that has since shipped and needs
# its real actions instead — draws nothing, silently. Declarations are discovered in the web's sources (specs are
# fixtures, not declarations), above a floor of their own.
declarations_floor=${PLANNED_MODULE_DECLARATIONS_FLOOR:-20}
mapfile -t declarations < <(
  grep -rhoE --include='*.ts' --exclude='*.spec.ts' \
    "module: '[a-z][a-z0-9_]*', label: '[a-z][a-z0-9_.]*'" "$root/web/src/app" 2>/dev/null |
    sed -E "s/module: '([^']*)', label: '([^']*)'/\1 \2/"
)
if ((${#declarations[@]} < declarations_floor)); then
  printf 'planned-module-labels: FAIL — found only %d declarations, below the floor of %d; the discovery pattern is broken\n' \
    "${#declarations[@]}" "$declarations_floor"
  exit 1
fi
declare -A planned=()
for key in "${keys[@]}"; do planned[$key]=1; done
unplanned=()
for declaration in "${declarations[@]}"; do
  module=${declaration%% *}
  [[ -n "${planned[$module]:-}" ]] || unplanned+=("$module")
done
if ((${#unplanned[@]})); then
  printf 'planned-module-labels: FAIL — declared for a module that is not planned: %s (a typo, or a module that shipped: give it its real actions)\n' \
    "$(printf '%s\n' "${unplanned[@]}" | sort -u | tr '\n' ' ')"
  exit 1
fi
missing=()
for language in fr en; do
  mapfile -t absent < <(
    for declaration in "${declarations[@]}"; do printf '%s\n' "${declaration#* }"; done | sort -u |
      unlabelled "$root/web/public/i18n/$language.json"
  )
  for label in "${absent[@]}"; do
    missing+=("$language: $label")
  done
done
if ((${#missing[@]})); then
  printf 'planned-module-labels: FAIL — %d declared label(s) missing (add them to web/public/i18n/<lang>.json):\n' "${#missing[@]}"
  printf '  %s\n' "${missing[@]}"
  exit 1
fi

printf 'planned-module-labels: OK — %d planned modules are named, described and placed in the menu, and %d declarations name one, in fr and en\n' \
  "${#keys[@]}" "${#declarations[@]}"
