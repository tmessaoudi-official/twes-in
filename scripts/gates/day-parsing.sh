#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A day parsed with a format does not fail on a day the calendar lacks: `createFromFormat('!Y-m-d', '2026-13-45')` rolls
# into the next year and answers a date, so a typed, sent or imported day is only one once it reads back as written.
# Every `createFromFormat` (DateTimeImmutable's or DateTime's) and `date_create[_immutable]_from_format` in api/src is
# followed, on its line or within the four after it, by a line comparing (`===` or `!==`) a `->format(` of the same
# quoted format, the leading `!` left out, or says why it needs none on its line or the one above:
# `// day-parse: <why>` (a moment the application wrote itself, a field `Assert\DateTime` has already refused, a format
# named by a constant this reading cannot match). The constructor, `new \DateTimeImmutable($day)`, is left to the
# `Assert\Date` on the property it reads (Symfony's DateValidator refuses a day `checkdate` does not have).
# Usage: day-parsing.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
# Fourteen parses compare back today: a reading that finds fewer has lost them.
floor=14
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "day-parsing: unknown argument $1" >&2; exit 2 ;;
  esac
done
problems=()
parse="(createFromFormat|date_create_from_format|date_create_immutable_from_format)[[:space:]]*\\([[:space:]]*"
literal="${parse}'!?([^']*)'"
said='//[[:space:]]*day-parse:[[:space:]]*[^[:space:]]'
compared=0

if [[ ! -d "$root/api/src" ]]; then problems+=("found no api/src")
else
  while IFS= read -r file; do
    mapfile -t lines < "$file"
    for ((i = 0; i < ${#lines[@]}; i++)); do
      line=${lines[i]}
      code=${line#"${line%%[![:space:]]*}"}
      [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* ]] && continue
      [[ "$code" =~ $parse ]] || continue
      previous=''; ((i > 0)) && previous=${lines[i - 1]}
      [[ "$line" =~ $said || "$previous" =~ $said ]] && continue
      found=0
      if [[ "$code" =~ $literal ]]; then
        back="->format('${BASH_REMATCH[2]}')"
        for ((j = i; j <= i + 4 && j < ${#lines[@]}; j++)); do
          if [[ "${lines[j]}" == *"$back"* && ( "${lines[j]}" == *'!=='* || "${lines[j]}" == *'==='* ) ]]; then found=1; break; fi
        done
      fi
      if ((found)); then compared=$((compared + 1))
      else problems+=("${file#"$root/"}:$((i + 1)): ${code:0:110}")
      fi
    done
  done < <(find "$root/api/src" -name '*.php' -type f | sort)
fi

if ((${#problems[@]})); then
  printf 'day-parsing: FAIL — %d day(s) parsed without reading back as written (compare ->format() of the same format with the input within four lines, or say why on the line with // day-parse: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
if ((compared < floor)); then
  echo "day-parsing: FAIL — $compared parse(s) compared back under api/src, under the floor of $floor: the reading has stopped finding them"
  exit 1
fi
printf 'day-parsing: OK — %d parse(s) compared back with what was written, the rest say why\n' "$compared"
