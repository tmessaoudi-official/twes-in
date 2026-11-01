#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Once a release is out, the schema only grows (docs/START.md § 16): an update that turns out unhealthy starts the
# release before it again on the new schema (scripts/onprem/twes), so that release must still read and write it. A
# migration added since the newest release tag therefore never contracts in up(): no table, view or column dropped,
# nothing renamed, no column's type changed, no NOT NULL set, no NOT NULL column added without a default, no default
# dropped (the release before inserts without the column). A contraction belongs to a later release than the one that
# stopped reading what it removes, and its line says so: `// contract: <why the release before no longer reads it>`.
# Before the first release there is nothing to roll back to, and every migration may still reshape the schema.
# A migration the release holds is never edited, a statement is read whole however many lines it takes, a new CHECK
# or UNIQUE on a table the release holds contracts too (the release before writes rows it would refuse), and CONCURRENTLY
# is refused outright: the update runs every migration in one transaction, which it cannot join.
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
# A migration the release holds is never edited: a shop that ran it keeps what it did, and the edit runs nowhere.
while IFS= read -r held; do
  [[ -n "$held" && -f "$root/$held" ]] || continue
  git -C "$root" show "$release:$held" | cmp -s - "$root/$held" \
    || problems+=("$held differs from $release: a released migration is never edited; write a new one")
done <<<"$released"

since=()
for path in "$root"/api/migrations/*.php; do
  [[ -f "$path" ]] || continue
  grep -qxF -e "api/migrations/${path##*/}" <<<"$released" || since+=("$path")
done
# The tables made since the release: a rule added to one of them refuses nothing an older release wrote.
new_tables=" "
for path in "${since[@]}"; do
  while read -r name; do new_tables+="${name//\"/} "; done < <(grep -oiE 'CREATE[[:space:]]+TABLE[[:space:]]+(IF[[:space:]]+NOT[[:space:]]+EXISTS[[:space:]]+)?"?[A-Za-z_][A-Za-z0-9_]*' "$path" \
    | awk '{ print tolower($NF) }')
done

# Judges one statement of up(), written on one line or over several, reported at the line it starts on.
judge() { # file, line, the statement as written
  local file=$1 n=$2 code=$3 statement table
  statement=$(tr '[:lower:]' '[:upper:]' <<<"$code" | sed -E 's#//[^'"'"'"]*$##')
  # The update migrates all or nothing in one transaction, which CONCURRENTLY cannot join: no reason makes it fit.
  if grep -qE '\bCONCURRENTLY\b' <<<"$statement"; then
    problems+=("api/migrations/$file:$n: CONCURRENTLY cannot run inside the update's one transaction: ${code:0:90}"); return
  fi
  [[ "$code" =~ //[[:space:]]*contract:[[:space:]]*[^[:space:]] ]] && return
  statement=$(sed -E "s/$loosening//g" <<<"$statement")
  # A NOT NULL column added with no default: the release before inserts rows without it, which are refused.
  if grep -qE "$contracting" <<<"$statement" \
    || { grep -qE '\bALTER[[:space:]]+TABLE\b.*\bADD\b.*\bNOT[[:space:]]+NULL\b' <<<"$statement" && ! grep -qE '\bDEFAULT\b|\bCHECK\b' <<<"$statement"; }; then
    problems+=("api/migrations/$file:$n: ${code:0:110}"); return
  fi
  # A new CHECK or UNIQUE on a table the release before writes to refuses what it writes.
  table=''
  if grep -qE '\bALTER[[:space:]]+TABLE\b.*\bADD[[:space:]]+(CONSTRAINT[[:space:]]+[^[:space:]]+[[:space:]]+)?(CHECK|UNIQUE)\b' <<<"$statement"; then
    table=$(sed -nE 's/.*\bALTER[[:space:]]+TABLE[[:space:]]+(IF[[:space:]]+EXISTS[[:space:]]+)?(ONLY[[:space:]]+)?"?([A-Z_][A-Z0-9_]*).*/\3/p' <<<"$statement")
  elif grep -qE '\bCREATE[[:space:]]+UNIQUE[[:space:]]+INDEX\b' <<<"$statement"; then
    table=$(sed -nE 's/.*\bON[[:space:]]+(ONLY[[:space:]]+)?"?([A-Z_][A-Z0-9_]*).*/\2/p' <<<"$statement")
  fi
  if [[ -n "$table" && "$new_tables" != *" ${table,,} "* ]]; then
    problems+=("api/migrations/$file:$n: a new rule on ${table,,}, which the release before writes to: ${code:0:90}")
  fi
}

for path in "${since[@]}"; do
  file=${path##*/}
  n=0 inside=0 pending='' start=0
  while IFS= read -r line || [[ -n "$line" ]]; do
    n=$((n + 1))
    if [[ "$line" =~ function[[:space:]]+up[[:space:]]*\( ]]; then inside=1; continue; fi
    if [[ "$line" =~ function[[:space:]]+[a-zA-Z_]+[[:space:]]*\( ]]; then
      # A statement still open when up() ends is judged as read, never dropped unseen.
      [[ -n "$pending" ]] && judge "$file" "$start" "$pending"
      inside=0 pending=''
    fi
    ((inside)) || continue
    code=${line#"${line%%[![:space:]]*}"}
    if [[ -z "$pending" ]]; then
      [[ -z "$code" || "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* ]] && continue
      start=$n pending=$code
    else
      pending+=" $code"
    fi
    # An addSql call whose closing `);` is not yet read continues on the next line.
    [[ "$pending" == *'addSql('* && ! "$pending" =~ \)[[:space:]]*\;[[:space:]\;]*(//.*)?$ ]] && continue
    judge "$file" "$start" "$pending"
    pending=''
  done < "$path"
  [[ -n "$pending" ]] && judge "$file" "$start" "$pending"
done

if ((${#problems[@]})); then
  printf 'additive-migrations: FAIL — %d statement(s) since %s contract the schema the release before it still runs on (add instead; contract in a later release, its line saying // contract: <why>):\n' "${#problems[@]}" "$release"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'additive-migrations: OK — every migration since %s only adds to the schema, or says why its contraction is safe\n' "$release"
