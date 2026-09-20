#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/permission-labels.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# A tree holding one permission class and the two translation files, with whatever labels the case wants.
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q
  mkdir -p "$d/api/src/Module/Shop/Infrastructure/Module" "$d/web/public/i18n" \
    "$d/api/src/ModuleRegistry/Infrastructure/Permission"
  cat > "$d/api/src/ModuleRegistry/Infrastructure/Permission/DeclaredPermissions.php" <<'PHP'
<?php
final readonly class DeclaredPermissions implements KnownPermissions
{
    private const array UNMODULED = [
        'members' => [MemberPermission::READ],
    ];
}
PHP
  cat > "$d/api/src/Module/Shop/Infrastructure/Module/ShopModule.php" <<'PHP'
<?php
final readonly class ShopModule implements DeclaresModule
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::KEY, 'modules.shop', [], [ShopPermission::READ]);
    }
}
PHP
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

# A whole translation file: the module heading, the unmoduled group heading, and the two permission labels. Every
# case starts from this and withholds exactly the one thing it is about, so a red names that thing and nothing else.
HEAD_MOD='"modules":{"shop":"Shop"}'
HEAD_GRP='"groups":{"members":"Team"}'
SHOP='"shop":{"read":"a","write":"b"}'
BOTH='{'"$HEAD_MOD"',"permissions":{'"$HEAD_GRP"','"$SHOP"'}}'

echo "permission-labels gate"

d=$(repo); labels "$d" "$BOTH" "$BOTH"; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=2 PERMISSION_GROUPS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "every permission labelled in both languages passes" $? 0 "$out" "OK — 2 permissions"
check "a platform permission is not wanted, so it is not counted" 0 0 "$out" "OK — 2 permissions"

d=$(repo); labels "$d" "$BOTH" '{'"$HEAD_MOD"',"permissions":{'"$HEAD_GRP"',"shop":{"read":"a"}}}'; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=2 PERMISSION_GROUPS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a label missing in one language only is caught, and names the language" $? 1 "$out" "en: permissions.shop.write"

d=$(repo); labels "$d" '{'"$HEAD_MOD"',"permissions":{'"$HEAD_GRP"',"shop":{"read":"a","write":"   "}}}' "$BOTH"; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=2 PERMISSION_GROUPS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a blank label is not a label" $? 1 "$out" "fr: permissions.shop.write"

d=$(repo); labels "$d" '{'"$HEAD_MOD"',"permissions":{'"$HEAD_GRP"',"shop":"a"}}' "$BOTH"; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=2 PERMISSION_GROUPS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a string where the nesting expects an object is caught, not crashed on" $? 1 "$out" "fr: permissions.shop.read"

# A group's HEADING is the line above the labels, and the matrix draws it the same way. The permission labels can
# all be present while the heading over them is a raw dotted key — which is how `modules.invoices` and
# `modules.expenses` shipped unlabelled under a green gate (row 104, found at the end-of-goal review).
d=$(repo); labels "$d" '{"permissions":{'"$HEAD_GRP"','"$SHOP"'}}' "$BOTH"; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=2 PERMISSION_GROUPS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a module group heading with no label is caught" $? 1 "$out" "fr: modules.shop"

d=$(repo); labels "$d" "$BOTH" '{"modules":{"shop":"  "},"permissions":{'"$HEAD_GRP"','"$SHOP"'}}'; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=2 PERMISSION_GROUPS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "a blank heading is not a heading" $? 1 "$out" "en: modules.shop"

# The three groups the screen shows that belong to no module are keyed permissions.groups.<key> by the collector.
d=$(repo); labels "$d" "$BOTH" "$BOTH"; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=2 PERMISSION_GROUPS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "the headings are counted in the report, so a silent zero is visible" $? 0 "$out" "headings"

# Same shape as the permission floor: a manifest pattern that stops matching must red, not compare nothing.
d=$(repo); labels "$d" "$BOTH" "$BOTH"; rm -f "$d/api/src/ModuleRegistry/Infrastructure/Permission/DeclaredPermissions.php"; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=2 PERMISSION_GROUPS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "the unmoduled groups' own source going missing reds, rather than dropping three headings quietly" $? 1 "$out" "DeclaredPermissions.php is missing"

d=$(repo); labels "$d" "$BOTH" "$BOTH"; rm -rf "$d/api/src/Module/Shop/Infrastructure"; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=2 PERMISSION_GROUPS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "no module manifest at all reds rather than passing over nothing" $? 1 "$out" "module manifest"

# The floor is what stops a broken discovery pattern comparing two empty sets and reading as a pass.
d=$(repo); labels "$d" "$BOTH" "$BOTH"; git -C "$d" add -A
out=$(PERMISSION_LABELS_FLOOR=99 PERMISSION_GROUPS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
check "fewer permissions than the floor reds rather than passing over nothing" $? 1 "$out" "below the floor"

d=$(mktemp -d); git -C "$d" init -q
out=$(bash "$GATE" --root "$d" 2>&1)
check "a tree with no permission class at all reds, rather than passing" $? 1 "$out" "found no *Permission.php"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
