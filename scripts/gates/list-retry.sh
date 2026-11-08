#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A list that could not be read offers « Réessayer » where it says so (DataList's failed state), and pressing it reads
# the list again: a list the API pages asks its page again through `(queryChange)`, any other list hands `(retry)` to
# what reads it. Before, the failed line told the person to reload the whole page (conf-44). Every `<app-data-list>`
# tag binding `[failed]` binds one of the two, on the tag itself. Nor does it take its failure from a facade's whole
# `error()`: a refused save sets that too, and on an empty list it would read as a list that could not be read, with a
# « Réessayer » that undoes nothing; it binds `readFailed()`, which only a read's failure sets. Reads templates from the index, specs excluded, and
# reds below a floor of lists that can fail, so a pattern that stops matching cannot pass over nothing.
# A paged list, binding `(queryChange)`, asks the API with its filters, which the API refuses with `filter_refused` when
# one is no value of its filter (an address kept from before): « Réessayer » would meet the same refusal, so the tag also
# binds `[refusedFilter]` (a facade's `readRefusedFilter()`), and DataList offers to clear the filters instead, or the
# comment just before the tag says why its list is never refused a filter, `<!-- filter-refusal: <why> -->`. Those told
# count against a floor of their own.
# Each list told a refused filter names the filter from the parameter the API refused, through the names its filters are
# sent as; `web/src/app/filters-sent-as.spec.ts` holds those names to each list's real read, a row a list. Its rows, by
# the folder their descriptor is imported from, match the lists told a refused filter, folder by folder: a list told one
# and missing from the table would name nothing it refused, unheld.
# Usage: list-retry.sh [--root DIR] [--floor N] [--refused-floor N] [--table FILE|none]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=31
refused_floor=14
table=web/src/app/filters-sent-as.spec.ts
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    --refused-floor) refused_floor=$2; shift 2 ;;
    --table) table=$2; shift 2 ;;
    *) echo "list-retry: unknown argument $1"; exit 2 ;;
  esac
done
mapfile -t files < <(cd "$root" && git ls-files -- 'web/src/app/*.html')
status=0
lists=0
told=0
told_in=()
for file in "${files[@]}"; do
  # Each opening tag, with the line it starts on, whether it binds a failure and a way to read again, whether it is a
  # paged list, whether it is told a filter was refused, and whether the comment just before it says why it is not.
  while IFS=$'\t' read -r line fails reads shared paged refused excused; do
    [[ -z "$line" ]] && continue
    ((fails)) || continue
    lists=$((lists + 1))
    if ((refused)); then
      told=$((told + 1))
      folder=${file#web/src/app/}
      told_in+=("${folder%%/*}")
    fi
    if ((paged && !refused && !excused)); then
      printf 'list-retry: FAIL — %s:%s asks the API with its filters and binds no [refusedFilter]: a refused filter would offer « Réessayer », which meets it again (bind readRefusedFilter(), or say <!-- filter-refusal: <why> --> before the tag)\n' "$file" "$line"
      status=1
    fi
    if ((reads == 0)); then
      printf 'list-retry: FAIL — %s:%s binds [failed] and neither (retry) nor (queryChange): « Réessayer » would read nothing again\n' "$file" "$line"
      status=1
    fi
    if ((shared)); then
      printf 'list-retry: FAIL — %s:%s takes [failed] from error(), which a refused save sets too: bind readFailed()\n' "$file" "$line"
      status=1
    fi
  done < <(perl -0ne 'while (/<app-data-list\b((?:[^>"]|"[^"]*")*)>/g) { my ($tag, $at) = ($1, $-[0]); my $line = 1 + (() = substr($_, 0, $at) =~ /\n/g); my ($failed) = $tag =~ /\[failed\]="([^"]*)"/; my $before = substr($_, 0, $at); printf "%d\t%d\t%d\t%d\t%d\t%d\t%d\n", $line, ($tag =~ /\[failed\]=/ ? 1 : 0), ($tag =~ /\((retry|queryChange)\)=/ ? 1 : 0), (defined $failed && $failed =~ /\berror\(\)/ ? 1 : 0), ($tag =~ /\(queryChange\)=/ ? 1 : 0), ($tag =~ /\[refusedFilter\]=/ ? 1 : 0), ($before =~ /<!--\s*filter-refusal:\s*[^\s-](?:(?!-->).)*-->\s*\z/s ? 1 : 0) }' "$root/$file")
done
if ((lists < floor)); then
  printf 'list-retry: FAIL — %d lists bind [failed], below the floor of %d: the pattern stopped matching\n' "$lists" "$floor"
  exit 1
fi
if ((told < refused_floor)); then
  printf 'list-retry: FAIL — %d paged lists are told a refused filter, below the refused-filter floor of %d: the pattern stopped matching\n' "$told" "$refused_floor"
  exit 1
fi
held=''
if [[ "$table" != none ]]; then
  if [[ ! -f "$root/$table" ]]; then
    printf 'list-retry: FAIL — found no %s, the table holding each list told a refused filter to the names its read sends\n' "$table"
    exit 1
  fi
  # Each row's descriptor, then the folder of the import naming it: `<folder>`, or `?<name>` when no import names it.
  mapfile -t rows < <(perl -0ne 'my %from; while (/import\s*(?:type\s*)?\{([^}]*)\}\s*from\s*\x27\.\/([^\/\x27]+)\//g) { my $f = $2; $from{$_} = $f for grep { length } map { s/^\s+|\s+$//gr } split /,/, $1 } my ($list) = /const LISTS\b.*?=\s*\[(.*?)\n\];/s; $list //= ""; while ($list =~ /descriptor:\s*([A-Za-z_]\w*)/g) { print exists $from{$1} ? "$from{$1}\n" : "?$1\n" }' "$root/$table")
  if ((${#rows[@]} == 0)); then
    printf 'list-retry: FAIL — %s holds no row: the pattern stopped matching\n' "$table"
    exit 1
  fi
  for row in "${rows[@]}"; do
    if [[ "$row" == '?'* ]]; then
      printf 'list-retry: FAIL — %s: a row descriptor, %s, is imported from no folder of web/src/app\n' "$table" "${row#?}"
      status=1
    fi
  done
  while read -r folder; do
    [[ -z "$folder" ]] && continue
    t=$(printf '%s\n' "${told_in[@]}" | grep -cx -e "$folder")
    r=$(printf '%s\n' "${rows[@]}" | grep -cx -e "$folder")
    if ((t != r)); then
      printf 'list-retry: FAIL — %s: %d lists told a refused filter, %d rows of %s: each list told one is a row of the table, and each row a list\n' "$folder" "$t" "$r" "$table"
      status=1
    fi
  done < <(printf '%s\n' "${told_in[@]}" "${rows[@]}" | grep -v '^?' | sort -u)
  held=", each a row of $table"
fi
((status)) && exit 1
printf 'list-retry: OK — %d lists that can fail read again on « Réessayer », %d told a refused filter%s\n' "$lists" "$told" "$held"
