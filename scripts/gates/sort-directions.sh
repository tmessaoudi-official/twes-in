#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A query's order says its direction with PHP's SortDirection, as Doctrine's own upgrade notes ask (UPGRADE.md, « Deprecated
# using strings or null as sort directions », doctrine/orm#11313): Doctrine ORM 3 deprecates 'ASC', 'DESC' and null as the
# direction of QueryBuilder::orderBy() and addOrderBy(), of Expr\OrderBy and of an #[ORM\OrderBy] mapping, and its next major
# removes them. No such string is written in api/src or api/tests, unless its own line says why: `// sort-direction: <why>`
# (DBAL's query builder, which takes a string). An order given no direction is ascending and passes; findBy's array keeps
# its strings, which Doctrine 3 takes without a deprecation. A direction held in a variable is the type's to say: ListOrder
# turns what a request asked for into a SortDirection. A floor of SortDirection uses in api/src reds a gate gone blind.
# Usage: sort-directions.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
floor=${SORT_DIRECTIONS_FLOOR:-101}
problems=()

if [[ ! -d "$root/api/src" ]]; then problems+=("found no api/src")
else
  dirs=("$root/api/src"); [[ -d "$root/api/tests" ]] && dirs+=("$root/api/tests")
  while IFS=: read -r file n line; do
    code=${line#"${line%%[![:space:]]*}"}
    # A comment that names an order is not one.
    [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'[^[]* ]] && continue
    [[ "$code" =~ //[[:space:]]*sort-direction:[[:space:]]*[^[:space:]] ]] && continue
    problems+=("${file#"$root/"}:$n: ${code:0:110}")
  done < <(grep -rniE --include='*.php' \
    -e "orderby[[:space:]]*\(.*(,|=>)[[:space:]]*['\"](asc|desc)['\"]" \
    -e "orderby[[:space:]]*\([[:space:]]*('[^']*'|\"[^\"]*\"|\\\$[a-z_][a-z0-9_]*)[[:space:]]*,[[:space:]]*null[[:space:]]*\)" \
    "${dirs[@]}")
  uses=$(grep -rhoE --include='*.php' 'SortDirection::(Ascending|Descending)' "$root/api/src" | wc -l)
  ((uses < floor)) && problems+=("found $uses SortDirection uses in api/src, under the floor of $floor: the orders moved somewhere this gate does not read")
fi

if ((${#problems[@]})); then
  printf 'sort-directions: FAIL — %d order(s) say their direction as a string (Doctrine deprecates it: \\SortDirection::Ascending or ::Descending; a line that must keep one says why with // sort-direction: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'sort-directions: OK — every order in api/src and api/tests says its direction with SortDirection (%d uses)\n' "$uses"
