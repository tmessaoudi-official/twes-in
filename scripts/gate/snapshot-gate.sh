#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The gate on a frozen snapshot (docs/START.md § 7): what is staged is written as a commit object that no branch names,
# checked out in a worktree of its own and gated there, while work goes on in the main tree beside the live stack. A
# green tree is recorded by its hash, and only that exact tree is ever committed, so master never takes a red one; a
# red one is fixed on top of whatever was done meanwhile and gated again.
# Usage: snapshot-gate.sh run             gate the staged tree; exits as the gate did, 3 when another gate is running
#        snapshot-gate.sh commit <msg>    commit the last green tree onto HEAD, which must be where its snapshot began
#        snapshot-gate.sh inside          the gate itself, as the worktree runs it (the default SNAPSHOT_GATE_CMD)
# SNAPSHOT_GATE_CMD replaces the gate (its tests do), SNAPSHOT_GATE_LOCK the lock every heavy run of this tree shares,
# SNAPSHOT_GATE_MAIN_FIRST the mark that holds every builder's gate back while the main session's batch goes first.
# Exit 3: another gate is running, or the main session goes first.
set -uo pipefail
root=$(git rev-parse --show-toplevel) || exit 2
cd "$root" || exit 2
records=$root/var/claude/gates
worktree=$root/.claude/worktrees/gate
lock=${SNAPSHOT_GATE_LOCK:-/tmp/twes-in-gate.lock}
# While this mark exists the main session's batch goes first: flock serves its waiters in no order, and the main gate
# once waited 76 minutes behind builders' runs. A builder's worktree then runs make check and its own tests instead.
main_first=${SNAPSHOT_GATE_MAIN_FIRST:-/tmp/twes-in-gate.main-first}

refuse() { echo "snapshot-gate: $1" >&2; exit "${2:-2}"; }

# The main tree is the repository itself; a builder works in a linked worktree, whose git directory is not the common one.
is_main_tree() {
  [[ "$(git rev-parse --path-format=absolute --git-dir)" == "$(git rev-parse --path-format=absolute --git-common-dir)" ]]
}

run() {
  local tree snap code log
  if [[ -e "$main_first" ]] && ! is_main_tree; then
    refuse "the main session goes first while $main_first exists: run make check and the tests of your files, and gate once it is gone" 3
  fi
  mkdir -p "$records"
  exec 9> "$lock"
  flock -n 9 || refuse "a gate is running (lock $lock); wait for it, never two side by side" 3
  tree=$(git write-tree) || refuse "the index cannot be written as a tree: is a merge unfinished?"
  snap=$(git commit-tree "$tree" -p HEAD -m "gate snapshot of $tree") || refuse "the snapshot commit could not be made"
  if [[ -e "$worktree/.git" ]]; then
    git -C "$worktree" checkout -q --detach --force "$snap" || refuse "the gate worktree could not take the snapshot"
    # What the last gate left is cleaned; what is ignored (vendor, node_modules, caches) is kept, which is the point.
    git -C "$worktree" clean -fdq
  else
    git worktree add -q --detach "$worktree" "$snap" || refuse "the gate worktree could not be made at $worktree"
  fi
  log=$records/$snap.log
  echo "snapshot-gate: gating ${snap:0:8} (tree ${tree:0:8}) in $worktree, log $log"
  (cd "$worktree" && bash -c "${SNAPSHOT_GATE_CMD:-bash scripts/gate/snapshot-gate.sh inside}") > "$log" 2>&1
  code=$?
  rm -f "$records/$tree.green" "$records/$tree.red"
  if [[ $code -eq 0 ]]; then
    printf '%s\n' "$snap" > "$records/$tree.green"
    echo "snapshot-gate: ${snap:0:8} tree ${tree:0:8} green"
  else
    printf '%s\n' "$snap" > "$records/$tree.red"
    echo "snapshot-gate: ${snap:0:8} tree ${tree:0:8} red (exit $code), log $log"
  fi
  return "$code"
}

commit() {
  local message=${1:-} green snap tree head parent commit branch sign
  [[ -f "$message" ]] || refuse "commit needs a message file"
  green=$(ls -t "$records"/*.green 2>/dev/null | head -n 1)
  [[ -n "$green" ]] || refuse "no green snapshot to commit: run the gate first"
  snap=$(cat "$green"); tree=$(basename "$green" .green)
  head=$(git rev-parse HEAD)
  parent=$(git rev-parse "$snap^") || refuse "the green snapshot $snap is not in this repository"
  [[ "$parent" == "$head" ]] || refuse "master moved since snapshot ${snap:0:8} began from ${parent:0:8}: gate again on top of ${head:0:8}"
  branch=$(git symbolic-ref -q --short HEAD) || refuse "HEAD names no branch"
  # commit-tree never reads commit.gpgsign: signed as `git commit` would sign it, or refused when the signature fails.
  # The snapshot above is not, as no branch names it and a gate must never wait on a key.
  sign=()
  [[ "$(git config --type=bool commit.gpgsign)" == true ]] && sign=(-S)
  commit=$(git commit-tree "${sign[@]}" "$tree" -p "$head" -F "$message") || refuse "the commit could not be made"
  # Moves the branch only from where the snapshot began: anything that moved it meanwhile makes this refuse.
  git update-ref -m "commit (snapshot gate): $(head -n 1 "$message")" "refs/heads/$branch" "$commit" "$head" || refuse "the branch moved while committing"
  rm -f "$green"
  head -n 1 "$message"
  git status --porcelain
  git log --oneline -1
}

# The real gate, in the worktree: its own test databases and temporary directory, so the main tree's suites and
# make check go on beside it, and a postgres start that leaves the live stack's container as it is.
inside() {
  local token=_gate
  grep -qx "TEST_TOKEN=$token" api/.env.test.local 2>/dev/null || printf 'TEST_TOKEN=%s\n' "$token" > api/.env.test.local
  docker compose up -d --wait --no-recreate postgres || return 2
  if ! docker compose exec -T postgres psql -U twes -d twes -tAc "SELECT 1 FROM pg_database WHERE datname = 'twes_test$token'" | grep -qx 1; then
    docker compose exec -T postgres psql -U twes -d twes -qc "CREATE DATABASE twes_test$token OWNER twes" || return 2
  fi
  make --no-print-directory gate TOOLS_TMP="/tmp/twes-in-tools-$(id -u)$token" < /dev/null
}

case "${1:-}" in
  run) run ;;
  commit) commit "${2:-}" ;;
  inside) inside ;;
  *) refuse "usage: snapshot-gate.sh run | commit <message file> | inside" ;;
esac
