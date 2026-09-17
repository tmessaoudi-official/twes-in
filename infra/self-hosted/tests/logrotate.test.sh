#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The shipped logrotate configuration, run for real against a copy pointed at a temporary directory: it parses, it
# rotates every channel's file, and a writer that kept its file open (as the API's PHP workers do) still writes to
# the live file afterwards, not to the rotated one.
set -uo pipefail
CONF=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/logrotate.conf
pass=0; fail=0
check() { if [[ "$2" == "$3" ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (got '$2', wanted '$3')"; fi; }
command -v logrotate >/dev/null || { echo "logrotate is not installed"; exit 1; }
echo "self-hosted logrotate"

dir=$(mktemp -d)
sed "s#/var/log/twes-in/#$dir/#" "$CONF" > "$dir/twes-in.conf"
check "the copy points at the temporary directory" "$(grep -c "^$dir/\*\.log {" "$dir/twes-in.conf")" 1

out=$(logrotate -d -s "$dir/state" "$dir/twes-in.conf" 2>&1); code=$?
check "logrotate reads the configuration without an error" "$code" 0
check "and names no error" "$(grep -ci 'error' <<<"$out")" 0

printf '{"channel":"request","message":"before"}\n' > "$dir/request.log"
printf '{"channel":"doctrine","message":"before"}\n' > "$dir/doctrine.log"
exec 3>>"$dir/request.log"
logrotate -f -s "$dir/state" "$dir/twes-in.conf"; code=$?
check "a forced rotation succeeds" "$code" 0
check "each channel's file was rotated" "$(find "$dir" -name '*.log-*' | wc -l | tr -d ' ')" 2
check "the rotated request file holds what was written before" "$(cat "$dir"/request.log-* | grep -c before)" 1
printf '{"channel":"request","message":"after"}\n' >&3
exec 3>&-
check "a writer that kept the file open writes to the live file" "$(grep -c after "$dir/request.log")" 1
check "and not to the rotated one" "$(cat "$dir"/request.log-* | grep -c after)" 0

rm -rf "$dir"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
