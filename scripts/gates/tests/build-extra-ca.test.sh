#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
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
  mkdir -p "$d/infra/api"
  printf '#!/bin/sh\nset -e\ncomposer install --no-interaction\nexec docker-entrypoint "$@"\n' > "$d/infra/api/live-entrypoint.sh"
  printf '#!/bin/sh\nset -eu\nnpm ci --strict-allow-scripts --no-audit\nexec npx ng serve\n' > "$d/infra/web/live.sh"
  cat > "$d/compose.yaml" <<'EOF'
services:
  api:
    build: &api-build
      context: .
  migrate:
    build: *api-build
  worker: &worker
    build: *api-build
  demo-worker:
    <<: *worker
  web:
    build:
      context: .
  mailpit:
    image: axllent/mailpit
  tools:
    profiles: [tools]
    build:
      context: .
  web-tools:
    image: twes-in-web-tools
    profiles: [tools]
EOF
  cat > "$d/compose.extra-ca.yaml" <<'EOF'
services:
  api:
    build:
      secrets: [extra_ca]
  migrate:
    build:
      secrets: [extra_ca]
  worker:
    build:
      secrets: [extra_ca]
  demo-worker:
    build:
      secrets: [extra_ca]
  web:
    build:
      secrets: [extra_ca]
  tools:
    build:
      secrets: [extra_ca]
    volumes:
      - ${CCS_EXTRA_CA_FILE:?}:/etc/ccs-ca.crt:ro
    environment:
      SSL_CERT_FILE: /etc/ccs-ca.crt
      NODE_EXTRA_CA_CERTS: /etc/ccs-ca.crt
      COMPOSER_CAFILE: /etc/ccs-ca.crt
      REQUESTS_CA_BUNDLE: /etc/ccs-ca.crt
      GIT_SSL_CAINFO: /etc/ccs-ca.crt
  web-tools:
    volumes:
      - ${CCS_EXTRA_CA_FILE:?}:/etc/ccs-ca.crt:ro
    environment:
      SSL_CERT_FILE: /etc/ccs-ca.crt
      NODE_EXTRA_CA_CERTS: /etc/ccs-ca.crt
      COMPOSER_CAFILE: /etc/ccs-ca.crt
      REQUESTS_CA_BUNDLE: /etc/ccs-ca.crt
      GIT_SSL_CAINFO: /etc/ccs-ca.crt
secrets:
  extra_ca:
    file: ${CCS_EXTRA_CA_FILE:?}
EOF
  # Live development: two services start through a mounted script that downloads, one image's own start script does,
  # one service is built only here. Its overlay gives each what the cloud needs.
  cat > "$d/compose.live.yaml" <<'EOF'
services:
  api:
    entrypoint: ["live-entrypoint"]
    volumes:
      - ./api:/app
      - ./infra/api/live-entrypoint.sh:/usr/local/bin/live-entrypoint:ro
  migrate:
    entrypoint: ["live-entrypoint"]
    volumes:
      - ./infra/api/live-entrypoint.sh:/usr/local/bin/live-entrypoint:ro
  migrate-watch:
    extends:
      file: compose.yaml
      service: api
    entrypoint: ["twes-migrate-watch"]
  worker:
    command: ["php", "bin/console", "messenger:consume"]
  web:
    build:
      target: live
    volumes:
      - ./web:/app
EOF
  cat > "$d/compose.live-extra-ca.yaml" <<'EOF'
services:
  migrate-watch:
    build:
      secrets: [extra_ca]
EOF
  local svc
  for svc in api migrate web; do
    printf '  %s:\n' "$svc"
    [[ $svc == web ]] && printf '    user: "${HOST_UID:-1000}:${HOST_GID:-1000}"\n'
    printf '    volumes:\n      - ${CCS_EXTRA_CA_FILE:?}:/etc/ccs-ca.crt:ro\n    environment:\n'
    printf '      %s: /etc/ccs-ca.crt\n' SSL_CERT_FILE NODE_EXTRA_CA_CERTS COMPOSER_CAFILE REQUESTS_CA_BUNDLE GIT_SSL_CAINFO
  done >> "$d/compose.live-extra-ca.yaml"
  printf 'web-tools-image:\n\tdocker build -q --load $(EXTRA_CA_SECRET) -f infra/web/tools.Dockerfile infra/web\n' > "$d/Makefile"
  printf 'LIVE_CA := $(if $(EXTRA_CA),compose.live-extra-ca.yaml)\n' >> "$d/Makefile"
  echo "$d"
}
# Removes one service's block from an overlay: the line naming it at two spaces and every deeper line after it.
drop() { python3 -I -c 'import sys,re; p,s=sys.argv[1],sys.argv[2]; t=open(p).read(); open(p,"w").write(re.sub(r"(?m)^  "+re.escape(s)+r":\n(?:    .*\n)*", "", t))' "$1" "$2"; }
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
out=$(run "$d"); check "strict-ssl false in the Makefile is refused" $? 1 "$out" "Makefile:5 switches a TLS check off"

d=$(tree); printf '  pdf:\n    build:\n      context: .\n' >> "$d/compose.yaml"
out=$(run "$d"); check "a built service the overlay leaves without the secret is refused" $? 1 "$out" "service pdf,"

d=$(tree); python3 -I -c 'import sys; p=sys.argv[1]; s=open(p).read(); open(p,"w").write(s.replace("  demo-worker:\n    build:\n      secrets: [extra_ca]\n", ""))' "$d/compose.extra-ca.yaml"
out=$(run "$d"); check "a service building through a merge key (<<: *worker) needs the secret too" $? 1 "$out" "demo-worker"

d=$(tree); python3 -I -c 'import sys; p=sys.argv[1]; s=open(p).read(); open(p,"w").write(s.replace("  worker:\n    build:\n      secrets: [extra_ca]\n", ""))' "$d/compose.extra-ca.yaml"
out=$(run "$d"); check "a service named with an anchor (worker: &worker) needs the secret too" $? 1 "$out" "service worker,"

d=$(tree); drop "$d/compose.live-extra-ca.yaml" migrate-watch
out=$(run "$d"); check "a service only compose.live.yaml builds needs the secret in the live overlay" $? 1 "$out" "compose.live-extra-ca.yaml gives the service migrate-watch"

# An overlay naming a service its own files do not define makes compose build one from ./Dockerfile: `make up-images`
# (compose.yaml alone) died on « target migrate-watch: failed to read dockerfile » in every cloud session.
d=$(tree); python3 -I -c 'import sys; p=sys.argv[1]; s=open(p).read(); open(p,"w").write(s.replace("\nsecrets:\n", "\n  migrate-watch:\n    build:\n      secrets: [extra_ca]\nsecrets:\n", 1))' "$d/compose.extra-ca.yaml"
out=$(run "$d"); check "compose.extra-ca.yaml naming a service compose.yaml does not define is refused" $? 1 "$out" "compose.extra-ca.yaml names migrate-watch, which compose.yaml does not define"

d=$(tree); printf '  ghost:\n    user: "0:0"\n' >> "$d/compose.live-extra-ca.yaml"
out=$(run "$d"); check "the live overlay naming a service neither file defines is refused" $? 1 "$out" "compose.live-extra-ca.yaml names ghost"

# A live service that downloads as it starts (npm ci, composer install) meets the proxy at run time, not at build.
d=$(tree); drop "$d/compose.live-extra-ca.yaml" migrate
out=$(run "$d"); check "a live service starting through a mounted script that downloads needs the CA at run time" $? 1 "$out" "migrate downloads as it starts"

d=$(tree); python3 -I -c 'import sys; p=sys.argv[1]; s=open(p).read(); i=s.index("  web:"); j=s.index("NODE_EXTRA_CA_CERTS", i); k=s.index("\n", j); open(p,"w").write(s[:s.rindex("\n", 0, j)]+s[k:])' "$d/compose.live-extra-ca.yaml"
out=$(run "$d"); check "a downloading live service missing one tool's variable is refused, naming it" $? 1 "$out" "web: NODE_EXTRA_CA_CERTS"

d=$(tree); sed -i '/user: /d' "$d/compose.live-extra-ca.yaml"
out=$(run "$d"); check "the live web, whose image fixes its user, runs as the host user in the cloud" $? 1 "$out" "web does not run as the host user"

d=$(tree); printf '#!/bin/sh\nexec npx ng serve\n' > "$d/infra/web/live.sh"
out=$(run "$d"); check "a declared start script that no longer downloads is refused, so the list stays true" $? 1 "$out" "infra/web/live.sh"

d=$(tree); sed -i '/entrypoint: \["live-entrypoint"\]/d' "$d/compose.live.yaml"
out=$(run "$d"); check "fewer services downloading at start than the floor is refused, not read as none" $? 1 "$out" "found 1 live service"

d=$(tree); rm "$d/compose.live-extra-ca.yaml"
out=$(run "$d"); check "a missing live overlay is refused, not skipped" $? 1 "$out" "compose.live-extra-ca.yaml is missing"

d=$(tree); sed -i '/compose.live-extra-ca.yaml/d' "$d/Makefile"
out=$(run "$d"); check "a Makefile that never adds the live overlay is refused" $? 1 "$out" "Makefile never adds compose.live-extra-ca.yaml"

d=$(tree); printf 'ps:\n\tdocker build --load -f infra/ps/Dockerfile infra/ps\n' >> "$d/Makefile"
out=$(run "$d"); check "a docker build in the Makefile without the secret is refused" $? 1 "$out" "Makefile:5"

# What runs in the toolchain (composer install, npm ci, git) reaches the network through the same proxy as a build.
d=$(tree); python3 -I -c 'import sys,re; p=sys.argv[1]; s=open(p).read(); open(p,"w").write(re.sub(r"  web-tools:\n(    .*\n|      .*\n)+", "", s))' "$d/compose.extra-ca.yaml"
out=$(run "$d"); check "a toolchain service the overlay leaves without the CA at run time is refused" $? 1 "$out" "web-tools"

d=$(tree); python3 -I -c 'import sys; p=sys.argv[1]; s=open(p).read(); i=s.index("  tools:"); j=s.index("COMPOSER_CAFILE", i); k=s.index("\n", j); open(p,"w").write(s[:s.rindex("\n", 0, j)]+s[k:])' "$d/compose.extra-ca.yaml"
out=$(run "$d"); check "a toolchain service missing one tool's variable is refused, naming it" $? 1 "$out" "tools: COMPOSER_CAFILE"

d=$(tree); sed -i 's/profiles: \[tools\]/profiles: [other]/' "$d/compose.yaml"
out=$(run "$d"); check "no toolchain service found is refused, not read as none to check" $? 1 "$out" "no service under the tools profile"

# A toolchain run started by hand, outside compose (Playwright's browser fetch, the RC lane), meets the same proxy.
d=$(tree); printf 'browser:\n\tdocker run --rm -e HOME=/tmp twes-in-web-tools playwright install chromium\n' >> "$d/Makefile"
out=$(run "$d"); check "a docker run in the Makefile without \$(EXTRA_CA_RUN) is refused with its line" $? 1 "$out" "Makefile:5 runs docker run without"

d=$(tree); printf 'rc:\n\t( docker run --rm --network host -u 1:1 \\\n\t    -v x:x \\\n\t    twes-in-tools-rc bash run.sh )\n' >> "$d/Makefile"
out=$(run "$d"); check "a docker run continued over lines is refused at its first line" $? 1 "$out" "Makefile:5 runs docker run without"

d=$(tree); printf 'rc:\n\t( docker run --rm --network host -u 1:1 \\\n\t    $(EXTRA_CA_RUN) -v x:x \\\n\t    twes-in-tools-rc bash run.sh )\n' >> "$d/Makefile"
out=$(run "$d"); check "a docker run handed \$(EXTRA_CA_RUN) on a continuation line passes" $? 0 "$out" "OK"

d=$(tree); printf 'ps:\n\tdocker run --rm --network none -v s:/s img pwsh -File t.ps1\n' >> "$d/Makefile"
out=$(run "$d"); check "a docker run with --network none reaches nothing and passes" $? 0 "$out" "OK"

d=$(tree); python3 -I -c 'import sys; p=sys.argv[1]; s=open(p).read(); i=s.index("  web-tools:"); j=s.index("GIT_SSL_CAINFO", i); k=s.index("\n", j); open(p,"w").write(s[:s.rindex("\n", 0, j)]+s[k:])' "$d/compose.extra-ca.yaml"
out=$(run "$d"); check "git's own variable is required too (GnuTLS never reads SSL_CERT_FILE)" $? 1 "$out" "web-tools: GIT_SSL_CAINFO"

d=$(tree); rm "$d/compose.extra-ca.yaml"
out=$(run "$d"); check "a missing overlay is refused, not skipped" $? 1 "$out" "compose.extra-ca.yaml"

d=$(tree); rm -r "$d/infra"
out=$(run "$d"); check "no Dockerfile at all is refused, not skipped" $? 1 "$out" "found no"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
