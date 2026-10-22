#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
GATE=$HERE/list-limits.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
resource() { # the property's block, as lines
  local d; d=$(mktemp -d); mkdir -p "$d/api/src/Module/Shop/Infrastructure/ApiPlatform/Resource"
  { echo '<?php'; echo 'final class ShopResource'; echo '{'; echo '    public string $name = "";'; echo
    printf '    %s\n' "$@"; echo '}'; } > "$d/api/src/Module/Shop/Infrastructure/ApiPlatform/Resource/ShopResource.php"
  echo "$d"
}
gate() { LIST_LIMITS_FLOOR=1 bash "$GATE" --root "$1" 2>&1; }

echo "list-limits gate"
d=$(resource '#[ListLimit(ListLimits::DOCUMENT_LINES, groups: [self::WRITE])]' '#[Groups([self::READ, self::WRITE])]' 'public array $lines = [];')
out=$(gate "$d"); check "a written list held to the operator's limit passes" $? 0 "$out" "1 written lists on 1 resources: 1 bounded"

d=$(resource '#[Assert\Count(min: 1, max: self::MOST, groups: [self::WRITE])]' '#[Groups([self::WRITE])]' 'public array $ids = [];')
out=$(gate "$d"); check "a written list counted to a maximum passes" $? 0 "$out" "1 bounded"

d=$(resource '#[Groups([self::READ, self::WRITE])]' 'public array $lines = [];')
out=$(gate "$d"); check "a written list with no limit is named" $? 1 "$out" "ShopResource.php:7 \$lines"

d=$(resource '#[Assert\Count(min: 1, groups: [self::WRITE])]' '#[Groups([self::WRITE])]' 'public array $parts = [];')
out=$(gate "$d"); check "a count with a minimum alone is no limit" $? 1 "$out" "\$parts"

d=$(resource '#[Assert\Type('"'list'"', groups: [self::ORDER])]' '#[Groups([self::ORDER])]' 'public ?array $placeIds = null;')
out=$(gate "$d"); check "a list a constraint checks is written, whatever its group is called" $? 1 "$out" "\$placeIds"

d=$(resource '#[Groups([self::READ, self::WRITE])]' '// count: one entry per language of the catalogue' 'public array $names = [];')
out=$(gate "$d"); check "a list whose block says why it carries no limit passes, counted" $? 0 "$out" "1 saying why not"

d=$(resource '#[Groups([self::WRITE])]' '// count:' 'public array $lines = [];')
out=$(gate "$d"); check "an empty reason is none" $? 1 "$out" "\$lines"

d=$(resource '#[ApiProperty(writable: false)]' '#[Groups([self::READ])]' 'public array $lines = [];' '#[Groups([self::READ, self::WRITE])]' 'public string $note = "";')
out=$(LIST_LIMITS_FLOOR=0 bash "$GATE" --root "$d" 2>&1); check "a list only read is not one a request writes" $? 0 "$out" "0 written lists"

d=$(resource '#[ListLimit(ListLimits::DOCUMENT_LINES, groups: [self::WRITE])]' '#[Groups([self::WRITE])]' 'public array $lines = [];')
out=$(LIST_LIMITS_FLOOR=2 bash "$GATE" --root "$d" 2>&1); check "fewer written lists than the floor fail rather than pass on nothing" $? 1 "$out" "fewer than the floor of 2"

echo "list-limits gate: $pass passed, $fail failed"
((fail == 0))
