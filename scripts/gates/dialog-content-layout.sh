#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# No dialog lays out `mat-dialog-content` itself: Material's own styles load after Tailwind's and set it back to a
# block, so a `flex`, `grid` or `gap-*` class placed on it is silently lost and the dialog's paragraphs run together.
# The layout goes on a div inside it (docs/SPEC.md § 7, 2026-10-11 00:55, row 254). Reads templates and inline
# component templates from the index, specs excluded, the element and the attribute forms both. A count below the
# floor means the reading broke, not that the dialogs went away: lower it with the change that removes one.
# Usage: dialog-content-layout.sh [--root DIR] [--min N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
min=20
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --min) min=$2; shift 2 ;;
    *) echo "dialog-content-layout: unknown argument $1" >&2; exit 2 ;;
  esac
done
mapfile -t files < <(git -C "$root" ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
result=$(cd "$root" && perl -0777 -ne '
  while (/<([a-zA-Z][\w-]*)\b([^>]*)>/sg) {
    my ($tag, $attrs, $at) = ($1, $2, $-[0]);
    next unless $tag eq "mat-dialog-content" || $attrs =~ /(?:^|\s)(?:mat-dialog-content|matDialogContent)(?=[\s=\/]|$)/;
    $checked++;
    my $line = 1 + (substr($_, 0, $at) =~ tr/\n//);
    my ($class) = $attrs =~ /(?:^|\s)class="([^"]*)"/;
    next unless defined $class;
    my @layout = grep { /^(?:[\w-]+:)*(?:inline-)?(?:flex|grid)$/ || /^(?:[\w-]+:)*(?:flex|grid|gap|items|justify|place|content)-/ } split /\s+/, $class;
    print "WRONG $ARGV:$line @layout\n" if @layout;
  }
  END { print "CHECKED ", ($checked // 0), "\n" }
' "${files[@]}" /dev/null)
checked=$(sed -n 's/^CHECKED //p' <<<"$result")
mapfile -t wrong < <(sed -n 's/^WRONG //p' <<<"$result")
if ((${#wrong[@]})); then
  printf 'dialog-content-layout: FAIL — %d mat-dialog-content laid out on itself, which Material overrides (fix: move the classes onto a <div> inside it):\n' "${#wrong[@]}"
  printf '  %s\n' "${wrong[@]}"
  exit 1
fi
if ((checked < min)); then
  printf 'dialog-content-layout: FAIL — %d mat-dialog-content found, fewer than the %d expected: the reading broke, or lower --min with the change that removed one\n' "$checked" "$min"
  exit 1
fi
printf 'dialog-content-layout: OK — %d mat-dialog-content, none laid out on itself\n' "$checked"
