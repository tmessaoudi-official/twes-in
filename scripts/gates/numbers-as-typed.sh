#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# No number is typed in `type="number"` (row 242): the browser hands the form an empty value for a French « 1,5 »
# and for anything else it cannot read, so what was typed is lost and the field says « required » or nothing. A whole
# number is typed in a text field with the numeric keyboard, `appCount` on a form control or `typedCount` on a plain
# one (`shared/form/count-input.ts`), which reads the comma and says a number that is not whole; a decimal takes
# `appDecimal`. A field that must stay a browser number says why with `number-input: <why>` on its line or in a comment
# just before its element. Reads templates and inline component templates from the index, specs excluded.
# The inputs counted below the floor mean the reading broke: lower it with the change that removes them.
# Usage: numbers-as-typed.sh [--root DIR] [--min N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
min=100
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --min) min=$2; shift 2 ;;
    *) echo "numbers-as-typed: unknown argument $1" >&2; exit 2 ;;
  esac
done
mapfile -t files < <(git -C "$root" ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
result=$(cd "$root" && perl -ne '
  $inputs += () = /\bmatInput\b/g;
  # A reason covers its own line and, written just before an element, that element until its tag closes.
  my $reason = /number-input:\s*\w/;
  if (/(?:^|\s)type\s*=\s*"number"/ && !$reason && !$excused) {
    print "WRONG $ARGV:$.\n";
  }
  if ($reason) { $excused = 1 } elsif (/>\s*$/) { $excused = 0 }
  if (eof) { close ARGV; $excused = 0; }
  END { print "INPUTS ", ($inputs // 0), "\n" }
' "${files[@]}" /dev/null)
inputs=$(sed -n 's/^INPUTS //p' <<<"$result")
mapfile -t wrong < <(sed -n 's/^WRONG //p' <<<"$result")
if ((${#wrong[@]})); then
  printf 'numbers-as-typed: FAIL — %d number field(s) lose a « 1,5 » unsaid (fix: type="text" with appCount or typedCount, shared/form/count-input.ts, or appDecimal; or `number-input: <why>`):\n' "${#wrong[@]}"
  printf '  %s\n' "${wrong[@]}"
  exit 1
fi
if ((inputs < min)); then
  printf 'numbers-as-typed: FAIL — %d inputs read, fewer than the %d expected: the reading broke, or lower --min with the change that removed them\n' "$inputs" "$min"
  exit 1
fi
printf 'numbers-as-typed: OK — %d inputs read, none typed as a browser number\n' "$inputs"
