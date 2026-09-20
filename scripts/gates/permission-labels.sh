#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every permission a role may be given has a label a person can read, in both languages
# (docs/SPEC.md § 7, 2026-09-20 11:30, row 104).
#
# The catalogue is COLLECTED from the modules, so adding a permission is one line in a manifest and nothing warns
# that the roles screen will then draw a raw dotted string where a sentence belongs — the same drift the collected
# catalogue exists to prevent, one layer further out. The permission strings live in the API's `*Permission`
# classes; what a person reads lives in the SPA's own translations, as every other SPA string does. This is what
# ties the two together.
#
# It discovers the strings from the source rather than listing them, and carries a floor so that a pattern which
# stops matching reds instead of comparing two empty sets and passing.
# Usage: permission-labels.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
floor=${PERMISSION_LABELS_FLOOR:-20}

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

missing=()
for language in fr en; do
  file="$root/web/public/i18n/$language.json"
  for permission in "${permissions[@]}"; do
    if ! PERMISSION="$permission" FILE="$file" python3 -c '
import json, os, sys
labels = json.load(open(os.environ["FILE"], encoding="utf-8")).get("permissions", {})
for part in os.environ["PERMISSION"].split("."):
    if not isinstance(labels, dict) or part not in labels:
        sys.exit(1)
    labels = labels[part]
sys.exit(0 if isinstance(labels, str) and labels.strip() else 1)
'; then
      missing+=("$language: permissions.$permission")
    fi
  done
done

if ((${#missing[@]})); then
  printf 'permission-labels: FAIL — %d permission label(s) missing (add them to web/public/i18n/<lang>.json):\n' "${#missing[@]}"
  printf '  %s\n' "${missing[@]}"
  exit 1
fi

printf 'permission-labels: OK — %d permissions are labelled in fr and en\n' "${#permissions[@]}"
