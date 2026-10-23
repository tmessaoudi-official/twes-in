#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A page outside the shell has no menu, so whatever it offers is all there is: the two-step page left an account nothing
# required to use it only « Se déconnecter », the end of a signup and an invitation's offer had no way back to the
# sign-in, and the page stopping a mail had none at all before its button. Every template drawing
# `<app-signed-out-layout` offers its way out under the card (`twesAuthAfter`), or names the one it draws in the card
# itself with `way-out: <where>`. Usage: signed-out-exits.sh [--root DIR]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
mapfile -t files < <(cd "$root" && git grep -l -e '<app-signed-out-layout' -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
status=0
under=0
named=0
for file in "${files[@]}"; do
  if grep -q 'twesAuthAfter' "$root/$file"; then
    under=$((under + 1))
  elif grep -q 'way-out: [^ -]' "$root/$file"; then
    named=$((named + 1))
  else
    printf 'signed-out-exits: FAIL — %s draws a signed-out page with no way out: offer one under the card with twesAuthAfter, or name the one in the card with way-out: <where>\n' "$file"
    status=1
  fi
done
# Eleven signed-out pages when this gate was written: far fewer means the scan went blind.
if ((${#files[@]} < 10)); then
  printf 'signed-out-exits: FAIL — %d signed-out pages found, 10 at least expected: the scan no longer sees them\n' "${#files[@]}"
  exit 1
fi
((status)) && exit 1
printf 'signed-out-exits: OK — %d signed-out pages offer a way out (%d under the card, %d named in it)\n' "${#files[@]}" "$under" "$named"
