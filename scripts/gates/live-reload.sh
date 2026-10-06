#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A page that reads records a teammate can change reads them again when they change (`LiveChanges.reloadOn`), or keeps an
# open record in step (`LiveRecord`, `RecordSync`): three pages that read through their facade or their API went without
# either, and kept showing old prices, stock values and price lists until reloaded (audit 2026-10-06, C-7). A site is a
# `*-page.ts` from the index, specs excluded, that reads through `this.facade` or `this.api` (a load, reload, find, list or
# read call). A page that has a reason not to is listed below with that reason. Usage: live-reload.sh [--root DIR]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
declare -A exempt=(
  [web/src/app/import/import-page.ts]="reads the import's guide, the columns of a file, which no teammate changes"
  [web/src/app/inventory/location-labels-page.ts]="a sheet of labels read once for the printer"
  [web/src/app/inventory/stock-count-page.ts]="a count being typed: the places and products it counts against stay put under the person counting"
  [web/src/app/platform/platform-legal-page.ts]="an operator's screen: the platform hears no company's live changes"
)
mapfile -t pages < <(cd "$root" && git ls-files -- 'web/src/app/*-page.ts' | grep -v '\.spec\.ts$')
status=0
checked=0
for page in "${pages[@]}"; do
  grep -qE 'this\.(facade|api)\.(load|reload|find|list|read)[A-Za-z]*\(' "$root/$page" 2>/dev/null || continue
  [[ -n "${exempt[$page]:-}" ]] && continue
  checked=$((checked + 1))
  if ! grep -qE 'reloadOn|LiveRecord|liveRecord|RecordSync' "$root/$page"; then
    printf 'live-reload: FAIL — %s reads records but never calls reloadOn nor keeps a LiveRecord: a change made elsewhere stays invisible until a reload\n' "$page"
    status=1
  fi
done
if ((checked == 0)); then
  echo "live-reload: FAIL — no page reads through its facade or API: the gate would pass over nothing"
  exit 1
fi
((status)) && exit 1
printf 'live-reload: OK — %d pages read again on a live change (%d exempt with a reason)\n' "$checked" "${#exempt[@]}"
