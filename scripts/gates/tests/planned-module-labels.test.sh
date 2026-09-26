#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/planned-module-labels.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# A tree holding a PlannedModules file with two planned modules and the two translation files.
repo() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/api/src/ModuleRegistry/Application" "$d/web/public/i18n"
  cat > "$d/api/src/ModuleRegistry/Application/PlannedModules.php" <<'PHP'
<?php
final readonly class PlannedModules
{
    public function __construct(?array $manifests = null)
    {
        $this->manifests = $manifests ?? [
            new ModuleManifest('quotes', 'modules.quotes', ['customers'], planned: 'v1'),
            new ModuleManifest('zakat', 'modules.zakat', [], planned: 'later'),
        ];
    }
}
PHP
  mkdir -p "$d/web/src/app/shell"
  places "$d" quotes zakat
  echo "$d"
}
# The web's menu places (web/src/app/shell/planned-nav.ts): one `{ key: '<k>', ... }` per planned module given.
places() {
  local d=$1; shift
  { echo "export const PLANNED_NAV = ["; for k in "$@"; do echo "  { key: '$k', icon: 'x', section: 'sell', after: 'invoices' },"; done; echo "];"; } \
    > "$d/web/src/app/shell/planned-nav.ts"
}
labels() { printf '%s\n' "$2" > "$1/web/public/i18n/fr.json"; printf '%s\n' "$3" > "$1/web/public/i18n/en.json"; }

BOTH='{"modules":{"quotes":"q","zakat":"z"},"coming":{"quotes":{"heading":"h","does":"d"},"zakat":{"heading":"h","does":"d"}}}'
NO_DOES='{"modules":{"quotes":"q","zakat":"z"},"coming":{"quotes":{"heading":"h","does":"d"},"zakat":{"heading":"h"}}}'
NO_NAME='{"modules":{"quotes":"q"},"coming":{"quotes":{"heading":"h","does":"d"},"zakat":{"heading":"h","does":"d"}}}'
BLANK='{"modules":{"quotes":"q","zakat":"  "},"coming":{"quotes":{"heading":"h","does":"d"},"zakat":{"heading":"h","does":"d"}}}'

d=$(repo); labels "$d" "$BOTH" "$BOTH"
out=$(PLANNED_MODULE_LABELS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "every planned module named and described in both languages passes" $? 0 "$out" "OK — 2 planned modules"

d=$(repo); labels "$d" "$BOTH" "$NO_DOES"
out=$(PLANNED_MODULE_LABELS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a description missing in one language is caught, and names the language" $? 1 "$out" "en: coming.zakat.does"

d=$(repo); labels "$d" "$NO_NAME" "$BOTH"
out=$(PLANNED_MODULE_LABELS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a module without its name is caught" $? 1 "$out" "fr: modules.zakat"

d=$(repo); labels "$d" "$BLANK" "$BOTH"
out=$(PLANNED_MODULE_LABELS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a blank name is not a name" $? 1 "$out" "fr: modules.zakat"

# The floor is what stops a broken discovery pattern comparing two empty sets and reading as a pass.
d=$(repo); labels "$d" "$BOTH" "$BOTH"
out=$(PLANNED_MODULE_LABELS_FLOOR=3 bash "$GATE" --root "$d" 2>&1)
check "fewer planned modules than the floor reds rather than passing over nothing" $? 1 "$out" "below the floor"

# A translation file that cannot be read labels nothing: it must red with every key, never pass with none reported.
d=$(repo); labels "$d" "$BOTH" "$BOTH"; rm "$d/web/public/i18n/en.json"
out=$(PLANNED_MODULE_LABELS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a missing translation file reds, with every key in that language" $? 1 "$out" "6 label(s) missing"

d=$(repo); labels "$d" "$BOTH" '{"modules":'
out=$(PLANNED_MODULE_LABELS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a translation file that is not JSON reds rather than passing" $? 1 "$out" "en: modules.quotes"

# Row 150's menu: a planned module the web has no place for would never be drawn, silently.
d=$(repo); labels "$d" "$BOTH" "$BOTH"; places "$d" quotes
out=$(PLANNED_MODULE_LABELS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a planned module without a place in the menu is caught" $? 1 "$out" "no menu place: zakat"

d=$(repo); labels "$d" "$BOTH" "$BOTH"; rm "$d/web/src/app/shell/planned-nav.ts"
out=$(PLANNED_MODULE_LABELS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a tree without planned-nav.ts reds with every module, rather than passing" $? 1 "$out" "no menu place: quotes"

d=$(mktemp -d)
out=$(bash "$GATE" --root "$d" 2>&1)
check "a tree without PlannedModules reds, rather than passing" $? 1 "$out" "PlannedModules.php is missing"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
