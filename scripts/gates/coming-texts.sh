#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What the « En construction » page draws stays true as entries ship. The page is drawn from the coming entries — the
# modules the API still plans (`PlannedModules`) and the settings pages not built yet (`COMING_NAV`) — and from their
# texts under `coming.<key>` in both languages. Once a module ships it leaves the planned list, and nothing else warns
# that its texts, its menu place and the roadmap row its page names are left behind. Both ways are checked: every
# entry has its texts, and every `coming.<key>` object names an entry; every menu place names a planned module; and the
# roadmap row an entry names is one docs/SPEC.md § 8 holds and has not finished. Each discovery has its own floor, so a
# pattern that no longer matches cannot compare two empty sets and pass.
# Usage: coming-texts.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2

ROOT="$root" \
  PLANNED_FLOOR="${COMING_TEXTS_PLANNED_FLOOR:-15}" \
  SETTINGS_FLOOR="${COMING_TEXTS_SETTINGS_FLOOR:-3}" \
  ROWS_FLOOR="${COMING_TEXTS_ROWS_FLOOR:-8}" \
  python3 - <<'PY'
import json, os, re, sys

root = os.environ["ROOT"]
problems = []


def read(path):
    try:
        with open(os.path.join(root, path), encoding="utf-8") as handle:
            return handle.read()
    except OSError:
        return None


def fail(message):
    print(f"coming-texts: FAIL — {message}")
    sys.exit(1)


def block(text, name):
    """The array literal `export const <name> ... = [ ... ];`, or None."""
    start = text.find(f"export const {name}")
    if start < 0:
        return None
    end = text.find("\n];", start)
    return text[start:end if end >= 0 else len(text)]


def entries_of(array):
    """{key: row or None} for each `{ key: '...', ... }` of an array literal, its row read up to the next key."""
    found = {}
    pieces = re.split(r"\bkey: '", array)[1:]
    for piece in pieces:
        key = re.match(r"([a-z][a-z0-9_-]*)'", piece)
        if key is None:
            continue
        row = re.search(r"\brow: (\d+)", piece)
        found[key.group(1)] = int(row.group(1)) if row else None
    return found


planned_source = read("api/src/ModuleRegistry/Application/PlannedModules.php")
if planned_source is None:
    fail("api/src/ModuleRegistry/Application/PlannedModules.php is missing; the planned modules cannot be discovered")
planned = sorted(set(re.findall(r"new ModuleManifest\('([a-z][a-z0-9_]*)'", planned_source)))
if len(planned) < int(os.environ["PLANNED_FLOOR"]):
    fail(f"found only {len(planned)} planned modules, below the floor of {os.environ['PLANNED_FLOOR']}; the discovery pattern is broken")

places_source = read("web/src/app/shell/planned-nav.ts") or ""
places = entries_of(block(places_source, "PLANNED_NAV") or "")
settings_source = read("web/src/app/shell/nav-manifest.ts") or ""
settings = entries_of(block(settings_source, "COMING_NAV") or "")
if len(settings) < int(os.environ["SETTINGS_FLOOR"]):
    fail(f"found only {len(settings)} coming settings pages, below the floor of {os.environ['SETTINGS_FLOOR']}; the discovery pattern is broken")

for key in sorted(set(places) - set(planned)):
    problems.append(f"menu place left behind: {key} is no longer planned (remove it from PLANNED_NAV in web/src/app/shell/planned-nav.ts)")

entries = {key: places.get(key) for key in planned}
entries.update(settings)

for language in ("fr", "en"):
    try:
        coming = json.loads(read(f"web/public/i18n/{language}.json") or "").get("coming", {})
    except ValueError:
        coming = {}  # an unreadable file holds no texts: every entry is reported, never none
    if not isinstance(coming, dict):
        coming = {}
    for key in sorted(entries):
        texts = coming.get(key)
        for part in ("heading", "does"):
            value = texts.get(part) if isinstance(texts, dict) else None
            if not (isinstance(value, str) and value.strip()):
                problems.append(f"{language}: coming.{key}.{part} is missing")
    # `version` is the page's own choice of words, not an entry's.
    for key in sorted(k for k, v in coming.items() if isinstance(v, dict) and k != "version" and k not in entries):
        problems.append(f"{language}: coming.{key} left behind: no coming entry is called {key} (remove its texts)")

spec = read("docs/SPEC.md")
if spec is None:
    fail("docs/SPEC.md is missing; the roadmap rows cannot be read")
status = spec[spec.find("<!-- progress-block v1 -->"):spec.find("<!-- /progress-block -->")] if "<!-- progress-block v1 -->" in spec else ""
states = {}
for line in status.splitlines():
    cells = [cell.strip() for cell in line.split("|")]
    if len(cells) > 4 and cells[1].isdigit():
        states[int(cells[1])] = cells[4]
named = {key: row for key, row in entries.items() if row is not None}
if len(named) < int(os.environ["ROWS_FLOOR"]):
    fail(f"found only {len(named)} roadmap rows named, below the floor of {os.environ['ROWS_FLOOR']}; the discovery pattern is broken")
for key, row in sorted(named.items()):
    state = states.get(row)
    if state is None:
        problems.append(f"{key} names row {row}, which § 8 does not hold")
    elif state in ("done", "certified", "confirmed"):
        problems.append(f"{key} names row {row}, which is {state}: the entry has shipped, so its « coming » texts and place go")

if problems:
    print(f"coming-texts: FAIL — {len(problems)} problem(s):")
    for problem in problems:
        print(f"  {problem}")
    sys.exit(1)

print(f"coming-texts: OK — {len(entries)} coming entries have their texts in fr and en, none is left behind, and the {len(named)} roadmap rows they name are still open")
PY
