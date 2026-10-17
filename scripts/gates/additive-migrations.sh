#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Once a release is out, the schema only grows (docs/START.md § 16): an update that turns out unhealthy starts the
# release before it again on the new schema (scripts/onprem/twes), so that release must still read and write it. A
# migration added since the newest release tag therefore never contracts in up(): no table, view or column dropped,
# nothing renamed, no column's type changed, no NOT NULL set, no NOT NULL column added without a default, no default
# dropped (the release before inserts without the column). A contraction belongs to a later release than the one that
# stopped reading what it removes, and its line says so: `// contract: <why the release before no longer reads it>`.
# Before the first release there is nothing to roll back to, and every migration may still reshape the schema.
# Usage: additive-migrations.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
release_tags='v[0-9][0-9][0-9][0-9].[0-9][0-9].[0-9][0-9].*'
contracting='\b(DROP[[:space:]]+(TABLE|VIEW|COLUMN)|RENAME|SET[[:space:]]+NOT[[:space:]]+NULL|DROP[[:space:]]+DEFAULT)\b|\bALTER[[:space:]]+(COLUMN[[:space:]]+)?[A-Za-z_"]+[[:space:]]+TYPE\b|\bALTER[[:space:]]+TABLE[[:space:]]+[^[:space:]]+[[:space:]]+DROP[[:space:]]+[A-Za-z_"]+'"'"'\)'
# Dropping an index or a constraint, or a NOT NULL, loosens what the release before may write; it never contracts.
loosening='DROP[[:space:]]+(INDEX|CONSTRAINT|NOT[[:space:]]+NULL|TRIGGER)\b'

[[ -d "$root/api/migrations" ]] || { echo "additive-migrations: FAIL — found no api/migrations under $root"; exit 1; }
git -C "$root" rev-parse --git-dir >/dev/null 2>&1 || { echo "additive-migrations: FAIL — $root is not a git repository: the release tags are read from it"; exit 1; }
release=$(git -C "$root" tag --list "$release_tags" --sort=-v:refname | head -n 1)
if [[ -z "$release" ]]; then
  echo "additive-migrations: OK — no release yet, so nothing runs an older one: every migration may still reshape the schema"
  exit 0
fi

problems=()
released=$(git -C "$root" ls-tree -r --name-only "$release" -- api/migrations)
for path in "$root"/api/migrations/*.php; do
  [[ -f "$path" ]] || continue
  file=${path##*/}
  grep -qxF -e "api/migrations/$file" <<<"$released" && continue
  n=0 inside=0
  while IFS= read -r line || [[ -n "$line" ]]; do
    n=$((n + 1))
    if [[ "$line" =~ function[[:space:]]+up[[:space:]]*\( ]]; then inside=1; continue; fi
    [[ "$line" =~ function[[:space:]]+[a-zA-Z_]+[[:space:]]*\( ]] && inside=0
    ((inside)) || continue
    code=${line#"${line%%[![:space:]]*}"}
    [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* ]] && continue
    statement=$(tr '[:lower:]' '[:upper:]' <<<"${code%%//*}")
    statement=$(sed -E "s/$loosening//g" <<<"$statement")
    # A NOT NULL column added with no default: the release before inserts rows without it, which are refused.
    if ! grep -qE "$contracting" <<<"$statement" \
      && ! { grep -qE '\bALTER[[:space:]]+TABLE\b.*\bADD\b.*\bNOT[[:space:]]+NULL\b' <<<"$statement" && ! grep -qE '\bDEFAULT\b' <<<"$statement"; }; then
      continue
    fi
    [[ "$code" =~ //[[:space:]]*contract:[[:space:]]*[^[:space:]] ]] && continue
    problems+=("api/migrations/$file:$n: ${code:0:110}")
  done < "$path"
done

if ((${#problems[@]})); then
  printf 'additive-migrations: FAIL — %d statement(s) since %s contract the schema the release before it still runs on (add instead; contract in a later release, its line saying // contract: <why>):\n' "${#problems[@]}" "$release"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'additive-migrations: OK — every migration since %s only adds to the schema, or says why its contraction is safe\n' "$release"
