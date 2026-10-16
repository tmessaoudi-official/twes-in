#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/list-stances.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x" "$d/web/src/app/shared/list"; echo "$d"; }
# The stances a fixture declares, one "key|aspect=reason" per line, read by the gate from --stances.
stances() { printf '%s\n' "$@" > "$TMPDIR/stances.txt"; echo "$TMPDIR/stances.txt"; }
page() { printf '%s\n' "$2" > "$1/web/src/app/x/a-page.html"; printf '%s\n' "$3" > "$1/web/src/app/x/a-page.ts"; git -C "$1" add -A; }
run() { bash "$GATE" --root "$1" --stances "$2" 2>&1; }
ALL_BUT_ROWS=('x/a-page#list|selection=r' 'x/a-page#list|bulk=r' 'x/a-page|import=r' 'x/a-page|export=r')
NONE=("${ALL_BUT_ROWS[@]}" 'x/a-page#list|row-actions=r')
echo "list-stances gate"

d=$(repo); page "$d" '<app-data-list [descriptor]="list()" [rows]="rows()" />' \
  'export class APage { protected readonly list = computed(() => ({ id: "a", actions: [OPEN] })); }'
out=$(run "$d" "$(stances "${ALL_BUT_ROWS[@]}")"); check "a list offering row actions, with a reason for each of the rest, passes" $? 0 "$out" "OK — 1 lists on 1 pages"

out=$(run "$d" "$(stances 'x/a-page#list|selection=r' 'x/a-page|import=r' 'x/a-page|export=r')"); check "an aspect neither offered nor given a reason is named" $? 1 "$out" "x/a-page#list|bulk"

out=$(run "$d" "$(stances "${NONE[@]}")"); check "a reason left for an aspect the list offers is named" $? 1 "$out" "x/a-page#list|row-actions is listed with a reason but offered"

out=$(run "$d" "$(stances "${ALL_BUT_ROWS[@]}" 'x/b-page#list|bulk=r')"); check "a reason naming no list is named" $? 1 "$out" "x/b-page#list|bulk names no list"

out=$(run "$d" "$(stances 'x/a-page#list|selection=r' 'x/a-page#list|bulk=' 'x/a-page|import=r' 'x/a-page|export=r')"); check "an empty reason is no reason" $? 1 "$out" "x/a-page#list|bulk has an empty reason"

d=$(repo); page "$d" '<app-data-list [descriptor]="list" [rows]="rows()" />' \
  'export class APage { protected readonly list = ELSEWHERE; }'
out=$(run "$d" "$(stances "${NONE[@]}")"); check "a descriptor that cannot be read reds rather than judging nothing" $? 1 "$out" "x/a-page#list: its descriptor could not be read"

d=$(repo); printf '%s\n' 'export const A_LIST: ListDescriptor<Row> = { id: "a", selection: { countKey: "k" } };' > "$d/web/src/app/x/a-forms.ts"
page "$d" '<app-data-list [descriptor]="list" [(selected)]="selected">
  <ng-template appDataListSelection><button type="button">Go</button></ng-template>
</app-data-list>' "import { A_LIST } from './a-forms';
export class APage { protected readonly list = A_LIST; }"
out=$(run "$d" "$(stances 'x/a-page#list|row-actions=r' 'x/a-page|import=r' 'x/a-page|export=r')"); check "selection and bulk reached through an imported descriptor are offered" $? 0 "$out" "OK"
out=$(run "$d" "$(stances 'x/a-page#list|row-actions=r' 'x/a-page|import=r' 'x/a-page|export=r' 'x/a-page#list|bulk=r')"); check "a bulk template's button is not a row action, and bulk is offered" $? 1 "$out" "x/a-page#list|bulk is listed with a reason but offered"

d=$(repo); printf '%s\n' 'export const A_LIST: ListDescriptor<Row> = { id: "a", selection: { countKey: "k" } };' > "$d/web/src/app/x/a-forms.ts"
page "$d" '<app-data-list [descriptor]="list" />' "import { A_LIST } from './a-forms';
export class APage { protected readonly list = A_LIST; }"
out=$(run "$d" "$(stances "${NONE[@]}")"); check "a selection the descriptor alone declares is offered" $? 1 "$out" "x/a-page#list|selection is listed with a reason but offered"

d=$(repo); printf '%s\n' 'export function aList(fields: string[]): ListDescriptor<Row> {
  return { id: "a", columns: fields.map((f) => ({ id: f })) };
}
export const B_LIST: ListDescriptor<Row> = { id: "b", selection: { countKey: "k" }, actions: [OPEN] };' > "$d/web/src/app/x/a-forms.ts"
page "$d" '<app-data-list [descriptor]="list()" />' "import { aList, B_LIST } from './a-forms';
export class APage { protected readonly list = computed(() => aList(this.fields())); other = B_LIST; }"
out=$(run "$d" "$(stances "${NONE[@]}")"); check "the descriptor after a function in its file is not read as the function's" $? 0 "$out" "OK"

d=$(repo); page "$d" '@if (view(); as current) { <app-data-list [descriptor]="current.list" /> }' \
  "import { subjectView } from './a-subjects';
export class APage { protected readonly view = computed(() => subjectView(this.kind())); }"
printf '%s\n' 'const VIEWS = { a: { list: { id: "a", actions: [OPEN] } } };
export function subjectView(kind: string) {
  return VIEWS[kind];
}' > "$d/web/src/app/x/a-subjects.ts"; git -C "$d" add -A
out=$(run "$d" "$(stances "${ALL_BUT_ROWS[@]}")"); check "a list named through an @if alias is read from what the alias holds" $? 0 "$out" "OK"

d=$(repo); page "$d" '<app-data-list [descriptor]="list">
  <ng-template appDataListCell="actions" let-row><button type="button">Lock</button></ng-template>
</app-data-list>' 'export class APage { protected readonly list = { id: "a", columns: [] }; }'
out=$(run "$d" "$(stances "${ALL_BUT_ROWS[@]}")"); check "a button in a cell is a row action" $? 0 "$out" "OK"

d=$(repo); page "$d" '<a routerLink="/imports/a">Import</a><app-list-export [address]="a()" />
<app-data-list [descriptor]="list" />' 'export class APage { protected readonly list = { id: "a" }; }'
out=$(run "$d" "$(stances "${NONE[@]}")"); check "an import link and an export button are offered by the page" $? 1 "$out" "x/a-page|import is listed with a reason but offered"
out=$(run "$d" "$(stances 'x/a-page#list|selection=r' 'x/a-page#list|bulk=r' 'x/a-page#list|row-actions=r')"); check "with no reason for them" $? 0 "$out" "OK"

d=$(repo); page "$d" '<p>no list</p>' 'export class APage {}'
out=$(run "$d" "$(stances)"); check "a tree with no list reds rather than passing over nothing" $? 1 "$out" "pass over nothing"

d=$(repo); page "$d" '<app-data-list [descriptor]="list" />' 'export class APage { protected readonly list = { id: "a" }; }'
printf '%s\n' '<app-data-list [descriptor]="rows" />' > "$d/web/src/app/x/b-dialog.html"
printf '%s\n' 'export class BDialog { protected readonly rows = { id: "b" }; }' > "$d/web/src/app/x/b-dialog.ts"; git -C "$d" add -A
out=$(run "$d" "$(stances "${NONE[@]}")"); check "a list drawn outside a page is judged too" $? 1 "$out" "x/b-dialog#rows|selection"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
