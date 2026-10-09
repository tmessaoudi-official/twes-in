#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/coming-texts.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

export COMING_TEXTS_PLANNED_FLOOR=2 COMING_TEXTS_SETTINGS_FLOOR=1 COMING_TEXTS_ROWS_FLOOR=2

# A tree with two planned modules (quotes, row 7; zakat, no row), one coming settings page (alerts, row 9), their
# texts in both languages and a roadmap where rows 7 and 9 are still to do.
repo() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/api/src/ModuleRegistry/Application" "$d/web/src/app/shell" "$d/web/public/i18n" "$d/docs"
  planned "$d" quotes zakat
  cat > "$d/web/src/app/shell/planned-nav.ts" <<'TS'
export const PLANNED_NAV: readonly PlannedPlace[] = [
  { key: 'quotes', icon: 'x', section: 'sell', after: 'invoices', row: 7 },
  {
    key: 'zakat',
    icon: 'x',
    section: 'manage',
    after: 'quotes',
  },
];

export function plannedNav() {}
TS
  cat > "$d/web/src/app/shell/nav-manifest.ts" <<'TS'
export const COMING_NAV: readonly (NavEntry & { readonly coming: Coming })[] = [
  {
    key: 'alerts',
    labelKey: 'nav.alerts',
    coming: { after: 'defaults', version: 'v1', row: 9 },
  },
];
TS
  texts "$d" "$ALL" "$ALL"
  roadmap "$d" "| 7 | Quotes | M | todo | - | |" "| 9 | Alerts | S | doing | - | |"
  echo "$d"
}
planned() {
  local d=$1; shift
  { echo '<?php'; echo 'return ['; for k in "$@"; do echo "    new ModuleManifest('$k', 'modules.$k', [], planned: 'v1'),"; done; echo '];'; } \
    > "$d/api/src/ModuleRegistry/Application/PlannedModules.php"
}
texts() { printf '%s\n' "$2" > "$1/web/public/i18n/fr.json"; printf '%s\n' "$3" > "$1/web/public/i18n/en.json"; }
roadmap() {
  local d=$1; shift
  { echo '## 7. Decisions Log'; echo '| 7 | not the status table | M | done | - | |'; echo '## 8. Status';
    echo '<!-- progress-block v1 -->'; echo '| # | Step | Size | State | Evidence | Files |'; echo '|---|---|---|---|---|---|';
    printf '%s\n' "$@"; echo '<!-- /progress-block -->'; } > "$d/docs/SPEC.md"
}
entry() { printf '"%s":{"heading":"h","does":"d"}' "$1"; }
ALL="{\"coming\":{\"version\":{\"v1\":\"V1\"},\"home\":\"Accueil\",$(entry quotes),$(entry zakat),$(entry alerts)}}"
LEFT="{\"coming\":{\"version\":{\"v1\":\"V1\"},$(entry quotes),$(entry zakat),$(entry alerts),$(entry statements)}}"
NO_DOES="{\"coming\":{$(entry quotes),$(entry zakat),\"alerts\":{\"heading\":\"h\"}}}"

d=$(repo)
out=$(bash "$GATE" --root "$d" 2>&1)
check "every coming entry with its texts, and no text without an entry, passes" $? 0 "$out" "OK — 3 coming entries"

# The point of the gate: a module that shipped leaves the planned list, and its « En construction » texts must go too.
d=$(repo); texts "$d" "$ALL" "$LEFT"
out=$(bash "$GATE" --root "$d" 2>&1)
check "« coming » texts left behind by a shipped module red, with the language" $? 1 "$out" "en: coming.statements"

d=$(repo); planned "$d" quotes
out=$(COMING_TEXTS_PLANNED_FLOOR=1 bash "$GATE" --root "$d" 2>&1)
check "texts and a menu place left behind once the API no longer plans the module red" $? 1 "$out" "zakat"

d=$(repo); texts "$d" "$ALL" "$NO_DOES"
out=$(bash "$GATE" --root "$d" 2>&1)
check "a coming settings page without what it will do reds" $? 1 "$out" "en: coming.alerts.does"

# The roadmap row an entry names must be one still to do: a row done means the thing shipped and its page is stale.
d=$(repo); roadmap "$d" "| 7 | Quotes | M | done | abc1234 | |" "| 9 | Alerts | S | doing | - | |"
out=$(bash "$GATE" --root "$d" 2>&1)
check "an entry whose roadmap row is done reds" $? 1 "$out" "quotes names row 7, which is done"

d=$(repo); roadmap "$d" "| 9 | Alerts | S | doing | - | |"
out=$(bash "$GATE" --root "$d" 2>&1)
check "a row the roadmap does not hold reds, even when another section has that number" $? 1 "$out" "quotes names row 7, which § 8 does not hold"

d=$(repo); rm "$d/docs/SPEC.md"
out=$(bash "$GATE" --root "$d" 2>&1)
check "a tree without the roadmap reds rather than passing" $? 1 "$out" "docs/SPEC.md"

# Floors: a pattern that no longer matches must not compare two empty sets and pass.
d=$(repo)
out=$(COMING_TEXTS_PLANNED_FLOOR=3 bash "$GATE" --root "$d" 2>&1)
check "fewer planned modules than the floor reds" $? 1 "$out" "planned modules, below the floor"

d=$(repo)
out=$(COMING_TEXTS_SETTINGS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "fewer coming settings pages than the floor reds" $? 1 "$out" "coming settings pages, below the floor"

d=$(repo)
out=$(COMING_TEXTS_ROWS_FLOOR=3 bash "$GATE" --root "$d" 2>&1)
check "fewer roadmap rows named than the floor reds" $? 1 "$out" "rows named, below the floor"

d=$(repo); texts "$d" "$ALL" '{"coming":'
out=$(bash "$GATE" --root "$d" 2>&1)
check "a translation file that is not JSON reds rather than passing" $? 1 "$out" "en: coming.quotes.heading"

d=$(mktemp -d)
out=$(bash "$GATE" --root "$d" 2>&1)
check "a tree without PlannedModules reds, rather than passing" $? 1 "$out" "PlannedModules.php"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
