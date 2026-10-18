#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The screens are read left to right in French and English and right to left in Arabic, from one set of templates: a
# side is said by where a line starts and ends, never by left and right. So in the web's templates, components and
# styles, no physical Tailwind direction class (pl-4, mr-2, left-0, text-right, border-l, rounded-r, translate-x…) and no
# physical CSS (margin-left, right:, text-align: left, translateX, a four-sided shorthand whose left and right differ) is written,
# unless its line says why it must keep its side:
# `physical-direction: <why>` (a stock plan, drawn in its own space, is one). An icon that points a way along the line
# (back, forward, chevrons, send) carries `mat-icon-rtl-mirror`, which Material turns over in right-to-left.
# Not seen: styles a component's TypeScript writes inline, overlay positions built in code, and an icon named through
# a binding; those are reviewed by hand. Specs and the generated API types are left out.
# Usage: logical-direction.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
problems=()
app="$root/web/src/app"
if [[ ! -d "$app" ]]; then
  printf 'logical-direction: FAIL — found no web/src/app\n'
  exit 1
fi

before="(^|[[:space:]\"'\`:])"
after="([[:space:]\"'\`]|$)"
suffix='-[][0-9a-zA-Z./%-]+'
sized="-?(m[lr]|p[lr]|left|right|scroll-[mp][lr]|translate-x)${suffix}"
sided="(border-[lr]|rounded-[lr]|rounded-[tb][lr])(${suffix})?|text-(left|right)|float-(left|right)"
class="${before}(${sized}|${sided})${after}"
rule='(margin|padding|border|scroll-margin|scroll-padding)-(left|right)|border-(top|bottom)-(left|right)-radius|(^|[;{[:space:]])(left|right)[[:space:]]*:|text-align:[[:space:]]*(left|right)|float:[[:space:]]*(left|right)|translate(X|3d)\('

# Split by hand: `IFS=: read` drops a trailing colon, the end of a `physical-direction:` with no reason after it.
report() {
  while IFS= read -r entry; do
    file=${entry%%:*}; rest=${entry#*:}; n=${rest%%:*}; line=${rest#*:}
    [[ "$file" == "$app/api/"* ]] && continue
    code=${line#"${line%%[![:space:]]*}"}
    # A comment that names a class is not one written.
    [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '<!--'* ]] && continue
    [[ "$code" =~ physical-direction:[[:space:]]*[[:alnum:]] ]] && continue
    problems+=("${file#"$root/"}:$n: ${code:0:110}")
  done
}
# A file nothing can read, or a pattern grep refuses (its answer 2, where 1 is only "nothing found"), is a failure: it
# would otherwise read as a tree with nothing to find.
while IFS= read -r unread; do problems+=("${unread#"$root/"}: cannot be read"); done < <(find "$root/web/src" -type f ! -readable)
search() {
  local found status
  found=$(grep -rnE "$@"); status=$?
  if ((status > 1)); then problems+=("grep could not search (answer $status)"); return; fi
  if [[ -n "$found" ]]; then report <<<"$found"; fi
}
search --include='*.html' --include='*.ts' --exclude='*.spec.ts' "$class" "$app"
search --include='*.scss' --include='*.css' "$rule" "$root/web/src"

# A shorthand of four sides (or a radius of four corners) that gives the left and the right apart is physical too.
while IFS= read -r found; do
  problems+=("${found#"$root/"}")
done < <(find "$root/web/src" \( -name '*.scss' -o -name '*.css' \) -type f -print0 \
  | xargs -0 -r perl -ne '
      next if /physical-direction:\s*\w/;
      my $sides = /^\s*(?:margin|padding|inset|border-width|border-color|border-style)\s*:\s*([^;]+);/ ? $1 : undef;
      my $corners = /^\s*border-radius\s*:\s*([^;\/]+);/ ? $1 : undef;
      my @v = split /\s+(?![^(]*\))/, ($sides // $corners // "");
      my $apart = defined $sides ? (@v == 4 && $v[1] ne $v[3])
        : (@v >= 2 && $v[0] ne $v[1]) || (@v == 4 && $v[2] ne $v[3]);
      (my $code = $_) =~ s/^\s+|\s+$//g;
      print "$ARGV:$.: $code\n" if $apart;
      close ARGV if eof;')

# An icon element may spread over several lines once formatted, so each is read whole and named at its opening line.
while IFS= read -r found; do
  problems+=("${found#"$root/"}")
done < <(find "$app" -path "$app/api" -prune -o \( -name '*.html' -o -name '*.ts' \) ! -name '*.spec.ts' -type f -print0 \
  | xargs -0 -r perl -0777 -ne '
      while (/<mat-icon\b([^>]*)>\s*(arrow_back|arrow_forward|chevron_left|chevron_right|send)\s*<\/mat-icon/g) {
        my ($attributes, $icon, $at) = ($1, $2, $-[0]);
        next if $attributes =~ /\bmat-icon-rtl-mirror\b/;
        my $n = 1 + (substr($_, 0, $at) =~ tr/\n//);
        print "$ARGV:$n: $icon without mat-icon-rtl-mirror\n";
      }')

if ((${#problems[@]})); then
  printf 'logical-direction: FAIL — %d physical direction(s) (use ps/pe, ms/me, start/end, text-start/end, border-s/e, rounded-s/e, or margin-inline-start and the like; a pointing icon takes mat-icon-rtl-mirror; a line that must keep its side says why with physical-direction: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'logical-direction: OK — every side is said by where the line starts and ends, but where its line says why\n'
