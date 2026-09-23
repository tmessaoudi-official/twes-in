#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every setting a company may set, and every chain heading its settings page draws a section under, has a label a
# person can read, in both languages (docs/SPEC.md § 3 Settings).
#
# The same drift `permission-labels.sh` guards one layer over: the settings catalogue is COLLECTED from every
# `DeclaresSettings` service, so declaring a setting is one `yield` and nothing warns that the settings page will
# then draw a raw dotted string where a sentence belongs. The keys live in the API's declarations; what a person
# reads lives in the SPA's own translations, as every other SPA string does. This ties the two together.
#
# The CHAIN HEADING is checked as well as the labels, and for the reason the permissions gate learned the hard way:
# they fail independently, so every setting of a chain can be labelled while the heading above them is a raw key.
# Adding the `venue` chain was exactly that shape.
#
# And each CHOICE of an enum setting, which the page's select draws by `settings.choices.<key>.<choice>`: the third
# independent half, found when two presentation selects showed raw keys on the company settings page (2026-09-23).
#
# It discovers the keys from the source rather than listing them, and carries floors so that a pattern which stops
# matching reds instead of comparing two empty sets and passing.
# Usage: setting-labels.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
# The floor sits ABOVE what the other declarations alone produce, so that losing one file's keys — an interpolated
# key, a renamed class, a pattern that stops matching — reds instead of reading as a pass. That is not hypothetical:
# it is how the venue settings' eight keys were found to be invisible to the first draft of this gate.
#
# It is now above the venue file's own two HALVES as well, not just above the file: the eight palette-shape keys and
# the eight structure keys are separate blocks of one `settings()`, and a floor that only cleared the other eleven
# would let either block vanish silently. Other declarations 11 + palette 8 = 19, so 23 reds on losing either eight.
floor=${SETTING_LABELS_FLOOR:-23}
chains_floor=${SETTING_CHAINS_FLOOR:-4}
# 16 choices on 2026-09-23 (presentation 14, document language 2): losing the document's pair or any select reds.
choices_floor=${SETTING_CHOICES_FLOOR:-15}

# One key, one language: present, a string, and not blank. The dots are nesting, as ngx-translate reads them.
labelled() {
  KEY="$1" FILE="$2" python3 -c '
import json, os, sys
node = json.load(open(os.environ["FILE"], encoding="utf-8"))
for part in os.environ["KEY"].split("."):
    if not isinstance(node, dict) or part not in node:
        sys.exit(1)
    node = node[part]
sys.exit(0 if isinstance(node, str) and node.strip() else 1)
'
}

mapfile -t files < <(git -C "$root" ls-files -- 'api/src/*Settings.php')
if ((${#files[@]} == 0)); then
  printf 'setting-labels: FAIL — found no *Settings.php declaration; the discovery pattern is broken\n'
  exit 1
fi

# The label key of each declaration. Two kinds are left out, each because no one ever reads the label:
#   - a PLATFORM setting is the operators' own screen, not a company's, and not the page these labels belong to;
#   - a key-PATTERN setting (`presentation.list.<id>`) is machine-written state, one row per list a person has
#     sorted, with no single key and no field on any page. Its declaration carries `keyPattern:`, which is the
#     property that makes it one, so that is what is matched rather than the names of today's two.
mapfile -t keys < <(
  cd "$root" && grep -hE "'settings\.[a-z]" "${files[@]}" | grep -v 'keyPattern:' |
    grep -oE "'settings\.[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+'" |
    tr -d "'" | grep -v '^settings\.platform\.' | sort -u
)

if ((${#keys[@]} < floor)); then
  printf 'setting-labels: FAIL — found only %d setting labels, below the floor of %d; the discovery pattern is broken\n' \
    "${#keys[@]}" "$floor"
  exit 1
fi

# The headings the page draws its sections under: one per chain a company reads and sets, which the enum names
# itself. Reading `ofCompanies()` rather than every case is deliberate — a platform-only chain has no section.
chain_file=api/src/Settings/Domain/SettingChain.php
if [[ ! -f "$root/$chain_file" ]]; then
  printf 'setting-labels: FAIL — %s is missing; the chains cannot be discovered\n' "$chain_file"
  exit 1
fi

mapfile -t chains < <(
  cd "$root" && sed -n '/function ofCompanies/,/}/p' "$chain_file" |
    grep -oE 'self::[A-Z][A-Za-z]*' | sed 's/self:://' |
    while read -r case_name; do
      grep -oE "case $case_name = '[a-z_]+'" "$chain_file" | grep -oE "'[a-z_]+'" | tr -d "'"
    done | sort -u
)

if ((${#chains[@]} < chains_floor)); then
  printf 'setting-labels: FAIL — found only %d company chains, below the floor of %d; the discovery pattern is broken\n' \
    "${#chains[@]}" "$chains_floor"
  exit 1
fi

# The choice labels of every company setting: `settings.choices.<setting key>.<choice>`, one per value of a literal
# `choices: [...]`. A list written any other way (a constant, a call) hides its values from a grep, so it is refused
# by name instead of skipped. Platform and key-pattern settings are left out, as above.
choice_out=$(
  cd "$root" && python3 - "${files[@]}" <<'PY'
import re, sys
bad = []
for path in sys.argv[1:]:
    for line in open(path, encoding="utf-8"):
        if "choices:" not in line or "keyPattern:" in line or "'settings.platform." in line:
            continue
        key = re.search(r"new SettingDefinition\(\s*'([^']+)'", line)
        values = re.search(r"choices:\s*\[([^\]]*)\]", line)
        if key is None or values is None:
            bad.append(f"{path}: {line.strip()[:120]}")
            continue
        for choice in re.findall(r"'([^']+)'", values.group(1)):
            print(f"settings.choices.{key.group(1)}.{choice}")
for entry in bad:
    print("BAD " + entry)
PY
)
if grep -q '^BAD ' <<<"$choice_out"; then
  printf 'setting-labels: FAIL — a choice list is not a literal list, so its labels cannot be checked:\n'
  grep '^BAD ' <<<"$choice_out" | sed 's/^BAD /  /'
  exit 1
fi
mapfile -t choices < <(grep -v '^$' <<<"$choice_out" | sort -u)

if ((${#choices[@]} < choices_floor)); then
  printf 'setting-labels: FAIL — found only %d choices, below the floor of %d; the discovery pattern is broken\n' \
    "${#choices[@]}" "$choices_floor"
  exit 1
fi

missing=()
for language in fr en; do
  file="$root/web/public/i18n/$language.json"
  for key in "${keys[@]}"; do
    labelled "$key" "$file" || missing+=("$language: $key")
  done
  for chain in "${chains[@]}"; do
    labelled "settings.chains.$chain" "$file" || missing+=("$language: settings.chains.$chain")
  done
  for choice in "${choices[@]}"; do
    labelled "$choice" "$file" || missing+=("$language: $choice")
  done
done

if ((${#missing[@]})); then
  printf 'setting-labels: FAIL — %d label(s) missing (add them to web/public/i18n/<lang>.json):\n' "${#missing[@]}"
  printf '  %s\n' "${missing[@]}"
  exit 1
fi

printf 'setting-labels: OK — %d settings, %d choices and %d chain headings are labelled in fr and en\n' \
  "${#keys[@]}" "${#choices[@]}" "${#chains[@]}"
