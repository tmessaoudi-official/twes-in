#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/compose-log-rotation.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
project() { local d; d=$(mktemp -d); printf '%s\n' "$1" > "$d/compose.yaml"; echo "$d"; }
echo "compose-log-rotation gate"
d=$(project 'name: t
x-logging: &logging
  driver: json-file
  options:
    max-size: 10m
    max-file: "5"
services:
  a:
    image: busybox
    logging: *logging
  b:
    image: busybox
    logging: *logging')
out=$(bash "$GATE" --root "$d" 2>&1); check "every service rotated through the shared anchor passes" $? 0 "$out" "OK — 2 services"
d=$(project 'name: t
x-logging: &logging
  driver: json-file
  options:
    max-size: 10m
    max-file: "5"
services:
  a:
    image: busybox
    logging: *logging
  worker:
    image: busybox')
out=$(bash "$GATE" --root "$d" 2>&1); check "a service added without the anchor is named" $? 1 "$out" "worker"
d=$(project 'name: t
services:
  a:
    image: busybox
    logging:
      driver: json-file
      options:
        max-size: 50m
        max-file: "5"')
out=$(bash "$GATE" --root "$d" 2>&1); check "a different size is refused, not only a missing one" $? 1 "$out" "a"
d=$(project 'name: t
services:
  a:
    image: busybox
    logging:
      driver: local
      options:
        max-size: 10m
        max-file: "5"')
out=$(bash "$GATE" --root "$d" 2>&1); check "another driver is refused" $? 1 "$out" "a"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
