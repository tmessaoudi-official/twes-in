#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A query never decides on the database's clock: the application decides on its own (`ClockInterface`), which a test,
# the demo's moved clock or a server whose clocks drift apart sets apart from PostgreSQL's `now()`. An invitation was
# counted open by `expires_at > NOW()` while accepting it, on the application's clock, already refused it. A query
# passes the application's moment as a parameter; one that means the database's own time (how long a queued message
# has waited, as Messenger wrote it) says so on its line or the one above: `// database-clock: <why>`, or `--` inside SQL.
# Usage: database-clock.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    *) echo "database-clock: unknown argument $1" >&2; exit 2 ;;
  esac
done
problems=()
# The SQL functions, never a PHP method call such as `$this->clock->now()` or `Clock::get()->now()`.
clock='(^|[^>a-zA-Z_:$])(now|NOW)[[:space:]]*\(\)|(^|[^a-zA-Z_])(CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME|LOCALTIMESTAMP|LOCALTIME|current_timestamp|current_date|current_time|localtimestamp|localtime|clock_timestamp|statement_timestamp|transaction_timestamp)([^a-zA-Z_]|$)'
said='(//|--)[[:space:]]*database-clock:[[:space:]]*[^[:space:]]'
seen=0

if [[ ! -d "$root/api/src" ]]; then problems+=("found no api/src")
else
  while IFS= read -r file; do
    seen=$((seen + 1))
    previous=''
    n=0
    while IFS= read -r line; do
      n=$((n + 1))
      code=${line#"${line%%[![:space:]]*}"}
      if [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* ]]; then previous=$line; continue; fi
      if [[ "$code" =~ $clock ]] && ! [[ "$line" =~ $said || "$previous" =~ $said ]]; then
        problems+=("${file#"$root/"}:$n: ${code:0:110}")
      fi
      previous=$line
    done < "$file"
  done < <(find "$root/api/src" -name '*.php' -type f | sort)
  ((seen == 0)) && problems+=("found no PHP file under api/src")
fi

if ((${#problems[@]})); then
  printf 'database-clock: FAIL — %d quer(ies) deciding on the database clock (pass the application clock as a parameter, or say why on the line with // database-clock: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'database-clock: OK — %d PHP files, no query decides on the database clock unless it says why\n' "$seen"
