#!/usr/bin/env bash
#
# Gate: every source file declares its licence, machine-readably.
#
# Why it exists: twes-in is AGPL-3.0-or-later plus a commercial licence, and licensing invariant 8(c)
# requires an SPDX identifier on every source file. What breaks without it: a file with no licence
# header is ambiguous the moment it is copied out of this repository, and the dual licence depends on
# provenance being unambiguous. A habit will not survive a rushed commit; a gate will.
#
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly REPO_ROOT
readonly EXPECTED='SPDX-License-Identifier: AGPL-3.0-or-later'

# Directories whose source files must carry the header, once each tier exists. Absent tiers are
# skipped rather than failing — but never silently: see the summary at the end.
readonly -a SEARCH_ROOTS=(
  "api/src"
  "api/tests"
  "api/tools"
  "api/config"
  "api/bin"
  "api/public"
  "api/migrations"
  "admin/src"
  "mobile/lib"
  "mobile/test"
  "scripts"
)

# How far into a file the header may appear. Without a bound, a file that merely MENTIONS the identifier
# in a comment far down passes — which an earlier version of this gate did. 40 lines is generous enough
# for a file whose header comment explains WHY it exists (house style here), and still far short of
# anywhere a licence line could hide.
readonly HEADER_WINDOW=40

# See no-ambient-calls-in-domain.php for why: one generated case per root and per extension.
readonly -a EXTENSIONS=(php ts dart sh xml sql yaml yml)

if [[ "${1:-}" == "--dump-rules" ]]; then
  printf 'roots %s\n' "${SEARCH_ROOTS[*]}"
  printf 'extensions %s\n' "${EXTENSIONS[*]}"
  exit 0
fi

missing=0
checked=0
skipped_roots=()

# Built from EXTENSIONS so the dump and the search can never disagree.
find_extension_args=()
for extension in "${EXTENSIONS[@]}"; do
  (( ${#find_extension_args[@]} )) && find_extension_args+=(-o)
  find_extension_args+=(-name "*.${extension}")
done
readonly find_extension_args

for root in "${SEARCH_ROOTS[@]}"; do
  if [[ ! -d "$REPO_ROOT/$root" ]]; then
    skipped_roots+=("$root")
    continue
  fi

  while IFS= read -r -d '' file; do
    checked=$((checked + 1))

    if ! head -n "$HEADER_WINDOW" "$file" | grep -qF "$EXPECTED"; then
      printf 'MISSING SPDX header: %s\n' "${file#"$REPO_ROOT"/}" >&2
      missing=$((missing + 1))
    fi
  done < <(find "$REPO_ROOT/$root" \
    -type f \
    \( "${find_extension_args[@]}" \) \
    -not -name '*.xlf' \
    -not -path '*/vendor/*' \
    -not -path '*/node_modules/*' \
    -print0)
done

if (( ${#skipped_roots[@]} > 0 )); then
  printf 'spdx-headers: not yet present, skipped: %s\n' "${skipped_roots[*]}"
fi

if (( missing > 0 )); then
  printf 'spdx-headers: FAIL — %d of %d file(s) lack "%s".\n' "$missing" "$checked" "$EXPECTED" >&2
  exit 1
fi

printf 'spdx-headers: OK — %d file(s) carry the SPDX identifier.\n' "$checked"
