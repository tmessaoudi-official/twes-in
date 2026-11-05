#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/interceptor-failures.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/shared/errors" "$d/web/src/app/shared/submissions"; echo "$d"; }
echo "interceptor-failures gate"
d=$(repo); printf "export const a = () => { feedback.failure('feedback.busy'); };\n" > "$d/web/src/app/shared/errors/busy-interceptor.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "an interceptor saying its refusal as a failure passes" $? 0 "$out" "OK — 1 "
printf "export const b = () => { injector.get(Feedback).notice(\`feedback.numbering.\${r}\`, days); };\n" > "$d/web/src/app/shared/errors/numbering-refused-interceptor.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "an interceptor saying a refusal as a notice is named" $? 1 "$out" "numbering-refused-interceptor.ts:1"
rm "$d/web/src/app/shared/errors/numbering-refused-interceptor.ts"
printf "export const s = () => { feedback.notice('feedback.submission.in_progress'); };\n" > "$d/web/src/app/shared/submissions/submission-interceptor.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "the submission interceptor, listed with its reason, passes" $? 0 "$out" "1 listed"
printf "it('x', () => { feedback.notice('k'); });\n" > "$d/web/src/app/shared/errors/busy-interceptor.spec.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a spec is not an interceptor" $? 0 "$out" "OK"
out=$(bash "$GATE" --root "$d" --floor 3 2>&1); check "fewer interceptors than the floor reds" $? 1 "$out" "floor"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
