#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
GATE=$HERE/secrets-overlay.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# Every installation's files as they are in the tree, each case changing one thing in its own copy.
project() {
  local d; d=$(mktemp -d)
  cp "$ROOT"/compose*.yaml "$d/"
  echo "$d"
}
edit() { sed -i "$2" "$1"; }

echo "secrets-overlay gate"
d=$(project)
out=$(bash "$GATE" --root "$d" 2>&1); check "every installation as shaped passes" $? 0 "$out" "OK — 5 installations"

# Compose 2.38 drops a `!reset` reached through an alias, so the workers kept what the block reset: read from the
# files, whichever Compose runs the gate.
d=$(project); edit "$d/compose.prod.yaml" 's|^  APP_ENV: prod$|&\n  APP_MFA_KEY: !reset null|'
out=$(bash "$GATE" --root "$d" 2>&1); check "a reset carried to an alias is named" $? 1 "$out" "compose.prod.yaml:21 &api-environment carries !reset"
d=$(project); edit "$d/compose.deploy.yaml" 's|^    ports: !reset \[\]$|    ports: \&no-ports !reset []|; s|^    environment: \*deploy-environment$|&\n    ports: *no-ports|'
out=$(bash "$GATE" --root "$d" 2>&1); check "a tag on the anchored line itself is named" $? 1 "$out" "&no-ports carries !reset"

d=$(project); edit "$d/compose.onprem.yaml" 's|^  SENTRY_DSN: "${SENTRY_DSN:-}"$|&\n  APP_SECRET: "${APP_SECRET:?x}"|'
out=$(bash "$GATE" --root "$d" 2>&1); check "a secret handed in an environment is named" $? 1 "$out" "api holds APP_SECRET in its environment"
check "the value written into the configuration is named" 1 1 "$out" "the value of APP_SECRET is written"

d=$(project); edit "$d/compose.onprem.yaml" 's|^  FILES_STORAGE: local$|  MAILER_DSN: smtp://mailpit:1025\n&|'
out=$(bash "$GATE" --root "$d" 2>&1); check "a shop sending to development's catcher is named" $? 1 "$out" "shop: api holds MAILER_DSN in its environment"

# FrankenPHP's workers read the environment over what was set at boot: an emptied variable beside its secret, which a
# console reads right, leaves every request without it.
d=$(project); edit "$d/compose.onprem.yaml" 's|^  FILES_STORAGE: local$|  MAILER_DSN: ""\n&|'
out=$(bash "$GATE" --root "$d" 2>&1); check "an api-image variable emptied beside its secret is named" $? 1 "$out" "shop: api holds MAILER_DSN in its environment beside the secret or template"
d=$(project); edit "$d/compose.deploy.yaml" 's|^  FILES_STORAGE: "${FILES_STORAGE:-local}"$|  DATABASE_URL: "postgresql://twes_app:x@postgres/twes"\n&|'
out=$(bash "$GATE" --root "$d" 2>&1); check "an address beside its template is named" $? 1 "$out" "server: worker holds DATABASE_URL in its environment beside the secret or template"

d=$(project); edit "$d/compose.prod.yaml" 's|twes_app:{postgres_app_password}@|twes_app:${POSTGRES_APP_PASSWORD:?x}@|'
out=$(bash "$GATE" --root "$d" 2>&1); check "a password interpolated into an address is named" $? 1 "$out" "the value of POSTGRES_APP_PASSWORD is written"

d=$(project); edit "$d/compose.prod.yaml" '/entrypoint: \[sh, \/twes-secrets, centrifugo\]/d'
out=$(bash "$GATE" --root "$d" 2>&1); check "a secret mounted and read by nothing is named" $? 1 "$out" "centrifugo mounts /run/secrets/centrifugo_http_api_key and nothing reads it"

d=$(project); edit "$d/compose.backup-bucket.yaml" 's|target: /run/secrets/pgbackrest_repo1_s3_key_secret|target: /run/secrets/s3_key|'
out=$(bash "$GATE" --root "$d" 2>&1); check "a secret mounted under a name no tool reads is named" $? 1 "$out" "postgres mounts backup_s3_secret at /run/secrets/s3_key, a name no tool there reads"

d=$(project); edit "$d/compose.prod.yaml" 's/^\(    secrets: &api-secrets \[.*\)\]$/\1, postgres_password]/'
out=$(bash "$GATE" --root "$d" 2>&1); check "an api mounting the owner's password is named" $? 1 "$out" "api mounts the owner's password"

d=$(project); edit "$d/compose.prod.yaml" '0,/^    environment: APP_SECRET$/s//    file: .\/app_secret/'
out=$(bash "$GATE" --root "$d" 2>&1); check "a secret read from a file of the checkout is named" $? 1 "$out" "/app_secret, not a variable of the values file"

d=$(project); edit "$d/compose.prod.yaml" 's/^\(    secrets: &api-secrets \[app_secret, \)app_mfa_key, /\1/'
out=$(bash "$GATE" --root "$d" 2>&1); check "an api left on api/.env's development key is named" $? 1 "$out" "production: api does not mount the secret app_mfa_key"

d=$(project); edit "$d/compose.prod.yaml" 's/^\(    secrets: &api-secrets \[.*\), mailer_dsn\]$/\1]/'
out=$(bash "$GATE" --root "$d" 2>&1); check "an api without its mail relay is named" $? 1 "$out" "shop: api does not mount the secret mailer_dsn"

d=$(project); edit "$d/compose.prod.yaml" 's/^\(    secrets: \[app_secret, .*\), postgres_password\]$/\1]/'
out=$(bash "$GATE" --root "$d" 2>&1); check "migrations without the owner's password are named" $? 1 "$out" "migrate does not mount the secret postgres_password"

d=$(project); rm "$d/compose.onprem.yaml"
out=$(bash "$GATE" --root "$d" 2>&1); check "an installation whose file is missing fails" $? 1 "$out" "shop: found no compose.onprem.yaml"

echo "secrets-overlay gate: $pass passed, $fail failed"
((fail == 0))
