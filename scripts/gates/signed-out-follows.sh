#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A page outside the shell hears no live change, so one showing what the session says stays on another person's old
# decision: the owner of a company the operator approved, or whose payment was confirmed, stayed « en attente » until
# they signed out (workflow hunt, B1, B2). Every component drawing `<app-signed-out-layout` (in its own template or its
# `.html`) that reads the session (`me()`, `company()`, `companyClosed()`) asks again on focus through `leaveOnceOpen`,
# or says why it need not with `session-followed: <why>`. Usage: signed-out-follows.sh [--root DIR]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
mapfile -t drawn < <(cd "$root" && git grep -l -e '<app-signed-out-layout' -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
declare -A components=()
for file in "${drawn[@]}"; do
  components[${file%.html}]=1
done
status=0
reading=0
for base in "${!components[@]}"; do
  component="${base%.ts}.ts"
  [[ -f "$root/$component" ]] || continue
  sources=("$root/$component")
  [[ -f "$root/${base%.ts}.html" ]] && sources+=("$root/${base%.ts}.html")
  grep -qE '\.me\(\)|\bcompany\(\)|companyClosed\(\)' "${sources[@]}" || continue
  reading=$((reading + 1))
  if ! grep -qE 'leaveOnceOpen\(|session-followed: [^ ]' "${sources[@]}"; then
    printf 'signed-out-follows: FAIL — %s shows what the session says outside the shell and never asks again: call leaveOnceOpen, or say why with session-followed: <why>\n' "$component"
    status=1
  fi
done
# Two such pages when this gate was written (the awaiting page and the two-step one): none means the scan went blind.
if ((reading < 2)); then
  printf 'signed-out-follows: FAIL — %d signed-out pages reading the session found, 2 at least expected: the scan no longer sees them\n' "$reading"
  exit 1
fi
((status)) && exit 1
printf 'signed-out-follows: OK — %d signed-out pages reading the session follow it\n' "$reading"
