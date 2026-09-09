#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Tests for scripts/gates/dependency-licences.php and scripts/notices/generate-third-party-notices.php.
# Every case asserts on the MESSAGE, never only on the exit code: a crash and a detection must not look alike.
set -uo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)
GATE="$ROOT/scripts/gates/dependency-licences.php"
GEN="$ROOT/scripts/notices/generate-third-party-notices.php"
pass=0; fail=0
ok()   { pass=$((pass+1)); echo "  ok   $1"; }
bad()  { fail=$((fail+1)); echo "  FAIL $1"; echo "$2" | sed 's/^/       /'; }

# assert_gate <name> <root> <expected-exit> <expected-substring>
assert_gate() {
  local out; out=$(php "$GATE" --root "$2" 2>&1); local code=$?
  if [[ $code -eq $3 && "$out" == *"$4"* ]]; then ok "$1"; else bad "$1 (exit $code, wanted $3 with '$4')" "$out"; fi
}

# A fixture is a tiny repository: two manifests, two lock files, and notices generated from them.
fixture() {
  local d; d=$(mktemp -d); mkdir -p "$d/api" "$d/web"
  printf '{"name":"twes-in/api","license":"AGPL-3.0-or-later"}\n' > "$d/api/composer.json"
  printf '{"name":"twes-in-web","license":"AGPL-3.0-or-later"}\n' > "$d/web/package.json"
  cat > "$d/api/composer.lock" <<'JSON'
{"packages":[{"name":"vendor/runtime-mit","version":"1.0.0","license":["MIT"]}],
 "packages-dev":[{"name":"vendor/dev-bsd","version":"2.0.0","license":["BSD-3-Clause"]}]}
JSON
  cat > "$d/web/package-lock.json" <<'JSON'
{"name":"twes-in-web","lockfileVersion":3,"packages":{
 "":{"name":"twes-in-web"},
 "node_modules/runtime-isc":{"version":"1.0.0","license":"ISC"},
 "node_modules/dev-apache":{"version":"3.0.0","license":"Apache-2.0","dev":true}}}
JSON
  php "$GEN" --root "$d" >/dev/null 2>&1
  echo "$d"
}
# mutate <root> <file> <php-expression over $j> — rewrite one JSON file in place.
mutate() { php -r '$f=$argv[1]; $j=json_decode(file_get_contents($f), true); eval($argv[2]); file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' "$1/$2" "$3"; }

echo "dependency-licences gate"
[[ -f "$GATE" && -f "$GEN" ]] || { echo "  FAIL gate or generator missing"; echo "1 failed"; exit 1; }

d=$(fixture); assert_gate "clean fixture passes" "$d" 0 "OK"
d=$(fixture); assert_gate "the real repository passes" "$ROOT" 0 "OK"

d=$(fixture); mutate "$d" api/composer.lock '$j["packages"][0]["license"]=["GPL-3.0-or-later"];'; php "$GEN" --root "$d" >/dev/null
assert_gate "GPL on a runtime composer package fails, naming it" "$d" 1 "vendor/runtime-mit (GPL-3.0-or-later) is a RUNTIME dependency"

d=$(fixture); mutate "$d" web/package-lock.json '$j["packages"]["node_modules/runtime-isc"]["license"]="MPL-2.0";'; php "$GEN" --root "$d" >/dev/null
assert_gate "MPL-2.0 on a runtime npm package fails" "$d" 1 "runtime-isc (MPL-2.0) is a RUNTIME dependency"

d=$(fixture); mutate "$d" web/package-lock.json '$j["packages"]["node_modules/dev-apache"]["license"]="MPL-2.0";'; php "$GEN" --root "$d" >/dev/null
assert_gate "MPL-2.0 on a dev-only npm package passes" "$d" 0 "OK"

d=$(fixture); mutate "$d" web/package-lock.json '$j["packages"]["node_modules/dev-apache"]["license"]="CC-BY-4.0";'; php "$GEN" --root "$d" >/dev/null
assert_gate "CC-BY-4.0 on a dev-only npm package passes" "$d" 0 "OK"

d=$(fixture); mutate "$d" web/package-lock.json '$j["packages"]["node_modules/runtime-isc"]["license"]="CC-BY-4.0";'; php "$GEN" --root "$d" >/dev/null
assert_gate "CC-BY-4.0 on a runtime npm package fails" "$d" 1 "runtime-isc (CC-BY-4.0) is a RUNTIME dependency"

d=$(fixture); mutate "$d" web/package-lock.json '$j["packages"]["node_modules/runtime-isc"]["license"]="(MIT OR Apache-2.0)";'; php "$GEN" --root "$d" >/dev/null
assert_gate "an OR expression with one permitted branch passes" "$d" 0 "OK"

d=$(fixture); mutate "$d" web/package-lock.json '$j["packages"]["node_modules/runtime-isc"]["license"]="(MIT AND GPL-2.0-only)";'; php "$GEN" --root "$d" >/dev/null
assert_gate "an AND expression with one refused branch fails" "$d" 1 "runtime-isc ((MIT AND GPL-2.0-only)) is a RUNTIME dependency"

d=$(fixture); mutate "$d" web/package-lock.json 'unset($j["packages"]["node_modules/runtime-isc"]["license"]);'; php "$GEN" --root "$d" >/dev/null
assert_gate "an npm package without a licence field fails" "$d" 1 "runtime-isc declares no licence"

d=$(fixture); mutate "$d" web/package-lock.json '$j["packages"]["node_modules/runtime-isc"]["license"]="SEE LICENSE IN LICENSE.md";'; php "$GEN" --root "$d" >/dev/null
assert_gate "a non-SPDX licence string fails" "$d" 1 "runtime-isc (SEE LICENSE IN LICENSE.md) is a RUNTIME dependency"

d=$(fixture); mutate "$d" api/composer.json '$j["license"]="proprietary";'
assert_gate "the API manifest must declare AGPL-3.0-or-later" "$d" 1 "api/composer.json declares 'proprietary', expected 'AGPL-3.0-or-later'"

d=$(fixture); mutate "$d" web/package.json 'unset($j["license"]);'
assert_gate "the web manifest must declare AGPL-3.0-or-later" "$d" 1 "web/package.json declares '', expected 'AGPL-3.0-or-later'"

d=$(fixture); sed -i 's/1.0.0/1.0.1/' "$d/THIRD-PARTY-NOTICES.md"
assert_gate "stale notices fail with the regeneration hint" "$d" 1 "THIRD-PARTY-NOTICES.md is out of date"

d=$(fixture); rm "$d/THIRD-PARTY-NOTICES.md"
assert_gate "missing notices fail" "$d" 1 "THIRD-PARTY-NOTICES.md is out of date"

out=$(php "$GATE" --dump-rules 2>&1)
if [[ $(echo "$out" | jq -c '.distributed') == '["MIT","Apache-2.0","BSD-2-Clause","BSD-3-Clause","ISC","0BSD","MIT-0","CC0-1.0","BlueOak-1.0.0"]' \
   && $(echo "$out" | jq -c '.dev_only_data') == '["CC-BY-4.0","CC-BY-3.0"]' \
   && $(echo "$out" | jq -c '.dev_only_tooling') == '["MPL-2.0"]' \
   && $(echo "$out" | jq -c '.font_assets') == '["OFL-1.1"]' \
   && $(echo "$out" | jq -c 'keys') == '["dev_only_data","dev_only_tooling","distributed","font_assets"]' ]]; then ok "--dump-rules exposes exactly the four lists (maximums)"; else bad "--dump-rules lists drifted" "$out"; fi

d=$(fixture); out=$(php "$GEN" --root "$d" 2>&1); n="$d/THIRD-PARTY-NOTICES.md"
if grep -q 'vendor/runtime-mit | 1.0.0 | MIT | runtime' "$n" && grep -q 'dev-apache | 3.0.0 | Apache-2.0 | dev' "$n" && grep -q 'GENERATED' "$n"; then ok "generator writes one row per package with tier and role"; else bad "generator output" "$(cat "$n")"; fi

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
