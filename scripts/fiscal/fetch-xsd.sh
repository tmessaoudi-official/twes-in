#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Fetches the published schemas the fiscal XML tests validate against, then runs a command with each set's variable
# set. Their licences are not settled for this repository, so no copy is ever kept here, in a cache or in an artifact:
# each run downloads them anew into a directory outside the tree (docs/SPEC.md § 7, the TEJ schemas ruled 2026-11-04).
#
#   fetch-xsd.sh <directory> [-- <command> …]   fetch every set into <directory>/<set>, then run <command>
#   fetch-xsd.sh --list                         print each set and the variable its tests read
#
# The sets are data, scripts/fiscal/xsd-sources.tsv (XSD_SOURCES names another file). Each is pinned: a git folder by
# its tree id, a zip by its SHA-256, so a schema changed at its source stops the run (exit 1) rather than validating
# against something nobody has read. A source that does not answer is said as « not run » and its variable left unset,
# so its tests say they were skipped; on GitHub it is also a warning on the run's page, never a silent pass.
set -euo pipefail

SOURCES=${XSD_SOURCES:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/xsd-sources.tsv}
say() { echo "fetch-xsd: $*" >&2; }

rows() { tail -n +2 "$SOURCES"; }

if [[ "${1:-}" == --list ]]; then
  rows | while IFS=$'\t' read -r set variable _; do echo "$set $variable"; done
  exit 0
fi
[[ $# -ge 1 && "$1" != -- ]] || { echo "usage: fetch-xsd.sh <directory> [-- <command> …] | --list" >&2; exit 2; }
dir=$1; shift
[[ "${1:-}" == -- ]] && shift

work=$(mktemp -d); trap 'rm -rf "$work"' EXIT

not_run() {
  say "not run $1: $2"
  # GitHub Actions turns this line into a warning shown on the run's summary page.
  [[ "${GITHUB_ACTIONS:-}" == true ]] && echo "::warning title=Fiscal schema not run::$1: $2"
  return 0
}
stop() { say "$1: $2"; exit 1; }

# Removes line <n> of <file> only where it closes an element the line before has already closed: the one defect the
# published TEJISOPaysDevises.xsd has, which no XML parser loads past.
patch_line() {
  local set=$1 file=$2 n=$3 here before
  here=$(sed -n "${n}p" "$file" | tr -d '[:space:]'); before=$(sed -n "$((n - 1))p" "$file" | tr -d '[:space:]')
  [[ "$here" == '</xs:enumeration>' && "$before" == '</xs:enumeration>' ]] \
    || stop "$set" "line $n of $(basename "$file") is not the doubled closing tag"
  sed -i "${n}d" "$file"
}

# Fills <target> with the pinned folder <path> of commit <ref>, fetching only that folder's files.
fetch_git() {
  local set=$1 source=$2 ref=$3 path=$4 pin=$5 target=$6 repo="$work/$1.git" tree
  git init -q --bare "$repo"
  git -C "$repo" fetch -q --depth 1 --filter=blob:none "$source" "$ref" 2>"$work/$set.err" \
    || { not_run "$set" "$source did not answer ($(tail -n 1 "$work/$set.err"))"; return 1; }
  tree=$(git -C "$repo" rev-parse "FETCH_HEAD:$path" 2>/dev/null) || stop "$set" "the published schema is not the one pinned ($path is not in $ref)"
  [[ "$tree" == "$pin" ]] || stop "$set" "the published schema is not the one pinned (folder $tree, pinned $pin)"
  git -C "$repo" archive --format=tar "$tree" | tar -x -C "$target"
}

# Fills <target> with the folder <path> of the zip at <source>, whose SHA-256 must be <sum>.
fetch_zip() {
  local set=$1 source=$2 sum=$3 path=$4 target=$5 got
  curl -fsS --connect-timeout 20 --max-time 120 -o "$work/$set.zip" "$source" 2>"$work/$set.err" \
    || { not_run "$set" "$source did not answer ($(tail -n 1 "$work/$set.err"))"; return 1; }
  got=$(sha256sum "$work/$set.zip" | cut -d' ' -f1)
  [[ "$got" == "$sum" ]] || stop "$set" "the published schema is not the one pinned (zip $got, pinned $sum)"
  python3 -I -m zipfile -e "$work/$set.zip" "$work/$set.unzipped"
  cp -R "$work/$set.unzipped/$path/." "$target/"
}

declare -a named=()
while IFS=$'\t' read -r set variable kind source ref path pin names entry patch; do
  target="$dir/$set"
  rm -rf "$target"; mkdir -p "$target"
  case "$kind" in
    git) fetch_git "$set" "$source" "$ref" "$path" "$pin" "$target" || continue ;;
    zip) fetch_zip "$set" "$source" "$ref" "$path" "$target" || continue ;;
    *) stop "$set" "unknown kind $kind" ;;
  esac
  [[ -f "$target/$entry" ]] || stop "$set" "$entry is not in the pinned folder"
  if [[ "$patch" != - ]]; then patch_line "$set" "$target/${patch%:*}" "${patch##*:}"; fi
  if [[ "$names" == file ]]; then named+=("$variable=$target/$entry"); else named+=("$variable=$target"); fi
  say "fetched $set"
done < <(rows)

if [[ $# -eq 0 ]]; then
  printf '%s\n' "${named[@]}"
  exit 0
fi
exec env "${named[@]}" "$@"
