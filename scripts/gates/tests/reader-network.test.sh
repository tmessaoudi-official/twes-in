#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/reader-network.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: the canon, one reader on it, the interceptor allowed, a spec testing status 0.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/web/src/app/shared/errors" "$d/web/src/app/shared/submissions" "$d/web/src/app/things"
  printf "export function isUnreachable(error: unknown): boolean {\n  return UNREACHABLE.has(status);\n}\n" > "$d/web/src/app/shared/errors/unknown-refusal-api.ts"
  printf "function codeOf(error: unknown) {\n  if (!(error instanceof HttpErrorResponse) || isUnreachable(error)) return 'network';\n}\n" > "$d/web/src/app/things/things-api.ts"
  printf "function stillUnanswered(error: HttpErrorResponse) {\n  return error.status === 0 || error.status >= 500;\n}\n" > "$d/web/src/app/shared/submissions/submission-interceptor.ts"
  printf "it('reads', () => expect(error.status === 0).toBe(true));\n" > "$d/web/src/app/things/things-api.spec.ts"
  echo "$d"
}
run() { bash "$GATE" --root "$1" --floor "${2:-1}" 2>&1; }
echo "reader-network gate"

d=$(tree); out=$(run "$d"); check "readers on the rule, the interceptor and a spec pass" $? 0 "$out" "OK — 3 sources read, 1 decide the network through isUnreachable"

d=$(tree); printf "function codeOf(error: HttpErrorResponse) {\n  if (error.status === 0) return 'network';\n}\n" > "$d/web/src/app/things/other-api.ts"
out=$(run "$d"); check "a reader testing status === 0 is refused with its file and line" $? 1 "$out" "web/src/app/things/other-api.ts:2"

d=$(tree); printf "const named = error instanceof HttpErrorResponse && error.status > 0;\n" > "$d/web/src/app/things/other-api.ts"
out=$(run "$d"); check "a reader testing status > 0 is refused" $? 1 "$out" "other-api.ts:1"

d=$(tree); printf "if (error.status!==0) read();\n" > "$d/web/src/app/things/other-facade.ts"
out=$(run "$d"); check "a test without spaces, in any source, is refused" $? 1 "$out" "other-facade.ts:1"

d=$(tree); printf "if (error.status === 0) return;\n" > "$d/web/src/app/shared/submissions/other-interceptor.ts"
out=$(run "$d"); check "only the interceptor named is allowed, not its folder" $? 1 "$out" "other-interceptor.ts:1"

d=$(tree); printf "if (error.status === 404) return;\nif (raw.statusCode === 0) return;\n" > "$d/web/src/app/things/other-api.ts"
out=$(run "$d"); check "another status is not counted" $? 0 "$out" "none by the status alone"

d=$(tree); out=$(run "$d" 2); check "fewer readers on the rule than the floor is refused" $? 1 "$out" "below the floor of 2"

d=$(mktemp -d); out=$(bash "$GATE" --root "$d" 2>&1); check "a tree without web/src/app is refused" $? 1 "$out" "found no web/src/app"

echo "$pass passed, $fail failed"
((fail == 0))
