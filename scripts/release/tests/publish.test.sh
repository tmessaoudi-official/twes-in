#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# publish.sh against a stand-in docker that records every call: what it refuses, the order it pushes in, when it moves
# :master and what it writes. The real round trip through a registry is make staging-check's (scripts/staging/).
set -uo pipefail
# None of the machine's own git settings: a developer's commit.gpgsign would sign the fixtures with a real key.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/publish.sh
REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# A checkout with two commits, the files publish.sh reads, and a docker that answers from the environment:
# FIND_OUTPUT what an image search finds, MASTER_REV the revision :master names (none: no :master yet).
fixture() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/repo/scripts/release" "$d/bin"
  cp "$SCRIPT" "$d/repo/scripts/release/publish.sh"
  cp "$REPO/scripts/release/publish.compose.yaml" "$d/repo/scripts/release/" 2>/dev/null || true
  mkdir -p "$d/repo/scripts"
  printf '#!/usr/bin/env bash\necho API_BUILD_VERSION=2026.01.01.1\necho WEB_BUILD_VERSION=2026.01.01.1\n' > "$d/repo/scripts/build-version.sh"
  : > "$d/repo/compose.yaml"; : > "$d/repo/compose.prod.yaml"; : > "$d/repo/compose.backup.yaml"
  git -C "$d/repo" init -q -b master && git -C "$d/repo" -c user.name=t -c user.email=t@t add -A \
    && git -C "$d/repo" -c user.name=t -c user.email=t@t commit -qm one \
    && git -C "$d/repo" -c user.name=t -c user.email=t@t commit -q --allow-empty -m two
  cat > "$d/bin/docker" <<'EOF'
#!/usr/bin/env bash
echo "docker $*" >> "$CALLS"
case "$1" in
  run) printf '%s' "${FIND_OUTPUT:-}" ;;
  pull) [[ "${@: -1}" == *:master && -n "${MASTER_REV:-}" ]] || { echo "manifest unknown" >&2; exit 1; } ;;
  inspect)
    if [[ "$*" == *RepoDigests* ]]; then ref=${@: -1}; printf '%s@sha256:%s\n' "${ref%:*}" "$(printf '%s' "${ref%:*}" | sha256sum | cut -c1-64)"
    else printf '%s\n' "${MASTER_REV:-}"; fi ;;
esac
exit 0
EOF
  chmod +x "$d/bin/docker"
  echo "$d"
}
publish() { # dir, then extra environment
  local d=$1; shift
  (cd "$d/repo" && env CALLS="$d/calls" PATH="$d/bin:$PATH" "$@" bash scripts/release/publish.sh registry.invalid/twes "$d/release.env" 2>&1)
}

d=$(fixture); out=$(publish "$d"); code=$?
head=$(git -C "$d/repo" rev-parse HEAD)
check "a clean checkout is built, pushed and written down" "$code" 0 "$(cat "$d/release.env" 2>/dev/null)" "TWES_REVISION=$head"
check "the release names each part by its digest" "$code" 0 "$(cat "$d/release.env" 2>/dev/null)" "TWES_DATABASE_IMAGE=registry.invalid/twes/twes-in-database@sha256:"
calls=$(cat "$d/calls")
last_sha=$(grep -n "push -q registry.invalid/twes/twes-in-.*:sha-" <<<"$calls" | tail -1 | cut -d: -f1)
first_master=$(grep -n "push -q registry.invalid/twes/twes-in-.*:master" <<<"$calls" | head -1 | cut -d: -f1)
[[ -n "$last_sha" && -n "$first_master" && "$last_sha" -lt "$first_master" ]]; check "every sha tag is pushed before :master moves" "$?" 0 "$calls" "push"
check "with no :master yet, :master is moved" "$code" 0 "$out" "moved :master to $head"
check "the build carries the revision the images are labelled with" 0 0 "$calls" "publish.compose.yaml"

d=$(fixture); echo stray > "$d/repo/notes.txt"
out=$(publish "$d"); check "an untracked file refuses the publish" "$?" 1 "$out" "notes.txt"
[[ ! -e "$d/calls" ]] || ! grep -q "push" "$d/calls"; check "  and nothing is pushed" "$?" 0 "" ""

d=$(fixture); out=$(publish "$d" FIND_OUTPUT=/srv/api/.env.local); code=$?
check "an image holding a .env.local refuses the publish" "$code" 1 "$out" "/srv/api/.env.local"
! grep -q "push" "$d/calls"; check "  and nothing is pushed" "$?" 0 "" ""

d=$(fixture); older=$(git -C "$d/repo" rev-parse HEAD~1)
out=$(publish "$d" MASTER_REV="$older"); check "a newer commit moves :master forward" "$?" 0 "$out" "moved :master to"

d=$(fixture); git -C "$d/repo" checkout -q HEAD~1; newer=$(git -C "$d/repo" rev-parse master)
out=$(publish "$d" MASTER_REV="$newer"); code=$?
check "an older commit is published but leaves :master where it is" "$code" 0 "$out" "kept :master at $newer"
! grep -q ":master$" <(grep "push" "$d/calls"); check "  and pushes no :master" "$?" 0 "$(cat "$d/calls")" ""
[[ "$out" != *"::warning"* ]]; check "  and warns of nothing: a late run is no fault" "$?" 0 "$out" ""

# A rewritten history (the re-signing of 2026-10-24) leaves :master on a commit master no longer follows; it is kept,
# and the run says so where it is seen, with the way to move it, rather than a green job no one reads.
d=$(fixture); old=$(git -C "$d/repo" rev-parse HEAD)
git -C "$d/repo" -c user.name=t -c user.email=t@t commit -q --amend --allow-empty -m "two, written again"
out=$(publish "$d" MASTER_REV="$old"); code=$?
check "a :master on a commit master no longer follows is kept" "$code" 0 "$out" "kept :master at $old"
check "  and the run warns of it, naming the way to move it" "$code" 0 "$out" "::warning title="
check "  with make point-master" "$code" 0 "$out" "make point-master COMMIT="

echo "publish.test: $pass passed, $fail failed"
((fail == 0))
