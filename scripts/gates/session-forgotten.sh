#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Nothing of a person outlives their session: a facade the whole application shares keeps what it read beyond every
# screen, so a person signing out left their lists and records in memory for whoever signed in next, and a company
# switched away from showed its rows on the next company's screens. Every value a root facade keeps is a
# `sessionSignal` (shared/session/session-data.ts), which SessionData puts back when the session ends or leaves its
# company; a plain `signal(` there says why on its line or the one above with `session-kept: <why>`, or its whole
# file is listed below with its reason. Usage: session-forgotten.sh [--root DIR]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
declare -A exempt=(
  [web/src/app/auth/auth-facade.ts]="the session itself, which tells SessionData every change and forgets itself"
  [web/src/app/shared/i18n/language-facade.ts]="the device's language, kept for the signed-out pages too"
  [web/src/app/shared/theme/theme-facade.ts]="the device's colour scheme, kept for the signed-out pages too"
  # Edited by two other writers in the same round; converted once their work is integrated, so neither rebase conflicts.
  [web/src/app/platform/platform-facade.ts]="to convert after the platform page's round is integrated"
  [web/src/app/settings/company-settings-facade.ts]="to convert after the settings engine's round is integrated"
  [web/src/app/customers/party-settings-facade.ts]="to convert after the settings engine's round is integrated"
  [web/src/app/products/article-settings-facade.ts]="to convert after the settings engine's round is integrated"
  [web/src/app/company/document-design-facade.ts]="to convert after the settings engine's round is integrated"
)
mapfile -t files < <(cd "$root" && git ls-files -- 'web/src/app/*-facade.ts' | grep -v '\.spec\.ts$')
status=0
facades=0
kept=0
for file in "${files[@]}"; do
  grep -q "providedIn: 'root'" "$root/$file" 2>/dev/null || continue
  [[ -n "${exempt[$file]:-}" ]] && continue
  facades=$((facades + 1))
  kept=$((kept + $(grep -c 'sessionSignal[<(]' "$root/$file")))
  previous=''
  line=0
  while IFS= read -r text; do
    line=$((line + 1))
    if [[ "$text" =~ readonly\ [A-Za-z]+\ =\ (signal|linkedSignal)[\<\(] ]] \
      && [[ "$text" != *session-kept:* && "$previous" != *session-kept:* ]]; then
      printf 'session-forgotten: FAIL — %s:%d keeps a plain signal: use sessionSignal, which is forgotten when the session ends, or say why with session-kept: <why>\n' "$file" "$line"
      status=1
    fi
    previous=$text
  done < "$root/$file"
done
# Over a hundred values were converted when this gate was written: far fewer means the scan went blind.
if ((kept < 100)); then
  printf 'session-forgotten: FAIL — %d sessionSignal values found in %d root facades, 100 at least expected: the scan no longer sees them\n' "$kept" "$facades"
  exit 1
fi
((status)) && exit 1
printf 'session-forgotten: OK — %d values in %d root facades are forgotten with the session (%d files exempt with a reason)\n' "$kept" "$facades" "${#exempt[@]}"
