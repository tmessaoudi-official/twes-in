#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/outcome-sentences.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree with one screen saying x.saved, whose text in each language is given, and a page part holding y.added.
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x" "$d/web/public/i18n/part"
  printf '%s\n' "this.feedback.success(" "  'x.saved'," "  {}," "  { key: 'x.undo', run: () => undefined }," ");" \
    "this.feedback.effect(done ? 'y.added' : 'x.saved', {}, 'annulable');" > "$d/web/src/app/x/a.ts"
  for lang in fr en ar; do
    printf '{ "x": { "saved": "%s", "undo": "Annuler" } }\n' "$1" > "$d/web/public/i18n/$lang.json"
    printf '{ "y": { "added": "%s" } }\n' "$2" > "$d/web/public/i18n/part/$lang.json"
  done
  git -C "$d" add -A; echo "$d"
}
echo "outcome-sentences gate"
d=$(repo 'Rôle enregistré' 'Photo ajoutée.')
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "an outcome left open is named, by file, line, key and language" $? 1 "$out" "web/src/app/x/a.ts:1 x.saved [fr]: no closing period: Rôle enregistré"
d=$(repo 'Le rôle a été enregistré.' 'Photo ajoutée')
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a key in a ternary is read, and its text in a page part" $? 1 "$out" "y.added [en]: no closing period: Photo ajoutée"
d=$(repo 'Le fichier « b.csv » est retiré.»' '{count, plural, one {{{count}} photo ajoutée} other {{{count}} photos ajoutées}}.')
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a period before a closing quote, and after a plural, closes it; an action's label is not read" $? 0 "$out" "OK — 2 outcome keys"
d=$(repo 'Le rôle a été enregistré.' 'Photo ajoutée.'); printf "this.feedback.success('z.open');\n" > "$d/web/src/app/x/a.spec.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "specs are not read" $? 0 "$out" "OK — 2 outcome keys"
d=$(repo 'Le rôle a été enregistré.' 'Photo ajoutée.'); printf "const outcome = { kind: 'done', key: 'z.scanned' };\n" > "$d/web/src/app/x/b.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a scan answered as done is an outcome, and a key with no text is named" $? 1 "$out" "web/src/app/x/b.ts:1 z.scanned [fr]: no text"
d=$(repo 'Le rôle a été enregistré.' 'Photo ajoutée.')
out=$(bash "$GATE" --root "$d" --floor 3 2>&1); check "fewer keys than the floor is a code no longer read, not a pass" $? 1 "$out" "2 outcome keys read, fewer than the 3"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
