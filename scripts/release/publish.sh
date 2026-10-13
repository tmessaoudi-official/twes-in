#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Publishes this checkout's three images (docs/START.md § 14): api, web and database, built as the deployment builds
# them, labelled with the commit, pushed as <prefix>/twes-in-<part>:sha-<commit>. Then moves :master to them, all three,
# but only forward: a run for an older commit that finishes late leaves :master on the newer one. Every sha tag is
# pushed before any :master moves, so whoever reads :master finds the other two already there.
# Refuses anything but a clean checkout, since the images copy the working tree as it is, and an image holding a
# .env.local or a .git directory, which the tree's .dockerignore files should have kept out.
# Writes the release (the three images by their digests and the commit) to <release file> for deploy.sh.
# Usage: publish.sh <registry prefix, such as ghcr.io/owner> <release file>
set -euo pipefail
[[ $# -eq 2 ]] || { echo "Usage: publish.sh <registry prefix> <release file>" >&2; exit 2; }
prefix=${1%/} release=$2
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
refuse() { echo "publish: refused — $*" >&2; exit 1; }

changed=$(git -C "$root" status --porcelain --untracked-files=all)
[[ -z "$changed" ]] || refuse "the checkout differs from its commit, and the images would carry it:
$changed"
revision=$(git -C "$root" rev-parse HEAD)

# Interpolation needs every value the files require; none is built into an image, only the build's version and commit.
placeholders=$(mktemp); trap 'rm -f "$placeholders"' EXIT
for name in APP_SECRET APP_MFA_KEY REALTIME_TOKEN_KEY REALTIME_API_KEY BACKUP_S3_ENDPOINT BACKUP_S3_BUCKET \
  BACKUP_S3_REGION BACKUP_S3_KEY BACKUP_S3_SECRET BACKUP_DATABASE_PASSPHRASE BACKUP_FILES_PASSPHRASE POSTGRES_APP_PASSWORD; do
  echo "$name=publish-placeholder"
done > "$placeholders"
{
  bash "$root/scripts/build-version.sh" --root "$root" --env
  echo "TWES_REVISION=$revision"
  echo "TWES_SOURCE=$(git -C "$root" config --get remote.origin.url || true)"
} >> "$placeholders"

echo "publish: building $revision"
(cd "$root" && docker compose --env-file "$placeholders" -p twes-release -f compose.yaml -f compose.prod.yaml \
  -f compose.backup.yaml -f scripts/release/publish.compose.yaml build api web postgres)

declare -A local_image=([api]="twes-release-api:$revision" [web]="twes-release-web:$revision" [database]="twes-release-database:$revision")
parts=(api web database)
for part in "${parts[@]}"; do
  found=$(docker run --rm --entrypoint sh "${local_image[$part]}" -c \
    'find / -xdev \( -name .env.local -o -name ".env.*.local" -o -name .git \) -print 2>/dev/null | head -5')
  [[ -z "$found" ]] || refuse "the $part image holds what must stay out of it:
$found"
done

declare -A digest
for part in "${parts[@]}"; do
  remote="$prefix/twes-in-$part:sha-$revision"
  docker tag "${local_image[$part]}" "$remote"
  docker push -q "$remote" >/dev/null
  digest[$part]=$(docker inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$remote" | grep -m1 "^$prefix/twes-in-$part@sha256:") \
    || refuse "the registry gave no digest for $remote"
done

{
  echo "TWES_REVISION=$revision"
  echo "TWES_API_IMAGE=${digest[api]}"
  echo "TWES_WEB_IMAGE=${digest[web]}"
  echo "TWES_DATABASE_IMAGE=${digest[database]}"
} > "$release"
echo "publish: $release written"

# A :master naming a commit this checkout does not hold (a shallow clone, a rewritten history) is not followed: kept.
current=''
if docker pull -q "$prefix/twes-in-api:master" >/dev/null 2>&1; then
  current=$(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$prefix/twes-in-api:master")
fi
if [[ -n "$current" && "$current" != "$revision" ]] && ! git -C "$root" merge-base --is-ancestor "$current" "$revision"; then
  echo "publish: kept :master at $current, which $revision does not follow"
  exit 0
fi
for part in "${parts[@]}"; do
  docker tag "${local_image[$part]}" "$prefix/twes-in-$part:master"
  docker push -q "$prefix/twes-in-$part:master" >/dev/null
done
echo "publish: moved :master to $revision"
