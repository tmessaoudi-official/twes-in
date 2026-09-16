#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every icon-only control (mat-icon-button, mat-mini-fab) in the web app is named through the appLabel directive,
# which gives it its accessible name and, from the same string, its tooltip (docs/SPEC.md § 7, 2026-09-16 review).
# An aria-label alone satisfies axe but leaves a pointer user guessing what the icon does. Reads templates and
# inline component templates from the index, specs excluded. A `>` inside an attribute value would end the tag
# early; no template here writes one. Usage: icon-buttons-named.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
mapfile -t files < <(git -C "$root" ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
result=$(cd "$root" && perl -0777 -ne '
  while (/<(?:button|a)\b[^>]*?\bmat-(?:icon-button|mini-fab)\b[^>]*>/sg) {
    my ($tag, $at) = ($&, $-[0]);
    $checked++;
    next if $tag =~ /\[?appLabel\]?=/;
    my $line = 1 + (substr($_, 0, $at) =~ tr/\n//);
    print "WRONG $ARGV:$line\n";
  }
  END { print "CHECKED ", ($checked // 0), "\n" }
' "${files[@]}" /dev/null)
checked=$(sed -n 's/^CHECKED //p' <<<"$result")
mapfile -t wrong < <(sed -n 's/^WRONG //p' <<<"$result")
if ((${#wrong[@]})); then
  printf 'icon-buttons-named: FAIL — %d icon-only control(s) without appLabel (fix: [appLabel]="… | translate" instead of aria-label):\n' "${#wrong[@]}"
  printf '  %s\n' "${wrong[@]}"
  exit 1
fi
printf 'icon-buttons-named: OK — %d icon-only controls are named with appLabel\n' "$checked"
