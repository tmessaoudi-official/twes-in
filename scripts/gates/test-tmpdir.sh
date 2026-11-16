#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every shell test (scripts/gates/tests/*.test.sh, scripts/tests/*.test.sh) makes its temporary folder first and stops
# when it cannot: its first TMPDIR line reads `TMPDIR=$(mktemp -d) || exit 1`. An unchecked one left the variable empty
# when the folder could not be made (the recipe run by hand, a root-owned toolchain folder), and every fixture path and
# every cleanup then started at `/`, where only an unprivileged account saved the tree; a cloud session runs as root.
# The runner every test goes through, no-leftovers.sh, stops the same way on its own folder. Tests are read from the
# index, as CI sees them. Usage: test-tmpdir.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=100  # under today's 104 tests, so losing a folder of them reds
while (($#)); do
  case "$1" in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "test-tmpdir: unknown argument $1" >&2; exit 2 ;;
  esac
done
wrong=(); read=0
while IFS= read -r path; do
  read=$((read+1))
  line=$(grep -m1 -E '^TMPDIR=' "$root/$path")
  if [[ -z "$line" ]]; then
    wrong+=("$path: no TMPDIR=\$(mktemp -d) || exit 1 line")
  elif [[ ! "$line" =~ ^TMPDIR=\$\(mktemp\ -d\)\ \|\|\ exit\ 1(\;|$) ]]; then
    wrong+=("$path: $line (stop when the folder cannot be made: TMPDIR=\$(mktemp -d) || exit 1)")
  fi
done < <(git -C "$root" ls-files -- 'scripts/gates/tests/*.test.sh' 'scripts/tests/*.test.sh')
runner=scripts/gates/tests/no-leftovers.sh
if [[ -f "$root/$runner" ]] && grep -qE '^[a-z_]+=\$\(mktemp -d\) *$' "$root/$runner"; then
  wrong+=("$runner: its own folder is made unchecked")
fi
if ((read < floor)); then
  wrong+=("read $read tests, under the floor of $floor: the enumeration lost some")
fi
if ((${#wrong[@]})); then
  printf 'test-tmpdir: FAIL — %d test(s) that would go on without their temporary folder:\n' "${#wrong[@]}"
  printf '  %s\n' "${wrong[@]}"
  exit 1
fi
printf 'test-tmpdir: OK — %d tests stop when their temporary folder cannot be made\n' "$read"
