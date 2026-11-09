#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A file put back where it was is refused one way on every record that keeps files: a 422 is the record full, said by
# the restore's own code, never by the reason's words, which are an upload's; a 409 names the move the record refuses.
# Each reader reads that through `restoreRefusalsIn` (web/src/app/shared/files/restore-refusals-api.ts), so a file holding
# a `…/restore` request names it as many times as it puts things back, or the request's line or the comment lines just
# above it say why it reads its refusal otherwise: `// restore-refusals: <why>` (a photo's reasons mapped to its own
# codes, the bin's restore of a whole record).
# Usage: restore-refusals.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=3
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "restore-refusals: unknown argument $1"; exit 2 ;;
  esac
done
app="$root/web/src/app"
if [[ ! -d "$app" ]]; then echo "restore-refusals: FAIL — found no web/src/app"; exit 1; fi

mapfile -t files < <(grep -rl --include='*.ts' '/restore`' "$app" | grep -vE '\.spec\.ts$|/shared/testing/' | sort)
read_through=0
problems=()
for file in "${files[@]}"; do
  # Each restore request's line, unless it or the comment lines just above it give its reason.
  mapfile -t bare < <(awk '
    function marked(text) { return text ~ /\/\/[ \t]*restore-refusals:[ \t]*[^ \t]/ }
    /\/restore`/ && !marked($0) && !said { print FNR }
    { if ($0 ~ /^[ \t]*\/\//) { said = said || marked($0) } else { said = 0 } }
  ' "$file")
  uses=$(grep -o 'restoreRefusalsIn(' "$file" | wc -l)
  if ((${#bare[@]} > uses)); then
    # The requests past what the file reads through it are the ones named: the first ones are taken as read.
    for line in "${bare[@]:uses}"; do problems+=("${file#"$root"/}:$line"); done
  fi
  read_through=$((read_through + (${#bare[@]} < uses ? ${#bare[@]} : uses)))
done

if ((${#problems[@]} > 0)); then
  printf 'restore-refusals: FAIL — a file put back whose refusal is not read through restoreRefusalsIn (read it there, or say // restore-refusals: <why>):\n'
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
if ((read_through < floor)); then
  printf 'restore-refusals: FAIL — only %d restores read through restoreRefusalsIn, below the floor of %d: the search no longer finds the readers\n' "$read_through" "$floor"
  exit 1
fi
printf 'restore-refusals: OK — %d restores read their refusal through restoreRefusalsIn\n' "$read_through"
