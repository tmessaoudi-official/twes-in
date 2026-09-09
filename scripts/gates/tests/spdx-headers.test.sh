#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/spdx-headers.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/api/config" "$d/src"; printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n' > "$d/src/Ok.php"; printf '<?php return [];\n' > "$d/api/config/bundles.php"; echo "$d"; }
echo "spdx-headers gate"
d=$(repo); out=$(bash "$GATE" --root "$d" 2>&1); check "a headed file and the Flex-owned bundles.php pass" $? 0 "$out" "OK — 1 files"
d=$(repo); printf 'export const x = 1;\n' > "$d/src/new.ts"; out=$(bash "$GATE" --root "$d" 2>&1); check "an UNTRACKED file without the identifier is caught and named" $? 1 "$out" "src/new.ts"
d=$(repo); printf '#!/usr/bin/env bash\necho hi\n' > "$d/x.sh"; git -C "$d" add -A; out=$(bash "$GATE" --root "$d" 2>&1); check "a tracked shell file without the identifier is caught" $? 1 "$out" "x.sh"
d=$(repo); printf '1\n2\n3\n4\n5\n6\n// SPDX-License-Identifier: AGPL-3.0-or-later\n' > "$d/src/late.ts"; out=$(bash "$GATE" --root "$d" 2>&1); check "an identifier after line six does not count" $? 1 "$out" "src/late.ts"
d=$(repo); printf 'x\n' > "$d/notes.md"; out=$(bash "$GATE" --root "$d" 2>&1); check "other file types are not checked" $? 0 "$out" "OK"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
