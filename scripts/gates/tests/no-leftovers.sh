#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Runs one gate test with a temporary directory of its own, and fails when the test leaves anything in it.
# The toolchain's TMPDIR outlives every run on the host's tmpfs, so a test that makes a directory per case and
# never removes it adds up, run after run, until the tmpfs has no inode left and every shell on the machine fails.
# Usage: no-leftovers.sh <test-script> [args…]. A failing test keeps its own exit code; a passing one that leaves
# something exits 1. The directory is removed either way, once what was in it has been named.
set -uo pipefail
[[ $# -ge 1 ]] || { echo "usage: $(basename "$0") <test-script> [args…]" >&2; exit 2; }
own=$(mktemp -d)
TMPDIR="$own" bash "$@"
status=$?
left=$(find "$own" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort)
rm -rf "$own"
if [[ -n "$left" ]]; then
  echo "LEFT IN TMPDIR by $1 ($(wc -l <<<"$left") entries; remove what the test makes, with a trap on EXIT):" >&2
  sed 's/^/  /' <<<"$left" | head -20 >&2
  [[ $status -ne 0 ]] || status=1
fi
exit "$status"
