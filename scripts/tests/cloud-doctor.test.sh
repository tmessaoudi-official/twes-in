#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# make cloud-doctor (scripts/cloud/doctor.sh): every difference a cloud run can meet against the computer is checked
# and said with its fix, never left to turn up as a red that reads like the code's (docs/SPEC.md § 7, a cloud session
# never silently worse). docker, curl and make are stand-ins; git is real, on repositories of the test's own.
set -uo pipefail
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
DOCTOR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/scripts/cloud/doctor.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
export GIT_AUTHOR_NAME=Dev GIT_AUTHOR_EMAIL=dev@example.test GIT_COMMITTER_NAME=Dev GIT_COMMITTER_EMAIL=dev@example.test

mkdir -p "$TMPDIR/bin"
cat > "$TMPDIR/bin/docker" <<'EOF'
#!/usr/bin/env bash
case "$1" in
  info) exit "${DOCKER_INFO:-0}" ;;
  image) exit "${DOCKER_IMAGES:-0}" ;;
esac
EOF
cat > "$TMPDIR/bin/curl" <<'EOF'
#!/usr/bin/env bash
[[ -n "${CURL_FAIL:-}" ]] && { echo "curl: (6) Could not resolve host" >&2; exit 6; }
case "$*" in
  *auth.docker.io*) echo '{"token":"t"}' ;;
  *registry-1.docker.io*) printf 'HTTP/1.1 200 OK\r\nratelimit-limit: 100;w=21600\r\nratelimit-remaining: %s;w=21600\r\n\r\n' "${HUB_LEFT:-90}" ;;
esac
EOF
cat > "$TMPDIR/bin/make" <<'EOF'
#!/usr/bin/env bash
printf '%b\n' "${PROBES-probe git ok\nprobe curl ok\nprobe node ok}"
EOF
chmod +x "$TMPDIR/bin/"*

# The repository a cloud session holds once set up: the whole history, a release tag, the developer as author, the
# bootstrap's files.
origin=$TMPDIR/origin; git init -q -b master "$origin"
for n in 1 2 3; do echo "$n" > "$origin/f"; git -C "$origin" add f; git -C "$origin" commit -qm "c$n"; done
git -C "$origin" tag v2026.10.30.1 HEAD~1
tree() {
  local d; d=$(mktemp -d)/clone; git clone -q "file://$origin" "$d"
  git -C "$d" config user.name Dev; git -C "$d" config user.email dev@example.test
  mkdir -p "$d/api/vendor" "$d/web/node_modules" "$d/web/src/app/api" "$d/web/src/generated" "$d/var/cache/ms-playwright/chromium-1"
  touch "$d/api/vendor/autoload.php" "$d/web/node_modules/.twes-installed" "$d/web/src/app/api/types.gen.ts" "$d/web/src/generated/icons.woff2"
  echo "$d"
}
printf 'a CA\n' > "$TMPDIR/ca.crt"
doctor() { env PATH="$TMPDIR/bin:$PATH" CLAUDE_CODE_REMOTE=true CCS_EXTRA_CA_FILE="$TMPDIR/ca.crt" TWES_BOOTSTRAP_DIR="$TMPDIR" "${@:2}" bash "$DOCTOR" --root "$1" 2>&1; }
echo "cloud doctor"

d=$(tree); out=$(doctor "$d"); check "a session set up as the computer is passes every check" $? 0 "$out" "cloud-doctor: OK — 8 checks"
check "  and says each one" 0 0 "$out" "ok   Docker Hub: 90 pulls left"

s=$(mktemp -d)/clone; git clone -q --depth 1 --no-tags "file://$origin" "$s"; git -C "$s" config user.email dev@example.test
out=$(doctor "$s"); check "a shallow clone is refused with its fix" $? 1 "$out" "git fetch --unshallow --tags origin"

d=$(tree); git -C "$d" tag -d v2026.10.30.1 >/dev/null
out=$(doctor "$d"); check "a clone with no release tag is refused: three gates would read nothing" $? 1 "$out" "no release tag"

d=$(tree); git -C "$d" config user.email noreply@anthropic.com
out=$(doctor "$d"); check "the harness's author is refused, naming the one the history carries" $? 1 "$out" "dev@example.test"

d=$(tree); out=$(doctor "$d" CCS_EXTRA_CA_FILE="$TMPDIR/absent.crt"); check "a cloud session without the proxy's CA is refused" $? 1 "$out" "proxy CA"
d=$(tree); out=$(doctor "$d" CLAUDE_CODE_REMOTE= CCS_EXTRA_CA_FILE=); check "  off the cloud no CA is asked for" $? 0 "$out" "not a cloud session"

d=$(tree); out=$(doctor "$d" DOCKER_INFO=1); check "Docker not answering is refused with its fix" $? 1 "$out" "dockerd"

d=$(tree); rm "$d/api/vendor/autoload.php"; out=$(doctor "$d"); check "a bootstrap not done is refused, naming what is missing" $? 1 "$out" "api/vendor"
mkdir "$TMPDIR/twes-in-bootstrap.running"; out=$(doctor "$d"); status=$?; rmdir "$TMPDIR/twes-in-bootstrap.running"
check "  and says to wait while the setup's run still goes" "$status" 1 "$out" "still running"

d=$(tree); out=$(doctor "$d" HUB_LEFT=0); check "no pull left at Docker Hub is refused before a build meets its 429" $? 1 "$out" "0 pulls left"
d=$(tree); out=$(doctor "$d" CURL_FAIL=1); check "Docker Hub not asked is said, never read as fine" $? 1 "$out" "could not ask Docker Hub"

d=$(tree); out=$(doctor "$d" PROBES='probe git fail\nprobe curl ok\nprobe node ok'); check "a toolchain tool refusing the proxy's certificate is named" $? 1 "$out" "git"
d=$(tree); out=$(doctor "$d" PROBES=''); check "  probes that say nothing are refused, never read as passed" $? 1 "$out" "said nothing"
d=$(tree); out=$(doctor "$d" DOCKER_IMAGES=1); check "missing toolchain images are refused, and the probes not run on them" $? 1 "$out" "make bootstrap"

echo "cloud doctor: $pass passed, $fail failed"
((fail == 0))
