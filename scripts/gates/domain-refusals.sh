#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A domain refusal answers as a violation (docs/SPEC.md § 8 row 84): `DomainRule::refused` names the field, the English
# message and the reason a screen translates, so a form shows it under its field. A plain 422 written « field: message »
# carries no violation, and the screen falls back to its catch-all. No such answer is built in api/src unless its line
# says why it is not a field's refusal: `// plain-refusal: <why>` (a token a page reads, a setting to complete).
# Usage: domain-refusals.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
# The answers built today: losing them (the helper renamed, the tree moved) would leave nothing to check.
FLOOR=90
# A 422 forwarding a domain refusal's English message ($refused->getMessage()) carries no reason and, mostly, no field
# a page can place, so each answers through DomainRule::refused instead. None is left: the count is held at 0, so one
# coming back reds; a token a screen reads on purpose says why on its line with // plain-refusal: <why>.
FORWARDED=${DOMAIN_REFUSALS_FORWARDED:-0}
problems=()

if [[ ! -d "$root/api/src" ]]; then problems+=("found no api/src")
else
  while IFS=: read -r file n line; do
    code=${line#"${line%%[![:space:]]*}"}
    [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* ]] && continue
    [[ "$code" =~ //[[:space:]]*plain-refusal:[[:space:]]*[^[:space:]] ]] && continue
    problems+=("${file#"$root/"}:$n: ${code:0:110}")
  done < <(grep -rnE --include='*.php' "UnprocessableEntityHttpException\(\\\\sprintf\('%s(\.%s)?: %s'" "$root/api/src")
  forwarded=()
  while IFS=: read -r file n line; do
    code=${line#"${line%%[![:space:]]*}"}
    [[ "$code" == '*'* || "$code" == '/*'* || "$code" == '//'* || "$code" == '#'* ]] && continue
    [[ "$code" =~ //[[:space:]]*plain-refusal:[[:space:]]*[^[:space:]] ]] && continue
    [[ "$code" =~ \\sprintf\(\'%s(\.%s)?:\ %s\' ]] && continue  # refused above, as a « field: message » answer
    forwarded+=("${file#"$root/"}:$n: ${code:0:110}")
  done < <(grep -rnE --include='*.php' 'UnprocessableEntityHttpException\(.*->getMessage\(\)' "$root/api/src")
  if ((${#forwarded[@]} > FORWARDED)); then
    problems+=("${#forwarded[@]} 422s forward a domain message, more than the $FORWARDED left: answer through DomainRule::refused with the refusal's field and reason")
    problems+=("${forwarded[@]}")
  elif ((${#forwarded[@]} < FORWARDED)); then
    problems+=("${#forwarded[@]} 422s forward a domain message, fewer than the $FORWARDED this gate holds: lower FORWARDED to ${#forwarded[@]}")
  fi
  found=$(grep -rhoE --include='*.php' 'DomainRule::refused\(' "$root/api/src" | wc -l)
  ((found < FLOOR)) && problems+=("found $found DomainRule::refused answers, fewer than the $FLOOR there are: the helper moved, or this gate reads the wrong tree")
fi

if ((${#problems[@]})); then
  printf 'domain-refusals: FAIL — %d (a domain refusal answers through DomainRule::refused, its field a violation; a plain « field: message » 422 says why with // plain-refusal: <why>):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'domain-refusals: OK — every domain refusal answers as a violation, %d through DomainRule::refused; %d still forward a domain message (S5.3)\n' "$found" "${#forwarded[@]}"
