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
# A functional or race test runs on the clock ApiTestCase holds still for the case, the one the API reads, so a moment it
# computes beside the API comes from that clock too. Refused under api/tests/Functional and api/tests/Concurrency: the
# wall clock let back in (`new NativeClock()`; ApiTestCase::clockBack returns to the held one), a mock clock set from the
# wall's now (`new MockClock(new \DateTimeImmutable('+16 minutes'))`; ApiTestCase::moveClock counts from the held one),
# and an authenticator code for the wall's now (`codeAt($secret, new \DateTimeImmutable())`; ApiTestCase::authenticatorCode).
# A moment off the wall (`new \DateTimeImmutable('-1 second')`, `'+24 hours'`) is refused there too: the API stamps the held
# instant, so an expectation read off the wall missed it by the case's run time (ApiTestCase::heldNow()->modify('…')).
# A bare `new \DateTimeImmutable()` too: it lands after the held instant, a second after once a timestamp(0) column rounds
# it, so a support grant stamped so was not yet active when the case closed the company (ApiTestCase::heldNow()).
# Usage: test-clock.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
# 705 test files are read today, and the smallest directory, Fuzz, holds 4: a floor 3 below the count reds on losing any
# directory or any 4 files. Lowered, with its reason, in the change that takes files away.
floor=702
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
  for helper in dayOf apiNow moveClock authenticatorCode; do
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
# The wall clock where a case holds its own: a moment off the wall, bare or `'now'`, `'+…'`, `'-…'` (heldNow() is the held
# one), whatever it is handed to (a mock clock, an authenticator code, an entity), PHP's time(), or a NativeClock.
wall_moment="new[[:space:]]+\\\\?DateTime(Immutable)?[[:space:]]*\\([[:space:]]*(\\)|['\"](now|[+-]))"
wall_call="(^|[^a-zA-Z0-9_>\$:'\"])\\\\?time[[:space:]]*\\("
wall="${wall_moment}|${wall_call}|new[[:space:]]+\\\\?(Symfony\\\\Component\\\\Clock\\\\)?NativeClock[[:space:]]*\\("
# The database's clock in SQL a case writes, matched on the line lowercased: its now() is the wall's, at the
# transaction's start, and its CURRENT_DATE is UTC's day, not the company's. Never a clock's now() method, a method
# declared now(), or a variable or property named current_date.
db_clock="(^|[^a-z0-9_>\$:])now[[:space:]]*\\(\\)|(^|[^a-z0-9_>\$])(current_(date|time|timestamp)|localtime(stamp)?)([^a-z0-9_]|\$)|(^|[^a-z0-9_>\$:])(clock|statement|transaction)_timestamp[[:space:]]*\\("
declared_now='function[[:space:]]+now[[:space:]]*\('
# A reason, in PHP or, inside an SQL string, as an SQL comment.
said='(//|--)[[:space:]]*test-clock:[[:space:]]*[^[:space:]]'
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
    if [[ "$file" == "$root/api/tests/Functional/"* || "$file" == "$root/api/tests/Concurrency/"* ]]; then
      [[ "$code" =~ $wall ]] && read_off=1
      lower=${code,,}
      [[ "$lower" =~ $db_clock ]] && ! [[ "$lower" =~ $declared_now ]] && read_off=1
    fi
    if ((read_off)) && ! [[ "$line" =~ $said || "$previous" =~ $said ]]; then
      problems+=("${file#"$root/"}:$n: ${code:0:110}")
    fi
    previous=$line
  done < "$file"
done < <(find "$root/api/tests" -name '*.php' -type f | sort)

if ((${#problems[@]})); then
  printf 'test-clock: FAIL — %d read(s) of a day off a clock not the company'"'"'s (use self::dayOf($company, <shift>), self::dayAsDate or self::apiNow; in a functional or race test $this->moveClock, $this->clockBack or self::authenticatorCode; or say why on the line with // test-clock: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
if ((seen < floor)); then
  echo "test-clock: FAIL — $seen test files under api/tests, under the floor of $floor: the reading has stopped finding them"
  exit 1
fi
printf 'test-clock: OK — %d PHP test files, every day read through the company'"'"'s clock unless it says why\n' "$seen"
