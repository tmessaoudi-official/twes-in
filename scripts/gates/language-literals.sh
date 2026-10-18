#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The languages twes-in knows are data (api/config/languages.json): a language written out in the code is one a new
# entry in the catalogue would not reach. So no quoted catalogue tag, with or without its region ('fr', "en-GB"), is
# written in the API's PHP or templates or in the web's sources, unless its own line says why it must be that language:
# `language-literal: <why>` in that line's comment. Specs and the generated API types are left out.
# Usage: language-literals.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
problems=()

catalogue="$root/api/config/languages.json"
tags=()
if [[ -f "$catalogue" ]]; then
  mapfile -t tags < <(jq -r '.languages[]?.tag // empty' "$catalogue")
fi
if ((${#tags[@]} == 0)); then
  printf 'language-literals: FAIL — read no language from %s\n' "${catalogue#"$root/"}"
  exit 1
fi
alternatives=$(IFS='|'; echo "${tags[*]}")
literal="['\"](${alternatives})(-[A-Za-z0-9]{1,8})*['\"]"

scan() {
  local dir=$1; shift
  if [[ ! -d "$root/$dir" ]]; then problems+=("found no $dir"); return; fi
  # Split by hand: `IFS=: read` drops a trailing colon, the end of `language-literal:` with no reason after it.
  while IFS= read -r entry; do
    file=${entry%%:*}; rest=${entry#*:}; n=${rest%%:*}; line=${rest#*:}
    code=${line#"${line%%[![:space:]]*}"}
    # A comment that names a language is not one written out.
    [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* || "$code" == '{#'* || "$code" == '<!--'* ]] && continue
    [[ "$code" =~ language-literal:[[:space:]]*[[:alnum:]] ]] && continue
    problems+=("${file#"$root/"}:$n: ${code:0:110}")
  done < <(grep -rnE "$@" --exclude='*.spec.ts' "$literal" "$root/$dir" | grep -v "^$root/web/src/app/api/")
}
scan api/src --include='*.php'
scan api/templates --include='*.twig'
scan web/src/app --include='*.ts' --include='*.html'

if ((${#problems[@]})); then
  printf 'language-literals: FAIL — %d language(s) written out (read it from the catalogue: Languages in the API, shared/i18n/languages.ts in the web; a line that must name one says why with language-literal: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'language-literals: OK — no language of the catalogue (%s) written out but where its line says why\n' "${tags[*]}"
