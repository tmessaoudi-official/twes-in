#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
SCRIPT=$HERE/scripts/monitor/new-monitor.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
value() { grep -E "^$2=" "$1" | head -1 | cut -d= -f2-; }
echo "new-monitor"

SECRETS=(GLITCHTIP_SECRET_KEY GLITCHTIP_DB_PASSWORD)
FACTS=(TWES_HOST MONITOR_TOKEN MONITOR_HEARTBEAT_TOKEN STATUS_HOST ERRORS_HOST EDGE_TLS ALERT_SMTP_HOST ALERT_SMTP_USERNAME
  ALERT_SMTP_PASSWORD ALERT_FROM ALERT_TO TELEGRAM_TOKEN TELEGRAM_CHAT_ID)

f="$TMPDIR/a/monitor.env"; mkdir -p "$TMPDIR/a"
out=$(bash "$SCRIPT" "$f" 2>&1); check "a new monitor's file is written" $? 0 "$out" "$f"
check "only its owner reads it" 0 0 "$(stat -c %a "$f")" "600"
missing=(); for k in "${SECRETS[@]}"; do [[ "$(value "$f" "$k")" =~ ^[A-Za-z0-9]{40,}$ ]] || missing+=("$k"); done
check "every secret is filled in, letters and digits" "${#missing[@]}" 0 "${missing[*]:-}" ""
filled=(); for k in "${FACTS[@]}"; do grep -qE "^$k=$" "$f" || filled+=("$k"); done
check "every fact is left for the operator, blank, never guessed" "${#filled[@]}" 0 "${filled[*]:-}" ""
check "the alerts' relay port starts on the submission port" 0 0 "$(value "$f" ALERT_SMTP_PORT)" "587"

# The file names every value the monitor's compose requires, no more and no fewer: the gate's list is that one.
required=$(sed -n '/^REQUIRED=(/,/)/p' "$HERE/scripts/gates/monitor-config.sh" | tr -d '()' | sed 's/REQUIRED=//' | tr -s ' \n' '\n' | grep . | sort)
written=$(grep -oE '^[A-Z_]+=' "$f" | tr -d = | sort)
[[ "$written" == "$required" ]]; check "it names exactly the values the monitor requires" $? 0 "$(diff <(echo "$required") <(echo "$written"))" ""

g="$TMPDIR/b.env"; bash "$SCRIPT" "$g" >/dev/null 2>&1
[[ "$(value "$f" GLITCHTIP_SECRET_KEY)" != "$(value "$g" GLITCHTIP_SECRET_KEY)" ]]; check "two monitors share no secret" $? 0 "" ""

before=$(cat "$f")
out=$(bash "$SCRIPT" "$f" 2>&1); check "an existing file is refused" $? 1 "$out" "exists"
[[ "$(cat "$f")" == "$before" ]]; check "and left as it was" $? 0 "" ""

out=$(bash "$SCRIPT" 2>&1); check "a file must be named" $? 2 "$out" "Usage"

echo "new-monitor: $pass passed, $fail failed"
((fail == 0))
