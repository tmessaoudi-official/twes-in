#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A refusal said only by a disabled button says nothing of what to change: « Livrer » greyed out on a time it could not
# read, the role's « Enregistrer » on an emptied name, the stock count's on a missing lot, and the label sheets put
# « 150 » copies back to 100 without a word. Every template whose matInput carries a rule (required, min, max, pattern)
# or whose button is disabled on a value being wrong (`[disabled]="!valid()"`, `invalid`, `!savable()`, `!…Ready()`)
# says the problem beside it: a `<mat-error>` in its field (shown once the field is left or a save is tried, the first
# wrong field focused: shared/form/reveal-invalid.ts), a line of its own just after the field (`role="alert"`,
# `text-error`), or `refusal-said: <where>` when it is said further on; or the file is listed below with its reason. Usage: refusals-said.sh [--root DIR]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
declare -A exempt=(
  # Edited by two other writers in the same round; their fields are said once that work is integrated.
  [web/src/app/platform/platform-page.html]="the platform cards' limits, said by the platform page's own round"
  [web/src/app/company/document-design-page.html]="the logo's width and height, said with the settings engine's round"
)
mapfile -t files < <(cd "$root" && git ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
status=0
ruled=0
for file in "${files[@]}"; do
  [[ -n "${exempt[$file]:-}" ]] && continue
  path="$root/$file"
  # Each matInput carrying a rule is answered beside it: a <mat-error> in its field, or a line just after the field.
  # A button disabled on a value being wrong is answered anywhere in its file (a dialog says it under the field).
  # shellcheck disable=SC2016 # the Perl program is single-quoted on purpose
  report=$(perl -0777 -ne '
    my ($ruled, @unsaid) = (0);
    my $said = qr/<mat-error|role="alert"|text-error|refusal-said: \S/;
    my $anywhere = $_ =~ $said;
    while (/<(?:input|textarea)\b[^>]*\bmatInput\b[^>]*>/g) {
      my ($element, $at, $start) = ($&, pos, $-[0]);
      next unless $element =~ /\s\[?(?:required|min|max|pattern)\]?(?:=|\s|\/?>)/;
      $ruled++;
      my $rest = substr($_, $at);
      my $close = index($rest, "</mat-form-field>");
      my $beside = substr($rest, 0, ($close < 0 ? 0 : $close) + 400);
      push @unsaid, 1 + (substr($_, 0, $start) =~ tr/\n//) unless $beside =~ $said;
    }
    while (/\[disabled\]="[^"]*(?:!valid\(\)|\binvalid\b|!savable\(|![a-z][A-Za-z]*Ready\(\))[^"]*"/g) {
      my $at = pos;
      $ruled++;
      push @unsaid, 1 + (substr($_, 0, $at) =~ tr/\n//) unless $anywhere;
    }
    print join(" ", $ruled, @unsaid);
  ' "$path")
  read -r found unsaid <<<"$report"
  ((found == 0)) && continue
  ruled=$((ruled + 1))
  for line in $unsaid; do
    printf 'refusals-said: FAIL — %s:%d refuses a value without a word: say it under the field with <mat-error> (revealInvalid on save), or on a line of its own beside it\n' "$file" "$line"
    status=1
  done
done
# Fifteen files with a ruled field or a value-disabled button when this gate was written: far fewer means it went blind.
if ((ruled < 14)); then
  printf 'refusals-said: FAIL — %d files with a ruled field found, 14 at least expected: the scan no longer sees them\n' "$ruled"
  exit 1
fi
((status)) && exit 1
printf 'refusals-said: OK — %d files with a ruled field say what they refuse (%d exempt with a reason)\n' "$ruled" "${#exempt[@]}"
