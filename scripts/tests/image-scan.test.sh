#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The weekly image scan (scripts/security/image-scan.sh), its Trivy a stand-in docker that writes down how it was run
# and answers with DOCKER_STATUS: the cache a person names is handed to docker as a folder, never as a volume's name,
# and a scan that could not run is told as such, never as a finding to fix.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/scripts/security/image-scan.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

mkdir -p "$TMPDIR/bin" "$TMPDIR/work"
printf '#!/usr/bin/env bash\nprintf "%%s\\n" "$@" >> "%s/docker.args"\nexit "${DOCKER_STATUS:-0}"\n' "$TMPDIR" > "$TMPDIR/bin/docker"
chmod +x "$TMPDIR/bin/docker"
run() { rm -f "$TMPDIR/docker.args"; (cd "$TMPDIR/work" && env PATH="$TMPDIR/bin:$PATH" "$@" bash "$SCRIPT" ghcr.io/owner 2>&1); }
mounts() { grep -A1 -x -- '-v' "$TMPDIR/docker.args" | grep -v -x -- '-v' | grep -v -x -- '--' | grep ':/root/.cache/trivy$' | sort -u; }
echo "image scan"

out=$(run TRIVY_CACHE=var/cache/trivy); status=$?
check "a clean scan says so" "$status" 0 "$out" "image-scan: OK"
check "  a cache named from where it runs reaches docker as that folder" 0 0 "$(mounts)" "$TMPDIR/work/var/cache/trivy:/root/.cache/trivy"
scans=$(grep -c -x 'image' "$TMPDIR/docker.args")
((scans >= 4)); check "  our three images and every one a shop starts by digest are scanned" $? 0 "$scans scans" ""
out=$(run TRIVY_CACHE="$TMPDIR/cache"); check "an absolute cache is handed over as it is" 0 0 "$(mounts)" "$TMPDIR/cache:/root/.cache/trivy"

out=$(run DOCKER_STATUS=3); check "a finding fails the scan" $? 1 "$out" "a fixable CRITICAL or HIGH"
check "  and says how to settle it" 0 0 "$out" "Move the image to a fixed release"

out=$(run DOCKER_STATUS=125); check "a scan that could not run fails too" $? 1 "$out" "Trivy could not scan it (exit 125)"
[[ "$out" != *"Move the image to a fixed release"* ]]; check "  and is never told as a finding to fix" $? 0 "$out" ""
check "  but as a scan to run again once its error above is settled" 0 0 "$out" "could not run"

echo "image scan: $pass passed, $fail failed"
((fail == 0))
