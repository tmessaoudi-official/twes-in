#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/flow-outcomes-forgotten.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
facade() { # dir file class signal [forget]
  printf "@Injectable({ providedIn: 'root' })\nexport class %s {\n  private readonly %s = signal(false);\n%b}\n" "$3" "$4" "${5:-}" > "$1/web/src/app/$2"
}
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"
  facade "$d" x/a-facade.ts AFacade requestedSignal '  forget(): void {\n    this.requestedSignal.set(false);\n  }\n'
  facade "$d" x/b-facade.ts BFacade completedSignal '  forget(): void {\n    this.completedSignal.set(null);\n  }\n'
  printf 'export class APage {\n  private readonly a = inject(AFacade);\n  constructor() { this.a.forget(); }\n}\n' > "$d/web/src/app/x/a-page.ts"
  git -C "$d" add -A; echo "$d"
}
echo "flow-outcomes-forgotten gate"
d=$(repo)
out=$(bash "$GATE" --root "$d" 2>&1); check "facades that forget, read by a page that calls forget(), pass" $? 0 "$out" "OK — 2 "
facade "$d" x/b-facade.ts BFacade completedSignal; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a facade keeping an outcome without forget() is named" $? 1 "$out" "x/b-facade.ts keeps a flow outcome"
d=$(repo); printf 'export class APage {\n  private readonly a = inject(AFacade);\n}\n' > "$d/web/src/app/x/a-page.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a page reading it without forget() is named" $? 1 "$out" "x/a-page.ts reads AFacade"
d=$(repo); printf 'export class APage {\n  private readonly a = inject(AFacade);\n}\n' > "$d/web/src/app/x/a-page.spec.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a spec is not a page" $? 0 "$out" "OK"
d=$(repo); sed -i "s/providedIn: 'root'/providedIn: 'any'/" "$d/web/src/app/x/b-facade.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "fewer than two root facades found reds rather than passing over nothing" $? 1 "$out" "no longer sees"
d=$(repo); sed -i 's/= signal(false)/= sessionSignal(false)/' "$d/web/src/app/x/b-facade.ts" "$d/web/src/app/x/a-facade.ts"; facade_text=$(cat "$d/web/src/app/x/b-facade.ts"); git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "an outcome kept for the session (sessionSignal) is seen as one" $? 0 "$out" "OK — 2 "
[[ "$facade_text" == *sessionSignal* ]] || { fail=$((fail+1)); echo "  FAIL the fixture was not turned to sessionSignal"; }
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
