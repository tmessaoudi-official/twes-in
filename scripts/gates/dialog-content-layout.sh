#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# No template lays out a Material content host itself, `mat-dialog-content` or `mat-card-content`: Material's own
# styles load after Tailwind's and set it back to a block, so a `flex`, `grid` or `gap-*` class placed on it is silently
# lost and its paragraphs run together. The layout goes on a div inside it (docs/SPEC.md § 7).
# A class sizing the host among its siblings (`flex-1`, `flex-auto`, `flex-none`, `flex-initial`) lays nothing out
# inside it and is left alone. Reads templates and inline component templates from the index, specs excluded, the
# element and the attribute forms both. A count below the floor means the reading broke, not that the hosts went away:
# lower it with the change that removes one.
# Usage: dialog-content-layout.sh [--root DIR] [--element mat-dialog-content|mat-card-content] [--min N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
element=mat-dialog-content
min=
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --element) element=$2; shift 2 ;;
    --min) min=$2; shift 2 ;;
    *) echo "dialog-content-layout: unknown argument $1" >&2; exit 2 ;;
  esac
done
# Each host's attribute forms and the floor counted when its reading was written.
case $element in
  mat-dialog-content) attributes='mat-dialog-content|matDialogContent'; floor=20 ;;
  mat-card-content) attributes='mat-card-content'; floor=90 ;;
  *) echo "dialog-content-layout: unknown element $element" >&2; exit 2 ;;
esac
min=${min:-$floor}
mapfile -t files < <(git -C "$root" ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
result=$(cd "$root" && ELEMENT=$element ATTRIBUTES=$attributes perl -0777 -ne '
  my ($element, $attributes) = ($ENV{ELEMENT}, $ENV{ATTRIBUTES});
  while (/<([a-zA-Z][\w-]*)\b([^>]*)>/sg) {
    my ($tag, $attrs, $at) = ($1, $2, $-[0]);
    next unless $tag eq $element || $attrs =~ /(?:^|\s)(?:$attributes)(?=[\s=\/]|$)/;
    $checked++;
    my $line = 1 + (substr($_, 0, $at) =~ tr/\n//);
    my ($class) = $attrs =~ /(?:^|\s)class="([^"]*)"/;
    next unless defined $class;
    my @layout = grep {
      !/^(?:[\w-]+:)*flex-(?:1|auto|initial|none)$/
        && (/^(?:[\w-]+:)*(?:inline-)?(?:flex|grid)$/ || /^(?:[\w-]+:)*(?:flex|grid|gap|items|justify|place|content)-/)
    } split /\s+/, $class;
    print "WRONG $ARGV:$line @layout\n" if @layout;
  }
  END { print "CHECKED ", ($checked // 0), "\n" }
' "${files[@]}" /dev/null)
checked=$(sed -n 's/^CHECKED //p' <<<"$result")
mapfile -t wrong < <(sed -n 's/^WRONG //p' <<<"$result")
if ((${#wrong[@]})); then
  printf 'dialog-content-layout: FAIL — %d %s laid out on itself, which Material overrides (fix: move the classes onto a <div> inside it):\n' "${#wrong[@]}" "$element"
  printf '  %s\n' "${wrong[@]}"
  exit 1
fi
if ((checked < min)); then
  printf 'dialog-content-layout: FAIL — %d %s found, fewer than the %d expected: the reading broke, or lower --min with the change that removed one\n' "$checked" "$element" "$min"
  exit 1
fi
printf 'dialog-content-layout: OK — %d %s, none laid out on itself\n' "$checked" "$element"
