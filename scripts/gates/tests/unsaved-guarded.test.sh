#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SOURCE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/unsaved-guarded.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# The gate with a floor of one and an exempt list naming only the login page, so a fixture of a few files can pass.
gate() {
  local g="$TMPDIR/gate.sh"
  sed -e 's/^floor=[0-9]*$/floor=1/' -e '/^declare -A exempt=($/,/^)$/c\declare -A exempt=(\n  [web/src/app/auth/login-page.ts]="signed out"\n)' "$SOURCE" > "$g"
  bash "$g" --root "$1" 2>&1
}
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x" "$d/web/src/app/auth"
  printf '@Component({}) export class XPage { readonly changes = unsavedChanges(this.form, this.saved); }\n' > "$d/web/src/app/x/x-page.ts"
  printf '<input [ngModel]="a" />\n' > "$d/web/src/app/x/x-page.html"
  printf '@Component({}) export class LoginPage { readonly form = new FormGroup({}); }\n' > "$d/web/src/app/auth/login-page.ts"
  echo "$d"
}
echo "unsaved-guarded gate"
d=$(repo); git -C "$d" add -A
out=$(gate "$d"); check "a page that counts and a listed one pass" $? 0 "$out" "OK — 1 "
printf '@Component({}) export class YPage { save() {} }\n' > "$d/web/src/app/x/y-page.ts"; printf '<input (input)="typed($event)" />\n' > "$d/web/src/app/x/y-page.html"; git -C "$d" add -A
out=$(gate "$d"); check "a template that edits while its class counts nothing is named" $? 1 "$out" "x/y-page.ts edits something"
printf '@Component({}) export class YPage { constructor() { inject(UnsavedChanges).declare(this.typed); } }\n' > "$d/web/src/app/x/y-page.ts"; git -C "$d" add -A
out=$(gate "$d"); check "a component declaring its own count passes" $? 0 "$out" "OK — 2 "
printf '@Component({}) export class ZDialog { data = inject(MAT_DIALOG_DATA); form = new FormGroup({}); }\n' > "$d/web/src/app/x/z-dialog.ts"; git -C "$d" add -A
out=$(gate "$d"); check "a dialog is not asked" $? 0 "$out" "1 dialogs"
printf 'export class Helper { form = new FormGroup({}); }\n' > "$d/web/src/app/x/helper.ts"; git -C "$d" add -A
out=$(gate "$d"); check "what is not a component is not asked" $? 0 "$out" "OK"
printf '@Component({}) export class SpecHost { form = new FormGroup({}); }\n' > "$d/web/src/app/x/x-page.spec.ts"; git -C "$d" add -A
out=$(gate "$d"); check "a spec is not asked" $? 0 "$out" "OK"
d=$(repo); printf '@Component({}) export class LoginPage { readonly changes = unsavedChanges(this.form, this.saved); form = new FormGroup({}); }\n' > "$d/web/src/app/auth/login-page.ts"; git -C "$d" add -A
out=$(gate "$d"); check "a listed component that counts after all reds until it leaves the list" $? 1 "$out" "still listed as exempt"
d=$(repo); rm "$d/web/src/app/auth/login-page.ts"; git -C "$d" add -A
out=$(gate "$d"); check "a listed component that is gone reds until it leaves the list" $? 1 "$out" "no editing component"
d=$(repo); printf '@Component({}) export class XPage { readonly form = new FormGroup({}); }\n' > "$d/web/src/app/x/x-page.ts"; git -C "$d" add -A
out=$(gate "$d"); check "fewer components counting than the floor reds, though none is named" $? 1 "$out" "fewer than the 1"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
