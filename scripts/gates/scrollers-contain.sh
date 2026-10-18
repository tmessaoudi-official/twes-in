#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every scroller is the containing block of what it holds. An absolutely placed element (a screen-reader text, a badge)
# is placed against the nearest POSITIONED ancestor, so inside a scroller that is not positioned it escapes the
# scroller's clip and stretches whatever lies beyond: the Paramètres page made the shell's panel as long as itself, and
# the wheel at the page's end then slid the list and its « Réduire les réglages » off screen, as folded menu entries
# had made the rail do before. A template element whose classes scroll (`overflow-auto`, `overflow-y-scroll`, behind
# any breakpoint) carries `relative`, `absolute`, `fixed` or `sticky`; a style rule that scrolls sets `position` in the
# same rule, or says on the declaration's line where it is positioned (`scroller-positioned: <where>`). Reads templates,
# inline component templates and styles from the index, specs excluded. A count below the floor means the reading
# broke, not that the scrollers went away: lower it with the change that removes one.
# Usage: scrollers-contain.sh [--root DIR] [--min N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
min=20
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --min) min=$2; shift 2 ;;
    *) echo "scrollers-contain: unknown argument $1" >&2; exit 2 ;;
  esac
done
mapfile -t templates < <(git -C "$root" ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
mapfile -t styles < <(git -C "$root" ls-files -- 'web/src/*.css' 'web/src/*.scss')
result=$(cd "$root" && perl -0777 -ne '
  while (/<[a-zA-Z][\w-]*\b([^>]*)>/sg) {
    my ($attrs, $start) = ($1, $-[1]);
    next unless $attrs =~ /(?:^|\s)class="([^"]*)"/;
    my ($class, $at) = ($1, $-[0]);
    my @scrolls = grep { /^(?:\S+:)?overflow(?:-[xy])?-(?:auto|scroll)$/ } split /\s+/, $class;
    next unless @scrolls;
    $checked++;
    next if grep { /^(?:\S+:)?(?:relative|absolute|fixed|sticky)$/ } split /\s+/, $class;
    my $line = 1 + (substr($_, 0, $start + $at) =~ tr/\n//);
    $line++ if substr($attrs, $at, 1) =~ /\n/;
    print "WRONG $ARGV:$line @scrolls\n";
  }
  END { print "CHECKED ", ($checked // 0), "\n" }
' "${templates[@]}" /dev/null; cd "$root" && perl -0777 -ne '
  my $source = $_;
  my $blank = sub { my $s = shift; $s =~ s/[^\n]/ /g; $s };
  # Comments and strings blanked to their length, so braces in them neither open nor close a rule and offsets hold.
  (my $code = $source) =~ s{/\*.*?\*/|"(?:[^"\\\n]|\\.)*"|\x27(?:[^\x27\\\n]|\\.)*\x27}{$blank->($&)}gse;
  $code =~ s{(?<![:\w])//[^\n]*}{$blank->($&)}ge if $ARGV =~ /\.scss$/;
  my @open;
  for my $i (0 .. length($code) - 1) {
    my $c = substr($code, $i, 1);
    if ($c eq "{") { push @open, $i; next }
    next unless $c eq "}" && @open;
    my $from = pop(@open) + 1;
    my $own = substr($code, $from, $i - $from);
    # Only this rule own declarations: a nested rule is blanked, so its position does not count for the parent.
    1 while $own =~ s/\{[^{}]*\}/$blank->($&)/e;
    while ($own =~ /(?<![\w-])(overflow(?:-[xy])?\s*:\s*[^;{}]*?\b(?:auto|scroll)\b)/g) {
      my ($declaration, $offset) = ($1, $from + $-[1]);
      $checked++;
      next if $own =~ /(?<![\w-])position\s*:\s*(?:relative|absolute|fixed|sticky)\b/;
      my $line = 1 + (substr($source, 0, $offset) =~ tr/\n//);
      my ($text) = (split /\n/, $source, -1)[$line - 1];
      next if $text =~ /scroller-positioned:\s*\S/;
      print "WRONG $ARGV:$line $declaration\n";
    }
  }
  END { print "CHECKED ", ($checked // 0), "\n" }
' "${styles[@]}" /dev/null)
checked=0
while read -r count; do checked=$((checked + count)); done < <(sed -n 's/^CHECKED //p' <<<"$result")
mapfile -t wrong < <(sed -n 's/^WRONG //p' <<<"$result")
if ((${#wrong[@]})); then
  printf 'scrollers-contain: FAIL — %d scroller(s) not positioned, so what they hold placed absolutely escapes them and stretches what lies beyond (fix: add `relative`, or `position: relative` to the rule):\n' "${#wrong[@]}"
  printf '  %s\n' "${wrong[@]}"
  exit 1
fi
if ((checked < min)); then
  printf 'scrollers-contain: FAIL — %d scrollers found, fewer than the %d expected: the reading broke, or lower --min with the change that removed one\n' "$checked" "$min"
  exit 1
fi
printf 'scrollers-contain: OK — %d scrollers, each the containing block of what it holds\n' "$checked"
