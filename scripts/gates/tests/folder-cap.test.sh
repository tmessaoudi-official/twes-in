#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/folder-cap.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# n files named <prefix>-<i>.<ext> in a folder of the tree
many() { local dir=$1 n=$2 prefix=$3 ext=${4:-ts} i; mkdir -p "$dir"; for ((i = 1; i <= n; i++)); do : > "$dir/$prefix-$i.$ext"; done; }
# A tree shaped like the real one: a web/src in a git repository, every folder under the cap, files staged.
tree() {
  local d; d=$(mktemp -d)
  many "$d/web/src/app/customers" 25 customer
  many "$d/web/src/app/shared/list" 12 list
  : > "$d/web/src/main.ts"
  : > "$d/allowed"
  echo "$d"
}
run() { git -C "$1" init -q && git -C "$1" add -A && bash "$GATE" --root "$1" --allowed "$1/allowed" --floor "${2:-30}" 2>&1; }
echo "folder-cap gate"

d=$(tree); out=$(run "$d"); check "a tree with every folder at or under 25 files passes" $? 0 "$out" "OK"

d=$(tree); : > "$d/web/src/app/customers/customer-26.ts"
out=$(run "$d"); check "a 26th file in a folder is refused, naming the folder and its count" $? 1 "$out" "web/src/app/customers holds 26 files"

d=$(tree); : > "$d/web/src/app/customers/customer-page.html"
out=$(run "$d"); check "a template counts as a file" $? 1 "$out" "web/src/app/customers holds 26 files"

d=$(tree); many "$d/web/src/app/customers" 30 customer spec.ts
out=$(run "$d"); check "specs are aside" $? 0 "$out" "OK"

d=$(tree); many "$d/web/src/app/customers/import" 20 importer
out=$(run "$d"); check "a subfolder counts on its own" $? 0 "$out" "OK"

d=$(tree); many "$d/web/src/app/customers" 30 customer
printf 'web/src/app/customers 30 split into sub-feature folders, ruled 2026-10-16\n' > "$d/allowed"
out=$(run "$d"); check "a folder allowed with its count and reason passes" $? 0 "$out" "OK"

d=$(tree); many "$d/web/src/app/customers" 31 customer
printf 'web/src/app/customers 30 split into sub-feature folders, ruled 2026-10-16\n' > "$d/allowed"
out=$(run "$d"); check "an allowed folder growing past its count is refused" $? 1 "$out" "web/src/app/customers holds 31 files, past the 30 it is allowed"

d=$(tree); many "$d/web/src/app/customers" 29 customer
printf 'web/src/app/customers 30 split into sub-feature folders, ruled 2026-10-16\n' > "$d/allowed"
out=$(run "$d"); check "an allowed folder that shrank must lower its line, so it cannot grow back" $? 1 "$out" "lower its line to 29"

d=$(tree); printf 'web/src/app/customers 30 split into sub-feature folders, ruled 2026-10-16\n' > "$d/allowed"
out=$(run "$d"); check "an allowed folder back under the cap must leave the list" $? 1 "$out" "is under the cap: remove its line"

d=$(tree); many "$d/web/src/app/customers" 30 customer
printf 'web/src/app/customers 30\n' > "$d/allowed"
out=$(run "$d"); check "an allowance with no reason is refused" $? 1 "$out" "no reason"

d=$(tree); printf 'web/src/app/vendors 30 a folder that is gone\n' > "$d/allowed"
out=$(run "$d"); check "an allowance naming no folder is refused" $? 1 "$out" "web/src/app/vendors holds no file"

d=$(tree); out=$(run "$d" 100); check "reading fewer files than the floor is refused, so losing the read reds" $? 1 "$out" "below the floor of 100"

d=$(tree); rm -r "$d/web/src"; mkdir -p "$d/web"; : > "$d/web/README"
out=$(run "$d"); check "a missing web/src is refused, not skipped" $? 1 "$out" "below the floor"

d=$(tree); out=$(git -C "$d" init -q && bash "$GATE" --root "$d" --allowed "$d/allowed" --floor 30 2>&1)
check "files not yet staged are not read, as CI reads the index" $? 1 "$out" "below the floor"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
