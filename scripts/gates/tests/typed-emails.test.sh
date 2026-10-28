#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/typed-emails.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: the shared form's rule on what was typed, and a page judging an address through it.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/web/src/app/shared/form" "$d/web/src/app/auth"
  printf "export function onTypedText(validator: ValidatorFn): ValidatorFn {\n  return validator;\n}\n" > "$d/web/src/app/shared/form/typed-text.ts"
  printf "const email = new FormControl('', {\n  validators: [onTypedText(Validators.required), onTypedText(Validators.email)],\n});\n" > "$d/web/src/app/auth/login-page.ts"
  echo "$d"
}
run() { bash "$GATE" --root "$1" 2>&1; }
echo "typed-emails gate"

d=$(tree); out=$(run "$d"); check "a tree whose addresses are judged through onTypedText passes" $? 0 "$out" "OK"

d=$(tree); printf "const email = new FormControl('', {\n  validators: [Validators.required, Validators.email],\n});\n" > "$d/web/src/app/auth/signup-page.ts"
out=$(run "$d"); check "a hand-built control's plain Validators.email is refused with its file and line" $? 1 "$out" "signup-page.ts:2"

d=$(tree); printf "if (Validators.email(new FormControl(email)) !== null) return 1;\n" > "$d/web/src/app/auth/rules.ts"
out=$(run "$d"); check "a direct call on a value is refused" $? 1 "$out" "rules.ts:1"

d=$(tree); printf "if (onTypedText(Validators.email)(new FormControl(email)) !== null) return 1;\n" > "$d/web/src/app/auth/rules.ts"
out=$(run "$d"); check "a direct call through onTypedText passes" $? 0 "$out" "OK"

d=$(tree); printf "const v = [onTypedText(Validators.required), Validators.email];\n" > "$d/web/src/app/auth/rules.ts"
out=$(run "$d"); check "onTypedText elsewhere on the line does not cover an unwrapped one" $? 1 "$out" "rules.ts:1"

d=$(tree); printf "if (Validators.email(new FormControl(email)) !== null) return 1; // typed-email: judged after typedText\n" > "$d/web/src/app/auth/rules.ts"
out=$(run "$d"); check "a use whose line says why passes" $? 0 "$out" "OK"

d=$(tree); printf "// typed-email: the caller hands typedText(value)\nif (Validators.email(new FormControl(email)) !== null) return 1;\n" > "$d/web/src/app/auth/rules.ts"
out=$(run "$d"); check "a use whose line above says why passes" $? 0 "$out" "OK"

d=$(tree); printf "if (Validators.email(new FormControl(email)) !== null) return 1; // typed-email:\n" > "$d/web/src/app/auth/rules.ts"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "rules.ts:1"

d=$(tree); printf "/** Never Validators.email on what was typed. */\nexport const x = 1;\n" > "$d/web/src/app/auth/doc.ts"
out=$(run "$d"); check "the validator named in a comment is not a use" $? 0 "$out" "OK"

d=$(tree); printf "it('x', () => expect(Validators.email(c)).toBeNull());\n" > "$d/web/src/app/auth/login-page.spec.ts"
out=$(run "$d"); check "a spec is not a screen" $? 0 "$out" "OK"

d=$(tree); printf "export const rule = Validators.email;\n" > "$d/web/src/app/shared/form/form-builder.ts"
out=$(run "$d"); check "the shared form judges the typed text itself" $? 0 "$out" "OK"

d=$(tree); printf "export const x = 1;\n" > "$d/web/src/app/shared/form/typed-text.ts"
out=$(run "$d"); check "a tree without onTypedText is refused, not skipped" $? 1 "$out" "found no onTypedText"

d=$(tree); rm -r "$d/web/src/app"
out=$(run "$d"); check "a missing web/src/app is refused, not skipped" $? 1 "$out" "found no web/src/app"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
