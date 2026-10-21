#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/refusal-answers.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: an adapter under web/src/app building its refusal from the answer it caught.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/web/src/app/things"
  printf "import { refusedFor } from '../shared/errors/said-already';\nexport class ThingRefused extends Error {}\nexport function refusal(error: unknown): ThingRefused {\n  return refusedFor(error, new ThingRefused('network'));\n}\n" > "$d/web/src/app/things/things-api.ts"
  echo "$d"
}
run() { bash "$GATE" --root "$1" --floor "${2:-1}" 2>&1; }
echo "refusal-answers gate"

d=$(tree); out=$(run "$d"); check "a refusal handed to refusedFor passes" $? 0 "$out" "OK — 1 refusals"

d=$(tree); printf "export function other(): ThingRefused {\n  return new ThingRefused('invalid');\n}\n" >> "$d/web/src/app/things/things-api.ts"
out=$(run "$d"); check "a refusal built bare is refused with its file and line" $? 1 "$out" "things-api.ts:7"

d=$(tree); printf "export function wrapped(error: unknown): ThingRefused {\n  return refusedFor(\n    error,\n    new ThingRefused(\n      'invalid',\n    ),\n  );\n}\n" >> "$d/web/src/app/things/things-api.ts"
out=$(run "$d"); check "a refusal handed over several lines passes" $? 0 "$out" "OK — 2 refusals"

d=$(tree); printf "export function guessed(error: unknown): ThingRefused {\n  return refusedFor(error.error, new ThingRefused('invalid'));\n}\n" >> "$d/web/src/app/things/things-api.ts"
out=$(run "$d"); check "an answer read through a property is still an answer" $? 0 "$out" "OK — 2 refusals"

d=$(tree); printf "export function both(error: unknown): ThingRefused {\n  return refusedFor(error, new ThingRefused('a')) ?? new ThingRefused('b');\n}\n" >> "$d/web/src/app/things/things-api.ts"
out=$(run "$d"); check "a second refusal on a wrapped line is still checked" $? 1 "$out" "things-api.ts:7"

d=$(tree); printf "export function here(): ThingRefused {\n  // refusal-answer: none, refused before anything was sent.\n  return new ThingRefused('invalid');\n}\n" >> "$d/web/src/app/things/things-api.ts"
out=$(run "$d"); check "a refusal whose line above says why it has no answer passes" $? 0 "$out" "OK"

d=$(tree); printf "export function here(): ThingRefused {\n  return new ThingRefused('invalid'); // refusal-answer: none, nothing asked\n}\n" >> "$d/web/src/app/things/things-api.ts"
out=$(run "$d"); check "a refusal whose own line says why passes" $? 0 "$out" "OK"

d=$(tree); printf "export function here(): ThingRefused {\n  // refusal-answer:\n  return new ThingRefused('invalid');\n}\n" >> "$d/web/src/app/things/things-api.ts"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "things-api.ts:8"

d=$(tree); printf "it('refuses', () => { throw new ThingRefused('invalid'); });\n" > "$d/web/src/app/things/things-api.spec.ts"
mkdir -p "$d/web/src/app/shared/testing"; printf "export const made = new ThingRefused('invalid');\n" > "$d/web/src/app/shared/testing/made.ts"
out=$(run "$d"); check "specs and shared/testing build refusals freely" $? 0 "$out" "OK"

d=$(tree); out=$(run "$d" 2); check "fewer wrapped refusals than the floor is refused" $? 1 "$out" "below the floor of 2"

d=$(tree); rm -r "$d/web/src/app"
out=$(run "$d"); check "a missing web/src/app is refused, not skipped" $? 1 "$out" "found no"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
