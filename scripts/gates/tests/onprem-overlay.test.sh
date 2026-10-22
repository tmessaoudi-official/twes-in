#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
GATE=$HERE/onprem-overlay.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# The shop's files as they are in the tree, each case changing one thing in its own copy.
project() {
  local d f; d=$(mktemp -d)
  mkdir -p "$d/infra/onprem"
  for f in compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-local.yaml compose.backup-offsite.yaml compose.onprem.yaml infra/onprem/Caddyfile; do
    cp "$ROOT/$f" "$d/$f"
  done
  echo "$d"
}
edit() { sed -i "$2" "$1"; }

echo "onprem-overlay gate"
d=$(project)
out=$(bash "$GATE" --root "$d" 2>&1); check "the shop's overlay as shaped passes" $? 0 "$out" "OK"

d=$(project); edit "$d/compose.onprem.yaml" '/^  postgres:/,/^  [a-z]*:$/{/ports: !reset \[\]/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a published database port is named" $? 1 "$out" "postgres publishes"

d=$(project); edit "$d/compose.onprem.yaml" '/^  worker:$/{n;/environment: \*onprem-environment/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a worker left on the development address is named" $? 1 "$out" "worker DEFAULT_URI"

d=$(project); edit "$d/compose.onprem.yaml" 's/\${MAILER_SENDER:?[^}]*}/${MAILER_SENDER:-shop@example.invalid}/'
out=$(bash "$GATE" --root "$d" 2>&1); check "a required value given a default is named" $? 1 "$out" "MAILER_SENDER is not required"

d=$(project); edit "$d/compose.prod.yaml" '/^  worker:$/,/^  demo-worker:$/{s/secrets: \*api-secrets/secrets: [app_secret, app_mfa_key, realtime_token_key, realtime_api_key, postgres_app_password]/}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a worker that does not mount the shop's mail account is named" $? 1 "$out" "worker does not mount MAILER_DSN"

d=$(project); edit "$d/compose.onprem.yaml" 's/\${SENTRY_DSN:-}/${SENTRY_DSN:?set SENTRY_DSN}/'
out=$(bash "$GATE" --root "$d" 2>&1); check "the monitor is optional on premises" $? 1 "$out" "SENTRY_DSN"

d=$(project); edit "$d/compose.onprem.yaml" 's|APP_WEBAUTHN_RP_ID: localhost|APP_WEBAUTHN_RP_ID: "${LAN_HOST:?set LAN_HOST}"|'
out=$(bash "$GATE" --root "$d" 2>&1); check "passkeys bound to the network address, which changes, are named" $? 1 "$out" "APP_WEBAUTHN_RP_ID"

d=$(project); edit "$d/compose.backup-offsite.yaml" 's/\${BACKUP_S3_KEY:?[^}]*}/${BACKUP_S3_KEY:-}/'
out=$(bash "$GATE" --root "$d" 2>&1); check "the off-site copy switched on without its key is named" $? 1 "$out" "BACKUP_S3_KEY"

d=$(project); edit "$d/infra/onprem/Caddyfile" 's/^https:\/\/localhost, /https:\/\/127.0.0.1, /'
out=$(bash "$GATE" --root "$d" 2>&1); check "an edge that no longer answers on localhost is named" $? 1 "$out" "localhost"

d=$(project); edit "$d/compose.onprem.yaml" 's/"80:80"/"8080:80"/'
out=$(bash "$GATE" --root "$d" 2>&1); check "the edge's doors moved are named" $? 1 "$out" "the edge publishes"

echo "onprem-overlay gate: $pass passed, $fail failed"
((fail == 0))
