#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
# None of the machine's own git settings: a developer's commit.gpgsign would sign the fixtures with a real key.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SCRIPT=$HERE/../snapshot-gate.sh
MAKEFILE=$HERE/../../../Makefile
pass=0; fail=0
ok() { pass=$((pass+1)); echo "  ok   $1"; }
ko() { fail=$((fail+1)); echo "  FAIL $1"; [[ -n "${2:-}" ]] && echo "$2" | sed 's/^/       /'; }
same() { if [[ "$2" == "$3" ]]; then ok "$1"; else ko "$1" "wanted [$3], got [$2]"; fi; }
has() { if [[ "$2" == *"$3"* ]]; then ok "$1"; else ko "$1" "wanted '$3' in: $2"; fi; }

# A repository with the script inside it, as the real one has; the gate is a stub that reads the snapshot it is handed.
repo() {
  local d; d=$(mktemp -d)
  git -C "$d" init -q -b master
  git -C "$d" config user.name 'Test Person'; git -C "$d" config user.email 'test@example.test'
  mkdir -p "$d/scripts/gate"; cp "$SCRIPT" "$d/scripts/gate/snapshot-gate.sh"
  printf '/vendor\n/var\n/.claude/worktrees\n' > "$d/.gitignore"
  echo one > "$d/a.txt"; echo kept > "$d/b.txt"
  git -C "$d" add -A; git -C "$d" commit -qm start
  echo "$d"
}
# The stub gate: writes what it saw of a.txt where the test reads it, and exits as told.
gate() {
  local d=$1; shift
  (cd "$d" && SNAPSHOT_GATE_LOCK="$d/../$(basename "$d").lock" SNAPSHOT_GATE_CMD="cat a.txt > '$d/seen'; ls > '$d/listed'; exit ${GATE_EXIT:-0}" \
    bash scripts/gate/snapshot-gate.sh "$@" 2>&1)
}

echo "snapshot-gate: the staged tree is gated beside the work, and only a green tree is committed"

d=$(repo); echo two > "$d/a.txt"; git -C "$d" add a.txt; echo three > "$d/a.txt"
head=$(git -C "$d" rev-parse HEAD)
out=$(gate "$d" run); code=$?
same "a green gate exits 0" "$code" 0
same "the gate reads the staged file, not what was typed after" "$(cat "$d/seen")" two
same "master has not moved" "$(git -C "$d" rev-parse HEAD)" "$head"
same "the working tree keeps what was typed after" "$(cat "$d/a.txt")" three
same "no branch is made for the snapshot" "$(git -C "$d" branch --format='%(refname:short)' | tr '\n' ' ')" "master "
has "the gate says green, naming its tree" "$out" "green"
tree=$(git -C "$d" write-tree)
[[ -f "$d/var/claude/gates/$tree.green" ]] && ok "a green tree is recorded by its hash" || ko "a green tree is recorded by its hash" "$(ls "$d/var/claude/gates" 2>&1)"

# Committed after green, while a later change is staged and another typed: the commit holds the gated tree alone.
echo later > "$d/c.txt"; git -C "$d" add c.txt
printf 'feat: what was gated\n\nIts body.\n' > "$d/msg"
out=$(cd "$d" && bash scripts/gate/snapshot-gate.sh commit "$d/msg" 2>&1); code=$?
same "the commit succeeds" "$code" 0
same "the commit is one step on from where the snapshot started" "$(git -C "$d" rev-parse HEAD~1)" "$head"
same "the commit holds exactly the gated tree" "$(git -C "$d" rev-parse 'HEAD^{tree}')" "$tree"
same "the commit carries the message" "$(git -C "$d" log -1 --format=%s)" "feat: what was gated"
same "the commit is the person's own" "$(git -C "$d" log -1 --format='%an <%ae>')" "Test Person <test@example.test>"
has "what was staged after the gate stays staged" "$(git -C "$d" diff --cached --name-only)" "c.txt"
same "what was typed after the gate stays in the working tree" "$(cat "$d/a.txt")" three
has "the commit prints the message's first line and the tree's status" "$out" "feat: what was gated"

out=$(cd "$d" && bash scripts/gate/snapshot-gate.sh commit "$d/msg" 2>&1); code=$?
[[ $code -ne 0 ]] && ok "the same green tree is not committed twice" || ko "the same green tree is not committed twice" "$out"

d=$(repo); echo red > "$d/a.txt"; git -C "$d" add a.txt
out=$(GATE_EXIT=1 gate "$d" run); code=$?
same "a red gate passes its exit on" "$code" 1
has "a red gate says red" "$out" "red"
head=$(git -C "$d" rev-parse HEAD); echo 'fix: x' > "$d/msg"
out=$(cd "$d" && bash scripts/gate/snapshot-gate.sh commit "$d/msg" 2>&1); code=$?
[[ $code -ne 0 ]] && ok "a red tree is never committed" || ko "a red tree is never committed" "$out"
same "a refused commit leaves master where it was" "$(git -C "$d" rev-parse HEAD)" "$head"

d=$(repo); echo two > "$d/a.txt"; git -C "$d" add a.txt
gate "$d" run > /dev/null
echo other > "$d/b.txt"; git -C "$d" commit -qm moved -- b.txt
head=$(git -C "$d" rev-parse HEAD); echo 'feat: x' > "$d/msg"
out=$(cd "$d" && bash scripts/gate/snapshot-gate.sh commit "$d/msg" 2>&1); code=$?
[[ $code -ne 0 ]] && ok "a snapshot made before master moved is not committed over it" || ko "a snapshot made before master moved is not committed over it" "$out"
has "the refusal says to gate again" "$out" "gate again"
same "master stays where it moved to" "$(git -C "$d" rev-parse HEAD)" "$head"

# The worktree is reused: a file the next snapshot deletes is gone, a stray file is cleaned, ignored ones are kept.
d=$(repo); echo two > "$d/a.txt"; git -C "$d" add a.txt
gate "$d" run > /dev/null
wt="$d/.claude/worktrees/gate"
mkdir -p "$wt/vendor"; echo dep > "$wt/vendor/lib"; echo stray > "$wt/stray.txt"
git -C "$d" rm -q b.txt
gate "$d" run > /dev/null
listed=$(cat "$d/listed")
[[ "$listed" != *b.txt* ]] && ok "a file the snapshot deletes is gone from the reused worktree" || ko "a file the snapshot deletes is gone from the reused worktree" "$listed"
[[ "$listed" != *stray.txt* ]] && ok "a file the last gate left is cleaned" || ko "a file the last gate left is cleaned" "$listed"
[[ -f "$wt/vendor/lib" ]] && ok "an ignored dependency folder is kept for the next gate" || ko "an ignored dependency folder is kept for the next gate"

# One gate at a time: the box starves two side by side.
d=$(repo); git -C "$d" add -A
lock="$d/../$(basename "$d").lock"
exec 9> "$lock"; flock 9
out=$(gate "$d" run); code=$?
exec 9>&-
same "a second gate while one runs is refused" "$code" 3
has "the refusal says a gate is running" "$out" "running"

# A worktree's API tests must never recreate the live stack's database container: compose would, its bind mount
# naming another path. Every start of it, by the gate, make check or make mutants, leaves a running one as it is.
starts=("$MAKEFILE" "$HERE/../../check/check.sh" "$HERE/../../mutants/mutants.sh")
found=$(grep -c 'up -d --wait --no-recreate postgres' "${starts[@]}" | awk -F: '$2 > 0' | wc -l)
bad=$(grep -n 'up -d --wait postgres' "${starts[@]}")
same "the gate, make check and make mutants each start postgres" "$found" 3
[[ -z "$bad" ]] && ok "no postgres start recreates a running container" || ko "no postgres start recreates a running container" "$bad"

echo "snapshot-gate: $pass passed, $fail failed"
[[ $fail -eq 0 ]]
