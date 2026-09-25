#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The places a guided tour may point at (docs/SPEC.md § 7, 2026-09-25 22:17, rows 137 and 140) are declared once, in
# web/src/app/shared/tour/tour-anchors.ts, and carried as data-tour="…" by shared components. Checked both ways: a
# declared anchor no element carries any more is a tour that would point at nothing, and a data-tour the list does
# not declare is a typo a tour could never reach. Reads templates and inline component templates from the index,
# specs excluded. Usage: tour-anchors.sh [--root DIR]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
list=web/src/app/shared/tour/tour-anchors.ts
mapfile -t declared < <(perl -0777 -ne 'if (/TOUR_ANCHORS\s*=\s*\[(.*?)\]/s) { my $body = $1; print "$1\n" while $body =~ /\x27([a-z0-9-]+)\x27/g }' "$root/$list" 2>/dev/null | sort -u)
if ((${#declared[@]} == 0)); then
  echo "tour-anchors: FAIL — no anchor read from $list (TOUR_ANCHORS missing or empty)"
  exit 1
fi
mapfile -t files < <(git -C "$root" ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
mapfile -t used < <(cd "$root" && perl -ne 'print "$1\n" while /\bdata-tour="([a-z0-9-]+)"/g' "${files[@]}" /dev/null | sort -u)
mapfile -t missing < <(comm -23 <(printf '%s\n' "${declared[@]}") <(printf '%s\n' "${used[@]}"))
mapfile -t undeclared < <(comm -13 <(printf '%s\n' "${declared[@]}") <(printf '%s\n' "${used[@]}"))
status=0
if ((${#missing[@]})); then
  printf 'tour-anchors: FAIL — declared in %s but carried by no element:\n' "$list"
  printf '  %s\n' "${missing[@]}"
  status=1
fi
if ((${#undeclared[@]})); then
  printf 'tour-anchors: FAIL — data-tour in the app that %s does not declare:\n' "$list"
  printf '  %s\n' "${undeclared[@]}"
  status=1
fi
((status)) && exit 1
printf 'tour-anchors: OK — %d anchors declared, each carried by an element\n' "${#declared[@]}"
