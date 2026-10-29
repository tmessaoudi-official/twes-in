#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/build-extra-ca.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: a Dockerfile that downloads, compose.yaml with two built services, the cloud overlay
# handing both the secret, and a Makefile building one image by hand.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/infra/web"
  cat > "$d/infra/web/Dockerfile" <<'EOF'
FROM node:26 AS build
# The proxy's CA, when a build is handed one (compose.extra-ca.yaml).
RUN --mount=type=secret,id=extra_ca \
    if [ -s /run/secrets/extra_ca ]; then export NODE_EXTRA_CA_CERTS=/run/secrets/extra_ca; fi \
 && npm ci --no-audit
RUN printf 'x' > /tmp/x
EOF
  cat > "$d/compose.yaml" <<'EOF'
services:
  api:
    build: &api-build
      context: .
  worker: &worker
    build: *api-build
  demo-worker:
    <<: *worker
  mailpit:
    image: axllent/mailpit
EOF
  cat > "$d/compose.extra-ca.yaml" <<'EOF'
services:
  api:
    build:
      secrets: [extra_ca]
  worker:
    build:
      secrets: [extra_ca]
  demo-worker:
    build:
      secrets: [extra_ca]
secrets:
  extra_ca:
    file: ${CCS_EXTRA_CA_FILE:?}
EOF
  printf 'web-tools-image:\n\tdocker build -q --load $(EXTRA_CA_SECRET) -f infra/web/tools.Dockerfile infra/web\n' > "$d/Makefile"
  echo "$d"
}
run() { bash "$GATE" --root "$1" 2>&1; }
echo "build-extra-ca gate"

d=$(tree); out=$(run "$d"); check "a tree whose downloads mount the CA passes" $? 0 "$out" "OK"

d=$(tree); printf 'FROM php:8\nRUN composer install --no-dev\n' > "$d/infra/web/api.Dockerfile"
out=$(run "$d"); check "a download without the CA is refused with its file and line" $? 1 "$out" "api.Dockerfile:2"

d=$(tree); printf 'FROM php:8\nRUN apt-get update \\\n && install-php-extensions intl\n' > "$d/infra/web/api.Dockerfile"
out=$(run "$d"); check "a download on a continuation line is refused at its RUN" $? 1 "$out" "api.Dockerfile:2"

d=$(tree); printf 'FROM node:26\nRUN --mount=type=secret,id=extra_ca NODE_TLS_REJECT_UNAUTHORIZED=0 npm ci\n' > "$d/infra/web/bad.Dockerfile"
out=$(run "$d"); check "switching TLS checks off is refused, even beside the CA" $? 1 "$out" "bad.Dockerfile:2 switches a TLS check off"

d=$(tree); printf 'web:\n\tnpm config set strict-ssl false\n' >> "$d/Makefile"
out=$(run "$d"); check "strict-ssl false in the Makefile is refused" $? 1 "$out" "Makefile:4 switches a TLS check off"

d=$(tree); printf '  web:\n    build:\n      context: .\n' >> "$d/compose.yaml"
out=$(run "$d"); check "a built service the overlay leaves without the secret is refused" $? 1 "$out" "web"

d=$(tree); python3 -I -c 'import sys; p=sys.argv[1]; s=open(p).read(); open(p,"w").write(s.replace("  demo-worker:\n    build:\n      secrets: [extra_ca]\n", ""))' "$d/compose.extra-ca.yaml"
out=$(run "$d"); check "a service building through a merge key (<<: *worker) needs the secret too" $? 1 "$out" "demo-worker"

d=$(tree); python3 -I -c 'import sys; p=sys.argv[1]; s=open(p).read(); open(p,"w").write(s.replace("  worker:\n    build:\n      secrets: [extra_ca]\n", ""))' "$d/compose.extra-ca.yaml"
out=$(run "$d"); check "a service named with an anchor (worker: &worker) needs the secret too" $? 1 "$out" "service worker,"

d=$(tree); printf 'services:\n  migrate-watch:\n    extends:\n      file: compose.yaml\n      service: api\n    entrypoint: ["w"]\n' > "$d/compose.live.yaml"
out=$(run "$d"); check "a live service extending a built one (compose.live.yaml) needs the secret too" $? 1 "$out" "migrate-watch"

d=$(tree); printf 'ps:\n\tdocker build --load -f infra/ps/Dockerfile infra/ps\n' >> "$d/Makefile"
out=$(run "$d"); check "a docker build in the Makefile without the secret is refused" $? 1 "$out" "Makefile:4"

d=$(tree); rm "$d/compose.extra-ca.yaml"
out=$(run "$d"); check "a missing overlay is refused, not skipped" $? 1 "$out" "compose.extra-ca.yaml"

d=$(tree); rm -r "$d/infra"
out=$(run "$d"); check "no Dockerfile at all is refused, not skipped" $? 1 "$out" "found no"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
