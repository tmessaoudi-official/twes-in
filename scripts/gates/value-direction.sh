#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A value someone typed (a name, a code, a phone), cut short with an ellipsis, keeps its START in sight whatever the
# screen's direction. A box cuts at the end of ITS OWN direction, so a Latin code in an Arabic screen lost its first
# letters (« …791728493868 »). Such a value sits in a `twes-value` box, which takes its direction from what it holds
# (`dir="auto"`) and sits at the start of the box around it. Two rules over templates and inline component templates
# from the index, specs excluded: every `twes-value` carries `dir="auto"`, and no `truncate` box holds an
# interpolation alone unless it is a text of the screen's own (`… | translate`, or a refusal said in the screen's words,
# `… | refusal`), which reads in the screen's direction.
# A third rule holds what is not cut: a value standing alone between two tags (« TVA 19 % », « Livrer au quai 3. »),
# its trailing sign or full stop being neutral, goes to the wrong end of a Latin value on an Arabic screen. Such a value
# sits in a `<bdi>` (or a `dir="auto"` box), which reads it its own way and leaves the line's alignment alone. A screen
# text (`| translate`, `| refusal`), a figure (`| amount`, `| day`, `| moment`, `| measure`, whose sign the formatter marks) and what
# is no running text (an icon's ligature, a key cap, code, SVG) are left alone; a line saying
# `value-direction: <why>` on the box's line or the one above leaves it alone too.
# A fourth holds signed figures: a change written « +6,2 % » or « −3,1 % » goes through the `signed` pipe
# (shared/i18n/format-pipes.ts), drawn in a `<bdi dir="ltr">` so its sign stays at its start and its percent at its end
# in Arabic. Angular's `percent` pipe, a `signDisplay` and a « + » built by hand (written before a value or as a quoted
# '+' inside one) are refused, and so is a `signed` figure outside a left-to-right `<bdi>`.
# A fifth holds compound values: a `twes-value` box holding a value joined by « · » (« 0412 · 14/09/2026 ») takes one
# direction for the whole line, and a part with no letter of its own then swaps places with the other. Each part sits
# in its own `<bdi>`, the separator outside them.
# A line saying `value-direction: <why>` on the line or the one above leaves the fourth and fifth rules alone too.
# A count below a floor means the reading broke, not that the values went away: lower it with the change that removes one.
# Usage: value-direction.sh [--root DIR] [--min N] [--min-isolated N] [--min-read N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
min=17
min_isolated=220
# Interpolations read for the fourth rule: losing one feature's templates (the invoices hold about 300) reds.
min_read=2900
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --min) min=$2; shift 2 ;;
    --min-isolated) min_isolated=$2; shift 2 ;;
    --min-read) min_read=$2; shift 2 ;;
    *) echo "value-direction: unknown argument $1" >&2; exit 2 ;;
  esac
done
mapfile -t files < <(git -C "$root" ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
result=$(cd "$root" && perl -CSD -0777 -ne '
  BEGIN {
    # Whether the line at this offset, or the one above, says why it is left alone.
    sub said { my ($at) = @_; my $from = rindex($_, "\n", rindex($_, "\n", $at) - 1); my $to = index($_, "\n", $at);
      return substr($_, $from + 1, ($to < 0 ? length($_) : $to) - $from - 1) =~ /value-direction:/ }
    sub line_of { 1 + (substr($_, 0, $_[0]) =~ tr/\n//) }
  }
  while (/<([a-zA-Z][\w-]*)\b((?:[^>"]|"[^"]*")*)>/sg) {
    my ($tag, $attrs, $at, $end) = ($1, $2, $-[0], $+[0]);
    my ($class) = $attrs =~ /(?:^|\s)class="([^"]*)"/;
    next unless defined $class;
    my $line = 1 + (substr($_, 0, $at) =~ tr/\n//);
    my %classes = map { $_ => 1 } split /\s+/, $class;
    if ($classes{"twes-value"}) {
      $checked++;
      print "NODIR $ARGV:$line <$tag>\n" unless $attrs =~ /(?:^|\s)dir="auto"/;
      # What the box holds, up to its own closing tag, boxes of the same name inside it counted.
      my ($depth, $pos, $inner) = (1, $end, undef);
      while ($depth > 0 && /<(\/?)\Q$tag\E\b[^>]*>/g) {
        $depth += $1 ? -1 : 1;
        $inner = substr($_, $end, $-[0] - $end) if $depth == 0;
      }
      pos($_) = $end;
      print "JOINED $ARGV:$line <$tag>\n"
        if defined $inner && $inner =~ /\{\{/ && $inner =~ /\x{00B7}|&middot;|&#183;|&#xb7;|\\u00b7/i && !said($at);
    }
    next unless $classes{"truncate"};
    my $rest = substr($_, $end);
    next unless $rest =~ /\A\s*\{\{\s*(.*?)\s*\}\}\s*<\/\Q$tag\E>/s;
    my $expression = $1;
    next if $expression =~ /\|\s*(?:translate|refusal)(?:\s*:[^|]*)?$/s;
    $expression =~ s/\s+/ /g;
    print "CUT $ARGV:$line {{ $expression }}\n";
  }
  # The third rule, over every run of text between two tags that holds a value and nothing to read around it.
  while (/>([^<>]*)</g) {
    my ($run, $start) = ($1, $-[0]);
    next unless $run =~ /\{\{/;
    (my $around = $run) =~ s/\{\{.*?\}\}//gs;
    next if $around =~ /[\p{L}\d{}@]/;
    my @values = grep { !/\|\s*(?:translate|refusal)\b/ && !/\|\s*(?:amount|day|moment|measure|signed)\b/ } ($run =~ /\{\{\s*(.*?)\s*\}\}/gs);
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
  # The fourth rule: Angular'"'"'s percent pipe and a signDisplay anywhere, a sign built by hand around a value, and a
  # signed figure outside a left-to-right bdi.
  while (/\|\s*percent\b|\bsignDisplay\b/g) {
    my ($what, $at) = ($&, $-[0]);
    $what =~ s/\s+/ /g;
    print "SIGN $ARGV:", line_of($at), " $what\n" unless said($at);
  }
  while (/\{\{(.*?)\}\}/gs) {
    my ($expression, $at) = ($1, $-[0]);
    $read++;
    if (substr($_, 0, $at) =~ /(?<![+\w])\+\s*\z/ && !said($at)) {
      print "SIGN $ARGV:", line_of($at), " +{{ ... }}\n";
    } elsif ($expression =~ /([\x27"])\+\1/ && !said($at)) {
      print "SIGN $ARGV:", line_of($at), " {{ ... \x27+\x27 ... }}\n";
    }
    next unless $expression =~ /\|\s*signed\b/;
    my $before = substr($_, 0, $at);
    if ($before =~ /<([a-zA-Z][\w-]*)\b((?:[^>"]|"[^"]*")*)>[^<]*\z/s && $1 eq "bdi" && $2 =~ /(?:^|\s)dir="ltr"/) {
      $signed++;
    } elsif (!said($at)) {
      my ($tag) = $before =~ /<([a-zA-Z][\w-]*)\b(?:[^>"]|"[^"]*")*>[^<]*\z/s;
      print "UNBOXED $ARGV:", line_of($at), " <", ($tag // "?"), ">\n";
    }
  }
  END {
    print "CHECKED ", ($checked // 0), "\n"; print "ISOLATED ", ($isolated // 0), "\n";
    print "READ ", ($read // 0), "\n"; print "SIGNED ", ($signed // 0), "\n";
  }
' "${files[@]}" /dev/null)
checked=$(sed -n 's/^CHECKED //p' <<<"$result")
isolated=$(sed -n 's/^ISOLATED //p' <<<"$result")
read_count=$(sed -n 's/^READ //p' <<<"$result")
signed=$(sed -n 's/^SIGNED //p' <<<"$result")
mapfile -t sign < <(sed -n 's/^SIGN //p' <<<"$result")
mapfile -t unboxed < <(sed -n 's/^UNBOXED //p' <<<"$result")
mapfile -t joined < <(sed -n 's/^JOINED //p' <<<"$result")
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
if ((${#sign[@]})); then
  printf 'value-direction: FAIL — %d sign or percent written by hand, hand-built where the signed pipe writes it (fix: <bdi dir="ltr">{{ change | signed: 1 }}</bdi>):\n' "${#sign[@]}"
  printf '  %s\n' "${sign[@]}"
fi
if ((${#unboxed[@]})); then
  printf 'value-direction: FAIL — %d signed figure outside a <bdi dir="ltr">, whose sign then goes to its end in Arabic:\n' "${#unboxed[@]}"
  printf '  %s\n' "${unboxed[@]}"
fi
if ((${#joined[@]})); then
  printf 'value-direction: FAIL — %d twes-value holding values joined by « · », whose parts swap places in a screen of the other direction (fix: each part in its own <bdi>, the separator outside):\n' "${#joined[@]}"
  printf '  %s\n' "${joined[@]}"
fi
((${#nodir[@]} + ${#cut[@]} + ${#bare[@]} + ${#sign[@]} + ${#unboxed[@]} + ${#joined[@]})) && exit 1
if ((checked < min)); then
  printf 'value-direction: FAIL — %d twes-value found, fewer than the %d expected: the reading broke, or lower --min with the change that removed one\n' "$checked" "$min"
  exit 1
fi
if ((isolated < min_isolated)); then
  printf 'value-direction: FAIL — %d value standing alone in its own box, fewer than the %d expected: the reading broke, or lower --min-isolated with the change that removed one\n' "$isolated" "$min_isolated"
  exit 1
fi
if ((read_count < min_read)); then
  printf 'value-direction: FAIL — %d interpolation read, fewer than the %d expected: the reading broke, or lower --min-read with the change that removed them\n' "$read_count" "$min_read"
  exit 1
fi
printf 'value-direction: OK — %d twes-value and %d value standing alone, each reading its own way, no typed value cut by truncate, and %d signed figure in a left-to-right bdi among %d interpolations read\n' "$checked" "$isolated" "$signed" "$read_count"
