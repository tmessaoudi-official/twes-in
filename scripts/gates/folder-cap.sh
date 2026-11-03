#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# No folder of the web holds more than 25 files directly, specs aside (docs/SPEC.md § 7, 2026-10-16 17:17, with the API's
# SourceLayoutTest::CAP): past that it is split by sub-feature or capability. Every file but a `*.spec.ts` counts, a
# template or a style as much as a class; a subfolder counts on its own. The files are those git tracks or has staged,
# as CI reads them, so a generated folder (`app/api`, `generated`) is never counted. A folder already past the cap is
# listed in folder-cap.allowed with the count it holds and why: it may not grow, a file it loses lowers its line, and
# under the cap it leaves the list. Fewer files read than the floor reds, so a read that stopped reading is seen.
# Usage: folder-cap.sh [--root DIR] [--allowed FILE] [--floor N]
set -uo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
root=$(cd "$here/../.." && pwd)
allowed=$here/folder-cap.allowed
floor=800
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --allowed) allowed=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "folder-cap: unknown argument $1"; exit 2 ;;
  esac
done
[[ -f "$allowed" ]] || { echo "folder-cap: FAIL — no allow-list at $allowed"; exit 1; }

git -C "$root" ls-files -z -- web/src | python3 -c '
import collections, os, sys
cap, floor, allowed_file = 25, int(sys.argv[1]), sys.argv[2]
files = [f for f in sys.stdin.read().split("\0") if f and not f.endswith(".spec.ts")]
held = collections.Counter(os.path.dirname(f) for f in files)
problems = []
allowed = {}
for n, line in enumerate(open(allowed_file, encoding="utf-8"), 1):
    line = line.strip()
    if not line or line.startswith("#"):
        continue
    parts = line.split(None, 2)
    if len(parts) < 3 or not parts[1].isdigit():
        problems.append(f"{os.path.basename(allowed_file)}:{n}: \"{line}\" gives no folder, count and reason (no reason, no allowance)")
        continue
    allowed[parts[0]] = int(parts[1])
for folder, most in sorted(allowed.items()):
    count = held.get(folder, 0)
    if count == 0:
        problems.append(f"{folder} holds no file: remove its line")
    elif count <= cap:
        problems.append(f"{folder} holds {count} files and is under the cap: remove its line")
    elif count > most:
        problems.append(f"{folder} holds {count} files, past the {most} it is allowed: split it rather than grow it")
    elif count < most:
        problems.append(f"{folder} holds {count} files, fewer than the {most} it is allowed: lower its line to {count}")
for folder, count in sorted(held.items()):
    if count > cap and folder not in allowed:
        problems.append(f"{folder} holds {count} files, past {cap}: split it by sub-feature or capability")
if len(files) < floor:
    problems.append(f"{len(files)} files read in web/src, below the floor of {floor}: the read has stopped reading")
if problems:
    print(f"folder-cap: FAIL — at most {cap} files a folder in web/src, specs aside:")
    for p in problems:
        print("  " + p)
    sys.exit(1)
over = sum(1 for f, c in held.items() if c > cap)
print(f"folder-cap: OK — {len(files)} files in {len(held)} folders of web/src, none past {cap} but the {over} allowed, none grown")
' "$floor" "$allowed"
