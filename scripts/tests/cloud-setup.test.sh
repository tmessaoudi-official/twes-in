#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# .claude/cloud-setup.sh, which a claude.ai cloud environment's setup runs (claude-code-setup, a 120-second budget shared
# by every hook): it starts the slow first steps detached and returns at once, says each outcome by a file a session
# reads, never starts twice, and does nothing off the cloud. Its make is a stand-in that writes down what it ran.
set -uo pipefail
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HOOK=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/.claude/cloud-setup.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

mkdir -p "$TMPDIR/bin"
# The stand-in make: one line per call, a pause (MAKE_PAUSE) and an exit (MAKE_STATUS) of its own.
printf '#!/usr/bin/env bash\necho "make $* CA=${CCS_EXTRA_CA_FILE:-none}" >> "%s/make.calls"\nsleep "${MAKE_PAUSE:-0}"\necho "made $*"\nexit "${MAKE_STATUS:-0}"\n' "$TMPDIR" > "$TMPDIR/bin/make"
chmod +x "$TMPDIR/bin/make"
case_dir() { local d; d=$(mktemp -d); echo "$d"; }
# No proxy CA unless a case names one: the session running the test has its own, which the hook would otherwise read.
# A repository of the test's own, its whole history: the hook's real one is never fetched into.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
origin=$TMPDIR/origin; git init -q -b master "$origin"
for n in 1 2 3; do echo "$n" > "$origin/f"; git -C "$origin" add f; git -C "$origin" commit -qm "c$n"; done
git -C "$origin" tag v2026.10.20.1 HEAD~1
full=$TMPDIR/full; git clone -q "file://$origin" "$full"
hook() { env PATH="$TMPDIR/bin:$PATH" TWES_BOOTSTRAP_DIR="$1" TWES_BOOTSTRAP_ROOT="${ROOT:-$full}" TWES_CA_WAIT="${CA_WAIT:-0}" CCS_EXTRA_CA_FILE= "${@:2}" bash "$HOOK" 2>&1; }
# Waits for the detached run to say how it ended, at most 20 seconds.
ended() { local i; for ((i = 0; i < 200; i++)); do [[ -e "$1/twes-in-bootstrap.done" || -e "$1/twes-in-bootstrap.failed" ]] && return 0; sleep 0.1; done; return 1; }
echo "cloud setup hook"

d=$(case_dir); rm -f "$TMPDIR/make.calls"
out=$(hook "$d" CLAUDE_CODE_REMOTE=); status=$?
check "off the cloud it does nothing and exits 0" "$status" 0 "$out" ""
[[ ! -e "$TMPDIR/make.calls" && -z "$(ls -A "$d")" ]]; check "  no make started, no file left" $? 0 "$(ls -A "$d")" ""

d=$(case_dir); rm -f "$TMPDIR/make.calls"
start=$SECONDS; out=$(hook "$d" CLAUDE_CODE_REMOTE=true MAKE_PAUSE=3); status=$?; took=$((SECONDS - start))
check "on the cloud it exits 0" "$status" 0 "$out" ""
((took < 2)); check "  and returns at once, the slow steps detached (took ${took}s, make pauses 3)" $? 0 "$out" ""
[[ -d "$d/twes-in-bootstrap.running" ]]; check "  saying it runs while it does" $? 0 "$(ls -A "$d")" ""
ended "$d"; check "  then says it ended" $? 0 "$(ls -A "$d")" ""
[[ -e "$d/twes-in-bootstrap.done" && ! -e "$d/twes-in-bootstrap.running" ]]; check "  done, and no longer running" $? 0 "$(ls -A "$d")" ""
check "  having made bootstrap, then the browsers" 0 0 "$(cat "$TMPDIR/make.calls")" "make bootstrap CA=none
make playwright-browser CA=none"
check "  every word of it in the log" 0 0 "$(cat "$d/twes-in-bootstrap.log")" "made playwright-browser"

d=$(case_dir); rm -f "$TMPDIR/make.calls"
out=$(hook "$d" CLAUDE_CODE_REMOTE=true MAKE_STATUS=2); ended "$d"
[[ -e "$d/twes-in-bootstrap.failed" && ! -e "$d/twes-in-bootstrap.done" ]]; check "a failed step says failed, never done" $? 0 "$(ls -A "$d")" ""
check "  with the exit it failed with" 0 0 "$(cat "$d/twes-in-bootstrap.failed")" "2"
check "  and stops there: the browsers wait for a bootstrap that worked" 0 0 "$(wc -l < "$TMPDIR/make.calls")" "1"

d=$(case_dir); rm -f "$TMPDIR/make.calls"
hook "$d" CLAUDE_CODE_REMOTE=true MAKE_PAUSE=2 >/dev/null; out=$(hook "$d" CLAUDE_CODE_REMOTE=true MAKE_PAUSE=2); ended "$d"
check "a second start while one runs starts nothing more" 0 0 "$(grep -c bootstrap "$TMPDIR/make.calls")" "1"

d=$(case_dir); rm -f "$TMPDIR/make.calls"; touch "$d/twes-in-bootstrap.done"
out=$(hook "$d" CLAUDE_CODE_REMOTE=true); ended "$d"
check "a start after one ended runs again, the old outcome cleared first" 0 0 "$(cat "$TMPDIR/make.calls" 2>/dev/null)" "make bootstrap"

# The proxy's CA is written by the harness after setup: the run waits for it, then hands it to make.
d=$(case_dir); rm -f "$TMPDIR/make.calls"
out=$(CA_WAIT=10 hook "$d" CLAUDE_CODE_REMOTE=true CCS_EXTRA_CA_FILE="$d/ca.crt"); sleep 1; printf 'a CA\n' > "$d/ca.crt"; ended "$d"
check "it waits for the proxy's CA the harness writes after setup, then hands it over" 0 0 "$(cat "$TMPDIR/make.calls")" "make bootstrap CA=$d/ca.crt"
d=$(case_dir); rm -f "$TMPDIR/make.calls"
out=$(CA_WAIT=1 hook "$d" CLAUDE_CODE_REMOTE=true CCS_EXTRA_CA_FILE="$d/never.crt"); ended "$d"
check "  and goes on without it when none comes" 0 0 "$(cat "$TMPDIR/make.calls")" "make bootstrap CA=none"
check "  saying so in its log" 0 0 "$(cat "$d/twes-in-bootstrap.log")" "no proxy CA"

# A cloud clone is shallow and holds no tag: the gates that read the history refuse it, so the run fetches it whole first.
d=$(case_dir); rm -f "$TMPDIR/make.calls"; shallow=$(mktemp -d)/clone; git clone -q --depth 1 --no-tags "file://$origin" "$shallow"
out=$(ROOT=$shallow hook "$d" CLAUDE_CODE_REMOTE=true); ended "$d"
check "a shallow clone is fetched whole, with its tags, before the bootstrap" 0 0 "$(git -C "$shallow" rev-parse --is-shallow-repository) $(git -C "$shallow" tag -l)" "false v2026.10.20.1"
check "  and says so in its log" 0 0 "$(cat "$d/twes-in-bootstrap.log")" "the whole history fetched"
d=$(case_dir); out=$(hook "$d" CLAUDE_CODE_REMOTE=true); ended "$d"
! grep -q 'whole history' "$d/twes-in-bootstrap.log"; check "  a whole clone is left as it is" $? 0 "$(cat "$d/twes-in-bootstrap.log")" ""

# A run killed with its machine (a worker restart, an environment restored from its cache) leaves its mark behind: it
# is read as dead by its process and the machine's boot, never waited for, and the next start runs again.
boot=$(cat /proc/sys/kernel/random/boot_id)
sleep 0 & dead=$!; wait "$dead"
d=$(case_dir); rm -f "$TMPDIR/make.calls"; mkdir "$d/twes-in-bootstrap.running"; echo "$dead $boot" > "$d/twes-in-bootstrap.running/pid"
out=$(hook "$d" CLAUDE_CODE_REMOTE=true); ended "$d"
check "a mark left by a run that died is cleared and the run starts again" 0 0 "$(cat "$TMPDIR/make.calls" 2>/dev/null)" "make bootstrap"
check "  saying so in its log" 0 0 "$(cat "$d/twes-in-bootstrap.log")" "died"
d=$(case_dir); rm -f "$TMPDIR/make.calls"; mkdir "$d/twes-in-bootstrap.running"; echo "$$ another-boot" > "$d/twes-in-bootstrap.running/pid"
out=$(hook "$d" CLAUDE_CODE_REMOTE=true); ended "$d"
check "  and one from another boot, whatever its number" 0 0 "$(cat "$TMPDIR/make.calls" 2>/dev/null)" "make bootstrap"
d=$(case_dir); rm -f "$TMPDIR/make.calls"; mkdir "$d/twes-in-bootstrap.running"; echo "$$ $boot" > "$d/twes-in-bootstrap.running/pid"
out=$(hook "$d" CLAUDE_CODE_REMOTE=true); sleep 1
[[ ! -e "$TMPDIR/make.calls" ]]; check "  a live one is left to run" $? 0 "$(cat "$TMPDIR/make.calls" 2>/dev/null)" ""

echo "cloud setup hook: $pass passed, $fail failed"
((fail == 0))
