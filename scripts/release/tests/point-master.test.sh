#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# point-master.sh against a stand-in docker: which commit :master is moved to, by copying the per-commit images in the
# registry, and what it refuses before moving anything.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR
trap 'rm -rf "$TMPDIR"' EXIT
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/point-master.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1

# A checkout of two master commits, and a docker answering from the environment: LABEL_REV the commit every sha image
# says it was built from (the commit asked for when unset), MASTER_REV what :master says, MISSING a part the registry
# has no image of, REFUSE_CREATE a part whose :master the registry will not take.
fixture() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/repo/scripts/release" "$d/bin"
  cp "$SCRIPT" "$d/repo/scripts/release/"
  git -C "$d/repo" init -q -b master && git -C "$d/repo" add -A && git -C "$d/repo" commit -qm one
  echo x > "$d/repo/x" && git -C "$d/repo" add -A && git -C "$d/repo" commit -qm two
  git -C "$d/repo" update-ref refs/remotes/origin/master HEAD
  cat > "$d/bin/docker" <<'EOF'
#!/usr/bin/env bash
echo "docker $*" >> "$CALLS"
ref=${@: -1}
case "$1" in
  pull) [[ -n "${MISSING:-}" && "$ref" == *"twes-in-$MISSING:sha-"* ]] && { echo "manifest unknown" >&2; exit 1; } ;;
  image) if [[ "$ref" == *:master ]]; then printf '%s\n' "${MASTER_REV:-}"; else printf '%s\n' "${LABEL_REV:-${ref##*:sha-}}"; fi ;;
  buildx) [[ -n "${REFUSE_CREATE:-}" && "$ref" == *"twes-in-$REFUSE_CREATE:"* ]] && { echo "denied" >&2; exit 1; } ;;
esac
exit 0
EOF
  chmod +x "$d/bin/docker"
  echo "$d"
}
point() { # dir, commit, then extra environment
  local d=$1 commit=$2; shift 2
  (cd "$d/repo" && env CALLS="$d/calls" PATH="$d/bin:$PATH" "$@" bash scripts/release/point-master.sh registry.invalid/o/ "$commit" 2>&1)
}
created() { if [[ -f "$1/calls" ]]; then grep -c "imagetools create" "$1/calls"; else echo 0; fi; }

d=$(fixture); first=$(git -C "$d/repo" rev-parse HEAD~1); second=$(git -C "$d/repo" rev-parse HEAD)
out=$(point "$d" "${first:0:8}" MASTER_REV="$second"); check "a master commit is pointed at by name, back from a later one" $? 0 "$out" "moved from $second to $first"
check "  each part's :master copied from that commit's own image" 0 0 "$(cat "$d/calls")" "imagetools create -t registry.invalid/o/twes-in-database:master registry.invalid/o/twes-in-database:sha-$first"
check "  all three" 0 0 "$(created "$d")" "3"
! grep -qE "^docker (push|tag|build) " "$d/calls"; check "  nothing built, tagged or pushed from this machine" $? 0 "$(cat "$d/calls")" ""

d=$(fixture); echo y > "$d/repo/y"; git -C "$d/repo" add -A; git -C "$d/repo" commit -qm local
out=$(point "$d" HEAD); check "a commit master does not hold is refused" $? 1 "$out" "origin/master"
check "  and nothing moves" 0 0 "$(created "$d")" "0"

d=$(fixture)
out=$(point "$d" nosuchcommit); check "a name that is no commit is refused" $? 1 "$out" "names no commit"

d=$(fixture)
out=$(point "$d" HEAD MISSING=database); check "a commit whose image was never published is refused" $? 1 "$out" "twes-in-database:sha-"
check "  before any part moves" 0 0 "$(created "$d")" "0"

d=$(fixture)
out=$(point "$d" HEAD LABEL_REV="$(printf 'c%.0s' {1..40})"); check "an image built from another commit is refused" $? 1 "$out" "built from cccccccc"
check "  and nothing moves" 0 0 "$(created "$d")" "0"

d=$(fixture)
out=$(point "$d" HEAD REFUSE_CREATE=web); check "a registry refusing :master says so, naming the part" $? 1 "$out" "did not take :master for web"

d=$(fixture)
out=$(cd "$d/repo" && bash scripts/release/point-master.sh registry.invalid/o 2>&1); check "the registry and the commit must be named" $? 2 "$out" "Usage"

echo "point-master.test: $pass passed, $fail failed"
((fail == 0))
