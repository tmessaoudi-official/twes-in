#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/permission-labels.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# A tree holding one permission class and the two translation files, with whatever labels the case wants.
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q
  mkdir -p "$d/api/src/Module/Shop" "$d/web/public/i18n"
  cat > "$d/api/src/Module/Shop/ShopPermission.php" <<'PHP'
<?php
final class ShopPermission
{
    public const string READ = 'shop.read';
    public const string WRITE = 'shop.write';
    public const string PLATFORM = 'platform.shop.audit';
}
PHP
  echo "$d"
}
labels() { printf '%s\n' "$2" > "$1/web/public/i18n/fr.json"; printf '%s\n' "$3" > "$1/web/public/i18n/en.json"; }
BOTH='{"permissions":{"shop":{"read":"a","write":"b"}}}'

echo "permission-labels gate"

d=$(repo); labels "$d" "$BOTH" "$BOTH"; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "every permission labelled in both languages passes" $? 0 "$out" "OK — 2 permissions"
check "a platform permission is not wanted, so it is not counted" 0 0 "$out" "OK — 2 permissions"

d=$(repo); labels "$d" "$BOTH" '{"permissions":{"shop":{"read":"a"}}}'; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a label missing in one language only is caught, and names the language" $? 1 "$out" "en: permissions.shop.write"

d=$(repo); labels "$d" '{"permissions":{"shop":{"read":"a","write":"   "}}}' "$BOTH"; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a blank label is not a label" $? 1 "$out" "fr: permissions.shop.write"

d=$(repo); labels "$d" '{"permissions":{"shop":"a"}}' "$BOTH"; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a string where the nesting expects an object is caught, not crashed on" $? 1 "$out" "fr: permissions.shop.read"

# The floor is what stops a broken discovery pattern comparing two empty sets and reading as a pass.
d=$(repo); labels "$d" "$BOTH" "$BOTH"; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=99 bash "$GATE" --root "$d" 2>&1)
check "fewer permissions than the floor reds rather than passing over nothing" $? 1 "$out" "below the floor"

d=$(mktemp -d); git -C "$d" init -q
out=$(bash "$GATE" --root "$d" 2>&1)
check "a tree with no permission class at all reds, rather than passing" $? 1 "$out" "found no *Permission.php"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
