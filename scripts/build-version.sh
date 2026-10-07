#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The version of one part, worked out from git so nothing is bumped by hand (docs/SPEC.md § 7, the footer's build line):
# `YYYY.MM.DD.N` from the last commit touching the part's own folders, N counting that part's commits of that day in
# Paris, then that commit's short hash. A build from uncommitted changes in those folders appends « -dirty ».
#   build-version.sh [--root DIR] web|api   prints « VERSION COMMIT »
#   build-version.sh [--root DIR] --env     prints the four variables compose passes to the images as build arguments
# The images are built without .git (.dockerignore), so the Makefile and CI run this on the checkout and pass the result.
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
if [[ ${1:-} == --root ]]; then root=$2; shift 2; fi
cd "$root"

if [[ $(git rev-parse --is-shallow-repository) == true ]]; then
  echo "build-version: a shallow clone cannot count a day's changes; fetch the whole history (fetch-depth: 0)" >&2
  exit 1
fi

folders() {
  case $1 in
    web) echo web infra/web ;;
    api) echo api infra/api ;;
    *) echo "build-version: the part is web or api, not « $1 »" >&2; exit 2 ;;
  esac
}

# « VERSION COMMIT » of one part.
part() {
  local paths; paths=$(folders "$1")
  local commit day count dirty=''
  # shellcheck disable=SC2086 # the folders are separate pathspecs
  commit=$(git log -1 --format=%h --abbrev=8 -- $paths)
  # shellcheck disable=SC2086
  day=$(TZ=Europe/Paris git log -1 --format=%cd --date=format-local:%Y.%m.%d -- $paths)
  # shellcheck disable=SC2086
  count=$(TZ=Europe/Paris git log --format=%cd --date=format-local:%Y.%m.%d -- $paths | grep -cxF -e "$day")
  # shellcheck disable=SC2086
  if [[ -n $(git status --porcelain -- $paths) ]]; then dirty=-dirty; fi
  echo "$day.$count$dirty $commit"
}

if [[ ${1:-} == --env ]]; then
  for name in web api; do
    read -r version commit < <(part "$name")
    upper=${name^^}
    echo "${upper}_BUILD_VERSION=$version"
    echo "${upper}_BUILD_COMMIT=$commit"
  done
else
  folders "${1:-}" > /dev/null
  part "$1"
fi
