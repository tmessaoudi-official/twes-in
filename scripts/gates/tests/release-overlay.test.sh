#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/release-overlay.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# The deployment's files as the real ones are shaped: three things built (the api's Dockerfile for api, worker and the
# toolchain, the web's, the database's under infra/postgres), restore running the image the database builds.
project() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/infra/api" "$d/infra/web" "$d/infra/postgres"
  cat > "$d/compose.yaml" <<'EOF'
name: t
services:
  api:
    build: &api {context: ., dockerfile: infra/api/Dockerfile}
  worker:
    build: *api
  web:
    build: {context: ., dockerfile: infra/web/Dockerfile}
  postgres:
    image: busybox
  tools:
    profiles: [tools]
    build: *api
EOF
  printf 'services:\n  api:\n    restart: unless-stopped\n' > "$d/compose.prod.yaml"
  cat > "$d/compose.backup.yaml" <<'EOF'
services:
  postgres:
    build: {context: infra/postgres}
    image: twes-in-postgres-backup
  backup:
    image: twes-in-postgres-backup
  restore:
    profiles: [restore]
    image: twes-in-postgres-backup
EOF
  printf 'services:\n  edge:\n    image: busybox\n    environment:\n      HOST: "${TWES_HOST:?set TWES_HOST}"\n' > "$d/compose.deploy.yaml"
  cat > "$d/compose.release.yaml" <<'EOF'
services:
  api: {image: "${TWES_API_IMAGE:?set TWES_API_IMAGE}", build: !reset null}
  worker: {image: "${TWES_API_IMAGE:?set TWES_API_IMAGE}", build: !reset null}
  web: {image: "${TWES_WEB_IMAGE:?set TWES_WEB_IMAGE}", build: !reset null}
  postgres: {image: "${TWES_DATABASE_IMAGE:?set TWES_DATABASE_IMAGE}", build: !reset null}
  backup: {image: "${TWES_DATABASE_IMAGE:?set TWES_DATABASE_IMAGE}"}
  restore: {image: "${TWES_DATABASE_IMAGE:?set TWES_DATABASE_IMAGE}"}
EOF
  echo "$d"
}
run() { local out; out=$(bash "$GATE" --root "$1" 2>&1); echo "$?"; echo "$out"; }
verdict() { local r; r=$(run "$2"); check "$1" "$(head -1 <<<"$r")" "$3" "$(tail -n +2 <<<"$r")" "$4"; }

d=$(project)
verdict "every built service runs its digest and builds nothing" "$d" 0 "6 services run a published image"

d=$(project); sed -i 's/  web: {image: "${TWES_WEB_IMAGE:?set TWES_WEB_IMAGE}", build: !reset null}/  web: {image: "${TWES_WEB_IMAGE:?set TWES_WEB_IMAGE}"}/' "$d/compose.release.yaml"
verdict "a service that keeps its build is refused" "$d" 1 "web still builds"

d=$(project); sed -i '/^  restore:/d' "$d/compose.release.yaml"
verdict "a service running a built image's tag is refused" "$d" 1 "restore runs twes-in-postgres-backup, wanted \${TWES_DATABASE_IMAGE}"

d=$(project); sed -i 's/  worker: {image: "${TWES_API_IMAGE:?set TWES_API_IMAGE}"/  worker: {image: "${TWES_WEB_IMAGE:?set TWES_WEB_IMAGE}"/' "$d/compose.release.yaml"
verdict "a service given another part's image is refused" "$d" 1 "worker runs ghcr.invalid/twes-in-web@"

d=$(project); sed -i 's/${TWES_WEB_IMAGE:?set TWES_WEB_IMAGE}/${TWES_WEB_IMAGE:-twes-in-web}/' "$d/compose.release.yaml"
verdict "an image with a default is refused" "$d" 1 "TWES_WEB_IMAGE is not required"

d=$(project); printf '  gotenberg:\n    build: {context: ., dockerfile: infra/gotenberg/Dockerfile}\n' >> "$d/compose.yaml"
verdict "a new build the gate cannot name is refused" "$d" 1 "gotenberg builds something this gate cannot name"

d=$(project); rm "$d/compose.release.yaml"
verdict "a missing overlay is refused" "$d" 1 "found no compose.release.yaml"

echo "release-overlay.test: $pass passed, $fail failed"
((fail == 0))
