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

d=$(fixture); mutate "$d" web/package-lock.json '$j["packages"]["node_modules/dev-apache"]["license"]="Python-2.0";'; php "$GEN" --root "$d" >/dev/null
assert_gate "Python-2.0 on a dev-only npm package passes (argparse under @hey-api/openapi-ts, ruled 2026-09-09)" "$d" 0 "OK"

d=$(fixture); mutate "$d" web/package-lock.json '$j["packages"]["node_modules/runtime-isc"]["license"]="Python-2.0";'; php "$GEN" --root "$d" >/dev/null
assert_gate "Python-2.0 on a runtime npm package fails" "$d" 1 "runtime-isc (Python-2.0) is a RUNTIME dependency"

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
if [[ $(echo "$out" | jq -c '.distributed') == '["MIT","Apache-2.0","BSD-2-Clause","BSD-3-Clause","ISC","0BSD","MIT-0","CC0-1.0","BlueOak-1.0.0","Unicode-3.0"]' \
   && $(echo "$out" | jq -c '.dev_only_data') == '["CC-BY-4.0","CC-BY-3.0"]' \
   && $(echo "$out" | jq -c '.dev_only_tooling') == '["MPL-2.0","Python-2.0"]' \
   && $(echo "$out" | jq -c '.font_assets') == '["OFL-1.1"]' \
   && $(echo "$out" | jq -c '.exceptions') == '["LLVM-exception"]' \
   && $(echo "$out" | jq -c 'keys') == '["dev_only_data","dev_only_tooling","distributed","exceptions","font_assets"]' ]]; then ok "--dump-rules exposes exactly the five lists (maximums)"; else bad "--dump-rules lists drifted" "$out"; fi

d=$(fixture); out=$(php "$GEN" --root "$d" 2>&1); n="$d/THIRD-PARTY-NOTICES.md"
if grep -q 'vendor/runtime-mit | 1.0.0 | MIT | runtime' "$n" && grep -q 'dev-apache | 3.0.0 | Apache-2.0 | dev' "$n" && grep -q 'GENERATED' "$n"; then ok "generator writes one row per package with tier and role"; else bad "generator output" "$(cat "$n")"; fi

# Vendored font FILES (not packages) may carry OFL-1.1, and only with their licence text beside them.
# vendor_font <root> <dir under web/> <licence text or empty for none>
vendor_font() {
  mkdir -p "$1/web/$2"; printf 'wOF2-fixture' > "$1/web/$2/demo-latin.woff2"
  if [[ -n "$3" ]]; then printf '%s\n' "$3" > "$1/web/$2/LICENSE"; fi
  php "$GEN" --root "$1" >/dev/null 2>&1
}
OFL='This Font Software is licensed under the SIL Open Font License, Version 1.1.'

d=$(fixture); vendor_font "$d" public/fonts/demo "$OFL"
assert_gate "a vendored OFL-1.1 font with its LICENSE passes" "$d" 0 "OK"
if grep -q 'web/public/fonts/demo | OFL-1.1 | demo-latin.woff2' "$d/THIRD-PARTY-NOTICES.md"; then ok "notices list a vendored font with its licence and files"; else bad "notices lack the vendored font" "$(cat "$d/THIRD-PARTY-NOTICES.md")"; fi

d=$(fixture); vendor_font "$d" public/fonts/demo ""
assert_gate "a vendored font with no LICENSE beside it fails, naming it" "$d" 1 "web/public/fonts/demo/demo-latin.woff2 is a vendored font with no LICENSE file beside it"

d=$(fixture); vendor_font "$d" public/fonts/demo "GNU GENERAL PUBLIC LICENSE Version 3, 29 June 2007"
assert_gate "a vendored font under another licence fails" "$d" 1 "web/public/fonts/demo/LICENSE is not a permitted font licence (OFL-1.1)"

d=$(fixture); vendor_font "$d" src/assets/odd ""
assert_gate "a font file under web/src is checked too" "$d" 1 "web/src/assets/odd/demo-latin.woff2 is a vendored font with no LICENSE file beside it"

# WebAssembly shipped from an npm package compiles in code no lock file shows: a COMPONENTS.json beside its licence
# texts lists what is inside, audited against ONE exact tarball (docs/SPEC.md § 7, 2026-09-22 22:38 and 2026-09-23 08:05).
# vendor_wasm <root> — a package in the lock, a build that ships its .wasm, and a manifest of what the wasm holds.
vendor_wasm() {
  mutate "$1" web/package-lock.json '$j["packages"]["node_modules/demo-wasm"]=["version"=>"1.2.3","license"=>"MIT","integrity"=>"sha512-abc"];'
  cat > "$1/web/angular.json" <<'JSON'
{"projects":{"web":{"architect":{"build":{"options":{"assets":[
 {"glob":"**/*","input":"public"},
 {"glob":"demo.wasm","input":"node_modules/demo-wasm/dist","output":"vendor/demo"},
 {"glob":"*","input":"src/third-party/demo","output":"vendor/demo"}]}}}}}}
JSON
  mkdir -p "$1/web/src/third-party/demo"
  cat > "$1/web/src/third-party/demo/COMPONENTS.json" <<'JSON'
{"package":"demo-wasm","version":"1.2.3","integrity":"sha512-abc","file":"dist/demo.wasm","components":[
 {"name":"core","licence":"Apache-2.0","copyright":"Copyright 2016 Core Inc.","text":"LICENSE.core"},
 {"name":"libc++","licence":"Apache-2.0 WITH LLVM-exception","copyright":"LLVM Project contributors","text":"LICENSE.llvm"}]}
JSON
  printf 'Apache License\n' > "$1/web/src/third-party/demo/LICENSE.core"
  printf 'Apache License with LLVM Exceptions\n' > "$1/web/src/third-party/demo/LICENSE.llvm"
  php "$GEN" --root "$1" >/dev/null 2>&1
}

d=$(fixture); vendor_wasm "$d"
assert_gate "a shipped wasm whose components are listed, licensed and texted passes" "$d" 0 "OK"
if grep -q '| core | Apache-2.0 | Copyright 2016 Core Inc. |' "$d/THIRD-PARTY-NOTICES.md" && grep -q 'demo-wasm 1.2.3' "$d/THIRD-PARTY-NOTICES.md"; then ok "notices list what the wasm compiles in"; else bad "notices lack the wasm components" "$(cat "$d/THIRD-PARTY-NOTICES.md")"; fi

d=$(fixture); vendor_wasm "$d"; mutate "$d" web/package-lock.json '$j["packages"]["node_modules/demo-wasm"]["version"]="1.2.4";'; php "$GEN" --root "$d" >/dev/null
assert_gate "a package bumped past its audit fails: the new wasm is unread" "$d" 1 "web/src/third-party/demo/COMPONENTS.json audits demo-wasm 1.2.3 (sha512-abc) but web/package-lock.json holds 1.2.4"

d=$(fixture); vendor_wasm "$d"; mutate "$d" web/package-lock.json '$j["packages"]["node_modules/demo-wasm"]["integrity"]="sha512-other";'; php "$GEN" --root "$d" >/dev/null
assert_gate "the same version from another tarball fails too" "$d" 1 "but web/package-lock.json holds 1.2.3 (sha512-other)"

d=$(fixture); vendor_wasm "$d"; mutate "$d" web/src/third-party/demo/COMPONENTS.json '$j["components"][0]["licence"]="LGPL-2.1-only";'; php "$GEN" --root "$d" >/dev/null
assert_gate "a component under a refused licence fails, naming it" "$d" 1 "demo-wasm compiles in core (LGPL-2.1-only), outside the permitted identifiers"

d=$(fixture); vendor_wasm "$d"; mutate "$d" web/src/third-party/demo/COMPONENTS.json '$j["components"][1]["licence"]="Apache-2.0 WITH Classpath-exception-2.0";'; php "$GEN" --root "$d" >/dev/null
assert_gate "an exception other than LLVM's is refused" "$d" 1 "demo-wasm compiles in libc++ (Apache-2.0 WITH Classpath-exception-2.0)"

d=$(fixture); vendor_wasm "$d"; rm "$d/web/src/third-party/demo/LICENSE.core"
assert_gate "a component without its licence text beside it fails" "$d" 1 "web/src/third-party/demo/LICENSE.core, the licence text of core, is missing or empty"

d=$(fixture); vendor_wasm "$d"; rm -r "$d/web/src/third-party/demo"; php "$GEN" --root "$d" >/dev/null
assert_gate "a build shipping a package's wasm with no manifest fails" "$d" 1 "web/angular.json ships demo-wasm's WebAssembly, and no web/src/third-party/*/COMPONENTS.json lists what it compiles in"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
