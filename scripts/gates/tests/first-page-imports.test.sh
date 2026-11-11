#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
GATE=$HERE/first-page-imports.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A start that reaches a service, which the case writes; the service reaches a helper only through what it says.
project() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/web/src/app/shared"
  printf "import { bootstrapApplication } from '@angular/platform-browser';\nimport { appConfig } from './app/app.config';\n" >"$d/web/src/main.ts"
  printf "export * from './shared/service';\nexport const appConfig = {};\n" >"$d/web/src/app/app.config.ts"
  printf "%s\n" "$1" >"$d/web/src/app/shared/service.ts"
  echo "$d"
}
run() { bash "$GATE" --root "$1" --floor 3 2>&1; }

echo "first-page-imports gate"
out=$(bash "$GATE" 2>&1); check "the tree passes" $? 0 "$out" "OK — "

d=$(project "export class Fine {}"); printf "export const helper = 1;\n" >"$d/web/src/app/shared/helper.ts"
sed -i '1i import { helper } from '"'"'./helper'"'"';' "$d/web/src/app/shared/service.ts"
out=$(run "$d"); check "a start reaching nothing on demand passes" $? 0 "$out" "OK — 4 files"

# A service the start reaches, keeping Material's dialog on every first page only to inject it.
d=$(project "import { MatDialog } from '@angular/material/dialog';")
out=$(run "$d"); check "a value import of the dialog is named" $? 1 "$out" "web/src/app/shared/service.ts: @angular/material/dialog"

d=$(project "$(printf "import {\n  MatDialog,\n  type MatDialogRef,\n} from '@angular/material/dialog';")")
out=$(run "$d"); check "  as prettier lays it out on lines" $? 1 "$out" "web/src/app/shared/service.ts: @angular/material/dialog"

d=$(project "import '@angular/material/dialog';")
out=$(run "$d"); check "  and for its side effects alone" $? 1 "$out" "web/src/app/shared/service.ts: @angular/material/dialog"

d=$(project "export { MatDialog } from '@angular/material/dialog';")
out=$(run "$d"); check "  and re-exported" $? 1 "$out" "web/src/app/shared/service.ts: @angular/material/dialog"

# What the compiled page never holds: a type, or a module fetched when it is asked for.
d=$(project "import type { MatDialog } from '@angular/material/dialog';")
out=$(run "$d"); check "a type import passes" $? 0 "$out" "OK — "

d=$(project "$(printf "import {\n  type MatDialog,\n  type MatDialogRef,\n} from '@angular/material/dialog';")")
out=$(run "$d"); check "  and types named one by one" $? 0 "$out" "OK — "

d=$(project "export const open = async () => (await import('@angular/material/dialog')).MatDialog;")
out=$(run "$d"); check "an import when asked passes" $? 0 "$out" "OK — "

d=$(project "// import { MatDialog } from '@angular/material/dialog';")
out=$(run "$d"); check "  and so does a comment" $? 0 "$out" "OK — "

# A file only a lazy page reaches is not on the first page; a file reached through a type is not either.
d=$(project "export class Fine {}"); printf "import { MatDialog } from '@angular/material/dialog';\n" >"$d/web/src/app/shared/lazy.ts"
sed -i '1i export const page = () => import('"'"'./lazy'"'"');\nimport type { Lazy } from '"'"'./lazy'"'"';' "$d/web/src/app/shared/service.ts"
out=$(run "$d"); check "a file reached only lazily or by a type passes" $? 0 "$out" "OK — 3 files"

# A walk that stopped reading would pass over nothing.
d=$(project "export class Fine {}")
out=$(bash "$GATE" --root "$d" --floor 4 2>&1); check "fewer files than the floor fail" $? 1 "$out" "walked 3 files"

d=$(project "export class Fine {}"); printf "export * from './missing';\n" >"$d/web/src/app/shared/service.ts"
out=$(run "$d"); check "an import that resolves to nothing fails" $? 1 "$out" "web/src/app/shared/service.ts: ./missing resolves to no file"

d=$(project "export class Fine {}"); rm "$d/web/src/main.ts"
out=$(run "$d"); check "a tree without the start fails" $? 1 "$out" "no web/src/main.ts"

echo "first-page-imports gate: $pass passed, $fail failed"
((fail == 0))
