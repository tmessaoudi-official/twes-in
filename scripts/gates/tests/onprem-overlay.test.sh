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
  mkdir -p "$d/infra/onprem" "$d/infra/lan"
  mkdir -p "$d/scripts/deploy"
  for f in compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-local.yaml compose.backup-offsite.yaml compose.onprem.yaml infra/onprem/Caddyfile infra/lan/Caddyfile infra/onprem/values.defaults scripts/deploy/new-instance.sh; do
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

d=$(project); edit "$d/compose.prod.yaml" '/^  pdf:$/,/^[a-z]/{/internal: true/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a pdf network with a way out is named" $? 1 "$out" "the pdf network is not internal"

d=$(project); edit "$d/compose.yaml" '/^  gotenberg:$/,/^  [a-z]*:$/{s/networks: \[pdf\]/networks: [default, pdf]/}'
out=$(bash "$GATE" --root "$d" 2>&1); check "Gotenberg on the default network too is named" $? 1 "$out" "gotenberg is on [default, pdf]"

d=$(project); edit "$d/compose.backup.yaml" 's/^  backup:$/  backup:\n    networks: [default, pdf]/'
out=$(bash "$GATE" --root "$d" 2>&1); check "another service joining the pdf network is named" $? 1 "$out" "backup joins the pdf network"

d=$(project); edit "$d/compose.yaml" '/^      - --chromium-disable-javascript$/d'
out=$(bash "$GATE" --root "$d" 2>&1); check "Gotenberg running JavaScript is named" $? 1 "$out" "gotenberg starts without --chromium-disable-javascript"

# A computer asleep past its edge's certificates wakes on a browser's warning: the leaves outlive many nights, the
# intermediate many leaves, and both are made again from the kept root at every start.
d=$(project); edit "$d/infra/onprem/Caddyfile" '/^[[:space:]]*lifetime [0-9]*d$/d'
out=$(bash "$GATE" --root "$d" 2>&1); check "leaves left at Caddy's twelve hours are named" $? 1 "$out" "leaf certificates last twelve hours"

d=$(project); edit "$d/infra/onprem/Caddyfile" 's/^\([[:space:]]*\)lifetime [0-9]*d$/\1lifetime 2d/'
out=$(bash "$GATE" --root "$d" 2>&1); check "leaves of two days are named" $? 1 "$out" "leaf certificates last 2 days"

d=$(project); edit "$d/infra/onprem/Caddyfile" '/intermediate_lifetime/d'
out=$(bash "$GATE" --root "$d" 2>&1); check "an intermediate left at Caddy's seven days is named" $? 1 "$out" "intermediate lasts seven days"

d=$(project); edit "$d/infra/onprem/Caddyfile" 's/intermediate_lifetime [0-9]*d/intermediate_lifetime 20d/'
out=$(bash "$GATE" --root "$d" 2>&1); check "an intermediate shorter than its leaves is named" $? 1 "$out" "intermediate lasts 20 days"

d=$(project); edit "$d/compose.onprem.yaml" 's|rm -rf /data/caddy/certificates/local && ||'
out=$(bash "$GATE" --root "$d" 2>&1); check "an edge keeping the leaves an older Caddyfile made is named" $? 1 "$out" "at every start"

# Development's phone door is the same kind of Caddy on a laptop, which sleeps too.
d=$(project); edit "$d/infra/lan/Caddyfile" '/^[[:space:]]*lifetime [0-9]*d$/d'
out=$(bash "$GATE" --root "$d" 2>&1); check "a phone door's leaves left at twelve hours are named" $? 1 "$out" "infra/lan/Caddyfile's leaf certificates last twelve hours"
d=$(project); edit "$d/infra/lan/Caddyfile" '/intermediate_lifetime/d'
out=$(bash "$GATE" --root "$d" 2>&1); check "a phone door's intermediate left at seven days is named" $? 1 "$out" "infra/lan/Caddyfile's intermediate lasts seven days"
d=$(project); edit "$d/compose.yaml" '/^  lan:$/,/^    environment:$/{s|rm -rf /data/caddy/certificates/local && ||}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a phone door keeping its old leaves is named" $? 1 "$out" "development's phone door (lan) does not make its intermediate"

# A shop updates with the values file its install wrote: what the files now require is written by that installer, or
# filled in by deploy.sh from infra/onprem/values.defaults.
d=$(project); edit "$d/compose.onprem.yaml" '/^  MAILER_SENDER: /a\  SHOP_GREETING: "${SHOP_GREETING:?set it}"'
out=$(bash "$GATE" --root "$d" 2>&1); check "a value required that no installer writes and no default fills is named" $? 1 "$out" "SHOP_GREETING"
echo 'SHOP_GREETING=Bonjour' >> "$d/infra/onprem/values.defaults"
out=$(bash "$GATE" --root "$d" 2>&1); [[ "$out" != *SHOP_GREETING* ]]; check "  the same value given a default is not" $? 0 "$out" ""
# The installer a shop ran is the release's, not the tree's: a secret this tree's installer makes is still missing from
# every shop installed before it.
d=$(project); edit "$d/scripts/deploy/new-instance.sh" '/^MONITOR_HEARTBEAT_TOKEN=/d'
( cd "$d" && export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 && git init -q -b master && git add -A \
  && git -c user.name=t -c user.email=t@t commit -qm r && git tag v2026.10.30.1 ) >/dev/null
git -C "$d" show HEAD:scripts/deploy/new-instance.sh >/dev/null
cp "$ROOT/scripts/deploy/new-instance.sh" "$d/scripts/deploy/new-instance.sh"
out=$(bash "$GATE" --root "$d" 2>&1); check "a secret the last release's installer never wrote is named, though this tree's writes it" $? 1 "$out" "MONITOR_HEARTBEAT_TOKEN"
check "  with the release it reads" 0 0 "$out" "v2026.10.30.1"
# Every release's installer, not the newest alone: a shop installed at an older release, or at a patch off master's
# history, keeps the values that installer wrote.
d=$(project); edit "$d/scripts/deploy/new-instance.sh" '/^MONITOR_HEARTBEAT_TOKEN=/d'
( cd "$d" && export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 && git init -q -b master && git add -A \
  && git -c user.name=t -c user.email=t@t commit -qm r && git tag v2026.10.29.1 ) >/dev/null
cp "$ROOT/scripts/deploy/new-instance.sh" "$d/scripts/deploy/new-instance.sh"
( cd "$d" && export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 && git add -A \
  && git -c user.name=t -c user.email=t@t commit -qm s && git tag v2026.10.30.1 ) >/dev/null
out=$(bash "$GATE" --root "$d" 2>&1); check "a secret an older release's installer never wrote is named, though the newest writes it" $? 1 "$out" "v2026.10.29.1"
d=$(project)
( cd "$d" && export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 && git init -q -b master && git add -A \
  && git -c user.name=t -c user.email=t@t commit -qm r && git tag v2026.10.29.1 && git checkout -q -b release/2026.10.29 ) >/dev/null
edit "$d/scripts/deploy/new-instance.sh" '/^MONITOR_HEARTBEAT_TOKEN=/d'
( cd "$d" && export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 && git add -A && git -c user.name=t -c user.email=t@t commit -qm p \
  && git tag v2026.10.31.1 && git checkout -q master ) >/dev/null
out=$(bash "$GATE" --root "$d" 2>&1); check "  and a patch's off master's history" $? 1 "$out" "v2026.10.31.1"

echo "onprem-overlay gate: $pass passed, $fail failed"
((fail == 0))
