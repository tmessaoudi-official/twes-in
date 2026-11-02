#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/unscoped-host-selectors.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"; echo "$d"; }
component() { # dir name encapsulation styleUrl-line
  printf "import { Component, ViewEncapsulation } from '@angular/core';\n@Component({\n  selector: 'app-%s',\n  template: '',\n  %s\n  encapsulation: ViewEncapsulation.%s,\n})\nexport class A {}\n" "$2" "$4" "$3" > "$1/web/src/app/x/$2.ts"
}
echo "unscoped-host-selectors gate"
d=$(repo); component "$d" a None "styleUrl: './a.scss',"; printf ':root[data-window="compact"] .x { padding: 0; }\n' > "$d/web/src/app/x/a.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "an unscoped stylesheet reading :root passes" $? 0 "$out" "OK — 1 unscoped component"
printf '.x {\n  :host-context(html.dark) & { color: red; }\n}\n' > "$d/web/src/app/x/a.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check ":host-context in an unscoped stylesheet is caught, by file and line" $? 1 "$out" "web/src/app/x/a.scss:2 :host-context"
printf ':host { display: block; }\n' > "$d/web/src/app/x/a.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check ":host is caught too" $? 1 "$out" "a.scss:1 :host"
printf '.x ::ng-deep .y { color: red; }\n' > "$d/web/src/app/x/a.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "::ng-deep is caught too" $? 1 "$out" "a.scss:1 ::ng-deep"
d=$(repo); component "$d" a None "styleUrls: ['./a.scss', './b.scss'],"; printf '.a {}\n' > "$d/web/src/app/x/a.scss"; printf '.b { :host-context(.c) & { x: 1; } }\n' > "$d/web/src/app/x/b.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "every stylesheet of styleUrls is read" $? 1 "$out" "web/src/app/x/b.scss:1 :host-context"
d=$(repo); component "$d" a None "styles: \`:host-context(.c) .x { x: 1; }\`,"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "inline styles are read in the component's own file" $? 1 "$out" "web/src/app/x/a.ts:5 :host-context"
d=$(repo); component "$d" a Emulated "styleUrl: './a.scss',"; printf ':host-context(.c) .x { x: 1; }\n' > "$d/web/src/app/x/a.scss"; component "$d" b None "styleUrl: './b.scss',"; printf '.b {}\n' > "$d/web/src/app/x/b.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "an encapsulated component's :host-context is its own business" $? 0 "$out" "OK — 1 unscoped component"
d=$(repo); component "$d" a None "styleUrl: './a.scss',"; printf '.a {}\n' > "$d/web/src/app/x/a.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 2 2>&1); check "fewer unscoped components than the floor is a failure, not a pass" $? 1 "$out" "fewer than the 2 expected"
d=$(repo); out=$(bash "$GATE" --root "$d" 2>&1); check "an empty tree is below the default floor" $? 1 "$out" "0 unscoped component"
echo "unscoped-host-selectors: $pass passed, $fail failed"
[[ $fail -eq 0 ]]
