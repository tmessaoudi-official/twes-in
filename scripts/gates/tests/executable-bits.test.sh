#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/executable-bits.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# core.fileMode=false reproduces the clone that shipped the defect: the disk bit is ignored at add time.
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; git -C "$d" config core.fileMode false; echo "$d"; }
echo "executable-bits gate"
d=$(repo); printf '#!/usr/bin/env bash\necho hi\n' > "$d/run.sh"; chmod +x "$d/run.sh"; git -C "$d" add -A; out=$(bash "$GATE" --root "$d" 2>&1); check "a +x shebang file staged under fileMode=false lands as 100644 and is caught" $? 1 "$out" "100644 run.sh"
git -C "$d" update-index --chmod=+x run.sh; out=$(bash "$GATE" --root "$d" 2>&1); check "the prescribed fix makes it pass" $? 0 "$out" "OK — 1 shebang"
d=$(repo); printf 'plain text\n' > "$d/notes.txt"; printf '\x00\x00\x01\x00' > "$d/icon.ico"; git -C "$d" add -A; out=$(bash "$GATE" --root "$d" 2>&1); check "files without a shebang, including a binary starting with null bytes, are ignored" $? 0 "$out" "OK — 0 shebang"
d=$(repo); printf '#!/usr/bin/env php\n<?php\n' > "$d/tool.php"; git -C "$d" add -A; out=$(bash "$GATE" --root "$d" 2>&1); check "the extension does not matter, the shebang does" $? 1 "$out" "tool.php"
d=$(repo); printf '#!/bin/sh\n' > "$d/untracked.sh"; out=$(bash "$GATE" --root "$d" 2>&1); check "an untracked file is not in the index and so not checked" $? 0 "$out" "OK — 0 shebang"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
