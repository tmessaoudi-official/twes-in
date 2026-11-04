#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/day-parsing.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: a day compared back on the next line, one two lines down past a comment, a moment
# compared in its own format, and a comparison written the other way round.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/src/Module/Reports"
  cat > "$d/api/src/Module/Reports/Day.php" <<'PHP'
<?php
$day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
if (false === $day || $day->format('Y-m-d') !== $value) {
    throw new Refused();
}
$at = \DateTimeImmutable::createFromFormat('!Y-m-d', $written, new \DateTimeZone('UTC'));
// A day the calendar does not have rolls over rather than failing: compare it with what was written.
if (false === $at || $at->format('Y-m-d') !== $written) {
    throw new Refused();
}
$moment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, new \DateTimeZone('UTC'));

return false === $moment || $moment->format('Y-m-d H:i') !== $value ? $value : $moment;
$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

return false !== $parsed && $parsed->format('Y-m-d') === $text;
PHP
  echo "$d"
}
add() { printf '<?php\n%s\n' "$2" > "$1"; }
run() { bash "$GATE" --root "$1" --floor 1 2>&1; }
echo "day-parsing gate"

d=$(tree); out=$(run "$d"); check "days and a moment compared back with what was written pass" $? 0 "$out" "OK — 4"

d=$(tree); add "$d/api/src/Module/Reports/Rolled.php" "\$day = \\DateTimeImmutable::createFromFormat('!Y-m-d', \$value);
return false === \$day ? null : \$day;"
out=$(run "$d"); check "a day never compared back is refused with its file and line" $? 1 "$out" "Rolled.php:2"

d=$(tree); add "$d/api/src/Module/Reports/Other.php" "\$day = \\DateTimeImmutable::createFromFormat('!Y-m-d H:i', \$value);
if (false === \$day || \$day->format('Y-m-d') !== \$value) { throw new Refused(); }"
out=$(run "$d"); check "a comparison in another format than the one parsed is no round trip" $? 1 "$out" "Other.php:2"

d=$(tree); add "$d/api/src/Module/Reports/Shown.php" "\$day = \\DateTimeImmutable::createFromFormat('!Y-m-d', \$value);
\$label = \$day->format('Y-m-d');"
out=$(run "$d"); check "a format() that compares nothing is no round trip" $? 1 "$out" "Shown.php:2"

d=$(tree); add "$d/api/src/Module/Reports/Far.php" "\$day = \\DateTimeImmutable::createFromFormat('!Y-m-d', \$value);
\$a = 1;
\$b = 2;
\$c = 3;
\$d = 4;
if (\$day->format('Y-m-d') !== \$value) { throw new Refused(); }"
out=$(run "$d"); check "a comparison more than four lines further is not read as this parse's" $? 1 "$out" "Far.php:2"

d=$(tree); add "$d/api/src/Module/Reports/Mutable.php" "\$day = \\DateTime::createFromFormat('Y-m-d', \$value);"
out=$(run "$d"); check "a mutable DateTime parse is held to the same" $? 1 "$out" "Mutable.php:2"

d=$(tree); add "$d/api/src/Module/Reports/Fn.php" "\$day = date_create_immutable_from_format('Y-m-d', \$value);"
out=$(run "$d"); check "PHP's function form is held to the same" $? 1 "$out" "Fn.php:2"

d=$(tree); add "$d/api/src/Module/Reports/Constant.php" "\$at = \\DateTimeImmutable::createFromFormat(\\DATE_ATOM, \$value);
if (\$at->format(\\DATE_ATOM) !== \$value) { throw new Refused(); }"
out=$(run "$d"); check "a format named by a constant cannot be matched, so it says why" $? 1 "$out" "Constant.php:2"

d=$(tree); printf '<?php\n// day-parse: an ATOM moment the application wrote into its own message\n$at = \\DateTimeImmutable::createFromFormat(\\DATE_ATOM, $value);\n' > "$d/api/src/Module/Reports/Said.php"
out=$(run "$d"); check "a reason on the line above passes" $? 0 "$out" "OK"

d=$(tree); add "$d/api/src/Module/Reports/Said.php" "\$at = \\DateTimeImmutable::createFromFormat(\\DATE_ATOM, \$value); // day-parse: written by the application"
out=$(run "$d"); check "a reason on the line passes" $? 0 "$out" "OK"

d=$(tree); add "$d/api/src/Module/Reports/Empty.php" "\$at = \\DateTimeImmutable::createFromFormat(\\DATE_ATOM, \$value); // day-parse:"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "Empty.php:2"

d=$(tree); printf '<?php\n/**\n * `createFromFormat` rolls 2026-13-45 into the next year.\n */\n// createFromFormat rolls over\n' > "$d/api/src/Module/Reports/Doc.php"
out=$(run "$d"); check "a parse named in a comment is no parse" $? 0 "$out" "OK — 4"

d=$(tree); rm -r "$d/api/src"
out=$(run "$d"); check "a missing api/src is refused, not skipped" $? 1 "$out" "found no api/src"

d=$(tree); out=$(bash "$GATE" --root "$d" --floor 5 2>&1); check "fewer days compared back than the floor is refused, not passed" $? 1 "$out" "under the floor of 5"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
