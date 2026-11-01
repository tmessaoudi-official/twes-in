#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR
trap 'GNUPGHOME="$TMPDIR/gnupg" gpgconf --kill all 2>/dev/null; rm -rf "$TMPDIR"' EXIT  # every case's fixture goes with it
# None of the machine's own git settings: a developer's commit.gpgsign would sign the fixtures with a real key.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/signed-commits.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# The developer's key and a stranger's, each throwaway, in a keyring of this test's own; the repository carries the
# developer's public key where the shops pin it.
export GNUPGHOME=$TMPDIR/gnupg; mkdir -m 700 "$GNUPGHOME"
gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-gen-key 'twes release <release@twes.test>' ed25519 sign never
gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-gen-key 'someone else <else@twes.test>' ed25519 sign never
KEY=$(gpg --batch --with-colons --list-secret-keys release@twes.test | awk -F: '$1 == "fpr" { print $10; exit }')
OTHER=$(gpg --batch --with-colons --list-secret-keys else@twes.test | awk -F: '$1 == "fpr" { print $10; exit }')
repo() {
  local d; d=$(mktemp -d); mkdir -p "$d/infra/onprem"
  gpg --batch --armor --export "$KEY" > "$d/infra/onprem/release-key.asc"
  git -C "$d" init -q -b master
  git -C "$d" add -A; git -C "$d" -c user.signingkey="$KEY" -c commit.gpgsign=true commit -qm one
  git -C "$d" tag v2026.10.20.1
  echo "$d"
}
commit() { # dir, key or "none"
  echo "$RANDOM" >> "$1/f"; git -C "$1" add -A
  if [[ "$2" == none ]]; then git -C "$1" commit -qm unsigned; else git -C "$1" -c user.signingkey="$2" -c commit.gpgsign=true commit -qm signed; fi
}
# The gate reads the pinned key into a keyring of its own, as CI has no other: run it with an empty GNUPGHOME.
run() { GNUPGHOME= bash "$GATE" --root "$1" 2>&1; }
echo "signed-commits gate"

d=$(repo); commit "$d" "$KEY"; commit "$d" "$KEY"
out=$(run "$d"); check "every commit since the release signed by the pinned key passes" $? 0 "$out" "2 commit"
d=$(repo); commit "$d" "$KEY"; commit "$d" none
out=$(run "$d"); check "an unsigned commit since the release: refused" $? 1 "$out" "$(git -C "$d" rev-parse --short HEAD)"
d=$(repo); commit "$d" "$OTHER"
out=$(run "$d"); check "a commit signed by another key: refused" $? 1 "$out" "$(git -C "$d" rev-parse --short HEAD)"
d=$(repo); commit "$d" none; git -C "$d" tag v2026.10.21.1; commit "$d" "$KEY"
out=$(run "$d"); check "only what follows the newest release is read" $? 0 "$out" "since v2026.10.21.1"
d=$(repo); rm "$d/infra/onprem/release-key.asc"; commit "$d" "$KEY"
out=$(run "$d"); check "no pinned key: refused, not skipped" $? 1 "$out" "release-key.asc"
d=$(repo); git -C "$d" tag -d v2026.10.20.1 >/dev/null; commit "$d" "$KEY"
out=$(run "$d"); check "no release yet: every commit is read" $? 0 "$out" "2 commit"

# The snapshot gate's own commit (scripts/gate/snapshot-gate.sh) is never signed and never lands: gated in its worktree,
# the snapshot is read as what it holds, its parent's history; anywhere else a commit saying so is held like any other.
d=$(repo); commit "$d" "$KEY"; echo s >> "$d/f"; git -C "$d" add -A
snap=$(git -C "$d" commit-tree "$(git -C "$d" write-tree)" -p HEAD -m "gate snapshot of $(git -C "$d" write-tree)")
git -C "$d" worktree add -q --detach "$d/.claude/worktrees/gate" "$snap"
out=$(run "$d/.claude/worktrees/gate"); check "the snapshot gate's unsigned commit, in its worktree: read as its parent" $? 0 "$out" "1 commit"
check "  saying so" 0 0 "$out" "snapshot"
git -C "$d" update-ref refs/heads/master "$snap"; git -C "$d" checkout -q -f master
out=$(run "$d"); check "the same commit on a branch outside the gate worktree: refused" $? 1 "$out" "${snap:0:7}"
d=$(repo); commit "$d" "$KEY"; echo s >> "$d/f"; git -C "$d" add -A
snap=$(git -C "$d" commit-tree "$(git -C "$d" write-tree)" -p HEAD -m "gate snapshot of 0000000000000000000000000000000000000000")
git -C "$d" worktree add -q --detach "$d/.claude/worktrees/gate" "$snap"
out=$(run "$d/.claude/worktrees/gate"); check "a commit in the gate worktree naming another tree than its own: refused" $? 1 "$out" "${snap:0:7}"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
