#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Keeps staging on the newest commit master published (docs/RUNBOOK.md), run by infra/staging/twes-staging-follow.timer
# on the staging machine: staging pulls, so GitHub holds no credential of any server. Reads the three :master images
# publish.sh moves, and deploys only when all three say they were built from one commit that origin's master holds and
# that staging has not already run or failed to start. Then checks that commit out and hands deploy.sh --staging the
# images by their digests. A file releases/hold beside the values stops it, so a rollback is not undone on the next tick.
# Usage: staging-follow.sh <registry prefix, such as ghcr.io/owner> <values file>
set -euo pipefail

# The whole script is read before it runs: it checks out another commit, whose copy of this file may differ.
main() {
  [[ $# -eq 2 ]] || { echo "Usage: staging-follow.sh <registry prefix> <values file>" >&2; return 2; }
  local prefix=${1%/} values=$2
  local root records
  root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
  records="$(dirname "$values")/releases"
  refuse() { echo "staging-follow: refused — $*" >&2; exit 1; }

  if [[ -f "$records/hold" ]]; then
    echo "staging-follow: held by $records/hold: $(head -1 "$records/hold")"
    return 0
  fi
  local changed
  changed=$(git -C "$root" status --porcelain --untracked-files=all)
  [[ -z "$changed" ]] || refuse "the checkout differs from its commit:
$changed"

  local part ref built digest revision='' disagree=no lines=()
  declare -A seen
  for part in api web database; do
    ref="$prefix/twes-in-$part:master"
    docker pull -q "$ref" >/dev/null
    built=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$ref")
    seen[$part]=$built
    [[ -z "$revision" ]] && revision=$built
    [[ "$built" == "$revision" ]] || disagree=yes
    digest=$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$ref" \
      | grep -m1 "^$prefix/twes-in-$part@sha256:") || refuse "the registry gave no digest for $ref"
    lines+=("TWES_${part^^}_IMAGE=$digest")
  done
  if [[ "$disagree" == yes ]]; then
    echo "staging-follow: waiting, :master is moving (api ${seen[api]}, web ${seen[web]}, database ${seen[database]})"
    return 0
  fi
  [[ "$revision" =~ ^[0-9a-f]{40}$ ]] || refuse ":master names no commit (${revision:-no revision label})"

  local last=''
  [[ -f "$records/history" ]] && last=$(tail -1 "$records/history")
  if [[ "$(awk '{ print $3 }' <<<"$last")" == "$revision" ]]; then
    echo "staging-follow: $revision already $(awk '{ print $2 }' <<<"$last") here; nothing to do"
    return 0
  fi

  git -C "$root" fetch -q origin master
  git -C "$root" merge-base --is-ancestor "$revision" refs/remotes/origin/master \
    || refuse ":master's images are of $revision, which origin/master does not hold"

  umask 077
  mkdir -p "$records"
  printf '%s\n' "TWES_REVISION=$revision" "${lines[@]}" > "$records/incoming.env"
  echo "staging-follow: deploying $revision"
  git -C "$root" checkout -q --detach "$revision"
  bash "$root/scripts/deploy/deploy.sh" --staging "$values" "$records/incoming.env"
}
main "$@"
exit
