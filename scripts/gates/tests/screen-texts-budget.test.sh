#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/screen-texts-budget.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A project with an nginx.conf holding $1 and one small text file per language named after it.
# The catalogue says the interface speaks French and English, and Arabic not yet (CATALOGUE replaces it).
project() {
  local d; d=$(mktemp -d); mkdir -p "$d/web/public/i18n" "$d/infra/web" "$d/web/src/app/shared/i18n"
  printf 'server {\n    gzip on;\n%s\n}\n' "$1" > "$d/infra/web/nginx.conf"
  local spoken='{"languages": [{"tag": "fr", "interface": true}, {"tag": "en", "interface": true}, {"tag": "ar", "interface": false}]}'
  printf '%s\n' "${CATALOGUE:-$spoken}" > "$d/web/src/app/shared/i18n/languages.json"
  shift
  for language in "$@"; do printf '{"hello": "%s"}\n' "$language" > "$d/web/public/i18n/$language.json"; done
  echo "$d"
}
# Text gzip cannot shrink: random bytes written as hex, $2 kilobytes of it.
heavy() { head -c $(( $2 * 1024 )) /dev/urandom | od -An -tx1 | tr -d ' \n' | sed 's/.*/{"x": "&"}/' > "$1"; }
echo "screen-texts-budget gate"
d=$(project '    gzip_comp_level 6;' fr en)
out=$(bash "$GATE" --root "$d" 2>&1); check "two small languages at level 6 pass, each named with its size" $? 0 "$out" "OK — 2 languages"
d=$(project '    gzip_comp_level 6;' fr en ar)
heavy "$d/web/public/i18n/ar.json" 80
out=$(bash "$GATE" --root "$d" 2>&1); check "a language over 61440 B at level 6 is named" $? 1 "$out" "ar.json"
d=$(project '' fr en)
out=$(bash "$GATE" --root "$d" 2>&1); check "a server that does not set the level is refused: the measure would not be what is served" $? 1 "$out" "gzip_comp_level 6"
d=$(project '    # gzip_comp_level 6;' fr en)
out=$(bash "$GATE" --root "$d" 2>&1); check "a level written only in a comment does not count" $? 1 "$out" "gzip_comp_level 6"
d=$(project '    gzip_comp_level 1;' fr en)
out=$(bash "$GATE" --root "$d" 2>&1); check "another level is refused" $? 1 "$out" "gzip_comp_level 6"
d=$(project '    gzip_comp_level 6;' fr)
out=$(bash "$GATE" --root "$d" 2>&1); check "a language the interface speaks without its texts is refused" $? 1 "$out" "en.json"
d=$(CATALOGUE='{"languages": [{"tag": "fr", "interface": false}]}' project '    gzip_comp_level 6;' fr en)
out=$(bash "$GATE" --root "$d" 2>&1); check "a catalogue whose interface speaks nothing is refused, not read as nothing to measure" $? 1 "$out" "no language"
d=$(project '    gzip_comp_level 6;' fr en); rm "$d/web/src/app/shared/i18n/languages.json"
out=$(bash "$GATE" --root "$d" 2>&1); check "a missing catalogue is refused" $? 1 "$out" "no language"
d=$(project '    gzip_comp_level 6;' fr en ar)
out=$(bash "$GATE" --root "$d" 2>&1); check "texts the interface does not speak yet are measured too" $? 0 "$out" "ar.json"
d=$(project '    gzip_comp_level 6;' fr en)
mkdir -p "$d/web/public/i18n/account"; printf '{"a": 1}\n' > "$d/web/public/i18n/account/fr.json"; printf '{"a": 1}\n' > "$d/web/public/i18n/account/en.json"
out=$(bash "$GATE" --root "$d" 2>&1); check "a page's own texts within the budget with their language's pass, and are named" $? 0 "$out" "account/fr.json"
d=$(project '    gzip_comp_level 6;' fr en)
mkdir -p "$d/web/public/i18n/account"; heavy "$d/web/public/i18n/account/fr.json" 80; printf '{"a": 1}\n' > "$d/web/public/i18n/account/en.json"
out=$(bash "$GATE" --root "$d" 2>&1); check "a page's own texts over the budget are named" $? 1 "$out" "account/fr.json:"
d=$(project '    gzip_comp_level 6;' fr en)
mkdir -p "$d/web/public/i18n/account"; printf '{"a": 1}\n' > "$d/web/public/i18n/account/fr.json"
out=$(bash "$GATE" --root "$d" 2>&1); check "a page's texts missing a language are named: its page would show keys" $? 1 "$out" "account/en.json"
echo "$pass passed, $fail failed"
((fail == 0))
