#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
# None of the machine's own git settings: a developer's commit.gpgsign would sign the fixtures with a real key.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
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
same "a repository that does not sign leaves the commit unsigned" "$(git -C "$d" cat-file commit HEAD | grep -c '^gpgsig ')" 0

# A repository that signs its commits signs the gated one as `git commit` would: commit-tree alone never reads
# commit.gpgsign. The signer is a stand-in, so no key of the machine is used.
signer() {
  local d=$1 exit=$2
  printf '#!/usr/bin/env bash\ncat > /dev/null\n[[ %s -eq 0 ]] || exit %s\necho "[GNUPG:] SIG_CREATED D 1 8 00 0 TEST" >&2\nprintf -- "-----BEGIN PGP SIGNATURE-----\\n\\ndGVzdA==\\n-----END PGP SIGNATURE-----\\n"\n' "$exit" "$exit" > "$d/../$(basename "$d").gpg"
  chmod +x "$d/../$(basename "$d").gpg"
  git -C "$d" config gpg.program "$d/../$(basename "$d").gpg"
  git -C "$d" config user.signingkey TEST
  git -C "$d" config commit.gpgsign true
}
d=$(repo); signer "$d" 0; echo two > "$d/a.txt"; git -C "$d" add a.txt
gate "$d" run > /dev/null; echo 'feat: signed' > "$d/msg"
out=$(cd "$d" && bash scripts/gate/snapshot-gate.sh commit "$d/msg" 2>&1); code=$?
same "a signed repository's gated commit succeeds" "$code" 0
same "a repository that signs signs the gated commit" "$(git -C "$d" cat-file commit HEAD | grep -c '^gpgsig ')" 1
same "the gate's own snapshot is never signed, so no gate asks for a key" "$(git -C "$d" cat-file commit "$(git -C "$d/.claude/worktrees/gate" rev-parse HEAD)" | grep -c '^gpgsig ')" 0

d=$(repo); signer "$d" 1; echo two > "$d/a.txt"; git -C "$d" add a.txt
gate "$d" run > /dev/null; echo 'feat: unsigned' > "$d/msg"
head=$(git -C "$d" rev-parse HEAD); tree=$(git -C "$d" write-tree)
out=$(cd "$d" && bash scripts/gate/snapshot-gate.sh commit "$d/msg" 2>&1); code=$?
[[ $code -ne 0 ]] && ok "a signature that fails refuses the commit" || ko "a signature that fails refuses the commit" "$out"
same "a refused signature leaves master where it was" "$(git -C "$d" rev-parse HEAD)" "$head"
[[ -f "$d/var/claude/gates/$tree.green" ]] && ok "a refused signature keeps the green tree to commit again" || ko "a refused signature keeps the green tree to commit again"

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

# Asked to wait, the gate takes the lock once the run holding it ends, rather than refusing: builders' short runs queue on
# the lock back to back, and a gate refusing at once never found it free.
d=$(repo); git -C "$d" add -A
lock="$d/../$(basename "$d").lock"
( flock 9; sleep 1 ) 9> "$lock" &
holder=$!
until [[ -e "$lock" ]] && ! flock -n "$lock" true; do :; done
out=$(SNAPSHOT_GATE_WAIT=1 gate "$d" run); code=$?
wait "$holder"
same "a gate asked to wait runs once the lock is free" "$code" 0
[[ -f "$d/seen" ]] && ok "the waiting gate gated the snapshot" || ko "the waiting gate gated the snapshot" "$out"

# The main session's batch goes first: while its mark exists, a builder's worktree starts no gate (flock serves its
# waiters in no order, and the main gate once waited 76 minutes behind builders). The main tree is never held back.
d=$(repo); git -C "$d" add -A; mark="$d/../$(basename "$d").main-first"
git -C "$d" worktree add -q "$d/../$(basename "$d")-builder" -b builder
b="$d/../$(basename "$d")-builder"; echo built > "$b/a.txt"; git -C "$b" add a.txt
touch "$mark"
out=$(cd "$b" && SNAPSHOT_GATE_MAIN_FIRST="$mark" SNAPSHOT_GATE_LOCK="$b.lock" SNAPSHOT_GATE_CMD="exit 0" bash scripts/gate/snapshot-gate.sh run 2>&1); code=$?
same "a builder's gate is refused while the main session goes first" "$code" 3
has "the refusal names the mark to wait for" "$out" "$mark"
out=$(cd "$d" && SNAPSHOT_GATE_MAIN_FIRST="$mark" SNAPSHOT_GATE_LOCK="$d.lock" SNAPSHOT_GATE_CMD="exit 0" bash scripts/gate/snapshot-gate.sh run 2>&1); code=$?
same "the main tree gates while its own mark exists" "$code" 0
rm -f "$mark"
out=$(cd "$b" && SNAPSHOT_GATE_MAIN_FIRST="$mark" SNAPSHOT_GATE_LOCK="$b.lock" SNAPSHOT_GATE_CMD="exit 0" bash scripts/gate/snapshot-gate.sh run 2>&1); code=$?
same "a builder gates once the mark is gone" "$code" 0
git -C "$d" worktree remove --force "$b"

# A worktree's API tests must never recreate the live stack's database container: compose would, its bind mount
# naming another path. Every start of it, by the gate, make check or make mutants, leaves a running one as it is.
starts=("$MAKEFILE" "$HERE/../../check/check.sh" "$HERE/../../mutants/mutants.sh")
found=$(grep -c 'up -d --wait --no-recreate postgres' "${starts[@]}" | awk -F: '$2 > 0' | wc -l)
bad=$(grep -n 'up -d --wait postgres' "${starts[@]}")
same "the gate, make check and make mutants each start postgres" "$found" 3
[[ -z "$bad" ]] && ok "no postgres start recreates a running container" || ko "no postgres start recreates a running container" "$bad"

# A change inside what CI's own paths-ignore lists is gated by the gates alone (make gate-docs, about a minute); anything
# else, or a list that cannot be read, by the whole gate. The list is ci.yml's, so the two never disagree.
scoped() {
  local d=$1; shift
  (cd "$d" && SNAPSHOT_GATE_LOCK="$d.lock" SNAPSHOT_GATE_CMD="printf '%s' \"\$SNAPSHOT_GATE_SCOPE\" > '$d/scope'" env "$@" bash scripts/gate/snapshot-gate.sh run 2>&1)
}
# The repository's own ci.yml, so the list is read as CI has it.
ci_yml() {
  mkdir -p "$1/.github/workflows"
  cp "$HERE/../../../.github/workflows/ci.yml" "$1/.github/workflows/ci.yml"
  git -C "$1" add .github; git -C "$1" commit -qm ci
}
d=$(repo); ci_yml "$d"; mkdir -p "$d/docs/plans" "$d/docs/research/a"
echo plan > "$d/docs/plans/x.plan.md"; echo spec > "$d/docs/SPEC.md"; echo note > "$d/docs/research/a/b.md"
git -C "$d" add docs
out=$(scoped "$d"); same "a change inside CI's paths-ignore is gated as docs" "$(cat "$d/scope")" docs
has "the green line names the scope" "$out" "green, docs only"
git -C "$d" commit -qm docs
echo again >> "$d/docs/plans/x.plan.md"; echo two > "$d/a.txt"; git -C "$d" add -A
out=$(scoped "$d"); same "a change touching anything else is gated whole" "$(cat "$d/scope")" full
has "the green line says so" "$out" "green, full"
git -C "$d" reset -q --hard
echo more >> "$d/docs/plans/x.plan.md"; git -C "$d" add -A
scoped "$d" SNAPSHOT_GATE_FULL=1 >/dev/null; same "SNAPSHOT_GATE_FULL=1 gates a docs change whole" "$(cat "$d/scope")" full
git -C "$d" reset -q --hard
echo kept > "$d/docs/START.md"; git -C "$d" add -A
scoped "$d" >/dev/null; same "a doc outside the list is gated whole" "$(cat "$d/scope")" full
git -C "$d" reset -q --hard; git -C "$d" commit -qm start-md --allow-empty
git -C "$d" mv a.txt docs/plans/a.txt
scoped "$d" >/dev/null; same "a file moved into the list counts where it left too" "$(cat "$d/scope")" full
git -C "$d" reset -q --hard
d=$(repo); mkdir -p "$d/docs/plans"; echo plan > "$d/docs/plans/x.plan.md"; git -C "$d" add -A
scoped "$d" >/dev/null; same "without ci.yml's list every change is gated whole" "$(cat "$d/scope")" full
d=$(repo); git -C "$d" commit -qm nothing --allow-empty
scoped "$d" >/dev/null; same "a snapshot changing nothing is gated whole" "$(cat "$d/scope")" full
same "the gate inside runs make gate-docs for docs and make gate otherwise" \
  "$(grep -cE 'make --no-print-directory gate(-docs)? TOOLS_TMP' "$SCRIPT")" 2

echo "snapshot-gate: $pass passed, $fail failed"
[[ $fail -eq 0 ]]
