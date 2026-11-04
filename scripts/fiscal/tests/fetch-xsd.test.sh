#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
# None of the machine's own git settings: a developer's commit.gpgsign would sign the fixtures with a real key.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/fetch-xsd.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
refuses() { if [[ "$2" != *"$3"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (found '$3')"; echo "$2" | sed 's/^/       /'; fi; }
unset GITHUB_ACTIONS FX_DIR CII_FILE ZIP_DIR
echo "fetch-xsd"

# A published repository holding a schema folder whose name has spaces, as the CEN artefacts' does.
src="$TMPDIR/upstream"; git init -q "$src"
mkdir -p "$src/schemas/D16B (Subset)/CII/standard" "$src/other"
echo '<xs:schema id="cii"/>' > "$src/schemas/D16B (Subset)/CII/standard/Invoice.xsd"
echo '<xs:schema id="fx"/>' > "$src/other/Main.xsd"
git -C "$src" add -A; git -C "$src" -c user.name=t -c user.email=t@t commit -qm schemas
git -C "$src" config uploadpack.allowFilter true; git -C "$src" config uploadpack.allowAnySHA1InWant true
commit=$(git -C "$src" rev-parse HEAD)
cii_tree=$(git -C "$src" rev-parse "HEAD:schemas/D16B (Subset)/CII"); fx_tree=$(git -C "$src" rev-parse HEAD:other)

# A published zip whose one schema closes an enumeration twice, as TEJISOPaysDevises.xsd does at its line 1580.
mkdir -p "$TMPDIR/z/pack"
printf '%s\n' '<xs:schema>' '<xs:enumeration value="EUR">' '</xs:enumeration>' '</xs:enumeration>' '</xs:schema>' > "$TMPDIR/z/pack/Codes.xsd"
echo '<xs:schema id="decl"/>' > "$TMPDIR/z/pack/Declaration.xsd"
(cd "$TMPDIR/z" && python3 -I -m zipfile -c "$TMPDIR/pack.zip" pack)
zip_sum=$(sha256sum "$TMPDIR/pack.zip" | cut -d' ' -f1)

# set, variable, kind, source, ref, path, pin, names, entry, patch
sources() {
  printf 'set\tvariable\tkind\tsource\tref\tpath\tpin\tnames\tentry\tpatch\n'
  printf '%s\n' "$@"
}
T=$'\t'
FX="fx${T}FX_DIR${T}git${T}file://$src${T}$commit${T}other${T}$fx_tree${T}dir${T}Main.xsd${T}-"
CII="cii${T}CII_FILE${T}git${T}file://$src${T}$commit${T}schemas/D16B (Subset)/CII${T}$cii_tree${T}file${T}standard/Invoice.xsd${T}-"
ZIP="zip${T}ZIP_DIR${T}zip${T}file://$TMPDIR/pack.zip${T}$zip_sum${T}pack${T}-${T}dir${T}Declaration.xsd${T}Codes.xsd:4"
sources "$FX" "$CII" "$ZIP" > "$TMPDIR/all.tsv"
show='printf "fx=%s cii=%s zip=%s\n" "${FX_DIR:-}" "${CII_FILE:-}" "${ZIP_DIR:-}"'

out=$(XSD_SOURCES="$TMPDIR/all.tsv" bash "$SCRIPT" "$TMPDIR/d1" -- sh -c "$show" 2>&1)
check "each set fetched is named to the command by its variable" $? 0 "$out" "fx=$TMPDIR/d1/fx cii=$TMPDIR/d1/cii/standard/Invoice.xsd zip=$TMPDIR/d1/zip"
check "and each fetched set is said" 0 0 "$out" "fetched cii"
[[ -f "$TMPDIR/d1/fx/Main.xsd" && -f "$TMPDIR/d1/cii/standard/Invoice.xsd" && ! -e "$TMPDIR/d1/fx/schemas" ]]
check "only the pinned folder's own files land in the set's directory" $? 0 "" ""
check "the patch removes the doubled closing tag and nothing else" 0 0 "$(tr '\n' '|' < "$TMPDIR/d1/zip/Codes.xsd")" \
  '<xs:schema>|<xs:enumeration value="EUR">|</xs:enumeration>|</xs:schema>|'

out=$(XSD_SOURCES="$TMPDIR/all.tsv" bash "$SCRIPT" "$TMPDIR/d1" -- sh -c 'exit 7' 2>&1)
check "the command's own exit is the script's" $? 7 "$out" ""

touch "$TMPDIR/d1/fx/stale.xsd"
XSD_SOURCES="$TMPDIR/all.tsv" bash "$SCRIPT" "$TMPDIR/d1" -- true >/dev/null 2>&1
[[ ! -e "$TMPDIR/d1/fx/stale.xsd" ]]
check "every run starts from an empty directory, never from what an earlier run left" $? 0 "" ""

GONE="zip${T}ZIP_DIR${T}zip${T}file://$TMPDIR/nowhere.zip${T}$zip_sum${T}pack${T}-${T}dir${T}Declaration.xsd${T}-"
sources "$FX" "$GONE" > "$TMPDIR/gone.tsv"
out=$(XSD_SOURCES="$TMPDIR/gone.tsv" bash "$SCRIPT" "$TMPDIR/d2" -- sh -c "$show" 2>&1)
check "a source that does not answer is said as not run, and the rest still runs" $? 0 "$out" "not run zip:"
refuses "its variable is left unset, so its tests say they were skipped" "$out" "zip=$TMPDIR"
refuses "and it never prints a GitHub annotation outside GitHub" "$out" "::warning"
out=$(GITHUB_ACTIONS=true XSD_SOURCES="$TMPDIR/gone.tsv" bash "$SCRIPT" "$TMPDIR/d2" -- true 2>&1)
check "on GitHub, a set not run is a warning on the run's page" $? 0 "$out" "::warning title=Fiscal schema not run::zip"

MOVED="cii${T}CII_FILE${T}git${T}file://$src${T}$commit${T}schemas/D16B (Subset)/CII${T}$fx_tree${T}file${T}standard/Invoice.xsd${T}-"
sources "$MOVED" > "$TMPDIR/moved.tsv"
out=$(XSD_SOURCES="$TMPDIR/moved.tsv" bash "$SCRIPT" "$TMPDIR/d3" -- sh -c 'echo ran' 2>&1)
check "a folder whose content is not the pinned one stops the run" $? 1 "$out" "cii: the published schema is not the one pinned"
refuses "and the command is not run on it" "$out" "ran"

BADSUM="zip${T}ZIP_DIR${T}zip${T}file://$TMPDIR/pack.zip${T}0000${T}pack${T}-${T}dir${T}Declaration.xsd${T}-"
sources "$BADSUM" > "$TMPDIR/badsum.tsv"
out=$(XSD_SOURCES="$TMPDIR/badsum.tsv" bash "$SCRIPT" "$TMPDIR/d4" -- true 2>&1)
check "a zip whose digest is not the pinned one stops the run" $? 1 "$out" "zip: the published schema is not the one pinned"

BADPATCH="zip${T}ZIP_DIR${T}zip${T}file://$TMPDIR/pack.zip${T}$zip_sum${T}pack${T}-${T}dir${T}Declaration.xsd${T}Codes.xsd:3"
sources "$BADPATCH" > "$TMPDIR/badpatch.tsv"
out=$(XSD_SOURCES="$TMPDIR/badpatch.tsv" bash "$SCRIPT" "$TMPDIR/d5" -- true 2>&1)
check "a patch is refused unless its line closes what the line before already closed" $? 1 "$out" "zip: line 3 of Codes.xsd is not the doubled closing tag"

NOENTRY="fx${T}FX_DIR${T}git${T}file://$src${T}$commit${T}other${T}$fx_tree${T}dir${T}Missing.xsd${T}-"
sources "$NOENTRY" > "$TMPDIR/noentry.tsv"
out=$(XSD_SOURCES="$TMPDIR/noentry.tsv" bash "$SCRIPT" "$TMPDIR/d6" -- true 2>&1)
check "a set without the file its tests read stops the run" $? 1 "$out" "fx: Missing.xsd is not in the pinned folder"

out=$(XSD_SOURCES="$TMPDIR/all.tsv" bash "$SCRIPT" 2>&1)
check "a run naming no directory says how it is used" $? 2 "$out" "usage:"

out=$(bash "$SCRIPT" --list 2>&1)
check "the shipped sources name the three schema sets the tests read" $? 0 "$out" "tej TEJ_XSD_DIR"
check "with Factur-X's" 0 0 "$out" "facturx FACTURX_XSD_DIR"
check "and the CEN's CII" 0 0 "$out" "cen-cii EN16931_CII_XSD"

echo "fetch-xsd: $pass passed, $fail failed"
[[ $fail -eq 0 ]]
