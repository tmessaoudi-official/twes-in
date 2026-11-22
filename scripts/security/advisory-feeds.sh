#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The weekly read of the maintainers' own advisories (.github/workflows/advisory-feeds.yml; docs/UPDATE.md): composer audit
# reads GitHub's reviewed database, and an advisory a maintainer publishes on its repository reaches it only once GitHub
# reviews it, which may never happen (API Platform's and webauthn-framework's of November 2026 never did). This reads the
# published advisories of every repository a package of api/composer.lock comes from, and of the monorepos that publish
# for their split packages (ADVISORY_EXTRA_REPOS, the three below unless set), matches each against every locked package
# by name, and fails on one whose range holds the locked version, unless .advisoryignore names it with its verdict and a
# date not yet passed (exp:YYYY-MM-DD, after which it fails again), as .trivyignore does for the images.
#   exit 0  nothing reaches a locked version outside .advisoryignore
#   exit 3  an advisory reaches one: take the fixed release, or triage it with its verdict
#   exit 1  a repository could not be read, or an advisory's range could not: the run says nothing of those
#   exit 2  usage, or a lock naming no package from GitHub
# Usage: advisory-feeds.sh [composer.lock] [.advisoryignore]     (gh reads GitHub: GH_TOKEN in CI, gh auth on a machine)
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
lock=${1:-$root/api/composer.lock} ignore=${2:-$root/.advisoryignore}
[[ -f "$lock" ]] || { echo "advisory-feeds: found no lock at $lock" >&2; exit 2; }
# A split package comes from a read-only repository of its own, and its advisories are published on the monorepo it is
# split from: Symfony's components and contracts (symfony/symfony), its polyfills (symfony/polyfill), webauthn-lib
# (web-auth/webauthn-framework), async-aws's clients (async-aws/aws); Flysystem's adapters are read through
# thephpleague/flysystem, itself locked. GitHub marks no split as such: a split has its issues switched off (some also
# say « [READ ONLY] », symfony/cache does not), so a dependency from a repository without issues names its monorepo here
# (docs/UPDATE.md).
extra=${ADVISORY_EXTRA_REPOS-symfony/symfony symfony/polyfill web-auth/webauthn-framework async-aws/aws}

declare -A version=() wanted=()
while IFS=$'\t' read -r name ver url; do
  version[$name]=$ver
  repo=$(sed -nE 's|^https://github\.com/([^/]+/[^/]+)/?$|\1|p' <<<"$url"); repo=${repo%.git}
  [[ -n "$repo" ]] && wanted[$repo]=1
done < <(jq -r '(.packages + (."packages-dev" // []))[] | [.name, .version, (.source.url // "")] | @tsv' "$lock")
((${#wanted[@]})) || { echo "advisory-feeds: found no package from GitHub in $lock: the run would read nothing" >&2; exit 2; }
for repo in $extra; do wanted[$repo]=1; done

today=$(date -u +%F)
declare -A triaged=()
if [[ -f "$ignore" ]]; then
  while read -r id exp _; do
    [[ "$exp" =~ ^exp:([0-9]{4}-[0-9]{2}-[0-9]{2})$ ]] && [[ ! "${BASH_REMATCH[1]}" < "$today" ]] && triaged[$id]=1
  done < <(grep -E '^(GHSA|CVE)-' "$ignore")
fi

# -1, 0 or 1 as $1 is below, equal to or above $2, both read as dotted numbers (a leading v dropped), the shorter padded
# with zeros: a bound of « <= 8.1 » holds 8.1.0, which sort -V alone puts above it.
compare() {
  local a=${1#v} b=${2#v} x y
  IFS=. read -ra x <<<"$a"; IFS=. read -ra y <<<"$b"
  while ((${#x[@]} < ${#y[@]})); do x+=(0); done
  while ((${#y[@]} < ${#x[@]})); do y+=(0); done
  a=$(IFS=.; echo "${x[*]}") b=$(IFS=.; echo "${y[*]}")
  [[ "$a" == "$b" ]] && { echo 0; return; }
  [[ "$(printf '%s\n%s\n' "$a" "$b" | sort -V | head -n 1)" == "$a" ]] && echo -1 || echo 1
}
# Whether version $1 lies in GitHub's range $2: alternatives joined by ||, each of clauses joined by commas that must all
# hold (">= 4.1.0, <= 5.0.7"). A wildcard ("2.16.*") is read as an alternative of its own, wherever it is written: Twig
# writes "2.16.*,>=3.9.0,<3.26.0" for either, which read as one conjunction would match nothing and hide the advisory.
# 1 when it does not; 2 when a clause cannot be read.
within() {
  local alternative clause op bound c holds plain v=${1#v}
  local -a alternatives clauses
  mapfile -t alternatives < <(sed 's/||/\n/g' <<<"$2")
  for alternative in "${alternatives[@]}"; do
    IFS=',' read -ra clauses <<<"$alternative"
    plain=()
    for clause in "${clauses[@]}"; do
      if [[ "$clause" =~ ^[[:space:]]*v?([0-9]+(\.[0-9]+)*)\.\*[[:space:]]*$ ]]; then
        [[ "$v" == "${BASH_REMATCH[1]}" || "$v" == "${BASH_REMATCH[1]}".* ]] && return 0
      else
        plain+=("$clause")
      fi
    done
    ((${#plain[@]})) || continue
    holds=yes
    for clause in "${plain[@]}"; do
      [[ "$clause" =~ ^[[:space:]]*(>=|<=|>|<|=)?[[:space:]]*v?([0-9][0-9A-Za-z.+-]*)[[:space:]]*$ ]] || return 2
      op=${BASH_REMATCH[1]:-=} bound=${BASH_REMATCH[2]}
      c=$(compare "$v" "$bound")
      case "$op" in
        '>=') ((c >= 0)) ;; '<=') ((c <= 0)) ;; '>') ((c > 0)) ;; '<') ((c < 0)) ;; '=') ((c == 0)) ;;
      esac || { holds=no; break; }
    done
    [[ "$holds" == yes ]] && return 0
  done
  return 1
}

found=() problems=() unread=() read=0 advisories=0 kept=0
declare -A seen=()
for repo in $(printf '%s\n' "${!wanted[@]}" | sort); do
  if ! page=$(gh api --paginate "repos/$repo/security-advisories?state=published&per_page=100" 2>&1); then
    unread+=("$repo: could not be read ($(head -n 1 <<<"$page"))"); continue
  fi
  read=$((read + 1))
  while IFS=$'\t' read -r id package range severity summary; do
    advisories=$((advisories + 1))
    [[ -n "${version[$package]:-}" && -z "${seen[$id|$package]:-}" ]] || continue
    seen[$id|$package]=1
    if [[ -z "$range" || "$range" == null ]]; then
      problems+=("$id names no version range for $package ($repo)"); continue
    fi
    within "${version[$package]}" "$range"; status=$?
    if ((status == 2)); then problems+=("$id: the range « $range » for $package could not be read ($repo)"); continue; fi
    ((status == 0)) || continue
    if [[ -n "${triaged[$id]:-}" ]]; then kept=$((kept + 1)); continue; fi
    found+=("$id $package ${version[$package]} ($range) $severity: $summary — https://github.com/$repo/security/advisories/$id")
  done < <(jq -rs 'add // [] | .[] | select(.withdrawn_at == null) | .ghsa_id as $id | .severity as $s | .summary as $t
      | .vulnerabilities[]? | select(.package.ecosystem == "composer")
      | [$id, .package.name, (.vulnerable_version_range // "null"), $s, ($t | gsub("[\t\n]"; " "))] | @tsv' <<<"$page")
done

if ((${#found[@]})); then
  echo "advisory-feeds: ${#found[@]} advisory(ies) reach a locked version:"
  printf '  %s\n' "${found[@]}"
  echo "Take the fixed release (docs/UPDATE.md), or triage it into .advisoryignore with its verdict and exp:."
fi
if ((${#unread[@]} + ${#problems[@]})); then
  echo "advisory-feeds: FAIL — what could not be read says nothing of its packages:"
  printf '  %s\n' "${unread[@]}" "${problems[@]}"
  exit 1
fi
((${#found[@]})) && exit 3
printf 'advisory-feeds: OK — %d repositories, %d advisories read, nothing reaches a locked version outside .advisoryignore (%d triaged)\n' \
  "$read" "$advisories" "$kept"
