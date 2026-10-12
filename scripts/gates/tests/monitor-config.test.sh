#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)
GATE=$HERE/scripts/gates/monitor-config.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# The real monitor files and the backup script whose heartbeats it takes, copied, so each case mutates one promise.
project() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/infra/monitor" "$d/infra/postgres/backup"
  cp "$HERE"/infra/monitor/{compose.yaml,gatus.yaml,Caddyfile} "$d/infra/monitor/"
  cp "$HERE/infra/postgres/backup/twes-backup" "$d/infra/postgres/backup/"
  echo "$d"
}
edit() { sed -i "$2" "$1"; }

echo "monitor-config gate"
d=$(project)
out=$(bash "$GATE" --root "$d" 2>&1); check "the monitor as shipped passes" $? 0 "$out" "OK"

d=$(project); edit "$d/infra/monitor/compose.yaml" '0,/^  glitchtip-db:/{/^  glitchtip-db:/a\    ports: ["5432:5432"]
}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a published database port is named" $? 1 "$out" "glitchtip-db publishes"

d=$(project); edit "$d/infra/monitor/compose.yaml" 's/\${MONITOR_TOKEN:?[^}]*}/${MONITOR_TOKEN:-x}/'
out=$(bash "$GATE" --root "$d" 2>&1); check "a required value given a default is named" $? 1 "$out" "MONITOR_TOKEN is not required"

d=$(project); edit "$d/infra/monitor/compose.yaml" 's/ENABLE_USER_REGISTRATION: "false"/ENABLE_USER_REGISTRATION: "true"/'
out=$(bash "$GATE" --root "$d" 2>&1); check "open sign-up on the error tracker is refused" $? 1 "$out" "ENABLE_USER_REGISTRATION"

d=$(project); edit "$d/infra/monitor/compose.yaml" '0,/logging: \*logging/{/logging: \*logging/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a service without log rotation is named" $? 1 "$out" "edge does not rotate"

d=$(project); edit "$d/infra/monitor/gatus.yaml" '0,/    ui: \*hidden/{/    ui: \*hidden/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a line showing its details on the public page is named" $? 1 "$out" "Traitements does not hide"

d=$(project); edit "$d/infra/monitor/gatus.yaml" '/hide-errors: true/d'
out=$(bash "$GATE" --root "$d" 2>&1); check "the hidden set missing a detail is named" $? 1 "$out" "hide-errors"

d=$(project); edit "$d/infra/monitor/gatus.yaml" '0,/    alerts: \*alerts/{/    alerts: \*alerts/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a line that alerts nobody is named" $? 1 "$out" "Traitements alerts nobody"

d=$(project); edit "$d/infra/monitor/gatus.yaml" 's/name: Copie/name: Copié/'
out=$(bash "$GATE" --root "$d" 2>&1); check "an accented name is refused" $? 1 "$out" "Copié"

d=$(project); edit "$d/infra/postgres/backup/twes-backup" "s/heartbeat Relecture verify/heartbeat Verification verify/"
out=$(bash "$GATE" --root "$d" 2>&1); check "a heartbeat the monitor does not expect is named" $? 1 "$out" "Verification"

d=$(project); edit "$d/infra/monitor/gatus.yaml" 's/Bearer \${MONITOR_TOKEN}/Bearer 0123456789abcdef/'
out=$(bash "$GATE" --root "$d" 2>&1); check "a token written into gatus.yaml is refused" $? 1 "$out" "written out"

d=$(project); edit "$d/infra/monitor/gatus.yaml" '0,/token: "\${MONITOR_HEARTBEAT_TOKEN}"/s//token: "abc"/'
out=$(bash "$GATE" --root "$d" 2>&1); check "a heartbeat token written out is refused" $? 1 "$out" "written out"

d=$(project); edit "$d/infra/monitor/Caddyfile" '0,/max-age=31536000"/s//max-age=31536000; includeSubDomains"/'
out=$(bash "$GATE" --root "$d" 2>&1); check "HSTS reaching the subdomains is refused" $? 1 "$out" "Strict-Transport-Security"

d=$(project); rm "$d/infra/monitor/gatus.yaml"
out=$(bash "$GATE" --root "$d" 2>&1); check "a tree without gatus.yaml fails" $? 1 "$out" "gatus.yaml"

echo "monitor-config gate: $pass passed, $fail failed"
((fail == 0))
