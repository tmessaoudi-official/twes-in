#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/live-reload.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x" "$d/web/src/app/import"
  echo "$d"
}
echo "live-reload gate"
d=$(repo); printf 'export class APage { init() { this.live.reloadOn(["a"], () => this.facade.load(id), this.destroyRef); void this.facade.load(id); } }\n' > "$d/web/src/app/x/a-page.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a page that reads and calls reloadOn passes" $? 0 "$out" "OK — 1 "
printf 'export class APage { init() { void this.facade.loadValuation(id); } }\n' > "$d/web/src/app/x/a-page.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a page that reads through its facade and never reloads is named" $? 1 "$out" "x/a-page.ts"
printf 'export class APage { look() { return this.api.find(id, words); } }\n' > "$d/web/src/app/x/a-page.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a page that reads through its API and never reloads is named" $? 1 "$out" "x/a-page.ts"
printf 'export class APage { readonly record = liveRecord(this.facade.loadOne(id)); }\n' > "$d/web/src/app/x/a-page.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a page keeping its record live passes" $? 0 "$out" "OK"
d=$(repo); printf 'export class ImportPage { init() { void this.facade.load(id, subject); } }\n' > "$d/web/src/app/import/import-page.ts"
printf 'export class APage { init() { this.live.reloadOn(["a"], r, d); void this.facade.load(id); } }\n' > "$d/web/src/app/x/a-page.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a page listed with its reason is not asked" $? 0 "$out" "OK — 1 "
d=$(repo); printf 'export class APage { init() { void this.facade.load(id); } }\n' > "$d/web/src/app/x/a-page.spec.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a spec is not a page" $? 1 "$out" "pass over nothing"
d=$(repo); git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a tree with no page reading anything reds rather than passing over nothing" $? 1 "$out" "pass over nothing"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
