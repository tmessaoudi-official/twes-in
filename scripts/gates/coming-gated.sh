#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# « Montrer ce qui arrive, marqué « Bientôt » » (docs/SPEC.md § 7, row 150) hides everything planned, so a template that draws the
# « Bientôt » chip (the `shell.soon` key) must sit beside a component that reads `showComing`: a chip drawn without it
# stays on screen after the person chose to hide what is coming (the account page's two tabs did, 2026-10-02). Reads the
# templates and inline templates from the index, specs excluded. Two screens are exempt on purpose: « En construction »
# itself, which a person reaches by asking for it, and the command palette, which draws only the commands the shell
# already filtered. Usage: coming-gated.sh [--root DIR]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
exempt=(web/src/app/shell/coming-page.html web/src/app/shell/command-palette.html)
mapfile -t sites < <(cd "$root" && git ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$' | xargs -r grep -l 'shell\.soon' 2>/dev/null)
if ((${#sites[@]} == 0)); then
  echo "coming-gated: FAIL — no template draws the « Bientôt » chip (shell.soon): the gate would pass over nothing"
  exit 1
fi
status=0
checked=0
for site in "${sites[@]}"; do
  [[ " ${exempt[*]} " == *" $site "* ]] && continue
  component=$site
  [[ $site == *.html ]] && component="${site%.html}.ts"
  checked=$((checked + 1))
  if ! grep -q 'showComing' "$root/$component" 2>/dev/null; then
    printf 'coming-gated: FAIL — %s draws « Bientôt » but %s never reads showComing, so it stays when what is coming is hidden\n' "$site" "$component"
    status=1
  fi
done
((status)) && exit 1
printf 'coming-gated: OK — %d « Bientôt » sites, each beside a component that honours showComing\n' "$checked"
