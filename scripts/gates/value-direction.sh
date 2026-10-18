#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A value someone typed (a name, a code, a phone), cut short with an ellipsis, keeps its START in sight whatever the
# screen's direction. A box cuts at the end of ITS OWN direction, so a Latin code in an Arabic screen lost its first
# letters (« …791728493868 »). Such a value sits in a `twes-value` box, which takes its direction from what it holds
# (`dir="auto"`) and sits at the start of the box around it. Two rules over templates and inline component templates
# from the index, specs excluded: every `twes-value` carries `dir="auto"`, and no `truncate` box holds an
# interpolation alone unless it is a text of the screen's own (`… | translate`), which reads in the screen's direction.
# A count below the floor means the reading broke, not that the values went away: lower it with the change that
# removes one.
# Usage: value-direction.sh [--root DIR] [--min N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
min=17
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --min) min=$2; shift 2 ;;
    *) echo "value-direction: unknown argument $1" >&2; exit 2 ;;
  esac
done
mapfile -t files < <(git -C "$root" ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
result=$(cd "$root" && perl -0777 -ne '
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
  END { print "CHECKED ", ($checked // 0), "\n" }
' "${files[@]}" /dev/null)
checked=$(sed -n 's/^CHECKED //p' <<<"$result")
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
((${#nodir[@]} + ${#cut[@]})) && exit 1
if ((checked < min)); then
  printf 'value-direction: FAIL — %d twes-value found, fewer than the %d expected: the reading broke, or lower --min with the change that removed one\n' "$checked" "$min"
  exit 1
fi
printf 'value-direction: OK — %d twes-value, each reading its own way, and no typed value cut by truncate\n' "$checked"
