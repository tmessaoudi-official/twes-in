#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A facade the whole application shares outlives the pages that read it: a flow's outcome kept there (a link asked for,
# a signup finished) was still shown when the page was opened again, « e-mail envoyé » instead of the form, until a
# reload. A facade provided at the root that keeps such an outcome (a signal named requested…, completed…, accepted…,
# sent…, done…, finished…, succeeded…, confirmed…, stopped… or submitted…) offers `forget(): void`, and every page that
# injects it calls `.forget()` on entry. Usage: flow-outcomes-forgotten.sh [--root DIR]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
declare -A exempt=(
  [web/src/app/auth/login-page.ts]="reads only whether signup is open, never how a signup went"
)
outcome='private readonly (requested|completed|accepted|sent|done|finished|succeeded|confirmed|stopped|submitted)[A-Za-z]*Signal = (signal|sessionSignal)[<(]'
mapfile -t facades < <(cd "$root" && git ls-files -- 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
mapfile -t pages < <(cd "$root" && git ls-files -- 'web/src/app/*-page.ts' | grep -v '\.spec\.ts$')
status=0
found=0
for file in "${facades[@]}"; do
  grep -q "providedIn: 'root'" "$root/$file" 2>/dev/null || continue
  grep -qE "$outcome" "$root/$file" || continue
  found=$((found + 1))
  class=$(grep -oE 'export class [A-Za-z]+' "$root/$file" | head -n 1 | awk '{print $3}')
  if ! grep -qE '^\s*forget\(\): void' "$root/$file"; then
    printf 'flow-outcomes-forgotten: FAIL — %s keeps a flow outcome and offers no forget(): a page opened again shows how the last visit ended\n' "$file"
    status=1
  fi
  for page in "${pages[@]}"; do
    grep -qE "inject\($class\)" "$root/$page" || continue
    [[ -n "${exempt[$page]:-}" ]] && continue
    if ! grep -qE '\.forget\(\)' "$root/$page"; then
      printf 'flow-outcomes-forgotten: FAIL — %s reads %s and never calls forget() on entry: it opens on the last visit'"'"'s outcome\n' "$page" "$class"
      status=1
    fi
  done
done
# Two facades keep an outcome today (the password reset's and the signup's): finding fewer means the scan went blind.
if ((found < 2)); then
  printf 'flow-outcomes-forgotten: FAIL — %d root facade(s) keeping a flow outcome found, 2 at least expected: the scan no longer sees them\n' "$found"
  exit 1
fi
((status)) && exit 1
printf 'flow-outcomes-forgotten: OK — %d root facades keeping a flow outcome forget it, and every page reading them calls forget() (%d exempt with a reason)\n' "$found" "${#exempt[@]}"
