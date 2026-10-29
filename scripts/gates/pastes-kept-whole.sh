#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# No field cuts what a person typed or pasted at its limit (row 239): a `maxlength` on an input drops the end of a
# paste unseen and nothing is said. A field keeps the value whole and says it is too long, its limit and the count,
# under it (`shared/form/length-said.ts`), and the save is refused until it fits. A fixed-format field, whose longer
# value is never meant (the colour's seven characters, a time's five), may keep its `maxlength` where its line, or a
# comment just before its element, says `paste-cut: <why>`. Reads templates and inline component templates from the index, specs excluded.
# The inputs counted below the floor mean the reading broke: lower it with the change that removes them.
# Usage: pastes-kept-whole.sh [--root DIR] [--min N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
min=100
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --min) min=$2; shift 2 ;;
    *) echo "pastes-kept-whole: unknown argument $1" >&2; exit 2 ;;
  esac
done
mapfile -t files < <(git -C "$root" ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
result=$(cd "$root" && perl -ne '
  $inputs += () = /\bmatInput\b/g;
  # A reason covers its own line and, written just before an element, that element until its tag closes.
  my $reason = /paste-cut:\s*\w/;
  if (/(?:^|\s)(?:\[(?:attr\.)?maxlength\]|maxlength)\s*=\s*"/ && !$reason && !$excused) {
    print "WRONG $ARGV:$.\n";
  }
  if ($reason) { $excused = 1 } elsif (/>\s*$/) { $excused = 0 }
  if (eof) { close ARGV; $excused = 0; }
  END { print "INPUTS ", ($inputs // 0), "\n" }
' "${files[@]}" /dev/null)
inputs=$(sed -n 's/^INPUTS //p' <<<"$result")
mapfile -t wrong < <(sed -n 's/^WRONG //p' <<<"$result")
if ((${#wrong[@]})); then
  printf 'pastes-kept-whole: FAIL — %d field(s) cut a paste at their limit with nothing said (fix: no maxlength, a Validators.maxLength or a length check, and the error under the field through shared/form/length-said.ts; or `paste-cut: <why>` for a fixed format):\n' "${#wrong[@]}"
  printf '  %s\n' "${wrong[@]}"
  exit 1
fi
if ((inputs < min)); then
  printf 'pastes-kept-whole: FAIL — %d inputs read, fewer than the %d expected: the reading broke, or lower --min with the change that removed them\n' "$inputs" "$min"
  exit 1
fi
printf 'pastes-kept-whole: OK — %d inputs read, none cuts a paste unsaid\n' "$inputs"
