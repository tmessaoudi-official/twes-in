#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A day a test sends or counts from is the company's, read through ApiTestCase::dayOf (a string) or ::dayAsDate (the same
# day as a date), and a stamp it writes beside the API's is ApiTestCase::apiNow: the API reads a day in the company's
# zone, so between 23:00 and 00:00 UTC Tunis is already on the next day and a test reading PHP's own clock sends
# yesterday. Five suites went red on CI in that hour only. Refused in api/tests: a DateTime built from a clock word or a
# day shift (`'today'`, `'now'`, `'yesterday'`, `'-3 days'`, `'first day of next month'`), PHP's `date()`, `gmdate()`,
# `strtotime()` and `mktime()`. Left alone: a fixed date (`'2026-10-15'`), a shift in seconds, minutes or hours (a moment,
# such as a token's expiry, which no zone moves), a shift in days the same line reads as a moment (a timestamp, a mock
# clock, a stamp to the second), and a bare `new \DateTimeImmutable()`, which is a moment too. A read
# that must be another clock (the platform's own day, no company's) says why on its line or the one above:
# `// test-clock: <why>`.
# Usage: test-clock.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
# Some 600 test files today: a reading that finds half of them has lost a directory.
floor=300
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "test-clock: unknown argument $1" >&2; exit 2 ;;
  esac
done
[[ -d "$root/api/tests" ]] || { echo "test-clock: FAIL — found no api/tests"; exit 1; }
problems=()
helpers="$root/api/tests/Functional/ApiTestCase.php"
if [[ ! -f "$helpers" ]]; then problems+=("found no api/tests/Functional/ApiTestCase.php, which holds the day helpers")
else
  for helper in dayOf apiNow; do
    grep -qE "function $helper\(" "$helpers" || problems+=("api/tests/Functional/ApiTestCase.php: found no $helper(), the helper this gate sends a day to")
  done
fi
# A DateTime whose first argument is a quoted clock word: always a day.
clock_word="new[[:space:]]+\\\\?DateTime(Immutable)?[[:space:]]*\\([[:space:]]*['\"][^'\"]*(today|now|yesterday|tomorrow|midnight|noon|monday|tuesday|wednesday|thursday|friday|saturday|sunday|first day|last day)"
# A shift by days, weeks, months or years: a day, unless the line reads it as a moment (seven days on, to the second).
day_shift="new[[:space:]]+\\\\?DateTime(Immutable)?[[:space:]]*\\([[:space:]]*['\"][^'\"]*(day|week|month|year)"
moment='getTimestamp\(|MockClock\(|H:i'
# PHP's own clock functions, never a method, a static call, a variable or an array key named so.
clock_call="(^|[^a-zA-Z0-9_>\$:'\"])\\\\?(date|gmdate|strtotime|mktime)[[:space:]]*\\("
said='//[[:space:]]*test-clock:[[:space:]]*[^[:space:]]'
seen=0
while IFS= read -r file; do
  seen=$((seen + 1))
  previous=''
  n=0
  while IFS= read -r line; do
    n=$((n + 1))
    code=${line#"${line%%[![:space:]]*}"}
    if [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* ]]; then previous=$line; continue; fi
    read_off=0
    [[ "$code" =~ $day_shift ]] && ! [[ "$code" =~ $moment ]] && read_off=1
    [[ "$code" =~ $clock_word || "$code" =~ $clock_call ]] && read_off=1
    if ((read_off)) && ! [[ "$line" =~ $said || "$previous" =~ $said ]]; then
      problems+=("${file#"$root/"}:$n: ${code:0:110}")
    fi
    previous=$line
  done < "$file"
done < <(find "$root/api/tests" -name '*.php' -type f | sort)

if ((${#problems[@]})); then
  printf 'test-clock: FAIL — %d read(s) of a day off a clock not the company'"'"'s (use self::dayOf($company, <shift>), self::dayAsDate or self::apiNow, or say why on the line with // test-clock: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
if ((seen < floor)); then
  echo "test-clock: FAIL — $seen test files under api/tests, under the floor of $floor: the reading has stopped finding them"
  exit 1
fi
printf 'test-clock: OK — %d PHP test files, every day read through the company'"'"'s clock unless it says why\n' "$seen"
