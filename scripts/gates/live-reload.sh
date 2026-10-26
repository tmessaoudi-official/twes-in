#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A screen that reads records a teammate can change reads them again when they change (`LiveChanges.reloadOn`), keeps an
# open record in step (`LiveRecord`, `RecordSync`), or, outside the shell, asks again on focus (`leaveOnceOpen`): pages
# that went without kept showing old prices, stock values and price lists until reloaded (audit 2026-10-06, C-7), and
# the panels inside pages (a customer's statement, the invoices home, an invoice's reminders) did the same while only
# `*-page.ts` was asked (hunt C1-C6). A site is any component under web/src/app, specs excluded, that reads through one of
# its members (a load, reload, find, list or read call on `this.<member>`). A screen that has a reason not to is listed
# below with that reason. Usage: live-reload.sh [--root DIR]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
declare -A exempt=(
  [web/src/app/import/import-page.ts]="reads the import's guide, the columns of a file, which no teammate changes"
  [web/src/app/inventory/location-labels-page.ts]="a sheet of labels read once for the printer"
  [web/src/app/inventory/stock-count-page.ts]="a count being typed: the places and products it counts against stay put under the person counting"
  [web/src/app/inventory/place-movement.ts]="inside the stock map, which reads its stock again on every change; it reads again after its own move"
  [web/src/app/platform/platform-legal-page.ts]="the legal texts the operators write, which no company changes and the platform's channel does not say"
  [web/src/app/account/account-page.ts]="reads the person's companies, which the shell reads again on every membership, company and grant change"
  [web/src/app/account/connected-devices.ts]="a device signing in is recorded outside any unit of work, so no change is said of it: read when opened"
  [web/src/app/account/notification-choices.ts]="the person's own choices, changed by them alone, here"
  [web/src/app/auth/two-factor-page.ts]="the person's own passkeys and factors, changed by them alone, and every change mailed to them"
  [web/src/app/auth/login-page.ts]="signed out, with no live connection: reads whether sign-up is open"
  [web/src/app/signup/signup-page.ts]="signed out, with no live connection: reads whether sign-up is open"
  [web/src/app/signup/finish-signup-page.ts]="signed out, with no live connection: reads the link it was opened from"
  [web/src/app/invitation/accept-invitation-page.ts]="signed out, with no live connection: reads the invitation it was opened from"
  [web/src/app/company/company-switcher.ts]="reads the companies the shell reads again on every membership, company and grant change, and again when a grant ends"
  [web/src/app/shared/form/colour-field.ts]="reads no record: a search in its own list of colours"
  [web/src/app/shared/legal/legal-text.ts]="a legal page's text, read when opened: a new version is validated by the operators, never by a teammate"
  [web/src/app/shared/scan/camera-view.ts]="reads a barcode from the camera, not a record"
)
mapfile -t files < <(cd "$root" && git ls-files -- 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
status=0
checked=0
for file in "${files[@]}"; do
  grep -q '@Component(' "$root/$file" 2>/dev/null || continue
  grep -qE 'this\.[A-Za-z]+\.(load|reload|find|list|read)[A-Za-z]*\(' "$root/$file" || continue
  [[ -n "${exempt[$file]:-}" ]] && continue
  checked=$((checked + 1))
  if ! grep -qE 'reloadOn|LiveRecord|liveRecord|RecordSync|leaveOnceOpen' "$root/$file"; then
    printf 'live-reload: FAIL — %s reads records but never calls reloadOn nor keeps a LiveRecord: a change made elsewhere stays invisible until a reload\n' "$file"
    status=1
  fi
done
if ((checked == 0)); then
  echo "live-reload: FAIL — no component reads through one of its members: the gate would pass over nothing"
  exit 1
fi
((status)) && exit 1
printf 'live-reload: OK — %d screens read again on a live change (%d exempt with a reason)\n' "$checked" "${#exempt[@]}"
