#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
GATE=$HERE/typed-trims.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
source_of() { # one class whose method holds the given lines
  local d; d=$(mktemp -d); mkdir -p "$d/api/src/Shop/Domain"
  { echo '<?php'; echo 'final class Shop'; echo '{'; echo '    public function named(string $name, string $digits): string'; echo '    {'
    printf '        %s\n' "$@"; echo '    }'; echo '}'; } > "$d/api/src/Shop/Domain/Shop.php"
  echo "$d"
}
gate() { TYPED_TRIMS_FLOOR=1 bash "$GATE" --root "$1" 2>&1; }

echo "typed-trims gate"
d=$(source_of 'return TypedText::trimmed($name);')
out=$(gate "$d"); check "a typed value trimmed through TypedText passes" $? 0 "$out" "1 typed values trimmed"

d=$(source_of 'return trim($name);')
out=$(gate "$d"); check "a one-argument trim() is named" $? 1 "$out" "Shop.php:6 return trim(\$name);"

d=$(source_of '$names = array_map(trim(...), [$name]);' 'return TypedText::trimmed($name);')
out=$(gate "$d"); check "trim() as a callable is named" $? 1 "$out" "array_map(trim(...)"

d=$(source_of 'return trim(str_replace(".", "", $digits), "0") . TypedText::trimmed($name);')
out=$(gate "$d"); check "a trim() given its own characters is another operation" $? 0 "$out" "OK"

d=$(source_of 'return trim($name); // trim: a header the server wrote, never typed' 'return TypedText::trimmed($name);')
out=$(gate "$d"); check "a plain trim() saying why passes, counted" $? 0 "$out" "1 plain trim() saying why"

d=$(source_of 'return trim($name); // trim:' 'return TypedText::trimmed($name);')
out=$(gate "$d"); check "an empty reason is none" $? 1 "$out" "Shop.php:6"

d=$(source_of 'return $this->rtrim($name) . ltrim($name) . Str::trim($name) . TypedText::trimmed($name);')
out=$(gate "$d"); check "rtrim, ltrim and another class's trim are not PHP's trim" $? 0 "$out" "OK"

d=$(source_of '// trim($name) once stood here' 'return TypedText::trimmed($name);')
out=$(gate "$d"); check "a comment naming trim() is no call" $? 0 "$out" "OK"

d=$(source_of 'return TypedText::trimmed($name);')
out=$(TYPED_TRIMS_FLOOR=2 bash "$GATE" --root "$d" 2>&1); check "fewer calls than the floor fail rather than pass on nothing" $? 1 "$out" "fewer than the floor of 2"

echo "typed-trims gate: $pass passed, $fail failed"
((fail == 0))
