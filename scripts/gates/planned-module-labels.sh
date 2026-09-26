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

printf 'planned-module-labels: OK — %d planned modules are named and described in fr and en\n' "${#keys[@]}"
