#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A role's name reaches a template through `roleKey` (web/src/app/shared/i18n/role-key.ts), which translates the roles
# the release ships and hands a role a company named through as typed. Built by hand as `'roles.' + name`, a company's
# « barista » read « roles.barista » in the company switcher, the shell and the invitation (conf-18). Reads templates
# and inline component templates from the index, specs excluded, and reds below a floor of roleKey uses, so a pattern
# that stops matching cannot pass over nothing. Usage: role-names.sh [--root DIR] [--floor N]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=8
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "role-names: unknown argument $1"; exit 2 ;;
  esac
done
mapfile -t files < <(cd "$root" && git ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
status=0
uses=0
for file in "${files[@]}"; do
  while IFS= read -r hit; do
    [[ -z "$hit" ]] && continue
    printf 'role-names: FAIL — %s:%s builds a roles. key by hand: a role a company named reads « roles.<name> »; write `name | roleKey | translate`\n' "$file" "$hit"
    status=1
  done < <(grep -nE "'roles\.' *\+" "$root/$file" | cut -d: -f1)
  n=$(grep -oE '\| *roleKey\b' "$root/$file" | wc -l)
  uses=$((uses + n))
done
if ((uses < floor)); then
  printf 'role-names: FAIL — %d roleKey uses in the templates, below the floor of %d: the pattern stopped matching or a site went back to a hand-built key\n' "$uses" "$floor"
  exit 1
fi
((status)) && exit 1
printf 'role-names: OK — %d roles named through roleKey, none built by hand\n' "$uses"
