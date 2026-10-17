#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Deploys this checkout (docs/START.md § 14) with the instance's values, in one of three ways:
#   --release <values>             production: HEAD at a release tag vYYYY.MM.DD.N, whose annotated message names the
#                                  three images by their digests (scripts/release/tag-release.sh writes it); nothing is
#                                  built, so what runs is byte for byte what staging ran.
#   --staging <values> <release>   staging: HEAD at the commit the release file names (scripts/deploy/staging-follow.sh
#                                  writes it from the registry); nothing is built. Its values say DEPLOY_ENV=staging and
#                                  keep its backups under their own prefix, so staging never writes where production does.
#   --build <values>               a self-hosted instance: HEAD at a release tag, the images built here.
#   --again <values>               the release this checkout last deployed here, started again from the images its record
#                                  names, nothing pulled: a shop's computer in the morning, offline or not, and an update's
#                                  rollback to the release it left (scripts/onprem/twes).
#   --compose <values> <arguments> any compose command on the release running here, over its files and the images its
#                                  record names, saying nothing of its own and writing nothing down: what the shop's
#                                  script runs a backup and reads the services through.
# The values say which installation it is: a server (the default), its backups in a bucket behind compose.deploy.yaml's
# edge, or TWES_INSTALL=onprem, a shop's machine (docs/START.md § 16), its backups in its own folder, copied off site
# when BACKUP_OFFSITE=on, behind compose.onprem.yaml's edge; a shop is never staging.
# Every way refuses a checkout that differs from its commit, since the compose files, and built images, come from it as
# it is, untracked files included. The values file must be readable by its owner alone, and it takes the place of the
# committed development .env, which compose would otherwise read for any value the file lacks.
# A deployment by digest is written down in releases/ beside the values: <commit>.env, the images it ran, which is what a
# rollback deploys again, and a line of history, which is what staging-follow.sh compares with.
set -euo pipefail
usage() {
  echo "Usage: deploy.sh --release <values file> | --staging <values file> <release file> | --build <values file> | --again <values file>" >&2
  echo "       deploy.sh --compose <values file> <compose arguments>" >&2
  exit 2
}
mode=${1:-}
case "$mode" in
  --release | --build | --again) [[ $# -eq 2 ]] || usage; values=$2 ;;
  --staging) [[ $# -eq 3 ]] || usage; values=$2 release=$3 ;;
  --compose) [[ $# -ge 3 ]] || usage; values=$2; shift 2 ;;
  *) usage ;;
esac
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
refuse() { echo "deploy: refused — $*" >&2; exit 1; }
# The last value the file gives a name, as compose reads it; empty when it gives none.
value() { awk -v k="$1" 'index($0, k "=") == 1 { v = substr($0, length(k) + 2) } END { print v }' "$values"; }

# make staging-check (scripts/staging/) adds its stand-in bucket and an edge publishing nothing; nothing else may add a file.
extra=()
if [[ -n "${DEPLOY_EXTRA_FILES:-}" ]]; then
  [[ "$mode" == --staging && "${COMPOSE_PROJECT_NAME:-}" == twes-staging-check ]] \
    || refuse "DEPLOY_EXTRA_FILES is make staging-check's alone: --staging under the compose project twes-staging-check"
  IFS=: read -ra layers <<<"$DEPLOY_EXTRA_FILES"
  for layer in "${layers[@]}"; do extra+=(-f "$layer"); done
fi
[[ -f "$values" ]] || refuse "no values file at $values (scripts/deploy/new-instance.sh writes one)"
[[ "$(stat -c %a "$values")" == 600 ]] || refuse "$values may be read by others than its owner: chmod 600 it"
commit=$(git -C "$root" rev-parse HEAD)
changed=$(git -C "$root" status --porcelain --untracked-files=all)
[[ -z "$changed" ]] || refuse "the checkout differs from its commit, and the deployment would carry it:
$changed"

# The compose files of this installation, in order, before the release overlay.
case "$(value TWES_INSTALL)" in
  '' | server) layers=(compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-bucket.yaml compose.deploy.yaml) ;;
  onprem)
    [[ "$mode" != --staging ]] || refuse "$values say TWES_INSTALL=onprem: a shop's installation is never staging"
    layers=(compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-local.yaml)
    case "$(value BACKUP_OFFSITE)" in
      on) layers+=(compose.backup-offsite.yaml) ;;
      '' | off) ;;
      *) refuse "$values say BACKUP_OFFSITE=$(value BACKUP_OFFSITE): on or off, nothing else" ;;
    esac
    layers+=(compose.onprem.yaml) ;;
  *) refuse "$values say TWES_INSTALL=$(value TWES_INSTALL): server or onprem, nothing else" ;;
esac
files=()
for layer in "${layers[@]}"; do files+=(-f "$layer"); done

staging_values=no
[[ "$(value DEPLOY_ENV)" == staging || "$(value BACKUP_S3_PREFIX)" == staging/ ]] && staging_values=yes
if [[ "$mode" == --staging ]]; then
  [[ "$(value DEPLOY_ENV)" == staging ]] || refuse "$values do not say DEPLOY_ENV=staging (new-instance.sh --staging writes it)"
  [[ "$(value BACKUP_S3_PREFIX)" == staging/ ]] \
    || refuse "$values do not say BACKUP_S3_PREFIX=staging/: staging's backups go under their own prefix, never production's"
elif [[ "$mode" != --compose ]]; then
  [[ "$staging_values" == no ]] || refuse "$values are staging's (DEPLOY_ENV=staging or BACKUP_S3_PREFIX=staging/), never a production instance's"
  tag=$(git -C "$root" describe --exact-match --tags --match 'v[0-9][0-9][0-9][0-9].[0-9][0-9].[0-9][0-9].*' HEAD 2>/dev/null) \
    || refuse "HEAD is not at a release tag (vYYYY.MM.DD.N): check one out, git checkout <tag>"
  echo "deploy: $tag"
fi

if [[ "$mode" == --build ]]; then
  set -a
  # shellcheck source=/dev/null
  source <(bash "$root/scripts/build-version.sh" --root "$root" --env)
  set +a
  cd "$root"
  exec docker compose --env-file "$values" "${files[@]}" up -d --build --wait
fi

# The release is data: read line by line, never sourced, and each of its four values held to the one shape it may take.
declare -A release_value
read_release() {
  local line
  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ "$line" =~ ^(TWES_REVISION|TWES_API_IMAGE|TWES_WEB_IMAGE|TWES_DATABASE_IMAGE)=(.*)$ ]] || continue
    release_value[${BASH_REMATCH[1]}]=${BASH_REMATCH[2]}
  done
}
records="$(dirname "$values")/releases"
if [[ "$mode" == --again || "$mode" == --compose ]]; then
  [[ -f "$records/$commit.env" ]] || refuse "$commit was never deployed here (no $records/$commit.env): deploy its release with --release"
  read_release < "$records/$commit.env"
  source_name="$records/$commit.env"
elif [[ "$mode" == --release ]]; then
  [[ "$(git -C "$root" cat-file -t "refs/tags/$tag")" == tag ]] \
    || refuse "$tag is not annotated, so it names no image: scripts/release/tag-release.sh writes the release tag"
  read_release < <(git -C "$root" for-each-ref --format='%(contents)' "refs/tags/$tag")
  source_name="the tag $tag"
else
  [[ -f "$release" ]] || refuse "no release file at $release"
  read_release < "$release"
  source_name=$release
fi
revision=${release_value[TWES_REVISION]:-}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || refuse "$source_name names no commit (TWES_REVISION, 40 hexadecimal digits)"
for part in api web database; do
  name="TWES_${part^^}_IMAGE"
  [[ "${release_value[$name]:-}" =~ ^[a-z0-9][a-z0-9._-]*(:[0-9]+)?(/[a-z0-9._-]+)*/twes-in-$part@sha256:[0-9a-f]{64}$ ]] \
    || refuse "$source_name names no $part image by its digest ($name=<registry>/twes-in-$part@sha256:<64 hex>)"
done
[[ "$revision" == "$commit" ]] \
  || refuse "$source_name is commit $revision and HEAD is $commit; the compose files must be the images' own: git checkout --detach $revision"

if [[ "$mode" == --compose ]]; then
  export TWES_API_IMAGE=${release_value[TWES_API_IMAGE]} TWES_WEB_IMAGE=${release_value[TWES_WEB_IMAGE]}
  export TWES_DATABASE_IMAGE=${release_value[TWES_DATABASE_IMAGE]}
  cd "$root"
  exec docker compose --env-file "$values" "${files[@]}" -f compose.release.yaml "$@"
fi

# Each image says which commit it was built from (scripts/release/publish.compose.yaml): one that disagrees with its
# release was recorded wrong, and starting it would run other code than the compose files around it expect. Started
# again, the images are the ones already here: nothing is fetched.
for part in api web database; do
  image=${release_value[TWES_${part^^}_IMAGE]}
  if [[ "$mode" == --again ]]; then
    found=$(docker image inspect --format '{{.Id}}' "$image" 2>&1) \
      || refuse "$image is not on this machine any more (docker: $found): deploy the release with --release while the network answers"
  else
    docker pull -q "$image" >/dev/null
  fi
  built=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$image")
  [[ "$built" == "$revision" ]] || refuse "$image was built from ${built:-a commit it does not name}, not $revision"
done

way=${mode#--} done_word=deployed
[[ "$mode" == --again ]] && done_word=started
umask 077
mkdir -p "$records"
export TWES_API_IMAGE=${release_value[TWES_API_IMAGE]} TWES_WEB_IMAGE=${release_value[TWES_WEB_IMAGE]}
export TWES_DATABASE_IMAGE=${release_value[TWES_DATABASE_IMAGE]}
echo "deploy: $revision ($way), by digest"
cd "$root"
if ! docker compose --env-file "$values" "${files[@]}" -f compose.release.yaml "${extra[@]}" up -d --no-build --wait; then
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) failed $revision $way" >> "$records/history"
  echo "deploy: $revision did not start; the last line of $records/history before this one names what ran" >&2
  exit 1
fi
printf '%s\n' "TWES_REVISION=$revision" "TWES_API_IMAGE=$TWES_API_IMAGE" "TWES_WEB_IMAGE=$TWES_WEB_IMAGE" \
  "TWES_DATABASE_IMAGE=$TWES_DATABASE_IMAGE" > "$records/$revision.env"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $done_word $revision $way" >> "$records/history"
echo "deploy: $revision running; written down in $records"
