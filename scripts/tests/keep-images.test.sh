#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# KEEP_IMAGES=1: the toolchain's images already built are kept as they are, through every sub-make a target calls.
# BuildKit asks Docker Hub about a base's tag on every build, even one its cache answers, and Docker Hub answers 429 to
# anonymous pulls in a cloud session: `make -o tools-image gate` did not help, as make hands no -o to the six sub-makes
# `make gate` calls, and the gate died in two seconds. A variable on make's command line reaches every sub-make. It is
# said each time, never silent, and refused where there is no image to keep. Read with `make -n`, which runs no build
# (it does run the recursive $(MAKE) lines, which is what this reads).
set -uo pipefail
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /' | head -n 20; fi; }
plan() { make --no-print-directory -n -C "$ROOT" "$@" 2>&1; }
echo "keep the images"

out=$(plan gate KEEP_IMAGES=1)
! grep -qE 'build -q tools|tools\.Dockerfile' <<<"$out"; check "the gate's six sub-makes build no toolchain image when told to keep them" $? 0 "$out" ""
check "  and say each one is kept, never silently" 0 0 "$out" "twes-in-tools: kept as built"
check "  the web one too" 0 0 "$out" "twes-in-web-tools: kept as built"
check "  refusing where there is no image to keep" 0 0 "$out" "no image"

out=$(plan gate)
check "without it, the gate builds the PHP toolchain image as before" 0 0 "$out" "docker compose --profile tools build -q tools"
check "  and the Node one" 0 0 "$out" "infra/web/tools.Dockerfile"

out=$(plan rc-lane KEEP_IMAGES=1)
! grep -q 'rc.Dockerfile' <<<"$out"; check "the RC lane's image is kept too" $? 0 "$out" ""
check "  and says so" 0 0 "$out" "twes-in-tools-rc: kept as built"

echo "keep the images: $pass passed, $fail failed"
((fail == 0))
