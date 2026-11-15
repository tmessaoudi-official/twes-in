#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The database keeps an issued invoice as it was issued (the fiscal guard), and only the owner's fiscal_release_company
# lets one go with its company. Tests have doors of their own, every function named fiscal_guard_test_* (the release, the
# owner's TRUNCATE), which api/tests/bootstrap.php makes in a test database and which refuse to run in any other: this
# gate keeps them there. Their names are written only under api/tests and in the scale generator, which works on a scale
# or test database alone; a migration, the configuration, the images or any other source naming one is refused, and each
# definition must keep its own refusal of a database not named for the tests.
# Usage: fiscal-test-door.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
door=fiscal_guard_test_
bootstrap=api/tests/bootstrap.php
allowed_source=api/src/DataFixtures/Scale/ScaleGenerator.php
problems=()

if [[ ! -d "$root/api/src" || ! -d "$root/api/tests" ]]; then problems+=("found no api/src or api/tests")
else
  while IFS=: read -r file n line; do
    rel=${file#"$root/"}
    [[ "$rel" == api/tests/* || "$rel" == "$allowed_source" ]] && continue
    code=${line#"${line%%[![:space:]]*}"}
    problems+=("$rel:$n: ${code:0:110}")
  done < <(grep -rnF --include='*.php' --include='*.sql' --include='*.sh' --include='*.yaml' --include='*.yml' --include='*.twig' \
    -e "$door" "$root/api" "$root/infra" "$root/scripts" 2>/dev/null | grep -v "^$root/scripts/gates/fiscal-test-door.sh:" | grep -v "^$root/scripts/gates/tests/")

  # The doors are made in one place, each keeping itself to a test database.
  makers=$(grep -rlE --include='*.php' "FUNCTION[[:space:]]+(public\.)?$door" "$root/api" 2>/dev/null | sed "s#^$root/##")
  if [[ "$makers" != "$bootstrap" ]]; then
    problems+=("the door must be made in $bootstrap alone; found: ${makers:-nowhere}")
  else
    made=$(grep -cE "FUNCTION[[:space:]]+(public\.)?$door" "$root/$bootstrap")
    refused=$(grep -cF -e "current_database() !~ '^twes_test'" "$root/$bootstrap")
    if ((refused < made)); then
      problems+=("$bootstrap makes $made door(s) and only $refused refuse a database not named for the tests (current_database() !~ '^twes_test'): each makes the door without refusing")
    fi
  fi
fi

if ((${#problems[@]})); then
  printf 'fiscal-test-door: FAIL — the fiscal guard'"'"'s test door is named or made outside the tests (it opens the guard for a transaction):\n'
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'fiscal-test-door: OK — the fiscal guard'"'"'s test door is made in %s, refusing any database but a test one, and named only by the tests and the scale generator\n' "$bootstrap"
