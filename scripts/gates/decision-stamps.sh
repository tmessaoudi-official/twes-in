#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A Decisions Log stamp is read from the clock when the entry is written (docs/SPEC.md § 7, audit 2026-10-06 H-1), so it
# is never later than the commit that wrote it: a stamp typed ahead of its commit is a ruling dated to a moment that had
# not come, which a later reader takes as the developer's. Each entry of § 7 is compared with the commit `git blame`
# names for its line, in Paris time, to the minute; a line not committed yet with the time now. Blame names the last
# commit to touch a line, never an earlier one, so it cannot refuse a good stamp. An entry whose stamp was found late
# says so in its own words, « (stamp corrected: written at HH:MM, commit <sha>) », and is not read again.
# Usage: decision-stamps.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
spec="$root/docs/SPEC.md"

if [[ "$(git -C "$root" rev-parse --is-shallow-repository 2>&1)" != "false" ]]; then
  printf 'decision-stamps: FAIL — a shallow clone cannot say when a line was written: check out the whole history (fetch-depth: 0)\n'
  exit 1
fi
if [[ ! -f "$spec" ]]; then
  printf 'decision-stamps: FAIL — found no docs/SPEC.md\n'
  exit 1
fi

git -C "$root" blame --line-porcelain -- docs/SPEC.md | python3 -c '
import re, sys
from datetime import datetime
from zoneinfo import ZoneInfo

paris = ZoneInfo("Europe/Paris")
now = datetime.now(paris).strftime("%Y-%m-%d %H:%M")
entry = re.compile(r"- \[(\d{4}-\d\d-\d\d \d\d:\d\d)\] ")
section, commit, when, number, entries, problems = None, None, None, 0, 0, []
for line in sys.stdin:
    line = line.rstrip("\n")
    if re.match(r"^[0-9a-f]{40} ", line):
        commit = line.split()[0]
        number = int(line.split()[2])
    elif line.startswith("committer-time "):
        when = datetime.fromtimestamp(int(line.split()[1]), paris).strftime("%Y-%m-%d %H:%M")
    elif line.startswith("\t"):
        text = line[1:]
        if text.startswith("## "):
            section = text
            continue
        if section != "## 7. Decisions Log":
            continue
        found = entry.match(text)
        if not found:
            continue
        entries += 1
        if "(stamp corrected:" in text:
            continue
        stamp = found.group(1)
        if set(commit) == {"0"}:
            if stamp > now:
                problems.append(f"docs/SPEC.md:{number}: [{stamp}] is later than now ({now})")
        elif stamp > when:
            problems.append(f"docs/SPEC.md:{number}: [{stamp}] is later than its commit {commit[:8]} ({when})")
if entries == 0:
    print("decision-stamps: FAIL — found no Decisions Log entry under « ## 7. Decisions Log »")
    sys.exit(1)
if problems:
    print(f"decision-stamps: FAIL — {len(problems)} Decisions Log stamp(s) later than the moment they were written (a stamp is read from the clock; a late one found afterwards gains « (stamp corrected: written at HH:MM, commit <sha>) »):")
    for problem in problems:
        print("  " + problem)
    sys.exit(1)
print(f"decision-stamps: OK — {entries} Decisions Log stamps, none later than the moment it was written")
'
