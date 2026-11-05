#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# An interceptor that says a refusal in words tells the screen to say nothing more (`saidAlready`), so what it says is
# all the person gets: it goes through `Feedback.failure`, announced at once and kept until closed (the Feedback
# port's contract, docs/SPEC.md § 7, 2026-09-17). Said as a notice, polite and gone in six seconds, a clock set back or
# a busy server left an invoice unissued with nothing on screen saying why (conf-08). An interceptor whose words are a
# status, not a refusal of the pressed action, is listed below with that reason. Reads every `*-interceptor.ts` under
# web/src/app from the index, specs excluded, and reds below a floor of interceptors saying anything, so a pattern that
# stops matching cannot pass over nothing. Usage: interceptor-failures.sh [--root DIR] [--floor N]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=5
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "interceptor-failures: unknown argument $1"; exit 2 ;;
  esac
done
declare -A listed=(
  [web/src/app/shared/submissions/submission-interceptor.ts]="a write still being made, or made with its answer lost, is a status, not a refusal: said as a notice (docs/SPEC.md § 7, 2026-10-21 21:19)"
)
mapfile -t files < <(cd "$root" && git ls-files -- 'web/src/app/*-interceptor.ts')
status=0
saying=0
for file in "${files[@]}"; do
  grep -qE '\.(failure|notice)\(' "$root/$file" || continue
  saying=$((saying + 1))
  [[ -n "${listed[$file]:-}" ]] && continue
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    printf 'interceptor-failures: FAIL — %s:%s says a refusal as a notice, gone in six seconds while the screen says nothing more: say it through failure()\n' "$file" "$line"
    status=1
  done < <(grep -nE '\.notice\(' "$root/$file" | cut -d: -f1)
done
if ((saying < floor)); then
  printf 'interceptor-failures: FAIL — %d interceptors say anything, below the floor of %d: the pattern stopped matching\n' "$saying" "$floor"
  exit 1
fi
((status)) && exit 1
printf 'interceptor-failures: OK — %d interceptors say a refusal as a lasting failure (%d listed with a reason)\n' "$saying" "${#listed[@]}"
