#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# staging-follow.sh against a stand-in docker and a stand-in deploy.sh, in a checkout cloned from a local origin: when it
# deploys, what it hands deploy.sh, and when it waits, holds or refuses.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/staging-follow.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t

# An origin holding two master commits, a staging checkout cloned from it at the first, the follower and a deploy.sh that
# writes down how it was called and at which commit; a docker whose :master images say they were built from API_REV,
# WEB_REV and DATABASE_REV (origin's master when unset).
fixture() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/src/scripts/deploy" "$d/bin" "$d/etc"
  cp "$SCRIPT" "$d/src/scripts/deploy/"
  printf '#!/usr/bin/env bash\necho "deploy $* at $(git -C "$(dirname "$0")/../.." rev-parse HEAD)" >> "$CALLS"\ncp "$3" "$CALLS.release"\n' > "$d/src/scripts/deploy/deploy.sh"
  git -C "$d/src" init -q -b master && git -C "$d/src" add -A && git -C "$d/src" commit -qm one
  git -C "$d/src" commit -q --allow-empty -m two
  git clone -q --bare "$d/src" "$d/origin.git"
  git clone -q "$d/origin.git" "$d/staging" && git -C "$d/staging" checkout -q --detach HEAD~1
  git -C "$d/src" commit -q --allow-empty -m local-only
  printf 'DEPLOY_ENV=staging\n' > "$d/etc/deploy.env"; chmod 600 "$d/etc/deploy.env"
  cat > "$d/bin/docker" <<'EOF'
#!/usr/bin/env bash
echo "docker $*" >> "$CALLS"
ref=${@: -1}
part=${ref##*/twes-in-}; part=${part%%[:@]*}
case "$1" in
  image|inspect)
    if [[ "$*" == *RepoDigests* ]]; then printf '%s@sha256:%s\n' "${ref%:*}" "$(printf '%s' "${ref%:*}" | sha256sum | cut -c1-64)"
    else rev_var="${part^^}_REV"; printf '%s\n' "${!rev_var:-$MASTER}"; fi ;;
esac
exit 0
EOF
  chmod +x "$d/bin/docker"
  echo "$d"
}
follow() { # dir, then extra environment
  local d=$1; shift
  (cd "$d" && env CALLS="$d/calls" PATH="$d/bin:$PATH" MASTER="$(git -C "$d/origin.git" rev-parse master)" "$@" \
    bash "$d/staging/scripts/deploy/staging-follow.sh" registry.invalid/o "$d/etc/deploy.env" 2>&1)
}
deployed() { grep "^deploy " "$1/calls" 2>/dev/null; }
history() { mkdir -p "$1/etc/releases"; echo "2026-10-13T00:00:00Z $2 $3 staging" >> "$1/etc/releases/history"; }

d=$(fixture); master=$(git -C "$d/origin.git" rev-parse master)
out=$(follow "$d"); check "a new commit on :master is deployed" $? 0 "$out" "$master"
check "  through deploy.sh --staging, checked out at that commit" 0 0 "$(deployed "$d")" "deploy --staging $d/etc/deploy.env"
check "  at that commit" 0 0 "$(deployed "$d")" "at $master"
check "  with the release the registry gave, by digest" 0 0 "$(cat "$d/calls.release" 2>&1)" "TWES_DATABASE_IMAGE=registry.invalid/o/twes-in-database@sha256:"
check "  naming the commit" 0 0 "$(cat "$d/calls.release" 2>&1)" "TWES_REVISION=$master"

d=$(fixture); first=$(git -C "$d/staging" rev-parse HEAD)
out=$(follow "$d" WEB_REV="$first"); check "while :master is moving, its three images disagree and it waits" $? 0 "$out" "waiting"
[[ -z "$(deployed "$d")" ]]; check "  deploying nothing" $? 0 "" ""

d=$(fixture); history "$d" deployed "$(git -C "$d/origin.git" rev-parse master)"
out=$(follow "$d"); check "what already runs is left running" $? 0 "$out" "already"
[[ -z "$(deployed "$d")" ]]; check "  deploying nothing" $? 0 "" ""

d=$(fixture); history "$d" failed "$(git -C "$d/origin.git" rev-parse master)"
out=$(follow "$d"); check "a commit that failed to start is not tried again on every tick" $? 0 "$out" "failed"
[[ -z "$(deployed "$d")" ]]; check "  deploying nothing" $? 0 "" ""

d=$(fixture); mkdir -p "$d/etc/releases"; echo "rolled back, investigating" > "$d/etc/releases/hold"
out=$(follow "$d"); check "a hold keeps a rollback in place" $? 0 "$out" "rolled back, investigating"
[[ -z "$(deployed "$d")" ]]; check "  deploying nothing" $? 0 "" ""

d=$(fixture); local_only=$(git -C "$d/src" rev-parse HEAD)
out=$(follow "$d" MASTER="$local_only"); check "images of a commit origin's master does not hold are refused" $? 1 "$out" "origin/master"
[[ -z "$(deployed "$d")" ]]; check "  deploying nothing" $? 0 "" ""

d=$(fixture); echo x > "$d/staging/stray"
out=$(follow "$d"); check "a checkout someone changed is refused" $? 1 "$out" "stray"

d=$(fixture)
out=$(cd "$d" && bash "$d/staging/scripts/deploy/staging-follow.sh" registry.invalid/o 2>&1); check "the registry and the values must be named" $? 2 "$out" "Usage"

echo "staging-follow.test: $pass passed, $fail failed"
((fail == 0))
