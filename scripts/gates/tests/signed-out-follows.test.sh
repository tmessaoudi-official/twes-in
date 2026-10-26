#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/signed-out-follows.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"
  for name in a b; do
    printf '<app-signed-out-layout>{{ company()?.name }}</app-signed-out-layout>\n' > "$d/web/src/app/x/$name-page.html"
    printf 'export class P { company = computed(() => this.auth.me()?.company); constructor() { leaveOnceOpen(); } }\n' > "$d/web/src/app/x/$name-page.ts"
  done
  printf '<app-signed-out-layout><h1>x</h1></app-signed-out-layout>\n' > "$d/web/src/app/x/plain-page.html"
  printf 'export class Plain {}\n' > "$d/web/src/app/x/plain-page.ts"
  git -C "$d" add -A; echo "$d"
}
echo "signed-out-follows gate"
d=$(repo)
out=$(bash "$GATE" --root "$d" 2>&1); check "pages reading the session and asking again on focus pass; one reading nothing is not asked" $? 0 "$out" "OK — 2 signed-out"
printf 'export class P { company = computed(() => this.auth.me()?.company); }\n' > "$d/web/src/app/x/b-page.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a page reading the session that never asks again is named" $? 1 "$out" "x/b-page.ts shows what the session says"
printf 'export class P { company = computed(() => this.auth.me()?.company); } // session-followed: its own\n' > "$d/web/src/app/x/b-page.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a page saying why it need not passes" $? 0 "$out" "OK — 2"
printf 'export class P { company = computed(() => this.auth.me()?.company); } // session-followed:\n' > "$d/web/src/app/x/b-page.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a marker saying nothing is no reason" $? 1 "$out" "x/b-page.ts"
d=$(repo); printf 'export class C { template = `<app-signed-out-layout></app-signed-out-layout>`; closed = this.auth.companyClosed(); }\n' > "$d/web/src/app/x/inline-page.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "an inline template reading the session is asked too" $? 1 "$out" "x/inline-page.ts"
d=$(repo); git -C "$d" rm -qf "$d/web/src/app/x/a-page.html" "$d/web/src/app/x/a-page.ts"
out=$(bash "$GATE" --root "$d" 2>&1); check "fewer pages reading the session than written reds rather than passing over nothing" $? 1 "$out" "no longer sees"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
