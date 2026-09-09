#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every PHP, TypeScript and shell source file we author carries a machine-readable SPDX identifier in its
# first lines (LICENSING.md § Notices). Enumerates tracked AND new untracked files, so a file written a
# minute ago is checked before it is staged. Usage: spdx-headers.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
IDENT='SPDX-License-Identifier: AGPL-3.0-or-later'
# Files Symfony Flex rewrites on every recipe change; ours by ownership, not by authorship.
EXEMPT='^api/config/bundles\.php$'
missing=(); checked=0
while IFS= read -r f; do
  [[ "$f" =~ $EXEMPT ]] && continue
  checked=$((checked+1))
  head -n 6 "$root/$f" | grep -qF "$IDENT" || missing+=("$f")
done < <(git -C "$root" ls-files --cached --others --exclude-standard -- '*.php' '*.ts' '*.sh')
if ((${#missing[@]})); then
  printf 'spdx-headers: FAIL — %d file(s) without "%s" in their first six lines:\n' "${#missing[@]}" "$IDENT"
  printf '  %s\n' "${missing[@]}"
  exit 1
fi
printf 'spdx-headers: OK — %d files carry the identifier\n' "$checked"
