#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A value someone typed (a name, a code, a phone), cut short with an ellipsis, keeps its START in sight whatever the
# screen's direction. A box cuts at the end of ITS OWN direction, so a Latin code in an Arabic screen lost its first
# letters (« …791728493868 »). Such a value sits in a `twes-value` box, which takes its direction from what it holds
# (`dir="auto"`) and sits at the start of the box around it. Two rules over templates and inline component templates
# from the index, specs excluded: every `twes-value` carries `dir="auto"`, and no `truncate` box holds an
# interpolation alone unless it is a text of the screen's own (`… | translate`), which reads in the screen's direction.
# A third rule holds what is not cut: a value standing alone between two tags (« TVA 19 % », « Livrer au quai 3. »),
# its trailing sign or full stop being neutral, goes to the wrong end of a Latin value on an Arabic screen. Such a value
# sits in a `<bdi>` (or a `dir="auto"` box), which reads it its own way and leaves the line's alignment alone. A screen
# text (`| translate`), a figure (`| amount`, `| day`, `| moment`, `| measure`, whose sign the formatter marks) and what
# is no running text (an icon's ligature, a key cap, code, SVG) are left alone; a line saying
# `value-direction: <why>` on the box's line or the one above leaves it alone too.
# A count below a floor means the reading broke, not that the values went away: lower it with the change that removes one.
# Usage: value-direction.sh [--root DIR] [--min N] [--min-isolated N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
min=17
min_isolated=220
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --min) min=$2; shift 2 ;;
    --min-isolated) min_isolated=$2; shift 2 ;;
    *) echo "value-direction: unknown argument $1" >&2; exit 2 ;;
  esac
done
mapfile -t files < <(git -C "$root" ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
result=$(cd "$root" && perl -CSD -0777 -ne '
  while (/<([a-zA-Z][\w-]*)\b((?:[^>"]|"[^"]*")*)>/sg) {
    my ($tag, $attrs, $at, $end) = ($1, $2, $-[0], $+[0]);
    my ($class) = $attrs =~ /(?:^|\s)class="([^"]*)"/;
    next unless defined $class;
    my $line = 1 + (substr($_, 0, $at) =~ tr/\n//);
    my %classes = map { $_ => 1 } split /\s+/, $class;
    if ($classes{"twes-value"}) {
      $checked++;
      print "NODIR $ARGV:$line <$tag>\n" unless $attrs =~ /(?:^|\s)dir="auto"/;
    }
    next unless $classes{"truncate"};
    my $rest = substr($_, $end);
    next unless $rest =~ /\A\s*\{\{\s*(.*?)\s*\}\}\s*<\/\Q$tag\E>/s;
    my $expression = $1;
    next if $expression =~ /\|\s*translate(?:\s*:[^|]*)?$/s;
    $expression =~ s/\s+/ /g;
    print "CUT $ARGV:$line {{ $expression }}\n";
  }
  # The third rule, over every run of text between two tags that holds a value and nothing to read around it.
  while (/>([^<>]*)</g) {
    my ($run, $start) = ($1, $-[0]);
    next unless $run =~ /\{\{/;
    (my $around = $run) =~ s/\{\{.*?\}\}//gs;
    next if $around =~ /[\p{L}\d{}@]/;
    my @values = grep { !/\|\s*translate\b/ && !/\|\s*(?:amount|day|moment|measure)\b/ } ($run =~ /\{\{\s*(.*?)\s*\}\}/gs);
    next unless @values;
    my $before = substr($_, 0, $start + 1);
    next unless $before =~ /<([a-zA-Z][\w-]*)\b((?:[^>"]|"[^"]*")*)>\z/s;
    my ($tag, $attrs, $tagAt) = ($1, $2, $-[0]);
    next if $tag =~ /^(?:mat-icon|kbd|code|text|title|tspan|option|textarea|script|style)$/;
    my ($class) = $attrs =~ /(?:^|\s)class="([^"]*)"/;
    my $boxed = $tag eq "bdi" || $attrs =~ /(?:^|\s)dir="auto"/ || (defined $class && $class =~ /(?:^|\s)twes-value(?:\s|$)/);
    if ($boxed) { $isolated++; next; }
    my $lineStart = rindex($_, "\n", rindex($_, "\n", $tagAt) - 1);
    my $lineEnd = index($_, "\n", $tagAt);
    next if substr($_, $lineStart + 1, ($lineEnd < 0 ? length($_) : $lineEnd) - $lineStart - 1) =~ /value-direction:/;
    my $line = 1 + (substr($_, 0, $tagAt) =~ tr/\n//);
    (my $shown = join(" ", @values)) =~ s/\s+/ /g;
    print "BARE $ARGV:$line <$tag> {{ $shown }}\n";
  }
  END { print "CHECKED ", ($checked // 0), "\n"; print "ISOLATED ", ($isolated // 0), "\n" }
' "${files[@]}" /dev/null)
checked=$(sed -n 's/^CHECKED //p' <<<"$result")
isolated=$(sed -n 's/^ISOLATED //p' <<<"$result")
mapfile -t bare < <(sed -n 's/^BARE //p' <<<"$result")
mapfile -t nodir < <(sed -n 's/^NODIR //p' <<<"$result")
mapfile -t cut < <(sed -n 's/^CUT //p' <<<"$result")
if ((${#nodir[@]})); then
  printf 'value-direction: FAIL — %d twes-value without dir="auto", which then cuts at the screen'"'"'s end, not its own:\n' "${#nodir[@]}"
  printf '  %s\n' "${nodir[@]}"
fi
if ((${#cut[@]})); then
  printf 'value-direction: FAIL — %d typed value cut short by `truncate`, which loses its start in a screen of the other direction (fix: <span class="twes-value" dir="auto"> around it):\n' "${#cut[@]}"
  printf '  %s\n' "${cut[@]}"
fi
if ((${#bare[@]})); then
  printf 'value-direction: FAIL — %d value standing alone outside a <bdi>, whose neutral signs go to the wrong end in a screen of the other direction (fix: <bdi>{{ … }}</bdi>):\n' "${#bare[@]}"
  printf '  %s\n' "${bare[@]}"
fi
((${#nodir[@]} + ${#cut[@]} + ${#bare[@]})) && exit 1
if ((checked < min)); then
  printf 'value-direction: FAIL — %d twes-value found, fewer than the %d expected: the reading broke, or lower --min with the change that removed one\n' "$checked" "$min"
  exit 1
fi
if ((isolated < min_isolated)); then
  printf 'value-direction: FAIL — %d value standing alone in its own box, fewer than the %d expected: the reading broke, or lower --min-isolated with the change that removed one\n' "$isolated" "$min_isolated"
  exit 1
fi
printf 'value-direction: OK — %d twes-value and %d value standing alone, each reading its own way, and no typed value cut by truncate\n' "$checked" "$isolated"
