#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A page that could not read something says so through the one shared line, `<app-read-failed>`, whose « Réessayer »
# hands `(retry)` to the page to read as on opening (S5.22's canon); it is offered only after a read failed, never
# under a refused save. Every `<app-read-failed>` binds `(retry)` on its tag. No page draws its own `feedback.retry`
# button: only the shared line, DataList's failed state, the activity bar and a home panel (its button named by
# `feedback.retry_panel`) do. A line that offers no retry says why beside it, `<!-- read-retry: <why> -->`, and the why
# is never empty. Reads templates and components from the index, specs excluded, and reds below a floor of shared
# lines, so a pattern that stops matching cannot pass over nothing.
# Usage: read-retry.sh [--root DIR] [--floor N]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=44
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "read-retry: unknown argument $1"; exit 2 ;;
  esac
done
# Where a « Réessayer » of its own is the canon itself.
canon=(
  web/src/app/shared/feedback/read-failed.ts
  web/src/app/shared/list/data-list.html
  web/src/app/shared/feedback/activity-bar.ts
)
# An exclude pathspec (':!*.spec.ts') lists nothing under the toolchain's git once a spec is staged: filtered instead.
mapfile -t files < <(cd "$root" && git ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
status=0
shared=0
reasons=0
for file in "${files[@]}"; do
  [[ -f "$root/$file" ]] || continue
  while IFS=$'\t' read -r kind line; do
    [[ -z "$kind" ]] && continue
    case $kind in
      shared) shared=$((shared + 1)) ;;
      unbound)
        shared=$((shared + 1))
        printf 'read-retry: FAIL — %s:%s an <app-read-failed> without (retry): « Réessayer » would read nothing again\n' "$file" "$line"
        status=1 ;;
      reason) reasons=$((reasons + 1)) ;;
      noreason)
        printf 'read-retry: FAIL — %s:%s a read-retry: marker that says no why\n' "$file" "$line"
        status=1 ;;
      own)
        skip=0
        for allowed in "${canon[@]}"; do [[ "$file" == "$allowed" ]] && skip=1; done
        if ((skip == 0)); then
          printf "read-retry: FAIL — %s:%s draws its own « Réessayer »: say the failure through <app-read-failed>\n" "$file" "$line"
          status=1
        fi ;;
    esac
  done < <(perl -0ne '
    sub at { 1 + (() = substr($_, 0, $_[0]) =~ /\n/g) }
    while (/<app-read-failed\b((?:[^>"]|"[^"]*")*)>/g) { printf "%s\t%d\n", ($1 =~ /\(retry\)=/ ? "shared" : "unbound"), at($-[0]) }
    while (/read-retry:(.*?)-->/gs) { my $why = $1; printf "%s\t%d\n", ($why =~ /\S/ ? "reason" : "noreason"), at($-[0]) }
    while (/\x27feedback\.retry\x27/g) {
      my $here = $-[0]; my $before = substr($_, $here > 400 ? $here - 400 : 0, $here > 400 ? 400 : $here);
      # A home panel names its button by the panel it reads again.
      next if $before =~ /feedback\.retry_panel[^<]*$/s;
      printf "own\t%d\n", at($here)
    }' "$root/$file")
done
if ((shared < floor)); then
  printf 'read-retry: FAIL — %d lines go through app-read-failed, below the floor of %d: the pattern stopped matching\n' "$shared" "$floor"
  exit 1
fi
((status)) && exit 1
printf 'read-retry: OK — %d lines read again through app-read-failed, %d say why not\n' "$shared" "$reasons"
