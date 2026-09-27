#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The icon font is cut at build to the icons web/src/app/shared/icons/icons.ts declares (web/scripts/subset-icons.mjs),
# so an icon the list does not hold shows as its name in letters. An `icon` field takes an IconName, which TypeScript
# checks; what it cannot see is a name written out in a template: <mat-icon>home</mat-icon>, or a ternary's result
# inside one ({{ open ? 'expand_less' : 'expand_more' }}, never the operand a value is compared against). Each such
# name must be declared. The other way, each declared name must still be named somewhere in the app, in a template or
# as a quoted string: looser, since any string counts, but enough to catch an entry left behind by a removal.
# The list is kept in alphabetical order. Reads templates and inline component templates from the index, specs
# excluded, and reds when fewer template icons than the floor are found, so a pattern that stops matching cannot pass
# over nothing. Usage: icons-declared.sh [--root DIR] [--floor N]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=30
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "icons-declared: unknown argument $1"; exit 2 ;;
  esac
done
list=web/src/app/shared/icons/icons.ts
mapfile -t declared < <(perl -0777 -ne 'if (/ICONS\s*=\s*\[(.*?)\]\s*as const/s) { my $body = $1; print "$1\n" while $body =~ /\x27([a-z0-9_]+)\x27/g }' "$root/$list" 2>/dev/null)
if ((${#declared[@]} == 0)); then
  echo "icons-declared: FAIL — no icon read from $list (ICONS missing or empty)"
  exit 1
fi
status=0
if [[ "$(printf '%s\n' "${declared[@]}")" != "$(printf '%s\n' "${declared[@]}" | sort -u)" ]]; then
  echo "icons-declared: FAIL — $list is not in alphabetical order, or names an icon twice"
  status=1
fi
mapfile -t files < <(git -C "$root" ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$' | grep -vx "$list")
# Names a template writes out: the whole content of a mat-icon, or a quoted result of a ternary inside its {{ }}.
mapfile -t written < <(cd "$root" && perl -0777 -ne '
  while (/<mat-icon\b[^>]*>(.*?)<\/mat-icon>/sg) {
    my $body = $1;
    if ($body =~ /^\s*([a-z0-9_]+)\s*$/) { print "$1\n" }
    elsif ($body =~ /^\s*\{\{(.*)\}\}\s*$/s) { my $e = $1; print "$1\n" while $e =~ /[?:]\s*\x27([a-z0-9_]+)\x27/g }
  }' "${files[@]}" /dev/null | sort -u)
if ((${#written[@]} < floor)); then
  printf 'icons-declared: FAIL — %d icon(s) read from the templates, below the floor of %d: the patterns no longer match\n' "${#written[@]}" "$floor"
  status=1
fi
mapfile -t undeclared < <(comm -13 <(printf '%s\n' "${declared[@]}" | sort -u) <(printf '%s\n' "${written[@]}"))
if ((${#undeclared[@]})); then
  printf 'icons-declared: FAIL — icon(s) a template writes that %s does not declare (they would show as letters):\n' "$list"
  printf '  %s\n' "${undeclared[@]}"
  status=1
fi
mapfile -t quoted < <(cd "$root" && perl -ne 'print "$1\n" while /\x27([a-z0-9_]+)\x27/g' "${files[@]}" /dev/null | sort -u)
mapfile -t unnamed < <(comm -23 <(printf '%s\n' "${declared[@]}" | sort -u) <(printf '%s\n' "${written[@]}" "${quoted[@]}" | sort -u))
if ((${#unnamed[@]})); then
  printf 'icons-declared: FAIL — declared in %s but named nowhere in the app:\n' "$list"
  printf '  %s\n' "${unnamed[@]}"
  status=1
fi
((status)) && exit 1
printf 'icons-declared: OK — %d icons declared, the %d a template writes among them\n' "${#declared[@]}" "${#written[@]}"
