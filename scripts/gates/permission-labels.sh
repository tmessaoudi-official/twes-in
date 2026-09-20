#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every permission a role may be given, and every heading the groups are drawn under, has a label a person can
# read, in both languages (docs/SPEC.md § 7, 2026-09-20 11:30, row 104).
#
# The catalogue is COLLECTED from the modules, so adding a permission is one line in a manifest and nothing warns
# that the roles screen will then draw a raw dotted string where a sentence belongs — the same drift the collected
# catalogue exists to prevent, one layer further out. The permission strings live in the API's `*Permission`
# classes; what a person reads lives in the SPA's own translations, as every other SPA string does. This is what
# ties the two together.
#
# It discovers the strings from the source rather than listing them, and carries a floor so that a pattern which
# stops matching reds instead of comparing two empty sets and passing.
# The HEADING is checked as well as the labels, because they fail independently: every permission under a group
# can be labelled while the line above them is a raw dotted key. That is not hypothetical — `modules.invoices` and
# `modules.expenses` shipped exactly that way under the first version of this gate.
# Usage: permission-labels.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
floor=${PERMISSION_LABELS_FLOOR:-20}
groups_floor=${PERMISSION_GROUPS_FLOOR:-10}

# One key, one language: present, a string, and not blank. The dots are nesting, as ngx-translate reads them.
labelled() {
  KEY="$1" FILE="$2" python3 -c '
import json, os, sys
node = json.load(open(os.environ["FILE"], encoding="utf-8"))
for part in os.environ["KEY"].split("."):
    if not isinstance(node, dict) or part not in node:
        sys.exit(1)
    node = node[part]
sys.exit(0 if isinstance(node, str) and node.strip() else 1)
'
}

mapfile -t files < <(git -C "$root" ls-files -- 'api/src/*Permission.php')
if ((${#files[@]} == 0)); then
  printf 'permission-labels: FAIL — found no *Permission.php file; the discovery pattern is broken\n'
  exit 1
fi

# A company's role never grants a platform permission, so those are not labelled and not wanted here.
mapfile -t permissions < <(
  cd "$root" && grep -hoE "'[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+'" "${files[@]}" |
    tr -d "'" | grep -v '^platform\.' | sort -u
)

if ((${#permissions[@]} < floor)); then
  printf 'permission-labels: FAIL — found only %d permissions, below the floor of %d; the discovery pattern is broken\n' \
    "${#permissions[@]}" "$floor"
  exit 1
fi

# The headings the matrix draws its groups under: each module manifest's own labelKey, plus the three groups the
# collector keys `permissions.groups.<key>` because they belong to no module (KnownPermissions::UNMODULED).
mapfile -t manifests < <(git -C "$root" ls-files -- 'api/src/Module/*/Infrastructure/Module/*Module.php')
if ((${#manifests[@]} == 0)); then
  printf 'permission-labels: FAIL — found no module manifest; the discovery pattern is broken\n'
  exit 1
fi

# The UNMODULED groups live in the ADAPTER, not the KnownPermissions port beside them: pointing at the port finds
# the file and matches nothing, which drops three headings while still reading as a pass. The floor is what caught
# that, and only because it was set above the number the modules alone produce — keep it that way.
known=api/src/ModuleRegistry/Infrastructure/Permission/DeclaredPermissions.php
if [[ ! -f "$root/$known" ]]; then
  printf 'permission-labels: FAIL — %s is missing; the unmoduled groups cannot be discovered\n' "$known"
  exit 1
fi

mapfile -t headings < <(
  cd "$root" && {
    grep -hoE "'modules\.[a-z][a-z0-9_]*'" "${manifests[@]}" | tr -d "'"
    grep -hoE "'[a-z][a-z0-9_]*' => \[" "$known" | sed -E "s/' => \[//; s/^'/permissions.groups./"
  } | sort -u
)

if ((${#headings[@]} < groups_floor)); then
  printf 'permission-labels: FAIL — found only %d group headings, below the floor of %d; the discovery pattern is broken\n' \
    "${#headings[@]}" "$groups_floor"
  exit 1
fi

missing=()
for language in fr en; do
  file="$root/web/public/i18n/$language.json"
  for permission in "${permissions[@]}"; do
    if ! labelled "permissions.$permission" "$file"; then
      missing+=("$language: permissions.$permission")
    fi
  done
  for heading in "${headings[@]}"; do
    if ! labelled "$heading" "$file"; then
      missing+=("$language: $heading")
    fi
  done
done

if ((${#missing[@]})); then
  printf 'permission-labels: FAIL — %d label(s) missing (add them to web/public/i18n/<lang>.json):\n' "${#missing[@]}"
  printf '  %s\n' "${missing[@]}"
  exit 1
fi

printf 'permission-labels: OK — %d permissions and %d group headings are labelled in fr and en\n' \
  "${#permissions[@]}" "${#headings[@]}"
