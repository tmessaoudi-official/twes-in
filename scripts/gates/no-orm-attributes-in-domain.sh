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

if [[ ! -d "$DOMAIN" ]]; then
  printf 'no-orm-attributes: api/src/Domain does not exist yet, nothing to check.\n'
  exit 0
fi

# Every spelling of the same mistake. The leading-backslash forms matter: `#[\Doctrine\ORM\Mapping\Entity]`
# is the canonical fully-qualified attribute and an earlier version of this gate missed it entirely while
# its own comment claimed to cover it.
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

printf 'no-orm-attributes: OK — Domain/ is free of Doctrine.\n'
