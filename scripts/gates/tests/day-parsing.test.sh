#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/day-parsing.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: the one helper parsing, and two readers of a day going through it.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/src/Shared/Domain/Service" "$d/api/src/Module/Reports"
  cat > "$d/api/src/Shared/Domain/Service/WrittenDay.php" <<'PHP'
<?php
final class WrittenDay
{
    public static function parsed(string $written, string $format = 'Y-m-d', ?\DateTimeZone $zone = null): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!'.$format, $written, $zone);

        return false === $day || $day->format($format) !== $written ? null : $day;
    }
}
PHP
  cat > "$d/api/src/Module/Reports/Day.php" <<'PHP'
<?php
$day = WrittenDay::parsed($value);
$moment = WrittenDay::parsed($value, 'Y-m-d H:i', new \DateTimeZone('UTC'));
PHP
  echo "$d"
}
add() { printf '<?php\n%s\n' "$2" > "$1"; }
run() { bash "$GATE" --root "$1" --floor 1 2>&1; }
echo "day-parsing gate"

d=$(tree); out=$(run "$d"); check "days read through the one helper pass, and are counted" $? 0 "$out" "OK — 2"

d=$(tree); add "$d/api/src/Module/Reports/Own.php" "\$day = \\DateTimeImmutable::createFromFormat('!Y-m-d', \$value);
if (false === \$day || \$day->format('Y-m-d') !== \$value) { throw new Refused(); }"
out=$(run "$d"); check "a parse of its own, even compared back by hand, is refused with its file and line" $? 1 "$out" "Own.php:2"

d=$(tree); add "$d/api/src/Module/Reports/Mutable.php" "\$day = \\DateTime::createFromFormat('Y-m-d', \$value);"
out=$(run "$d"); check "a mutable DateTime parse is held to the same" $? 1 "$out" "Mutable.php:2"

d=$(tree); add "$d/api/src/Module/Reports/Fn.php" "\$day = date_create_immutable_from_format('Y-m-d', \$value);"
out=$(run "$d"); check "PHP's function form is held to the same" $? 1 "$out" "Fn.php:2"

d=$(tree); printf '<?php\n// day-parse: an ATOM moment the application wrote into its own message\n$at = \\DateTimeImmutable::createFromFormat(\\DATE_ATOM, $value);\n' > "$d/api/src/Module/Reports/Said.php"
out=$(run "$d"); check "a reason on the line above passes" $? 0 "$out" "OK"

d=$(tree); add "$d/api/src/Module/Reports/Said.php" "\$at = \\DateTimeImmutable::createFromFormat(\\DATE_ATOM, \$value); // day-parse: written by the application"
out=$(run "$d"); check "a reason on the line passes" $? 0 "$out" "OK"

d=$(tree); add "$d/api/src/Module/Reports/Empty.php" "\$at = \\DateTimeImmutable::createFromFormat(\\DATE_ATOM, \$value); // day-parse:"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "Empty.php:2"

d=$(tree); printf '<?php\n/**\n * `createFromFormat` rolls 2026-13-45 into the next year.\n */\n// createFromFormat rolls over\n' > "$d/api/src/Module/Reports/Doc.php"
out=$(run "$d"); check "a parse named in a comment is no parse" $? 0 "$out" "OK — 2"

d=$(tree); mkdir -p "$d/api/src/Other/Domain/Service"; cp "$d/api/src/Shared/Domain/Service/WrittenDay.php" "$d/api/src/Other/Domain/Service/WrittenDay.php"
out=$(run "$d"); check "a second file named like the helper elsewhere is no helper" $? 1 "$out" "Other/Domain/Service/WrittenDay.php"

d=$(tree); add "$d/api/src/Shared/Domain/Service/WrittenDay.php" "final class WrittenDay {}"
out=$(run "$d"); check "a helper that no longer compares what it read back is refused" $? 1 "$out" "WrittenDay.php no longer"

d=$(tree); rm "$d/api/src/Shared/Domain/Service/WrittenDay.php"
out=$(run "$d"); check "the helper gone is refused, not passed" $? 1 "$out" "WrittenDay.php no longer"

d=$(tree); rm -r "$d/api/src"
out=$(run "$d"); check "a missing api/src is refused, not skipped" $? 1 "$out" "found no api/src"

d=$(tree); out=$(bash "$GATE" --root "$d" --floor 5 2>&1); check "fewer days read through the helper than the floor is refused, not passed" $? 1 "$out" "under the floor of 5"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
