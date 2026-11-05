#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A list that could not be read offers « Réessayer » where it says so (DataList's failed state), and pressing it reads
# the list again: a list the API pages asks its page again through `(queryChange)`, any other list hands `(retry)` to
# what reads it. Before, the failed line told the person to reload the whole page (conf-44). Every `<app-data-list>`
# tag binding `[failed]` binds one of the two, on the tag itself. Nor does it take its failure from a facade's whole
# `error()`: a refused save sets that too, and on an empty list it would read as a list that could not be read, with a
# « Réessayer » that undoes nothing; it binds `readFailed()`, which only a read's failure sets. Reads templates from the index, specs excluded, and
# reds below a floor of lists that can fail, so a pattern that stops matching cannot pass over nothing.
# Usage: list-retry.sh [--root DIR] [--floor N]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=31
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "list-retry: unknown argument $1"; exit 2 ;;
  esac
done
mapfile -t files < <(cd "$root" && git ls-files -- 'web/src/app/*.html')
status=0
lists=0
for file in "${files[@]}"; do
  # Each opening tag, with the line it starts on and whether it binds a failure and a way to read again.
  while IFS=$'\t' read -r line fails reads shared; do
    [[ -z "$line" ]] && continue
    ((fails)) || continue
    lists=$((lists + 1))
    if ((reads == 0)); then
      printf 'list-retry: FAIL — %s:%s binds [failed] and neither (retry) nor (queryChange): « Réessayer » would read nothing again\n' "$file" "$line"
      status=1
    fi
    if ((shared)); then
      printf 'list-retry: FAIL — %s:%s takes [failed] from error(), which a refused save sets too: bind readFailed()\n' "$file" "$line"
      status=1
    fi
  done < <(perl -0ne 'while (/<app-data-list\b((?:[^>"]|"[^"]*")*)>/g) { my ($tag, $at) = ($1, $-[0]); my $line = 1 + (() = substr($_, 0, $at) =~ /\n/g); my ($failed) = $tag =~ /\[failed\]="([^"]*)"/; printf "%d\t%d\t%d\t%d\n", $line, ($tag =~ /\[failed\]=/ ? 1 : 0), ($tag =~ /\((retry|queryChange)\)=/ ? 1 : 0), (defined $failed && $failed =~ /\berror\(\)/ ? 1 : 0) }' "$root/$file")
done
if ((lists < floor)); then
  printf 'list-retry: FAIL — %d lists bind [failed], below the floor of %d: the pattern stopped matching\n' "$lists" "$floor"
  exit 1
fi
((status)) && exit 1
printf 'list-retry: OK — %d lists that can fail read again on « Réessayer »\n' "$lists"
