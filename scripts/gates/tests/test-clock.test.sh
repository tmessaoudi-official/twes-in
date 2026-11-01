#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/test-clock.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: the day helpers in ApiTestCase, and a test reading its days through them.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/tests/Functional" "$d/api/tests/Unit"
  cat > "$d/api/tests/Functional/ApiTestCase.php" <<'PHP'
<?php
abstract class ApiTestCase
{
    protected static function apiNow(): string
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); // test-clock: the API's own clock
    }

    /** The day it is for the company: `new \DateTimeImmutable('today')` would be UTC's. */
    protected static function dayOf(Company $company, string $shift = 'today'): string
    {
        return new \DateTimeImmutable($shift, new \DateTimeZone($company->getTimezone()))->format('Y-m-d');
    }
}
PHP
  cat > "$d/api/tests/Functional/InvoicesTest.php" <<'PHP'
<?php
final class InvoicesTest extends ApiTestCase
{
    public function testDue(): void
    {
        $due = self::dayOf($this->company, '+30 days');
        $issued = new \DateTimeImmutable('2026-10-15');
        $token = new \DateTimeImmutable('+30 seconds');
        $summary = $this->json()['today'];
        $at = new \DateTimeImmutable();
    }
}
PHP
  echo "$d"
}
member() { printf '<?php\n$x = 1;\n%s\n' "$2" > "$1"; }
run() { bash "$GATE" --root "$1" --floor 2 2>&1; }
echo "test-clock gate"

d=$(tree); out=$(run "$d"); check "days through dayOf, fixed dates, moments and the helpers' own reads pass" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/SeriesTest.php" "\$series->allocate(new \\DateTimeImmutable('today'), new \\DateTimeImmutable());"
out=$(run "$d"); check "a day read on UTC's clock is refused with its file and line" $? 1 "$out" "SeriesTest.php:3"

d=$(tree); member "$d/api/tests/Functional/NotesTest.php" "\$today = new \\DateTimeImmutable('now', new \\DateTimeZone(\$this->company->getTimezone()))->format('Y-m-d');"
out=$(run "$d"); check "a day read in the company's zone by hand is refused: dayOf says it" $? 1 "$out" "NotesTest.php:3"

d=$(tree); member "$d/api/tests/Unit/LateTest.php" "\$late = new DateTime('-3 days');"
out=$(run "$d"); check "a day shift read off the clock is refused, under Unit too" $? 1 "$out" "LateTest.php:3"

d=$(tree); member "$d/api/tests/Functional/EndTest.php" "self::assertEqualsWithDelta((new \\DateTimeImmutable('+7 days'))->getTimestamp(), \$at, 60); Clock::set(new MockClock(new \\DateTimeImmutable('+8 days')));"
out=$(run "$d"); check "a shift by days read as a moment (a timestamp, a mock clock) is no day" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/EndTest.php" "Clock::set(new MockClock(new \\DateTimeImmutable('tomorrow')));"
out=$(run "$d"); check "a clock word is a day even handed to a mock clock" $? 1 "$out" "EndTest.php:3"

d=$(tree); member "$d/api/tests/Functional/YearTest.php" "\$year = (int) date('Y');"
out=$(run "$d"); check "PHP's date() is refused" $? 1 "$out" "YearTest.php:3"

d=$(tree); member "$d/api/tests/Functional/StampTest.php" "\$at = \\strtotime('tomorrow');"
out=$(run "$d"); check "strtotime() is refused" $? 1 "$out" "StampTest.php:3"

d=$(tree); member "$d/api/tests/Functional/PlatformTest.php" "\$day = new \\DateTimeImmutable('today', new \\DateTimeZone('Africa/Tunis')); // test-clock: the platform's own day, no company's"
out=$(run "$d"); check "a reason on the line passes" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/PlatformTest.php" "// test-clock: the platform's own day, no company's
\$day = new \\DateTimeImmutable('today', new \\DateTimeZone('Africa/Tunis'));"
out=$(run "$d"); check "a reason on the line above passes" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/PlatformTest.php" "\$day = new \\DateTimeImmutable('today'); // test-clock:"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "PlatformTest.php:3"

d=$(tree); member "$d/api/tests/Functional/CallsTest.php" "\$d = \$this->date('x'); \$e = \$row['date'](); \$f = Clock::date('y');"
out=$(run "$d"); check "a method or key named date is not PHP's date()" $? 0 "$out" "OK"

d=$(tree); sed -i 's/function dayOf/function someDay/' "$d/api/tests/Functional/ApiTestCase.php"
out=$(run "$d"); check "an ApiTestCase without dayOf is refused: the helper the gate sends to is gone" $? 1 "$out" "dayOf"

d=$(tree); rm "$d/api/tests/Functional/ApiTestCase.php"
out=$(run "$d"); check "a missing ApiTestCase is refused, not read as empty" $? 1 "$out" "found no api/tests/Functional/ApiTestCase.php"

d=$(tree); out=$(bash "$GATE" --root "$d" --floor 3 2>&1); check "fewer test files than the floor reds" $? 1 "$out" "floor"

d=$(tree); rm -r "$d/api/tests"; out=$(run "$d"); check "a missing api/tests is refused, not skipped" $? 1 "$out" "found no api/tests"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
