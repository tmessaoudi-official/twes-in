#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# tag-release.sh against a stand-in docker: which commits it tags, what the tag's message holds, what it refuses, and that
# it never pushes.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR
# Every case's fixture is made here and goes with it, the key's agents stopped first.
trap 'GNUPGHOME="$TMPDIR/gnupg" gpgconf --kill all 2>/dev/null; rm -rf "$TMPDIR"' EXIT
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/tag-release.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
# None of the machine's own git settings: a developer's commit.gpgsign would sign the fixtures with a real key.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
# The developer's signing key, a throwaway one with no passphrase, in a keyring of this test's own.
export GNUPGHOME=$TMPDIR/gnupg; mkdir -m 700 "$GNUPGHOME"
gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-gen-key 'twes release <release@twes.test>' ed25519 sign never
KEY=$(gpg --batch --with-colons --list-secret-keys | awk -F: '$1 == "fpr" { print $10; exit }')
# A rehearsal's key (update-rehearsal.sh, onprem-check.sh make one each run): no shop pins it.
gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-gen-key 'a rehearsal <rehearsal@twes.test>' ed25519 sign never
REHEARSAL=$(gpg --batch --with-colons --list-secret-keys rehearsal@twes.test | awk -F: '$1 == "fpr" { print $10; exit }')

# A commit signed by the release key, as every commit on master is.
signed_commit() { git -C "$1/repo" -c user.signingkey="$KEY" -c commit.gpgsign=true commit -qm "$2"; }
# The rehearsal's and onprem-check's markers for a commit's tree, as update-rehearsal.sh and onprem-check.sh write them.
proven() {
  local tree; tree=$(git -C "$1/repo" rev-parse "$2^{tree}")
  mkdir -p "$1/repo/var/claude/gates/proofs"
  for kind in rehearsal onprem; do echo "$kind OK" > "$1/repo/var/claude/gates/proofs/$kind-$tree.ok"; done
}
# A checkout whose origin/master holds its HEAD, and a docker that answers from the environment: LABEL_REV the commit
# every image says it was built from (HEAD when unset), MISSING a part the registry has no image of, NO_BUILDX a docker
# without buildx, DIGEST_SAYS what the registry gives in place of a digest. It reads the registry through buildx's
# imagetools, as the real one does, and answers nothing else: a pull would fail the case.
fixture() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/repo/scripts/release" "$d/repo/infra/onprem" "$d/bin"
  cp "$SCRIPT" "$d/repo/scripts/release/"
  gpg --batch --armor --export "$KEY" > "$d/repo/infra/onprem/release-key.asc"
  git -C "$d/repo" init -q -b master && git -C "$d/repo" add -A && signed_commit "$d" one
  git -C "$d/repo" update-ref refs/remotes/origin/master HEAD
  proven "$d" HEAD
  cat > "$d/bin/docker" <<'EOF'
#!/usr/bin/env bash
echo "docker $*" >> "$CALLS"
[[ "$1 ${2:-}" == "buildx version" ]] && { [[ -z "${NO_BUILDX:-}" ]] || { echo "docker: unknown command: docker buildx" >&2; exit 1; }; exit 0; }
[[ "$1 ${2:-} ${3:-}" == "buildx imagetools inspect" ]] || { echo "the stand-in answers no docker $1" >&2; exit 9; }
[[ -z "${NO_BUILDX:-}" ]] || exit 1
ref=''; shift 3
while (($#)); do case "$1" in --format) shift ;; *) ref=$1 ;; esac; shift; done
[[ -n "${MISSING:-}" && "$ref" == *"twes-in-$MISSING:"* ]] && { echo "ERROR: $ref: not found" >&2; exit 1; }
[[ -z "${WARNS:-}" ]] || echo "WARNING: current commit information was not captured by the build" >&2
printf '%s %s' "${DIGEST_SAYS:-sha256:$(printf '%s' "${ref%:*}" | sha256sum | cut -c1-64)}" "$LABEL_REV"
EOF
  chmod +x "$d/bin/docker"
  echo "$d"
}
FLAGS=()
tag_release() { # dir, tag, then extra environment (FLAGS: options before the arguments)
  local d=$1 tag=$2; shift 2
  (cd "$d/repo" && env CALLS="$d/calls" PATH="$d/bin:$PATH" LABEL_REV="$(git rev-parse HEAD)" TWES_RELEASE_KEY="$KEY" TWES_TODAY=2026.10.13 "$@" bash scripts/release/tag-release.sh "${FLAGS[@]}" registry.invalid/o "$tag" 2>&1)
}
message() { git -C "$1/repo" for-each-ref --format='%(contents)' "refs/tags/$2"; }

d=$(fixture); head=$(git -C "$d/repo" rev-parse HEAD)
out=$(tag_release "$d" v2026.10.13.1); check "a commit master holds is tagged, and verified" $? 0 "$out" "v2026.10.13.1 made and verified"
check "  as an annotated tag" 0 0 "$(git -C "$d/repo" cat-file -t refs/tags/v2026.10.13.1)" "tag"
verified=$(git -C "$d/repo" verify-tag --raw v2026.10.13.1 2>&1); check "  signed by the release key" $? 0 "$verified" "VALIDSIG $KEY"
check "  naming its commit" 0 0 "$(message "$d" v2026.10.13.1)" "TWES_REVISION=$head"
check "  and each image by the digest the registry holds" 0 0 "$(message "$d" v2026.10.13.1)" \
  "TWES_WEB_IMAGE=registry.invalid/o/twes-in-web@sha256:$(printf '%s' registry.invalid/o/twes-in-web | sha256sum | cut -c1-64)"
check "  resolved from the commit's own sha tag" 0 0 "$(cat "$d/calls")" "buildx imagetools inspect registry.invalid/o/twes-in-database:sha-$head"
! grep -q " pull" "$d/calls"; check "  read from the registry, nothing pulled" $? 0 "$(cat "$d/calls")" ""
check "  one line per image as it is read" 0 0 "$out" "[1/3] twes-in-api:sha-${head:0:12}"
check "  saying which comes next" 0 0 "$out" "next: twes-in-web"
check "  and the last" 0 0 "$out" "[3/3] twes-in-database"
check "  and says how to push it, which it never does itself" 0 0 "$out" "git push origin v2026.10.13.1"
check "  once staging has run it" 0 0 "$out" "once staging has run this commit"
! grep -q "push" "$d/calls"; check "  and pushes nothing to the registry" $? 0 "$(cat "$d/calls")" ""

d=$(fixture)
out=$(tag_release "$d" v2026.10.13.1 TWES_RELEASE_KEY=); check "no key named, by TWES_RELEASE_KEY or git's user.signingkey: refused" $? 1 "$out" "signing key"
git -C "$d/repo" rev-parse -q --verify refs/tags/v2026.10.13.1 >/dev/null; check "  and no tag is left" $? 1 "" ""
git -C "$d/repo" config user.signingkey "$KEY"
out=$(tag_release "$d" v2026.10.13.1 TWES_RELEASE_KEY=); check "git's user.signingkey is the key by default" $? 0 "$(git -C "$d/repo" verify-tag --raw v2026.10.13.1 2>&1)" "VALIDSIG $KEY"
d=$(fixture)
out=$(tag_release "$d" v2026.10.13.1 TWES_RELEASE_KEY=0000000000000000); check "a key this keyring cannot sign with: refused" $? 1 "$out" "could not sign"
git -C "$d/repo" rev-parse -q --verify refs/tags/v2026.10.13.1 >/dev/null; check "  and no tag is left" $? 1 "" ""

d=$(fixture); first=$(git -C "$d/repo" rev-parse HEAD)
echo x > "$d/repo/x"; git -C "$d/repo" add -A; signed_commit "$d" two
git -C "$d/repo" update-ref refs/remotes/origin/master HEAD
out=$(cd "$d/repo" && env CALLS="$d/calls" PATH="$d/bin:$PATH" LABEL_REV="$first" TWES_RELEASE_KEY="$KEY" TWES_TODAY=2026.10.13 bash scripts/release/tag-release.sh registry.invalid/o v2026.10.13.2 "${first:0:8}" 2>&1)
check "an older commit master holds is tagged by name, HEAD elsewhere" $? 0 "$out" "made and verified at $first"
check "  the tag points at that commit" 0 0 "$(git -C "$d/repo" rev-parse 'v2026.10.13.2^{commit}')" "$first"
check "  its message names that commit" 0 0 "$(message "$d" v2026.10.13.2)" "TWES_REVISION=$first"
check "  from that commit's own images" 0 0 "$(cat "$d/calls")" "buildx imagetools inspect registry.invalid/o/twes-in-api:sha-$first"
out=$(cd "$d/repo" && env CALLS="$d/calls" PATH="$d/bin:$PATH" TWES_RELEASE_KEY="$KEY" bash scripts/release/tag-release.sh registry.invalid/o v2026.10.13.3 nosuchcommit 2>&1)
check "a name that is no commit is refused" $? 1 "$out" "names no commit"

d=$(fixture)
out=$(tag_release "$d" v2026.10.13); check "a name that is not vYYYY.MM.DD.N is refused" $? 1 "$out" "vYYYY.MM.DD.N"

d=$(fixture); tag_release "$d" v2026.10.13.1 >/dev/null
out=$(tag_release "$d" v2026.10.13.1); check "a tag that exists is never moved" $? 1 "$out" "exists"

d=$(fixture); echo x > "$d/repo/x"; git -C "$d/repo" add -A; git -C "$d/repo" commit -qm local
out=$(tag_release "$d" v2026.10.13.1); check "a commit master does not hold is refused" $? 1 "$out" "origin/master"

d=$(fixture)
out=$(tag_release "$d" v2026.10.13.1 MISSING=web); check "a commit whose image was never published is refused" $? 1 "$out" "twes-in-web:sha-"
check "  saying what the registry answered" 0 0 "$out" "not found"
git -C "$d/repo" rev-parse -q --verify refs/tags/v2026.10.13.1 >/dev/null; check "  and no tag is left" $? 1 "" ""

d=$(fixture)
out=$(tag_release "$d" v2026.10.13.1 DIGEST_SAYS=latest); check "a registry that gives no digest: refused" $? 1 "$out" "gave no digest"
git -C "$d/repo" rev-parse -q --verify refs/tags/v2026.10.13.1 >/dev/null; check "  and no tag is left" $? 1 "" ""

d=$(fixture)
out=$(tag_release "$d" v2026.10.13.1 WARNS=1); check "a warning docker prints beside its answer is no answer: tagged all the same" $? 0 "$out" "made and verified"

d=$(fixture)
out=$(tag_release "$d" v2026.10.13.1 NO_BUILDX=1); check "a docker without buildx: refused, saying what is needed" $? 1 "$out" "docker buildx"

d=$(fixture)
out=$(tag_release "$d" v2026.10.13.1 LABEL_REV="$(printf 'c%.0s' {1..40})"); check "an image built from another commit is refused" $? 1 "$out" "built from cccccccc"

d=$(fixture)
out=$(cd "$d/repo" && bash scripts/release/tag-release.sh registry.invalid/o 2>&1); check "the registry and the tag must be named" $? 2 "$out" "Usage"

# What a release rests on: the rehearsal and onprem-check proved this very tree, every commit since the last release
# is signed, and no tag is made in the year-end freeze but a hotfix.
d=$(fixture); rm -rf "$d/repo/var/claude/gates/proofs"
out=$(tag_release "$d" v2026.10.13.1); check "no proof of the rehearsal or onprem-check for this tree: refused" $? 1 "$out" "update-rehearsal"
git -C "$d/repo" rev-parse -q --verify refs/tags/v2026.10.13.1 >/dev/null; check "  and no tag is left" $? 1 "" ""
d=$(fixture); rm -f "$d/repo/var/claude/gates/proofs/onprem-"*
out=$(tag_release "$d" v2026.10.13.1); check "the rehearsal proved but not onprem-check: refused" $? 1 "$out" "onprem-check"
d=$(fixture); tree=$(git -C "$d/repo" rev-parse 'HEAD^{tree}'); rm -rf "$d/repo/var/claude/gates/proofs"
mkdir -p "$d/repo/var/claude/gates/proofs"; for k in rehearsal onprem; do echo ok > "$d/repo/var/claude/gates/proofs/$k-$(printf 'a%.0s' {1..40}).ok"; done
out=$(tag_release "$d" v2026.10.13.1); check "proofs of another tree prove nothing for this one: refused" $? 1 "$out" "${tree:0:12}"
d=$(fixture); rm -rf "$d/repo/var/claude/gates/proofs"
FLAGS=(--unrehearsed "the 2027 values alone, rehearsed by hand"); out=$(tag_release "$d" v2026.10.13.1); FLAGS=()
check "unproved, but said why: tagged" $? 0 "$out" "made and verified"
check "  and the tag's message says why" 0 0 "$(message "$d" v2026.10.13.1)" "UNREHEARSED=the 2027 values alone"

d=$(fixture); tag_release "$d" v2026.10.13.1 >/dev/null
echo y > "$d/repo/y"; git -C "$d/repo" add -A; git -C "$d/repo" commit -qm unsigned
git -C "$d/repo" update-ref refs/remotes/origin/master HEAD; proven "$d" HEAD
out=$(tag_release "$d" v2026.10.14.1); check "an unsigned commit since the last release: refused" $? 1 "$out" "unsigned"
check "  naming it" 0 0 "$out" "$(git -C "$d/repo" rev-parse --short HEAD)"
d=$(fixture); tag_release "$d" v2026.10.13.1 >/dev/null
echo y > "$d/repo/y"; git -C "$d/repo" add -A; signed_commit "$d" signed
git -C "$d/repo" update-ref refs/remotes/origin/master HEAD; proven "$d" HEAD
out=$(tag_release "$d" v2026.10.14.1); check "every commit since the last release signed: tagged" $? 0 "$out" "made and verified"

d=$(fixture)
out=$(tag_release "$d" v2026.12.24.1); check "a tag dated in the year-end freeze: refused" $? 1 "$out" "freeze"
out=$(tag_release "$d" v2026.10.13.1 TWES_TODAY=2027.01.05); check "a tag made during the freeze, whatever its name: refused" $? 1 "$out" "freeze"
out=$(tag_release "$d" v2027.01.11.1 TWES_TODAY=2027.01.11); check "the day after the freeze: tagged" $? 0 "$out" "made and verified"
d=$(fixture)
FLAGS=(--hotfix "a sale refused at the till"); out=$(tag_release "$d" v2026.12.24.1); FLAGS=()
check "a hotfix in the freeze, said why: tagged" $? 0 "$out" "made and verified"
check "  and the tag's message says why" 0 0 "$(message "$d" v2026.12.24.1)" "HOTFIX=a sale refused at the till"
d=$(fixture)
out=$(tag_release "$d" v2026.12.24.1 TWES_RELEASE_FREEZE=2026.12.26-2027.01.02); check "the freeze is a parameter" $? 0 "$out" "made and verified"

# The maintenance lane: a patch for the shops while master carries work not ready to ship. Its commits sit on
# release/<the release's date>, which grows from that release's tag; the patch tag is dated after it.
lane() { # dir: a release tagged, master moved on, a patch committed on release/2026.10.13 and published there
  tag_release "$1" v2026.10.13.1 >/dev/null
  echo m > "$1/repo/m"; git -C "$1/repo" add -A; signed_commit "$1" master-work
  git -C "$1/repo" update-ref refs/remotes/origin/master HEAD
  git -C "$1/repo" checkout -q -b release/2026.10.13 v2026.10.13.1
  echo p > "$1/repo/p"; git -C "$1/repo" add -A; signed_commit "$1" patch
  git -C "$1/repo" update-ref refs/remotes/origin/release/2026.10.13 HEAD; proven "$1" HEAD
}
d=$(fixture); lane "$d"
out=$(tag_release "$d" v2026.10.20.1); check "a patch on release/<date>, grown from that release's tag: tagged" $? 0 "$out" "made and verified"
check "  saying its lane and its base" 0 0 "$out" "release/2026.10.13 from v2026.10.13.1"
check "  the push it says asks no staging run, staging following master alone" 0 0 "$(grep -c 'staging has run' <<<"$out"):$(grep -c 'git push origin v2026.10.20.1' <<<"$out")" "0:1"
check "  the tag at the patch, not at master" 0 0 "$(git -C "$d/repo" rev-parse 'v2026.10.20.1^{commit}')" "$(git -C "$d/repo" rev-parse HEAD)"
d=$(fixture); lane "$d"
out=$(tag_release "$d" v2026.10.13.2); check "a patch tag dated on or before its base: refused" $? 1 "$out" "after v2026.10.13.1"
d=$(fixture); lane "$d"; git -C "$d/repo" update-ref -d refs/remotes/origin/release/2026.10.13
git -C "$d/repo" update-ref refs/remotes/origin/release/2026.10.14 HEAD
out=$(tag_release "$d" v2026.10.20.1); check "a release branch named for no release tag it grows from: refused" $? 1 "$out" "origin/master does not hold"
d=$(fixture); lane "$d"; git -C "$d/repo" update-ref -d refs/remotes/origin/release/2026.10.13
out=$(tag_release "$d" v2026.10.20.1); check "a patch never pushed to its release branch: refused" $? 1 "$out" "origin/master does not hold"
d=$(fixture); lane "$d"; echo q > "$d/repo/q"; git -C "$d/repo" add -A; git -C "$d/repo" commit -qm unsigned-patch
git -C "$d/repo" update-ref refs/remotes/origin/release/2026.10.13 HEAD; proven "$d" HEAD
out=$(tag_release "$d" v2026.10.20.1); check "an unsigned commit on the lane: refused as on master" $? 1 "$out" "unsigned"

# A tag signed with a key no shop pins is no release a shop takes: a rehearsal's. What a release rests on is asked of
# the pinned key alone, so update-rehearsal.sh and onprem-check.sh tag their own commits with their own key.
d=$(fixture); rm -rf "$d/repo/var/claude/gates/proofs"; echo r > "$d/repo/r"; git -C "$d/repo" add -A; git -C "$d/repo" commit -qm unsigned
git -C "$d/repo" update-ref refs/remotes/origin/master HEAD
out=$(tag_release "$d" v2026.12.24.1 TWES_RELEASE_KEY="$REHEARSAL"); check "a rehearsal's key, unproved, unsigned commits, in the freeze: tagged" $? 0 "$out" "made and verified"
check "  saying no shop takes it, so nothing of a release is asked" 0 0 "$out" "not the release key the shops pin"
check "  and its message says so" 0 0 "$(message "$d" v2026.12.24.1)" "REHEARSAL="
d=$(fixture); rm -f "$d/repo/infra/onprem/release-key.asc"; git -C "$d/repo" add -A; signed_commit "$d" no-key
git -C "$d/repo" update-ref refs/remotes/origin/master HEAD; proven "$d" HEAD
out=$(tag_release "$d" v2026.10.13.1); check "no pinned key in the checkout: refused, nothing tells a release from a rehearsal" $? 1 "$out" "release-key.asc"

# A patch is for the shops still on its base: once master released after it, the patch would sort above that release
# and carry its shops back onto older code, so the lane refuses it and says to patch master instead.
d=$(fixture); lane "$d"; git -C "$d/repo" tag v2026.10.15.1 master
out=$(tag_release "$d" v2026.10.20.1); check "a patch while master has released after its base: refused, naming that release" $? 1 "$out" "v2026.10.15.1"
check "  saying to patch master" 0 0 "$out" "master"

d=$(fixture); lane "$d"; root_commit=$(git -C "$d/repo" rev-list --max-parents=0 HEAD)
git -C "$d/repo" tag v2026.10.11.1 "$(git -C "$d/repo" commit-tree "$root_commit^{tree}" -p "$root_commit" -m "another lane's patch")"
out=$(tag_release "$d" v2026.10.20.1); check "an older release the patch does not hold is no reason to refuse it" $? 0 "$out" "made and verified"
echo "tag-release.test: $pass passed, $fail failed"
((fail == 0))
