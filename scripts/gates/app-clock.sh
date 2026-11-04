#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every moment the API decides on comes from the application's clock (`Psr\Clock\ClockInterface`, `$this->clock->now()`),
# which a test's MockClock and the demo's moved clock set apart from the server's. A domain method takes the moment as a
# required parameter; a `$now ?? new \DateTimeImmutable()` default read the system clock wherever a caller forgot it.
# Refused in api/src: a DateTime built with no argument or a zone alone, or from a clock word or a shift from now
# (`'today'`, `'now'`, `'+3 days'`, even after `??`), PHP's `time()`, `microtime()`, `date()`, `gmdate()`, `strtotime()`,
# `mktime()`, `date_create()`, `getdate()`, `localtime()`, `gettimeofday()`, `idate()` and `$_SERVER['REQUEST_TIME']`.
# Left alone: a date parsed from data, a fixed day, `'@'.$stamp`, a method or key named like a clock, and `hrtime()`, a
# span measured, no moment. A read that must be the system's (a library comparing with its own `time()`) says why on its
# line or the one above: `// system-clock: <why>`. The SQL half is database-clock.sh.
# Usage: app-clock.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
# 2,421 PHP files are read today, and the smallest context, Installation, holds 4: a floor 3 below the count reds on
# losing any context or any 4 files. Lowered, with its reason, in the change that takes files away.
floor=2418
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "app-clock: unknown argument $1" >&2; exit 2 ;;
  esac
done
problems=()
datetime="new[[:space:]]+\\\\?DateTime(Immutable)?[[:space:]]*\\("
# Built with nothing, or with a zone alone: now.
bare="${datetime}[[:space:]]*(\\)|timezone[[:space:]]*:)"
# A quoted string opening on a clock word or a shift from now, anywhere inside the constructor's own parentheses.
word="${datetime}[^)]*['\"][[:space:]]*(now|today|yesterday|tomorrow|midnight|noon|monday|tuesday|wednesday|thursday|friday|saturday|sunday|first day|last day|next |last |this |[+-][[:space:]]*[0-9])"
# PHP's own clock functions, never a method, a static call, a variable, a string or a declaration of that name.
call="(^|[^a-zA-Z0-9_>\$:'\"\\\\])\\\\?(time|microtime|date|gmdate|strtotime|mktime|date_create|date_create_immutable|idate|getdate|localtime|gettimeofday)[[:space:]]*\\("
declared="function[[:space:]]+&?(time|microtime|date|gmdate|strtotime|mktime|date_create|date_create_immutable|idate|getdate|localtime|gettimeofday)[[:space:]]*\\("
request="REQUEST_TIME"
said='//[[:space:]]*system-clock:[[:space:]]*[^[:space:]]'
seen=0

if [[ ! -d "$root/api/src" ]]; then problems+=("found no api/src")
else
  while IFS= read -r file; do
    seen=$((seen + 1))
    previous=''
    n=0
    while IFS= read -r line || [[ -n "$line" ]]; do
      n=$((n + 1))
      code=${line#"${line%%[![:space:]]*}"}
      if [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* ]]; then previous=$line; continue; fi
      read_off=0
      [[ "$code" =~ $bare || "$code" =~ $word || "$code" == *"$request"* ]] && read_off=1
      [[ "$code" =~ $call ]] && ! [[ "$code" =~ $declared ]] && read_off=1
      if ((read_off)) && ! [[ "$line" =~ $said || "$previous" =~ $said ]]; then
        problems+=("${file#"$root/"}:$n: ${code:0:110}")
      fi
      previous=$line
    done < "$file"
  done < <(find "$root/api/src" -name '*.php' -type f | sort)
fi

if ((${#problems[@]})); then
  printf 'app-clock: FAIL — %d read(s) of the system clock in api/src (take the moment from ClockInterface, a required parameter in Domain/, or say why on the line with // system-clock: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
if ((seen < floor)); then
  echo "app-clock: FAIL — $seen PHP files under api/src, under the floor of $floor: the reading has stopped finding them"
  exit 1
fi
printf 'app-clock: OK — %d PHP files, every moment from the application clock unless it says why\n' "$seen"
