#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/restore-refusals.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: a reader putting a file back and reading its refusal through the shared reader.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/web/src/app/things"
  printf "import { restoreRefusalsIn } from '../shared/files/restore-refusals-api';\nexport class ThingsApi {\n  async restore(id: string): Promise<void> {\n    try {\n      await this.http.post(\`/things/\${id}/restore\`, null);\n    } catch (error) {\n      throw new ThingRefused(await restoreRefusalsIn(this.injector, error));\n    }\n  }\n}\n" > "$d/web/src/app/things/things-api.ts"
  echo "$d"
}
second() { printf "export async function again(id: string): Promise<void> {\n  await http.post(\`/others/\${id}/restore\`, null);\n}\n" >> "$1/web/src/app/things/things-api.ts"; }
run() { bash "$GATE" --root "$1" --floor "${2:-1}" 2>&1; }
echo "restore-refusals gate"

d=$(tree); out=$(run "$d"); check "a restore read through restoreRefusalsIn passes" $? 0 "$out" "OK — 1 restores"

d=$(tree); sed -i 's/await restoreRefusalsIn(this.injector, error)/[]/' "$d/web/src/app/things/things-api.ts"
out=$(run "$d"); check "a restore reading its refusal another way is refused with its file and line" $? 1 "$out" "things-api.ts:5"

d=$(tree); second "$d"
out=$(run "$d"); check "two restores and one reading through it: the second is refused" $? 1 "$out" "things-api.ts:12"

d=$(tree); printf "export async function photo(id: string): Promise<void> {\n  // restore-refusals: a photo's reasons are mapped to its own codes.\n  await http.post(\`/photos/\${id}/restore\`, null);\n}\n" >> "$d/web/src/app/things/things-api.ts"
out=$(run "$d"); check "a restore whose line above says why passes" $? 0 "$out" "OK — 1 restores"

d=$(tree); printf "export async function photo(id: string): Promise<void> {\n  // restore-refusals: a photo's reasons are read into its own codes,\n  // which fit a photo put back.\n  await http.post(\`/photos/\${id}/restore\`, null);\n}\n" >> "$d/web/src/app/things/things-api.ts"
out=$(run "$d"); check "a reason over several comment lines just above passes" $? 0 "$out" "OK — 1 restores"

d=$(tree); printf "export async function photo(id: string): Promise<void> {\n  // restore-refusals: said for another line.\n  const other = 1;\n  await http.post(\`/photos/\${id}/restore\`, null);\n}\n" >> "$d/web/src/app/things/things-api.ts"
out=$(run "$d"); check "a reason above another line is not this request's" $? 1 "$out" "things-api.ts:14"

d=$(tree); printf "export async function photo(id: string): Promise<void> {\n  await http.post(\`/photos/\${id}/restore\`, null); // restore-refusals: its own codes\n}\n" >> "$d/web/src/app/things/things-api.ts"
out=$(run "$d"); check "a restore whose own line says why passes" $? 0 "$out" "OK"

d=$(tree); printf "export async function photo(id: string): Promise<void> {\n  // restore-refusals:\n  await http.post(\`/photos/\${id}/restore\`, null);\n}\n" >> "$d/web/src/app/things/things-api.ts"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "things-api.ts:13"

d=$(tree); printf "it('puts back', () => http.expectOne(\`/things/a/restore\`));\n" > "$d/web/src/app/things/things-api.spec.ts"
out=$(run "$d"); check "specs ask for restores freely" $? 0 "$out" "OK"

d=$(tree); out=$(run "$d" 2); check "fewer restores read through it than the floor is refused" $? 1 "$out" "below the floor of 2"

d=$(tree); rm -r "$d/web/src/app"
out=$(run "$d"); check "a missing web/src/app is refused, not skipped" $? 1 "$out" "found no"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
