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

# Individual files that live at a tier's top level rather than inside one of the roots above. They exist
# because a tool insists on the location (`phpunit.xml`, `.php-cs-fixer.dist.php`), and they were the set
# complement the coverage check at the end of this gate now makes impossible to leave out: adding
# `api` itself as a root would re-walk every root beneath it and double-count.
readonly -a SEARCH_FILES=(
  "api/phpunit.xml"
  "api/.php-cs-fixer.dist.php"
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
  printf 'files %s\n' "${SEARCH_FILES[*]}"
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

for file in "${SEARCH_FILES[@]}"; do
  if [[ ! -f "$REPO_ROOT/$file" ]]; then
    skipped_roots+=("$file")
    continue
  fi

  checked=$((checked + 1))

  if ! head -n "$HEADER_WINDOW" "$REPO_ROOT/$file" | grep -qF "$EXPECTED"; then
    printf 'MISSING SPDX header: %s\n' "$file" >&2
    missing=$((missing + 1))
  fi
done

if (( ${#skipped_roots[@]} > 0 )); then
  printf 'spdx-headers: not yet present, skipped: %s\n' "${skipped_roots[*]}"
fi

# THE MISSING DIRECTION OF THE INVENTORY. Everything above proves that every file *under a root* carries
# the header. Nothing proved that the roots *cover every file* — the set complement was unscanned, and a
# review found a live licensing-invariant-8(c) miss sitting in it: `api/phpunit.xml` has no identifier and
# this gate reported OK. `api/` itself is not a root, so both files at its top level were invisible, and the
# reassuring "not yet present, skipped: …" line made it look as though every tier were accounted for.
#
# Asserted against git rather than a second find, because git already knows what "a file in this project"
# means: it honours .gitignore, so vendor/, node_modules/ and var/ need no exclusion list that could drift
# from the one above. Both tracked and new-but-uncommitted files count — a header is owed before the commit,
# not after it.
if git -C "$REPO_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  extension_pattern="$(IFS='|'; printf '%s' "${EXTENSIONS[*]}")"
  root_pattern="$(IFS='|'; printf '%s' "${SEARCH_ROOTS[*]}")"
  # Anchored and terminated, so `api/phpunit.xml` in SEARCH_FILES does not accidentally excuse
  # `api/phpunit.xml.bak`. The dots are escaped for the same reason.
  file_pattern="$(IFS='|'; printf '%s' "${SEARCH_FILES[*]}")"
  file_pattern="${file_pattern//./\\.}"

  uncovered=()
  while IFS= read -r file; do
    [[ -n "$file" ]] && uncovered+=("$file")
  done < <(git -C "$REPO_ROOT" ls-files --cached --others --exclude-standard \
    | grep -E "\.(${extension_pattern})$" \
    | grep -Ev "^(${root_pattern})/" \
    | grep -Ev "^(${file_pattern})$" \
    | grep -v '\.xlf$' || true)

  if (( ${#uncovered[@]} > 0 )); then
    printf 'spdx-headers: FAIL — %d file(s) with an in-scope extension are under NO search root, so this gate never looked at them:\n' \
      "${#uncovered[@]}" >&2
    printf '  %s\n' "${uncovered[@]}" >&2
    printf 'Add the containing directory to SEARCH_ROOTS, or exclude the extension deliberately.\n' >&2
    exit 1
  fi
else
  # Announced, never silent: in a non-git tree this half of the check cannot run, and a reader must not
  # take the OK below as covering it.
  printf 'spdx-headers: not a git work tree, so root COVERAGE was not checked (headers were).\n'
fi

if (( missing > 0 )); then
  printf 'spdx-headers: FAIL — %d of %d file(s) lack "%s".\n' "$missing" "$checked" "$EXPECTED" >&2
  exit 1
fi

printf 'spdx-headers: OK — %d file(s) carry the SPDX identifier.\n' "$checked"
