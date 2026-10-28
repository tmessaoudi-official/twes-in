#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The digest of every file an installation's services mount from the checkout, read from its merged configuration on
# standard input (`docker compose … config`). A checkout replaces such a file with a new one, which a running container
# never sees: its mount still holds the file it started with, and compose, finding the configuration unchanged, keeps the
# container. Every service mounting a file carries this digest as the label twes.mounts-digest
# (scripts/gates/mounted-files.sh), so a changed file recreates it and an unchanged one recreates nothing. A directory's
# mount sees what changes inside it, and is left out. Nothing but bash, awk and sha256sum: a shop's machine has no jq.
# Usage: docker compose … config | mounts-digest.sh
set -euo pipefail
# A bind mount in compose's long syntax, its keys in any order: `- bind: {…}`, `source: /path`, `target: …`, `type: bind`
# at one indentation under its list's dash. An item ends at the next dash or at a line less indented than its keys.
sources=$(awk '
  function flush() { if (bind && source != "") print source; bind = 0; source = ""; depth = -1 }
  BEGIN { depth = -1 }
  {
    indent = match($0, /[^ ]/) - 1
    line = substr($0, indent + 1)
    if (line ~ /^- /) { flush(); depth = indent + 2; line = substr(line, 3) }
    else if (depth < 0 || indent < depth) { flush(); next }
    else if (indent > depth) { next }
    if (line == "type: bind") bind = 1
    else if (line ~ /^source: /) { source = substr(line, 9); gsub(/^"|"$/, "", source); gsub(/^'\''|'\''$/, "", source) }
  }
  END { flush() }')
while IFS= read -r source; do
  [[ -n "$source" && -f "$source" ]] || continue
  printf '%s %s\n' "$source" "$(sha256sum < "$source" | cut -d' ' -f1)"
done < <(sort -u <<<"$sources") | sha256sum | cut -d' ' -f1
