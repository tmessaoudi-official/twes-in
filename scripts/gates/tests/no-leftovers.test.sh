#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
RUN=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/no-leftovers.sh
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
fake() { local f="$TMPDIR/$1.sh"; printf '%s\n' "$2" > "$f"; echo "$f"; }
# The runner's own directory is the only thing it may make: none of its runs leaves one behind.
none_left() { [[ -z "$(find "$TMPDIR" -mindepth 1 -maxdepth 1 ! -name '*.sh')" ]] && echo none || echo some; }

out=$(bash "$RUN" "$(fake leaks 'd=$(mktemp -d); touch "$d/x"; mktemp >/dev/null; echo ran')" 2>&1); s=$?
check "a passing test that leaves a directory fails" "$s" 1 "$out" "LEFT IN TMPDIR"
check "the leftovers are named, not only counted" 0 0 "$out" "tmp."
check "the test itself ran" 0 0 "$out" "ran"
check "what it left is removed" 0 0 "$(none_left)" "none"

out=$(bash "$RUN" "$(fake clean 'd=$(mktemp -d); rm -rf "$d"; echo fine')" 2>&1); s=$?
check "a test that cleans up passes" "$s" 0 "$out" "fine"

out=$(bash "$RUN" "$(fake fails 'echo broken; exit 3')" 2>&1); s=$?
check "a failing test keeps its exit code" "$s" 3 "$out" "broken"

out=$(bash "$RUN" "$(fake both 'mktemp -d >/dev/null; exit 4')" 2>&1); s=$?
check "a failing test that leaks keeps its code and names the leak" "$s" 4 "$out" "LEFT IN TMPDIR"
check "nothing is left after a failing run either" 0 0 "$(none_left)" "none"

out=$(bash "$RUN" "$(fake args 'echo "got $1"')" two 2>&1); s=$?
check "arguments reach the test" "$s" 0 "$out" "got two"

out=$(bash "$RUN" 2>&1); s=$?
check "no test named is a usage error" "$s" 2 "$out" "usage"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
