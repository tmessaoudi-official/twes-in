#!/usr/bin/env bash
#
# Gate: no Doctrine mapping attributes anywhere in the domain layer.
#
# Why it exists: putting `#[ORM\Entity]` on a domain entity is the single most common way a PHP
# codebase that calls itself hexagonal quietly becomes framework-coupled. It compiles, it tests green,
# and it means the domain now cannot be instantiated or reasoned about without Doctrine's attribute
# classes on the autoloader.
#
# What breaks without it: mapping migrates into the entities one attribute at a time, and by the time
# anybody notices, "the domain has zero framework dependencies" is a comment rather than a fact.
# Mapping belongs in XML under Infrastructure/, where the persistence concern lives.
#
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly REPO_ROOT
readonly DOMAIN="$REPO_ROOT/api/src/Domain"

# Every spelling of the same mistake. The leading-backslash forms matter: `#[\Doctrine\ORM\Mapping\Entity]`
# is the canonical fully-qualified attribute and an earlier version of this gate missed it entirely while
# its own comment claimed to cover it.
#
# Each pattern is paired with a concrete line that MUST trip it. That pairing is what lets the meta-suite
# generate one case per pattern instead of covering three of eight by hand — deriving a matching sample
# from a grep regex is not something a test can do, so the gate declares it.
readonly -a FORBIDDEN=(
  '#\[ORM\\'
  '#\[\\\?Doctrine\\ORM\\'
  '#\[\\\?Doctrine\\DBAL\\'
  'use Doctrine\\'
  'use \\Doctrine\\'
  '\\Doctrine\\ORM\\'
  '\\Doctrine\\DBAL\\'
  'Doctrine\\ORM\\Mapping'
)

readonly -a SAMPLES=(
  '#[ORM\Entity]'
  '#[\Doctrine\ORM\Mapping\Entity]'
  '#[\Doctrine\DBAL\Types\Type]'
  'use Doctrine\ORM\Mapping as ORM;'
  'use \Doctrine\ORM\EntityManagerInterface;'
  '$x = \Doctrine\ORM\Query::HYDRATE_ARRAY;'
  '$x = \Doctrine\DBAL\ParameterType::STRING;'
  '$x = Doctrine\ORM\Mapping\ClassMetadata::class;'
)

# A pairing that drifts is worse than none: the meta-suite would generate cases for patterns that no
# longer exist, or silently stop covering ones that do. Checked here so it fails at the gate, not later.
if (( ${#FORBIDDEN[@]} != ${#SAMPLES[@]} )); then
  printf 'no-orm-attributes: FAIL — %d patterns but %d samples. Every pattern needs a sample line.\n' \
    "${#FORBIDDEN[@]}" "${#SAMPLES[@]}" >&2
  exit 1
fi

# See no-ambient-calls-in-domain.php for why gates are introspectable: one generated meta-case per rule,
# so deleting a rule deletes its own case and the baseline in test-gates.sh catches the shrink.
if [[ "${1:-}" == '--dump-rules' ]]; then
  printf '{"patterns":['
  for i in "${!SAMPLES[@]}"; do
    [[ $i -gt 0 ]] && printf ','
    # The samples contain backslashes and quotes; python does the escaping rather than a sed pipeline.
    printf '%s' "$(SAMPLE="${SAMPLES[$i]}" python3 -c 'import json,os; print(json.dumps(os.environ["SAMPLE"]), end="")')"
  done
  printf ']}\n'
  exit 0
fi

# A gate that inspected nothing must not report OK. This printed "does not exist yet, nothing to check"
# — true when written, and still printed after the domain landed, so relocating api/src/Domain would leave
# this P0 unchecked while composer gate:architecture stayed green. layer-dependencies.php was fixed for
# exactly this; these two siblings, reading the same input, were not.
if [[ ! -d "$DOMAIN" ]]; then
  printf 'no-orm-attributes: FAIL — %s does not exist, so nothing was checked. If the domain layer moved, update DOMAIN in this gate.\n' \
    "${DOMAIN#"$REPO_ROOT"/}" >&2
  exit 1
fi

files_checked=$(find "$DOMAIN" -name '*.php' -type f | wc -l)

if (( files_checked == 0 )); then
  printf 'no-orm-attributes: FAIL — inspected 0 files. %s exists but contains no PHP.\n' \
    "${DOMAIN#"$REPO_ROOT"/}" >&2
  exit 1
fi

found=0

for pattern in "${FORBIDDEN[@]}"; do
  # No stderr suppression: the directory's existence is already checked above, so anything grep has to
  # say here is a real problem (an unreadable file, a bad pattern) and must not be swallowed.
  if matches=$(grep -rn --include='*.php' -e "$pattern" "$DOMAIN"); then
    printf 'FORBIDDEN in Domain/ (%s):\n%s\n' "$pattern" "$matches" >&2
    found=$((found + 1))
  fi
done

if (( found > 0 )); then
  cat >&2 <<'EOF'
no-orm-attributes: FAIL — the domain layer references Doctrine.

Move the mapping to XML under api/src/Infrastructure/Persistence/Doctrine/Mapping/ and keep the
entity a plain PHP object. See CLAUDE.md, "Architecture".
EOF
  exit 1
fi

printf 'no-orm-attributes: OK — %d domain file(s) are free of Doctrine.\n' "$files_checked"
