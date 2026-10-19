#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What a person just did is said one way on every screen: a sentence closed by its period, « Le client a été
# enregistré. » or « Photo ajoutée. », never a label left open such as « Rôle enregistré ». Every text the code hands
# Feedback as an outcome (success, effect) or a scan answers as done is read in each language the screens speak, main
# file and page parts alike. A key built at run time is not seen, so a floor stops the gate passing over a code it no
# longer reads. Usage: outcome-sentences.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=120
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "outcome-sentences: unknown argument $1" >&2; exit 2 ;;
  esac
done
mapfile -t files < <(git -C "$root" ls-files -- 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
python3 - "$root" "$floor" "${files[@]}" <<'PY'
import json, pathlib, re, sys

root, floor, files = pathlib.Path(sys.argv[1]), int(sys.argv[2]), sys.argv[3:]
# The first argument only: a toast's action (« Annuler », « Ouvrir ») is a button's label, not a sentence.
CALL = re.compile(r"feedback\.(?:success|effect)\(\s*((?:[^,()]|\([^()]*\))*)")
DONE = re.compile(r"kind:\s*'done',\s*key:\s*'([^']+)'")
KEY = re.compile(r"'([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)'")
keys = {}
for name in files:
    text = (root / name).read_text(encoding="utf-8")
    for match in CALL.finditer(text):
        for key in KEY.findall(match.group(1)):
            keys.setdefault(key, f"{name}:{text.count(chr(10), 0, match.start()) + 1}")
    for match in DONE.finditer(text):
        keys.setdefault(match.group(1), f"{name}:{text.count(chr(10), 0, match.start()) + 1}")

i18n = root / "web/public/i18n"
def flat(value, prefix=""):
    if isinstance(value, dict):
        for key, child in value.items():
            yield from flat(child, f"{prefix}.{key}" if prefix else key)
    elif isinstance(value, str):
        yield prefix, value
texts = {}
for lang in ("fr", "en", "ar"):
    found = {}
    for path in [i18n / f"{lang}.json", *sorted(i18n.glob(f"*/{lang}.json"))]:
        found.update(flat(json.loads(path.read_text(encoding="utf-8"))))
    texts[lang] = found

wrong = []
for key, where in sorted(keys.items()):
    for lang in ("fr", "en", "ar"):
        said = texts[lang].get(key)
        if said is None:
            wrong.append(f"{where} {key} [{lang}]: no text")
        elif not re.search(r"\.[»”\"]?$", said.strip()):
            wrong.append(f"{where} {key} [{lang}]: no closing period: {said}")
if len(keys) < floor:
    print(f"outcome-sentences: FAIL — {len(keys)} outcome keys read, fewer than the {floor} the code holds: the calls are no longer found")
    sys.exit(1)
if wrong:
    print(f"outcome-sentences: FAIL — {len(wrong)} outcome text(s) left open (fix: close it with its period in every language):")
    for line in wrong:
        print(f"  {line}")
    sys.exit(1)
print(f"outcome-sentences: OK — {len(keys)} outcome keys, each closed by its period in fr, en and ar")
PY
