#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/session-forgotten.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A facade with $2 values kept for the session, and whatever else $3 adds.
facade() {
  { printf "@Injectable({ providedIn: 'root' })\nexport class AFacade {\n"
    for ((i = 0; i < $2; i++)); do printf '  private readonly value%dSignal = sessionSignal(0);\n' "$i"; done
    printf '%b}\n' "${3:-}"; } > "$1"
}
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x" "$d/web/src/app/auth"
  facade "$d/web/src/app/x/a-facade.ts" 100
  git -C "$d" add -A; echo "$d"
}
echo "session-forgotten gate"
d=$(repo)
out=$(bash "$GATE" --root "$d" 2>&1); check "a root facade keeping everything for the session passes" $? 0 "$out" "OK — 100 values in 1 root facades"
facade "$d/web/src/app/x/a-facade.ts" 100 '  private readonly leftSignal = signal<string | null>(null);\n'; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a plain signal in a root facade is named with its line" $? 1 "$out" "x/a-facade.ts:103 keeps a plain signal"
facade "$d/web/src/app/x/a-facade.ts" 100 '  // session-kept: the device, not the person\n  private readonly leftSignal = signal(0);\n'; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a plain signal saying why on the line above passes" $? 0 "$out" "OK"
facade "$d/web/src/app/x/a-facade.ts" 100 '  readonly deviceSignal = signal(0); // session-kept: the device\n'; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a plain signal saying why on its own line passes" $? 0 "$out" "OK"
d=$(repo); facade "$d/web/src/app/auth/auth-facade.ts" 0 '  private readonly stateSignal = signal(null);\n'; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a file listed with its reason is not read" $? 0 "$out" "OK — 100 "
d=$(repo); facade "$d/web/src/app/x/b-facade.ts" 0 '  private readonly leftSignal = signal(0);\n'; sed -i "s/'root'/'any'/" "$d/web/src/app/x/b-facade.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a facade not provided at the root is not read" $? 0 "$out" "OK"
d=$(repo); facade "$d/web/src/app/x/a-facade.ts" 10; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "far fewer kept values than written reds rather than passing over nothing" $? 1 "$out" "no longer sees"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
