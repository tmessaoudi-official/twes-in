#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# An address a person typed is judged without what sits at its ends, as the API judges it (`TypedText::trimmed`): a
# zero-width space or a direction mark pasted at an end is invisible, and `type="email"` takes only ASCII spaces, so a
# plain `Validators.email` on a hand-built control answers « adresse invalide » with nothing to see. Outside
# `shared/form`, where the descriptor's e-mail field judges the typed text, every `Validators.email` in web/src/app is
# wrapped in `onTypedText(…)` (`shared/form/typed-text.ts`), or its line or the one above says `typed-email: <why>`.
# What is sent is the page's own `typedText(…)`, which each page's spec reads in the request.
# Usage: typed-emails.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
app=$root/web/src/app
problems=()

if [[ ! -d "$app" ]]; then problems+=("found no web/src/app")
elif ! grep -q 'export function onTypedText' "$app/shared/form/typed-text.ts" 2>/dev/null; then
  problems+=("found no onTypedText in web/src/app/shared/form/typed-text.ts")
else
  while IFS=: read -r file n line; do
    code=${line#"${line%%[![:space:]]*}"}
    # A comment that names the validator is not a use of it.
    [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* ]] && continue
    # Each use wrapped in onTypedText is judged on the typed text; one left over on the line is not.
    unwrapped=${code//onTypedText(Validators.email/}
    [[ "$unwrapped" != *Validators.email* ]] && continue
    [[ "$code" =~ typed-email:[[:space:]]*[^[:space:]] ]] && continue
    if ((n > 1)); then
      above=$(sed -n "$((n - 1))p" "$file")
      [[ "$above" =~ typed-email:[[:space:]]*[^[:space:]] ]] && continue
    fi
    problems+=("${file#"$root/"}:$n: ${code:0:110}")
  done < <(grep -rn --include='*.ts' --exclude='*.spec.ts' 'Validators\.email' "$app" | grep -v "^$app/shared/form/")
fi

if ((${#problems[@]})); then
  printf 'typed-emails: FAIL — %d address check(s) on what was typed with its ends (a new or changed address takes emailRule from shared/form/email-rule; one an account signs in with is wrapped in onTypedText from shared/form/typed-text; or say why with typed-email: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'typed-emails: OK — every address a person types is judged without its ends\n'
