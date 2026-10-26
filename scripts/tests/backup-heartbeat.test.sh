#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/infra/postgres/backup/twes-backup
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# The backup's tools answer with STEP_STATUS; curl writes down how it was called and answers with CURL_STATUS.
mkdir -p "$TMPDIR/bin"
# STEP_SLEEP makes each call hang that long first, as a network gone mid-upload does.
for tool in gosu restic; do printf '#!/usr/bin/env bash\n[[ -z "${STEP_SLEEP:-}" ]] || sleep "$STEP_SLEEP"\nexit "${STEP_STATUS:-0}"\n' > "$TMPDIR/bin/$tool"; done
printf '#!/usr/bin/env bash\nprintf "%%s\\n" "$@" > "%s/curl.args"\nexit "${CURL_STATUS:-0}"\n' "$TMPDIR" > "$TMPDIR/bin/curl"
chmod +x "$TMPDIR/bin/"*
# Bounded itself: a step that is not bounded must read red here, never hang the suite.
run() { rm -f "$TMPDIR/curl.args"; env PATH="$TMPDIR/bin:$PATH" MONITOR_URL="${URL-https://monitor.test}" MONITOR_HEARTBEAT_TOKEN="${TOKEN-beat}" OUTCOME_DIRECTORY="$TMPDIR/outcome" BACKUP_LOCK="$TMPDIR/backup.lock" "$@" timeout 60 bash "$SCRIPT" "${STEP:-full}" 2>&1; }
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

# Every step ends within its time: one that hangs fails as itself, rather than holding the lock every later step and an
# update's own copy wait on, and the schedule goes on.
rm -rf "$TMPDIR/outcome"; started=$SECONDS
out=$(STEP=copy run BACKUP_STEP_LIMIT=2 STEP_SLEEP=30); status=$?
check "a step past its time limit is stopped and fails" "$status" 124 "$out" "copy stopped after its time limit of 2 s"
((SECONDS - started < 20)); check "  within its time, not the hang's" $? 0 "$((SECONDS - started)) s" ""
check "  its outcome written down as failed, saying why" 0 0 "$(cat "$TMPDIR/outcome/copy" 2>/dev/null)" "failed copy stopped after its time limit of 2 s"
check "  and the monitor told it failed" 0 0 "$(called)" "success=false"
[[ ! -f "$TMPDIR/outcome/copy.ok" ]]; check "  never written down as done, so the schedule runs it again" $? 0 "$(ls "$TMPDIR/outcome")" ""
out=$(STEP=before-update run BACKUP_STEP_LIMIT=2 STEP_SLEEP=30); check "the copy before an update is bounded too" $? 124 "$out" "before-update stopped after its time limit of 2 s"
out=$(STEP=offsite run OFFSITE_REPOSITORY=s3:offsite.test/twes BACKUP_STEP_LIMIT=1 BACKUP_OFFSITE_LIMIT=20 STEP_SLEEP=2)
check "the off-site copy has its own, longer limit: a first upload takes hours" $? 0 "$out" "local folder copied off site"
out=$(STEP=copy run BACKUP_STEP_LIMIT=soon); check "a limit that is not a number of seconds is refused" $? 1 "$out" "BACKUP_STEP_LIMIT=soon: a number of seconds"

# While a step runs, the schedule says every few minutes that it is alive and which step it runs, so a long step is no
# stopped schedule; once done it says it ran.
rm -rf "$TMPDIR/outcome"
(STEP=due run BACKUP_STEP_LIMIT=2 DUE_BEAT=0.2 STEP_SLEEP=30 > "$TMPDIR/due.out") &
sleep 1.5; marker=$(cat "$TMPDIR/outcome/due" 2>/dev/null); wait
check "the schedule says it is alive while a step runs" 0 0 "$marker" " running full"
check "  and that it ran, once done" 0 0 "$(cat "$TMPDIR/outcome/due")" " ran"
check "  every step bounded, each failure written down" 0 0 "$(cat "$TMPDIR/outcome/copy" "$TMPDIR/outcome/verify" 2>/dev/null | tr '\n' '|')" "failed copy stopped after its time limit of 2 s|"
rm -rf "$TMPDIR/outcome"; started=$SECONDS
out=$(STEP=due run DUE_BEAT=300); status=$?
((status == 0 && SECONDS - started < 20)); check "the schedule's run hands its output back once done, its keeper gone with it" $? 0 "exit $status in $((SECONDS - started)) s" ""

# The service's start-up prepares both repositories while a step may already be running (a drill's, an update's): it
# takes the steps' lock, so restic never finds its repository locked by the other, and lets it go before the schedule.
printf '#!/usr/bin/env bash\nflock -n "$BACKUP_LOCK" true && echo restic-unlocked >> "%s/lock.seen" || echo restic-locked >> "%s/lock.seen"\n' "$TMPDIR" "$TMPDIR" > "$TMPDIR/bin/restic"
printf '#!/usr/bin/env bash\nflock -n "$BACKUP_LOCK" true && echo schedule-unlocked >> "%s/lock.seen" || echo schedule-locked >> "%s/lock.seen"\n' "$TMPDIR" "$TMPDIR" > "$TMPDIR/bin/supercronic"
chmod +x "$TMPDIR/bin/restic" "$TMPDIR/bin/supercronic"
rm -f "$TMPDIR/lock.seen"
out=$(URL='' STEP=run run BACKUP_LOCK="$TMPDIR/backup.lock"); seen=$(tr '\n' ' ' < "$TMPDIR/lock.seen" 2>/dev/null)
check "the start-up prepares the repositories under the steps' lock" $? 0 "$seen" "restic-locked restic-locked"
[[ "$seen" != *restic-unlocked* ]]; check "and never outside it" $? 0 "$seen" ""
check "and the schedule starts with the lock let go" 0 0 "$seen" "schedule-unlocked"

echo "backup heartbeat: $pass passed, $fail failed"
((fail == 0))
