#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/decision-stamps.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A repository whose docs/SPEC.md has a Decisions Log, committed at a known time (Paris: 10:00 on 2026-10-02).
repo() {
  local d; d=$(mktemp -d); mkdir -p "$d/docs"
  git -C "$d" init -q
  printf '# Spec\n\n## 7. Decisions Log\n\n%s\n\n## 8. Status\n\n- [2026-12-31 23:59] outside the log, not read\n' "$1" > "$d/docs/SPEC.md"
  git -C "$d" add docs/SPEC.md
  GIT_COMMITTER_DATE='2026-10-02T10:00:00+02:00' GIT_AUTHOR_DATE='2026-10-02T10:00:00+02:00' \
    git -C "$d" -c user.name=t -c user.email=t@t commit -qm init
  echo "$d"
}
run() { bash "$GATE" --root "$1" 2>&1; }
echo "decision-stamps gate"

d=$(repo '- [2026-10-02 09:55] AGREED: a ruling written five minutes after it was made'); out=$(run "$d")
check "a stamp before its commit passes" $? 0 "$out" "OK"

d=$(repo '- [2026-10-02 10:00] AGREED: stamped the minute it was committed'); out=$(run "$d")
check "a stamp in the minute of its commit passes" $? 0 "$out" "OK"

d=$(repo '- [2026-10-02 12:42] ASSUMED (review): a stamp typed ahead of the clock'); out=$(run "$d")
check "a stamp later than its commit is refused, with the line and the commit" $? 1 "$out" "SPEC.md:5: [2026-10-02 12:42] is later than its commit"

d=$(repo '- [2026-10-02 12:42] ASSUMED (review): a typed stamp (stamp corrected: written at 10:00, commit abc1234)'); out=$(run "$d")
check "a late stamp already marked as corrected passes" $? 0 "$out" "OK"

d=$(repo '- [2026-10-02 09:00] AGREED: first'); sed -i 's/^- \[2026-10-02 09:00\] AGREED: first$/&\n- [2099-01-01 00:00] AGREED: not committed, stamped after now/' "$d/docs/SPEC.md"
out=$(run "$d"); check "an uncommitted stamp later than now is refused" $? 1 "$out" "[2099-01-01 00:00] is later than now"

d=$(repo '- [2026-10-02 09:00] AGREED: first'); sed -i 's/^- \[2026-10-02 09:00\] AGREED: first$/&\n- [2026-10-01 08:00] AGREED: written now, about yesterday/' "$d/docs/SPEC.md"
out=$(run "$d"); check "an uncommitted stamp in the past passes" $? 0 "$out" "OK"

d=$(repo '- [2026-10-02 09:00] AGREED: first'); sed -i 's/^## 7. Decisions Log$/## 7. Something else/' "$d/docs/SPEC.md"
git -C "$d" -c user.name=t -c user.email=t@t commit -qam rename
out=$(run "$d"); check "a SPEC with no Decisions Log is refused, not skipped" $? 1 "$out" "found no Decisions Log"

s=$(repo '- [2026-10-02 09:00] AGREED: first'); c=$(mktemp -d); rmdir "$c"; git clone -q --depth 1 "file://$s" "$c"
out=$(run "$c"); check "a shallow clone is refused, since it cannot say when a line was written" $? 1 "$out" "shallow"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
