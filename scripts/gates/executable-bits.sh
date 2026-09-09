#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every tracked file that starts with a shebang must be committed with mode 100755. A clone with
# core.fileMode=false keeps the disk bit and silently stages 100644, which works locally and fails on a
# CI checkout ("bin/console: Permission denied", first CI run of the reset, 2026-09-09). The index is
# the only thing CI sees, so the index is what this checks. Usage: executable-bits.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
wrong=(); checked=0
while read -r mode _ _ path; do
  head -c 2 "$root/$path" 2>/dev/null | LC_ALL=C grep -q '^#!' || continue
  checked=$((checked+1))
  [[ "$mode" == 100755 ]] || wrong+=("$mode $path")
done < <(git -C "$root" ls-files --stage)
if ((${#wrong[@]})); then
  printf 'executable-bits: FAIL — %d shebang file(s) not committed as 100755 (fix: git update-index --chmod=+x <path>):\n' "${#wrong[@]}"
  printf '  %s\n' "${wrong[@]}"
  exit 1
fi
printf 'executable-bits: OK — %d shebang files are 100755\n' "$checked"
