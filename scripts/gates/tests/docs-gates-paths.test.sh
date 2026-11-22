#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
GATE=$HERE/docs-gates-paths.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# ci.yml's paths-ignore and the docs workflow's paths, each a space-separated list, and the docs workflow's last step.
tree() {
  local d item skipped gated; d=$(mktemp -d); mkdir -p "$d/.github/workflows"
  read -ra skipped <<<"$1"; read -ra gated <<<"$2"  # read, not a bare $1, so docs/plans/** is never expanded here
  { printf 'on:\n  push:\n    branches: [master]\n    # what a docs-only push touches\n    paths-ignore:\n'
    for item in "${skipped[@]}"; do printf '      - %s\n' "$item"; done
    printf '  pull_request:\njobs: {}\n'; } > "$d/.github/workflows/ci.yml"
  { printf 'name: docs-gates\non:\n  push:\n    branches: [master]\n    # the same list\n    paths:\n'
    for item in "${gated[@]}"; do printf '      - %s\n' "$item"; done
    printf 'jobs:\n  gates:\n    steps:\n      - run: %s\n' "${3:-make gate-docs}"; } > "$d/.github/workflows/decision-stamps.yml"
  echo "$d"
}
three='docs/SPEC.md docs/research/** docs/plans/**'

echo "docs-gates-paths gate"
d=$(tree "$three" "docs/plans/** docs/SPEC.md docs/research/**")
out=$(bash "$GATE" --root "$d" 2>&1); check "the same three paths in any order: passes" $? 0 "$out" "OK — 3 paths"

d=$(tree "$three docs/notes/**" "$three")
out=$(bash "$GATE" --root "$d" 2>&1); check "a path ci.yml skips that the docs gates miss is named" $? 1 "$out" "docs/notes/**"

d=$(tree "$three" "$three docs/START.md")
out=$(bash "$GATE" --root "$d" 2>&1); check "a path the docs gates take that ci.yml runs is named" $? 1 "$out" "docs/START.md"

d=$(tree "$three" "$three" "make gate-stamps")
out=$(bash "$GATE" --root "$d" 2>&1); check "a docs workflow not running make gate-docs is refused" $? 1 "$out" "make gate-docs"

d=$(tree "" "")
out=$(bash "$GATE" --root "$d" 2>&1); check "no list at all fails rather than passing on nothing" $? 1 "$out" "reads no paths-ignore"

d=$(mktemp -d)
out=$(bash "$GATE" --root "$d" 2>&1); check "a tree without the workflows fails" $? 1 "$out" "found no"

echo "docs-gates-paths: $pass passed, $fail failed"
[[ $fail -eq 0 ]]
