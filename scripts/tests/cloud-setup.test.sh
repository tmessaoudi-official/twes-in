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
hook() { env PATH="$TMPDIR/bin:$PATH" TWES_BOOTSTRAP_DIR="$1" TWES_CA_WAIT="${CA_WAIT:-0}" CCS_EXTRA_CA_FILE= "${@:2}" bash "$HOOK" 2>&1; }
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

echo "cloud setup hook: $pass passed, $fail failed"
((fail == 0))
