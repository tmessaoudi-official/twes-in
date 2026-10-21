#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A raw read never falls back to a value of its own: in a file that reads rows straight from the database (DBAL's fetch
# calls, an ORM result read by hand), a check followed by a fixed default (`is_numeric($x) ? (int) $x : 0`,
# `$row['quantity'] ?? 0`, `\is_array($found) ? $found : []`) turns a renamed column, a driver change or a corrupt row into
# an empty name or a zero on the screen, which nobody sees as an error. Read through `Shared\Infrastructure\Doctrine\Rows`,
# which refuses what the query does not name; a default the schema itself means says so on its line or the one above:
# `// row-default: <why>`.
# Usage: row-defaults.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=60
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "row-defaults: unknown argument $1" >&2; exit 2 ;;
  esac
done
problems=()
readers='fetchOne\(|fetchAllAssociative\(|fetchAssociative\(|fetchFirstColumn\(|fetchAllKeyValue\(|getArrayResult\(|getSingleResult\(|getScalarResult\(|getSingleScalarResult\(|getResult\(|->execute\(\)'
fallback="(is_numeric|\\\\is_(string|int|scalar|bool|float|array))\\([^)]*\\)[^;?]*\\? [^:;]+ : (0|''|'0'|false|\\[\\])[[:space:]]*[,);]|\\\$row\\[[^]]+\\] \\?\\? (0|''|'0'|false|\\[\\])"
said='//[[:space:]]*row-default:[[:space:]]*[^[:space:]]'

if [[ ! -d "$root/api/src" ]]; then problems+=("found no api/src")
else
  mapfile -t files < <(grep -rlE --include='*.php' "$readers" "$root/api/src")
  if ((${#files[@]} < floor)); then
    problems+=("found ${#files[@]} file(s) reading raw rows, below the floor of $floor: the readers' names changed, or the search did")
  fi
  for file in "${files[@]}"; do
    previous=''
    n=0
    while IFS= read -r line; do
      n=$((n + 1))
      code=${line#"${line%%[![:space:]]*}"}
      if [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* ]]; then previous=$line; continue; fi
      if [[ "$code" =~ $fallback ]] && ! [[ "$line" =~ $said || "$previous" =~ $said ]]; then
        problems+=("${file#"$root/"}:$n: ${code:0:110}")
      fi
      previous=$line
    done < "$file"
  done
fi

if ((${#problems[@]})); then
  printf 'row-defaults: FAIL — %d raw read(s) falling back to a value of their own (read through Shared\\Infrastructure\\Doctrine\\Rows, or say why on the line with // row-default: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'row-defaults: OK — %d files read raw rows, none falls back to a value of its own unless it says why\n' "${#files[@]}"
