#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every commit since the newest release tag carries a good signature by the key the shops pin
# (infra/onprem/release-key.asc): a shop updates only to a tag that key signed, and a commit on master without it is a
# way round that signing (git commit-tree never signs by itself, and 67 commits once reached master unsigned). The key is
# read into a keyring of the gate's own, so CI, which holds no other, checks the same thing as this machine.
# Usage: signed-commits.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
pinned=$root/infra/onprem/release-key.asc
[[ -s "$pinned" ]] || { echo "signed-commits: FAIL — no pinned key at infra/onprem/release-key.asc to check the signatures against"; exit 1; }

keyring=$(mktemp -d)
trap 'GNUPGHOME="$keyring" gpgconf --kill all 2>/dev/null; rm -rf "$keyring"' EXIT
export GNUPGHOME=$keyring
gpg --batch --quiet --import "$pinned" 2>/dev/null || { echo "signed-commits: FAIL — infra/onprem/release-key.asc is not a key gpg can read"; exit 1; }
fingerprints=$(gpg --batch --with-colons --list-keys | awk -F: '$1 == "fpr" { print $10 }')

# The snapshot gate (scripts/gate/snapshot-gate.sh) gates the index as a commit of its own, unsigned since a gate never
# waits on a key, in its worktree alone; what lands is that tree, signed by make commit-gated. There, and only there, a
# HEAD saying it is the snapshot of its own tree is read as its parent's history. CI's checkout is no such worktree.
end=HEAD snapshot=''
if [[ "$(git -C "$root" rev-parse --absolute-git-dir)" == */worktrees/gate \
  && "$(git -C "$root" log -1 --format=%s HEAD)" == "gate snapshot of $(git -C "$root" rev-parse 'HEAD^{tree}')" ]]; then
  end=HEAD^ snapshot=" (the snapshot gate's own commit $(git -C "$root" rev-parse --short HEAD) read as its parent)"
fi
release=$(git -C "$root" describe --tags --abbrev=0 --match 'v[0-9][0-9][0-9][0-9].[0-9][0-9].[0-9][0-9].*' "$end" 2>/dev/null || true)
range=${release:+$release..}$end
# %G? is U for a good signature by a key the keyring holds but never marked trusted, which is what a fresh keyring
# says of the pinned key; %GF names the key that signed, which must be the pinned one. The checkout's own gpg.program
# names the host's binary, which a container lacks: the gate verifies with the gpg on its PATH and its own keyring.
bad=()
count=0
while read -r sha status signer; do
  [[ -n "$sha" ]] || continue
  count=$((count + 1))
  if [[ "$status" != G && "$status" != U ]] || ! grep -qxF -e "${signer:-none}" <<<"$fingerprints"; then
    bad+=("$sha")
  fi
done < <(git -C "$root" -c gpg.program=gpg log --format='%h %G? %GF' "$range")

if ((${#bad[@]})); then
  printf 'signed-commits: FAIL — %d commit(s) since %s not signed by the pinned release key: %s (git log --format="%%h %%G? %%GF %%s" to read them)\n' \
    "${#bad[@]}" "${release:-the first commit}" "${bad[*]}"
  exit 1
fi
printf 'signed-commits: OK — %d commit(s) since %s, each signed by the pinned release key%s\n' "$count" "${release:-the first commit}" "$snapshot"
