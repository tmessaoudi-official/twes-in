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

# A checkout whose origin/master holds its HEAD, and a docker that answers from the environment: LABEL_REV the commit
# every image says it was built from (HEAD when unset), MISSING a part the registry has no image of.
fixture() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/repo/scripts/release" "$d/bin"
  cp "$SCRIPT" "$d/repo/scripts/release/"
  git -C "$d/repo" init -q -b master && git -C "$d/repo" add -A && git -C "$d/repo" commit -qm one
  git -C "$d/repo" update-ref refs/remotes/origin/master HEAD
  cat > "$d/bin/docker" <<'EOF'
#!/usr/bin/env bash
echo "docker $*" >> "$CALLS"
ref=${@: -1}
case "$1" in
  pull) [[ -n "${MISSING:-}" && "$ref" == *"twes-in-$MISSING:"* ]] && { echo "manifest unknown" >&2; exit 1; } ;;
  image|inspect)
    if [[ "$*" == *RepoDigests* ]]; then printf '%s@sha256:%s\n' "${ref%:*}" "$(printf '%s' "${ref%:*}" | sha256sum | cut -c1-64)"
    else printf '%s\n' "$LABEL_REV"; fi ;;
esac
exit 0
EOF
  chmod +x "$d/bin/docker"
  echo "$d"
}
tag_release() { # dir, tag, then extra environment
  local d=$1 tag=$2; shift 2
  (cd "$d/repo" && env CALLS="$d/calls" PATH="$d/bin:$PATH" LABEL_REV="$(git rev-parse HEAD)" TWES_RELEASE_KEY="$KEY" "$@" bash scripts/release/tag-release.sh registry.invalid/o "$tag" 2>&1)
}
message() { git -C "$1/repo" for-each-ref --format='%(contents)' "refs/tags/$2"; }

d=$(fixture); head=$(git -C "$d/repo" rev-parse HEAD)
out=$(tag_release "$d" v2026.10.13.1); check "a commit master holds is tagged" $? 0 "$out" "v2026.10.13.1"
check "  as an annotated tag" 0 0 "$(git -C "$d/repo" cat-file -t refs/tags/v2026.10.13.1)" "tag"
verified=$(git -C "$d/repo" verify-tag --raw v2026.10.13.1 2>&1); check "  signed by the release key" $? 0 "$verified" "VALIDSIG $KEY"
check "  naming its commit" 0 0 "$(message "$d" v2026.10.13.1)" "TWES_REVISION=$head"
check "  and each image by the digest the registry holds" 0 0 "$(message "$d" v2026.10.13.1)" "TWES_WEB_IMAGE=registry.invalid/o/twes-in-web@sha256:"
check "  resolved from the commit's own sha tag" 0 0 "$(cat "$d/calls")" "pull -q registry.invalid/o/twes-in-database:sha-$head"
check "  and says how to push it, which it never does itself" 0 0 "$out" "git push origin v2026.10.13.1"
! grep -q "push" "$d/calls"; check "  and pushes nothing to the registry" $? 0 "$(cat "$d/calls")" ""

d=$(fixture)
out=$(tag_release "$d" v2026.10.13.1 TWES_RELEASE_KEY=); check "no key named, by TWES_RELEASE_KEY or git's user.signingkey: refused" $? 1 "$out" "signing key"
git -C "$d/repo" rev-parse -q --verify refs/tags/v2026.10.13.1 >/dev/null; check "  and no tag is left" $? 1 "" ""
git -C "$d/repo" config user.signingkey "$KEY"
out=$(tag_release "$d" v2026.10.13.1 TWES_RELEASE_KEY=); check "git's user.signingkey is the key by default" $? 0 "$(git -C "$d/repo" verify-tag --raw v2026.10.13.1 2>&1)" "VALIDSIG $KEY"
d=$(fixture)
out=$(tag_release "$d" v2026.10.13.1 TWES_RELEASE_KEY=0000000000000000); check "a key this keyring cannot sign with: refused" $? 1 "$out" "could not sign"
git -C "$d/repo" rev-parse -q --verify refs/tags/v2026.10.13.1 >/dev/null; check "  and no tag is left" $? 1 "" ""

d=$(fixture)
out=$(tag_release "$d" v2026.10.13); check "a name that is not vYYYY.MM.DD.N is refused" $? 1 "$out" "vYYYY.MM.DD.N"

d=$(fixture); tag_release "$d" v2026.10.13.1 >/dev/null
out=$(tag_release "$d" v2026.10.13.1); check "a tag that exists is never moved" $? 1 "$out" "exists"

d=$(fixture); echo x > "$d/repo/x"; git -C "$d/repo" add -A; git -C "$d/repo" commit -qm local
out=$(tag_release "$d" v2026.10.13.1); check "a commit master does not hold is refused" $? 1 "$out" "origin/master"

d=$(fixture)
out=$(tag_release "$d" v2026.10.13.1 MISSING=web); check "a commit whose image was never published is refused" $? 1 "$out" "twes-in-web:sha-"
git -C "$d/repo" rev-parse -q --verify refs/tags/v2026.10.13.1 >/dev/null; check "  and no tag is left" $? 1 "" ""

d=$(fixture)
out=$(tag_release "$d" v2026.10.13.1 LABEL_REV="$(printf 'c%.0s' {1..40})"); check "an image built from another commit is refused" $? 1 "$out" "built from cccccccc"

d=$(fixture)
out=$(cd "$d/repo" && bash scripts/release/tag-release.sh registry.invalid/o 2>&1); check "the registry and the tag must be named" $? 2 "$out" "Usage"

echo "tag-release.test: $pass passed, $fail failed"
((fail == 0))
