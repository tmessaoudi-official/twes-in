#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A test builds an issued invoice, a sent quote, a validated delivery note or a sent order the way a person does, through
# the use case: a status written by SQL skips the numbering, the chain, the stock and every check the issue makes, and the
# database guard on issued rows will refuse what follows it. Every SQL statement in api/tests that sets the status of an
# invoice, a quote, a delivery note or a purchase order (`UPDATE <table> SET … status =`, an `INSERT INTO <table>`
# naming `status`, or DBAL's table API, `->update('<table>', ['status' => …], …)` or `->insert('<table>', [… 'status' => …])`,
# whose data array is read to its closing bracket over as many lines as it takes) is refused unless its line or the one
# above says why the state cannot be reached for real:
# `// issue-shortcut: <why>` (a data check proving it notices a state no use case makes, a merge test whose customers
# lack the identity an issue needs on purpose).
# Usage: test-issue-shortcut.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
# 705 test files are read today, and the smallest directory, Fuzz, holds 4: a floor 3 below the count reds on losing any
# directory or any 4 files. Lowered, with its reason, in the change that takes files away.
floor=702
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "test-issue-shortcut: unknown argument $1" >&2; exit 2 ;;
  esac
done
problems=()
tables='(invoice|quote|delivery_note|purchase_order)'
updated="update[[:space:]]+${tables}[[:space:]]+set[[:space:]]"
assigned='(^|[^a-z_])status[[:space:]]*='
inserted="insert[[:space:]]+into[[:space:]]+${tables}[[:space:]]*\\([^)]*[^a-z_]status[^a-z_]"
said='//[[:space:]]*issue-shortcut:[[:space:]]*[^[:space:]]'
read_files=0
said_count=0

if [[ ! -d "$root/api/tests" ]]; then problems+=("found no api/tests")
else
  while IFS= read -r file; do
    read_files=$((read_files + 1))
    mapfile -t lines < "$file"
    for ((i = 0; i < ${#lines[@]}; i++)); do
      line=${lines[i]}
      code=${line#"${line%%[![:space:]]*}"}
      [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* ]] && continue
      lower=${code,,}
      if [[ "$lower" =~ $updated ]]; then
        # Only what the SET writes: a status the WHERE reads, or a subquery's, writes nothing.
        set_part=${lower#*"${BASH_REMATCH[0]}"}
        set_part=${set_part%%" where "*}
        [[ "$set_part" =~ $assigned ]] || continue
      elif ! [[ "$lower" =~ $inserted ]]; then continue
      fi
      previous=''; ((i > 0)) && previous=${lines[i - 1]}
      if [[ "$line" =~ $said || "$previous" =~ $said ]]; then said_count=$((said_count + 1)); continue; fi
      problems+=("${file#"$root/"}:$((i + 1)): ${code:0:110}")
    done
  done < <(find "$root/api/tests" -name '*.php' -type f | sort)
fi

if ((${#problems[@]})); then
  printf 'test-issue-shortcut: FAIL — %d document status(es) written by SQL in a test (issue, send or validate through the API, or say why on the line with // issue-shortcut: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
if ((read_files < floor)); then
  echo "test-issue-shortcut: FAIL — $read_files test file(s) read under api/tests, under the floor of $floor: the reading has stopped finding them"
  exit 1
fi
printf 'test-issue-shortcut: OK — %d shortcut(s) say why, %d test files read\n' "$said_count" "$read_files"
