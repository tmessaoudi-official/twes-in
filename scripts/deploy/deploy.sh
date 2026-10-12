#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Deploys this checkout (docs/START.md § 14): the four compose files, built here and started, with the instance's values.
# Refuses anything but a release: HEAD exactly at a tag vYYYY.MM.DD.N and nothing changed or added, since the images copy
# the working tree as it is, untracked files included, and what runs must be what the tag names. The values file must be
# readable by its owner alone, and it takes the place of the committed development .env, which compose would otherwise
# read for any value the file lacks.
# Usage: deploy.sh <values file>      (written once by scripts/deploy/new-instance.sh)
set -euo pipefail
[[ $# -eq 1 ]] || { echo "Usage: deploy.sh <values file>" >&2; exit 2; }
values=$1
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
refuse() { echo "deploy: refused — $*" >&2; exit 1; }

[[ -f "$values" ]] || refuse "no values file at $values (scripts/deploy/new-instance.sh writes one)"
[[ "$(stat -c %a "$values")" == 600 ]] || refuse "$values may be read by others than its owner: chmod 600 it"
tag=$(git -C "$root" describe --exact-match --tags --match 'v[0-9][0-9][0-9][0-9].[0-9][0-9].[0-9][0-9].*' HEAD 2>/dev/null) \
  || refuse "HEAD is not at a release tag (vYYYY.MM.DD.N): check one out, git checkout <tag>"
changed=$(git -C "$root" status --porcelain --untracked-files=all)
[[ -z "$changed" ]] || refuse "the checkout differs from $tag, and the images would carry it:
$changed"

echo "deploy: $tag"
set -a
# shellcheck source=/dev/null
source <(bash "$root/scripts/build-version.sh" --root "$root" --env)
set +a
cd "$root"
docker compose --env-file "$values" -f compose.yaml -f compose.prod.yaml -f compose.backup.yaml -f compose.deploy.yaml \
  up -d --build --wait
