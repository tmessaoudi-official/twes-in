#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/npm-install-scripts.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: the Makefile's install, the image's, the live container's, and the manifest's policy.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/infra/web" "$d/web" "$d/.github/workflows" "$d/scripts/tests"
  printf 'NPM_INSTALL := { npm ci --strict-allow-scripts --no-audit --no-fund && touch x; }\n' > "$d/Makefile"
  cat > "$d/infra/web/Dockerfile" <<'EOF'
FROM node:26 AS build
RUN --mount=type=secret,id=extra_ca \
    if [ -s /run/secrets/extra_ca ]; then export NODE_EXTRA_CA_CERTS=/run/secrets/extra_ca; fi \
 && npm ci --strict-allow-scripts --no-audit --no-fund
EOF
  cat > "$d/infra/web/tools.Dockerfile" <<'EOF'
FROM node:26
RUN npm install --global --no-audit "playwright@1.60.0"
EOF
  printf '#!/bin/sh\n# npm ci in a comment is not an install\nnpm ci --strict-allow-scripts --no-audit\n' > "$d/infra/web/live.sh"
  printf '{\n  "name": "w",\n  "allowScripts": {\n    "esbuild": false\n  }\n}\n' > "$d/web/package.json"
  printf 'run_it() { npm ci; }\n' > "$d/scripts/tests/fixture.test.sh"
  echo "$d"
}
run() { bash "$GATE" --root "$1" 2>&1; }
echo "npm install scripts gate"

d=$(tree); out=$(run "$d"); check "every install under the policy passes, a global install and a test's fixture aside" $? 0 "$out" "OK — 3 installs"

d=$(tree); sed -i 's/npm ci --strict-allow-scripts/npm ci/' "$d/Makefile"
out=$(run "$d"); check "an npm ci in the Makefile without --strict-allow-scripts is refused with its line" $? 1 "$out" "Makefile:1"

d=$(tree); sed -i 's/ && npm ci --strict-allow-scripts/ \&\& npm ci/' "$d/infra/web/Dockerfile"
out=$(run "$d"); check "an image's npm ci on a continuation line is refused at that line" $? 1 "$out" "infra/web/Dockerfile:4"

d=$(tree); sed -i 's/^npm ci --strict-allow-scripts/npm ci/' "$d/infra/web/live.sh"
out=$(run "$d"); check "a container script's npm ci is refused too" $? 1 "$out" "infra/web/live.sh:3"

d=$(tree); printf 'jobs:\n  web:\n    steps:\n      - run: cd web && npm install\n' > "$d/.github/workflows/ci.yml"
out=$(run "$d"); check "a project npm install in a workflow is refused (it runs the same scripts)" $? 1 "$out" ".github/workflows/ci.yml:4"

d=$(tree); printf '{\n  "name": "w"\n}\n' > "$d/web/package.json"
out=$(run "$d"); check "a manifest with no allowScripts is refused: the flag would stop every install" $? 1 "$out" "allowScripts"

d=$(tree); printf 'all:\n\ttrue\n' > "$d/Makefile"; rm "$d/infra/web/live.sh"
out=$(run "$d"); check "fewer installs than the floor is refused: a moved install is not a passing one" $? 1 "$out" "found 1"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
