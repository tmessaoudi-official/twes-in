#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/setting-labels.sh
pass=0
fail=0
check() {
  if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then
    pass=$((pass + 1))
    echo "  ok   $1"
  else
    fail=$((fail + 1))
    echo "  FAIL $1 (exit $2, wanted $3 with '$5')"
    echo "$4" | sed 's/^/       /'
  fi
}

# A tree holding one settings declaration, the chain enum and the two translation files.
# The language catalogue the gate reads its languages from: each tag given, spoken by the interface.
speaks() {
  local d=$1 tag sep=''; shift
  mkdir -p "$d/web/src/app/shared/i18n"
  { printf '{"default": "fr", "languages": ['; for tag in "$@"; do printf '%s{"tag": "%s", "interface": true}' "$sep" "$tag"; sep=', '; done; printf ']}\n'; } \
    > "$d/web/src/app/shared/i18n/languages.json"
}
repo() {
  local d
  d=$(mktemp -d)
  git -C "$d" init -q
  mkdir -p "$d/api/src/Shop/Application" "$d/api/src/Settings/Domain/ValueObject" "$d/web/public/i18n"
  cat > "$d/api/src/Settings/Domain/ValueObject/SettingChain.php" <<'PHP'
<?php
enum SettingChain: string
{
    case Parties = 'parties';
    case Venue = 'venue';
    case Platform = 'platform';

    public static function ofCompanies(): array
    {
        return [self::Parties, self::Venue];
    }
}
PHP
  cat > "$d/api/src/Shop/Application/ShopSettings.php" <<'PHP'
<?php
final readonly class ShopSettings implements DeclaresSettings
{
    public function settings(): iterable
    {
        yield new SettingDefinition('shop.hours', SettingType::Text, '', $c, $l, 'settings.shop.hours', 'shop');
        yield new SettingDefinition('shop.colour', SettingType::Text, '', $c, $l, 'settings.shop.colour', 'shop');
        yield new SettingDefinition('shop.side-door', SettingType::Enum, 'open', $c, $l, 'settings.shop.side_door', 'shop', choices: ['open', 'shut']);
        yield new SettingDefinition('platform.seats', SettingType::Int, 1, $c, $l, 'settings.platform.seats', 'core');
        yield new SettingDefinition('platform.phone', SettingType::Text, '', $c, $l, 'settings.platform.phone', 'core');
        yield new SettingDefinition('shop.list.<id>', SettingType::Json, null, $c, $l, 'settings.shop.list', 'shop', keyPattern: self::LIST);
    }
}
PHP
  git -C "$d" add -A >/dev/null 2>&1
  speaks "$d" fr en
  echo "$d"
}

# Both languages, with whatever the case asks left out.
translations() {
  local d=$1 drop=${2:-}
  local language
  for language in fr en; do
    DROP="$drop" python3 -c '
import json, os, sys
doc = {"settings": {"chains": {"parties": "Clients", "venue": "Plan"}, "platform": {"seats": "Places"},
                    "shop": {"hours": "Horaires", "colour": "Couleur", "side_door": "Porte"},
                    "choices": {"shop": {"side-door": {"open": "Ouverte", "shut": "Fermée"}}}}}
drop = os.environ["DROP"]
if drop == "label":
    del doc["settings"]["shop"]["colour"]
if drop == "chain":
    del doc["settings"]["chains"]["venue"]
if drop == "choice":
    del doc["settings"]["choices"]["shop"]["side-door"]["shut"]
if drop == "platform":
    del doc["settings"]["platform"]["seats"]
if drop == "blank":
    doc["settings"]["shop"]["colour"] = "   "
json.dump(doc, open(sys.argv[1], "w", encoding="utf-8"), ensure_ascii=False)
' "$d/web/public/i18n/$language.json"
  done
}

# The fixture tree declares two settings and two chains; the gate's real floors are sized for the real tree, so
# every case but the floor cases themselves passes floors this fixture can meet.
gate() { SETTING_LABELS_FLOOR=2 SETTING_CHAINS_FLOOR=2 SETTING_CHOICES_FLOOR=2 SETTING_PLATFORM_FLOOR=1 bash "$GATE" "$@"; }

echo "setting-labels gate"

d=$(repo) && translations "$d"
out=$(gate --root "$d" 2>&1)
code=$?
# The platform's text setting and the key-pattern one are deliberately left out: no page draws their labels.
check "a fully labelled tree passes" "$code" 0 "$out" "3 settings, 1 platform numbers, 2 choices and 2 chain headings"

# The operators' page draws every whole-number platform setting by its declared label, as its delays.
d=$(repo) && translations "$d" platform
out=$(gate --root "$d" 2>&1)
code=$?
check "an unlabelled platform number fails" "$code" 1 "$out" "settings.platform.seats"

d=$(repo) && translations "$d"
out=$(SETTING_LABELS_FLOOR=2 SETTING_CHAINS_FLOOR=2 SETTING_CHOICES_FLOOR=2 SETTING_PLATFORM_FLOOR=99 bash "$GATE" --root "$d" 2>&1)
code=$?
check "too few platform numbers found is a broken pattern, not a pass" "$code" 1 "$out" "below the floor"

d=$(repo) && translations "$d" label
out=$(gate --root "$d" 2>&1)
code=$?
check "an unlabelled setting fails" "$code" 1 "$out" "settings.shop.colour"

d=$(repo) && translations "$d" chain
out=$(gate --root "$d" 2>&1)
code=$?
# The half the permissions gate learned to check: every setting labelled, the heading above them raw.
check "an unlabelled chain heading fails" "$code" 1 "$out" "settings.chains.venue"

# A choice is drawn in the page's select by its own label (settings.choices.<key>.<choice>), and was the half no
# gate read: two presentation selects showed raw keys on the company settings page (2026-09-23).
d=$(repo) && translations "$d" choice
out=$(gate --root "$d" 2>&1)
code=$?
check "an unlabelled choice fails" "$code" 1 "$out" "settings.choices.shop.side-door.shut"

# A choice list the gate cannot read is refused rather than skipped: a constant would hide every choice behind it.
d=$(repo) && translations "$d"
python3 - "$d" <<'PY'
import pathlib, sys
p = pathlib.Path(sys.argv[1], 'api/src/Shop/Application/ShopSettings.php')
p.write_text(p.read_text().replace("choices: ['open', 'shut']", 'choices: self::DOORS'))
PY
out=$(gate --root "$d" 2>&1)
code=$?
check "a choice list that is not written out fails" "$code" 1 "$out" "not a literal list"

# A language setting offers the languages the catalogue names: the language dropdown names each one itself, so it
# asks no label, and a language the catalogue adds asks none either.
language_tree() {
  local d=$1
  python3 - "$d" <<'PY'
import json, pathlib, sys
root = pathlib.Path(sys.argv[1])
(root / 'api/config').mkdir(parents=True, exist_ok=True)
entry = lambda tag, documents: {"tag": tag, "name": tag, "direction": "ltr", "script": "Latn", "plurals": ["one", "other"],
                                "interface": True, "documents": documents, "legal": True}
(root / 'api/config/languages.json').write_text(json.dumps({"default": "fr", "languages": [entry("fr", True), entry("de", True), entry("it", False)]}))
p = root / 'api/src/Shop/Application/ShopSettings.php'
p.write_text(p.read_text().replace("keyPattern: self::LIST);", "keyPattern: self::LIST);\n        yield new SettingDefinition('shop.letters', SettingType::Enum, 'fr', $c, $l, 'settings.shop.letters', 'shop', choices: $this->languages->documents());"))
for language in ('fr', 'en'):
    t = root / f'web/public/i18n/{language}.json'
    doc = json.loads(t.read_text())
    doc["settings"]["shop"]["letters"] = "Lettres"
    doc["settings"]["choices"]["shop"]["letters"] = {"fr": "Français", "de": "Allemand"}
    t.write_text(json.dumps(doc, ensure_ascii=False))
PY
  git -C "$d" add -A >/dev/null 2>&1
}

d=$(repo) && translations "$d" && language_tree "$d"
out=$(gate --root "$d" 2>&1)
code=$?
check "a language setting's choices ask no label" "$code" 0 "$out" "4 settings, 1 platform numbers, 2 choices"

d=$(repo) && translations "$d" && language_tree "$d"
python3 - "$d" <<'PY'
import json, pathlib, sys
p = pathlib.Path(sys.argv[1], 'api/config/languages.json')
doc = json.loads(p.read_text())
doc["languages"][2]["documents"] = True
p.write_text(json.dumps(doc))
PY
out=$(gate --root "$d" 2>&1)
code=$?
check "a language the catalogue adds asks no label in every other language" "$code" 0 "$out" "2 choices"

d=$(repo) && translations "$d" && language_tree "$d"
python3 - "$d" <<'PY'
import pathlib, sys
p = pathlib.Path(sys.argv[1], 'api/src/Shop/Application/ShopSettings.php')
p.write_text(p.read_text().replace('$this->languages->documents()', '$this->languages->spoken()'))
PY
out=$(gate --root "$d" 2>&1)
code=$?
check "a call the gate does not know is still not a literal list" "$code" 1 "$out" "not a literal list"

d=$(repo) && translations "$d"
out=$(SETTING_LABELS_FLOOR=2 SETTING_CHAINS_FLOOR=2 SETTING_CHOICES_FLOOR=99 bash "$GATE" --root "$d" 2>&1)
code=$?
check "too few choices found is a broken pattern, not a pass" "$code" 1 "$out" "below the floor"

d=$(repo) && translations "$d" blank
out=$(gate --root "$d" 2>&1)
code=$?
check "a blank label is not a label" "$code" 1 "$out" "settings.shop.colour"

# The floor: without it a pattern that stops matching compares two empty sets and reads as a pass.
d=$(repo) && translations "$d"
out=$(SETTING_LABELS_FLOOR=99 bash "$GATE" --root "$d" 2>&1)
code=$?
check "too few settings found is a broken pattern, not a pass" "$code" 1 "$out" "below the floor"

d=$(repo) && translations "$d"
out=$(SETTING_CHAINS_FLOOR=99 bash "$GATE" --root "$d" 2>&1)
code=$?
check "too few chains found is a broken pattern, not a pass" "$code" 1 "$out" "below the floor"

# A key built by interpolation is invisible to a grep, which is exactly how this gate was found to be vacuous on
# the venue settings: the tree still parses and still runs, and the gate must notice that it sees fewer keys.
d=$(repo) && translations "$d"
python3 - "$d" <<'PY'
import pathlib, sys
p = pathlib.Path(sys.argv[1], 'api/src/Shop/Application/ShopSettings.php')
p.write_text(p.read_text().replace("'settings.shop.colour'", '"settings.shop.$name"'))
PY
out=$(SETTING_LABELS_FLOOR=2 bash "$GATE" --root "$d" 2>&1)
code=$?
check "an interpolated label key is caught by the floor" "$code" 1 "$out" "below the floor"

d=$(repo) && translations "$d" && rm "$d/api/src/Settings/Domain/ValueObject/SettingChain.php"
out=$(gate --root "$d" 2>&1)
code=$?
check "a missing chain enum fails rather than skipping the headings" "$code" 1 "$out" "cannot be discovered"

d=$(mktemp -d) && git -C "$d" init -q && mkdir -p "$d/web/public/i18n"
out=$(bash "$GATE" --root "$d" 2>&1)
code=$?
check "a tree with no declaration at all fails" "$code" 1 "$out" "discovery pattern is broken"


# The languages labelled are the catalogue's, so switching one on asks for its labels.
d=$(repo) && translations "$d" && speaks "$d" fr en ar
out=$(gate --root "$d" 2>&1)
check "a language the catalogue switches on without its labels reds" $? 1 "$out" "ar: settings."
d=$(repo) && translations "$d" && rm "$d/web/src/app/shared/i18n/languages.json"
out=$(gate --root "$d" 2>&1)
check "a tree without the language catalogue reds rather than checking no language" $? 1 "$out" "no language catalogue"

echo
echo "$pass passed, $fail failed"
[[ "$fail" -eq 0 ]]
