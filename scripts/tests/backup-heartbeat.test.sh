#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/infra/postgres/backup/twes-backup
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# The backup's tools answer with STEP_STATUS; curl writes down how it was called and answers with CURL_STATUS.
mkdir -p "$TMPDIR/bin"
for tool in gosu restic; do printf '#!/usr/bin/env bash\nexit "${STEP_STATUS:-0}"\n' > "$TMPDIR/bin/$tool"; done
printf '#!/usr/bin/env bash\nprintf "%%s\\n" "$@" > "%s/curl.args"\nexit "${CURL_STATUS:-0}"\n' "$TMPDIR" > "$TMPDIR/bin/curl"
chmod +x "$TMPDIR/bin/"*
run() { rm -f "$TMPDIR/curl.args"; env PATH="$TMPDIR/bin:$PATH" MONITOR_URL="${URL-https://monitor.test}" MONITOR_HEARTBEAT_TOKEN="${TOKEN-beat}" "$@" bash "$SCRIPT" "${STEP:-full}" 2>&1; }
called() { [[ -f "$TMPDIR/curl.args" ]] && tr '\n' ' ' < "$TMPDIR/curl.args"; }
echo "backup heartbeat"

out=$(STEP=full run); check "a full backup that worked exits 0" $? 0 "$out" "full backup done"
check "and tells the monitor's base heartbeat it worked" 0 0 "$(called)" "success=true"
check "with its token" 0 0 "$(called)" "Authorization: Bearer beat "
check "at its endpoint" 0 0 "$(called)" " https://monitor.test/api/v1/endpoints/Sauvegardes_Base/external "

out=$(STEP=diff run); check "a differential feeds the same base heartbeat" 0 0 "$(called)" "Sauvegardes_Base/external"

out=$(STEP=full run STEP_STATUS=3); check "a backup that failed exits with its own status" $? 3 "$out" ""
check "and tells the monitor it failed, and why" 0 0 "$(called)" "success=false --data-urlencode error=full exited 3"

out=$(STEP=copy run); check "the nightly copy has its own heartbeat" $? 0 "$(called)" "Sauvegardes_Copie/external"
out=$(STEP=verify run); check "so does the weekly verify" $? 0 "$(called)" "Sauvegardes_Relecture/external"

out=$(STEP=full run CURL_STATUS=7); check "a monitor that does not answer never fails the backup" $? 0 "$out" "did not take the Base heartbeat"

out=$(URL='' STEP=full run); check "without a monitor (development, the drill) the step still runs" $? 0 "$out" "full backup done"
[[ -z "$(called)" ]]; check "and no heartbeat is sent" $? 0 "" ""

out=$(TOKEN='' STEP=full run); check "a monitor named without its token is refused" $? 1 "$out" "MONITOR_HEARTBEAT_TOKEN"

out=$(STEP=init run); [[ -z "$(called)" ]]; check "preparing the bucket sends no heartbeat" $? 0 "$out" ""

echo "backup heartbeat: $pass passed, $fail failed"
((fail == 0))
