#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/read-retry.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x" "$d/web/src/app/shared/feedback"; echo "$d"; }
echo "read-retry gate"
d=$(repo)
printf '<app-read-failed\n  testId="a-error"\n  retryTestId="a-retry"\n  (retry)="readAgain()"\n>\n  {{ x }}\n</app-read-failed>\n' > "$d/web/src/app/x/a.html"
printf '<p>no</p>\n<!-- read-retry: the form above sends the words again -->\n' > "$d/web/src/app/x/b.html"
printf "template: \`<button>{{ 'feedback.retry' | translate }}</button>\`\n" > "$d/web/src/app/shared/feedback/read-failed.ts"
git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a failed line through the shared one and a marker with its reason pass" $? 0 "$out" "OK — 1 lines read again through app-read-failed, 1 say why not"
printf '<app-read-failed testId="c-error" retryTestId="c-retry">{{ x }}</app-read-failed>\n' > "$d/web/src/app/x/c.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a shared line with no (retry) is named" $? 1 "$out" "x/c.html:1"
printf '<app-read-failed testId="c-error" retryTestId="c-retry" [retryable]="readFailed()" (retry)="again()">{{ x }}</app-read-failed>\n' > "$d/web/src/app/x/c.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 2 2>&1); check "a (retry) after another binding counts" $? 0 "$out" "OK — 2 "
printf '<p role="alert">x</p>\n<button mat-button (click)="again()">\n  {{ '"'"'feedback.retry'"'"' | translate }}\n</button>\n' > "$d/web/src/app/x/e.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a page's own « Réessayer » outside the shared line is named" $? 1 "$out" "x/e.html:3"
rm "$d/web/src/app/x/e.html"; git -C "$d" add -A
printf '<!-- read-retry: -->\n' > "$d/web/src/app/x/f.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a marker giving no reason is named" $? 1 "$out" "x/f.html:1"
rm "$d/web/src/app/x/f.html"; git -C "$d" add -A
printf '<!--\n  read-retry: the reason\n  runs onto a second line\n-->\n' > "$d/web/src/app/x/g.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a marker whose reason runs over lines counts" $? 0 "$out" "2 say why not"
printf '<!-- read-retry:\n\n-->\n' > "$d/web/src/app/x/g.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a marker over lines with no reason is named" $? 1 "$out" "x/g.html:1"
rm "$d/web/src/app/x/g.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 3 2>&1); check "fewer shared lines than the floor reds" $? 1 "$out" "floor"
printf '<app-read-failed testId="s-error" retryTestId="s-retry" (retry)="x()">y</app-read-failed>\n' > "$d/web/src/app/x/s.spec.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 2 2>&1); check "a spec's host is not counted" $? 0 "$out" "OK — 2 "
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
