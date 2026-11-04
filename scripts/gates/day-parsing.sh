#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A day parsed with a format does not fail on a day the calendar lacks: `createFromFormat('!Y-m-d', '2026-13-45')` rolls
# into the next year and answers a date, so a typed, sent or imported day is only one once it reads back as written.
# One canon does that, `api/src/Shared/Domain/Service/WrittenDay.php`: `WrittenDay::parsed($text, $format, $zone)` answers
# null for a day that does not read back. Every other `createFromFormat` (DateTimeImmutable's or DateTime's) and
# `date_create[_immutable]_from_format` in api/src is refused, unless its line or the one above says why it needs none:
# `// day-parse: <why>` (a moment the application wrote itself). The helper must still compare what it read back with
# what was written, and the days read through it must not fall under the floor. The constructor,
# `new \DateTimeImmutable($day)`, is left to the `Assert\Date` on the property it reads (Symfony's DateValidator refuses
# a day `checkdate` does not have).
# Usage: day-parsing.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
# Fifteen days and moments are read through the helper today: a reading that finds fewer has lost them.
floor=15
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "day-parsing: unknown argument $1" >&2; exit 2 ;;
  esac
done
helper=api/src/Shared/Domain/Service/WrittenDay.php
problems=()
parse="(createFromFormat|date_create_from_format|date_create_immutable_from_format)[[:space:]]*\\("
said='//[[:space:]]*day-parse:[[:space:]]*[^[:space:]]'
read_back='->format\(\$format\)[[:space:]]*!==[[:space:]]*\$written'
used=0

if [[ ! -d "$root/api/src" ]]; then problems+=("found no api/src")
else
  if [[ ! -f "$root/$helper" ]] || ! grep -Eq -- "$read_back" "$root/$helper"; then
    problems+=("$helper no longer compares the day it read back with what was written: it is the one canon every day goes through")
  fi
  while IFS= read -r file; do
    relative=${file#"$root/"}
    mapfile -t lines < "$file"
    for ((i = 0; i < ${#lines[@]}; i++)); do
      line=${lines[i]}
      code=${line#"${line%%[![:space:]]*}"}
      [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* ]] && continue
      if [[ "$code" == *'WrittenDay::parsed('* ]]; then used=$((used + 1)); fi
      [[ "$code" =~ $parse ]] || continue
      [[ "$relative" == "$helper" ]] && continue
      previous=''; ((i > 0)) && previous=${lines[i - 1]}
      [[ "$line" =~ $said || "$previous" =~ $said ]] && continue
      problems+=("$relative:$((i + 1)): ${code:0:110}")
    done
  done < <(find "$root/api/src" -name '*.php' -type f | sort)
fi

if ((${#problems[@]})); then
  printf 'day-parsing: FAIL — %d day(s) parsed outside WrittenDay::parsed (read a written day through it, or say why on the line with // day-parse: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
if ((used < floor)); then
  echo "day-parsing: FAIL — $used day(s) read through WrittenDay::parsed under api/src, under the floor of $floor: the reading has stopped finding them"
  exit 1
fi
printf 'day-parsing: OK — %d day(s) read through WrittenDay::parsed, the rest say why\n' "$used"
