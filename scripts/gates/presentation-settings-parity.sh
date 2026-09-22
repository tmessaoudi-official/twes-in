#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every presentation preference the SPA can write is a preference the API declares, and the reverse.
#
# The defect this exists for is SILENT on both sides of the wire. `ApiSettings.set` posts the choice and swallows
# the answer — `.catch(() => {})`, with the comment "Refused or unreachable: the page keeps the choice until it is
# reloaded" — so for a signed-in person a key the API does not declare works perfectly until the page is reloaded,
# and is then simply gone. Nothing logs, nothing warns, and the unit specs use the browser-storage adapter, where
# every key persists. `presentation.sidebar-settings` shipped that way and nobody noticed; `presentation.plan-labels`
# was caught only because an e2e reloaded the page and looked (docs/SPEC.md § 7, 2026-09-22).
#
# The mirror is checked too, for the reason `permission-labels.sh` learned: the two halves fail independently. A key
# declared on the API that no page reads is dead weight and usually a rename left half-done.
#
# Keys built by interpolation are invisible to any grep for a literal — `presentation.list.${listId}` is made by
# `listPreferencesSetting`, and the API declares it as the placeholder `presentation.list.<id>` with a keyPattern.
# Those are excluded by NAME, never counted as missing; that exclusion is the one thing here that is a list and not
# a discovery, so it is short and its shape is pinned by the tests beside this file.
# Usage: presentation-settings-parity.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2

registry="$root/web/src/app/shared/settings/settings-registry.ts"
declaration="$root/api/src/Settings/Application/PresentationSettings.php"

for file in "$registry" "$declaration"; do
  [[ -f "$file" ]] || {
    echo "presentation-settings-parity: FAIL — $file is missing"
    exit 1
  }
done

# The floors sit above zero and above one side alone, so a pattern that stops matching — a renamed file, a changed
# call — reds instead of comparing two empty sets and reporting agreement.
floor=${PRESENTATION_PARITY_FLOOR:-3}

# Every single-quoted `'presentation.…'` literal in the registry. Deliberately NOT anchored to `defineSetting(`:
# the call is wrapped over several lines whenever it carries a type argument, so anchoring to the call name reads
# one key where seven are declared — which is exactly what this gate's own floor caught on its first real run.
# A built key (`presentation.list.${listId}`) is a template literal and a keyPattern is a regex, so neither is
# single-quoted and neither is picked up here.
spa=$(grep -oE "'presentation\.[a-z0-9.-]+'" "$registry" | tr -d "'" | sort -u)
# The same on the API side, minus the `<id>` placeholders that stand for the built keys.
api=$(grep -oE "'presentation\.[a-z0-9.<>-]+'" "$declaration" | tr -d "'" | grep -v '<id>' | sort -u)

spa_count=$(printf '%s\n' "$spa" | grep -c . )
api_count=$(printf '%s\n' "$api" | grep -c . )
if [[ "$spa_count" -lt "$floor" || "$api_count" -lt "$floor" ]]; then
  echo "presentation-settings-parity: FAIL — below the floor ($spa_count in the registry, $api_count declared, floor $floor)"
  echo "  a pattern that stops matching must red here rather than compare two empty sets"
  exit 1
fi

unpersisted=$(comm -23 <(printf '%s\n' "$spa") <(printf '%s\n' "$api"))
unread=$(comm -13 <(printf '%s\n' "$spa") <(printf '%s\n' "$api"))

status=0
if [[ -n "$unpersisted" ]]; then
  echo "presentation-settings-parity: FAIL — the SPA writes keys the API does not declare, so they are lost on reload:"
  printf '  %s\n' $unpersisted
  echo "  declare them in api/src/Settings/Application/PresentationSettings.php, with a label in both languages"
  status=1
fi
if [[ -n "$unread" ]]; then
  echo "presentation-settings-parity: FAIL — the API declares keys no page can read:"
  printf '  %s\n' $unread
  echo "  register them in web/src/app/shared/settings/settings-registry.ts, or remove the declaration"
  status=1
fi

[[ "$status" -eq 0 ]] &&
  echo "presentation-settings-parity: OK — $spa_count presentation keys are registered and declared on both sides"
exit "$status"
