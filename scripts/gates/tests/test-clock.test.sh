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

    protected function moveClock(string $shift): void
    {
    }

    protected static function authenticatorCode(string $secret, string $shift = 'now'): string
    {
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
        $token = self::heldNow()->modify('+30 seconds');
        $summary = $this->json()['today'];
        $at = self::heldNow();
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

d=$(tree); member "$d/api/tests/Unit/EndTest.php" "self::assertEqualsWithDelta((new \\DateTimeImmutable('+7 days'))->getTimestamp(), \$at, 60); Clock::set(new MockClock(new \\DateTimeImmutable('+8 days')));"
out=$(run "$d"); check "a shift by days read as a moment (a timestamp, a unit test's mock clock) is no day" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/AuthTest.php" "Clock::set(new MockClock(new \\DateTimeImmutable('+16 minutes')));"
out=$(run "$d"); check "a functional test moving the clock from the wall's now is refused: moveClock counts from the held one" $? 1 "$out" "AuthTest.php:3"

d=$(tree); mkdir -p "$d/api/tests/Concurrency"; member "$d/api/tests/Concurrency/RaceTest.php" "Clock::set(new MockClock(new \\DateTimeImmutable()));"
out=$(run "$d"); check "a bare wall moment handed to a mock clock is refused under Concurrency too" $? 1 "$out" "RaceTest.php:3"

d=$(tree); member "$d/api/tests/Functional/AuthTest.php" "Clock::set(new NativeClock());"
out=$(run "$d"); check "a functional test letting the wall clock back in is refused: clockBack returns to the held one" $? 1 "$out" "AuthTest.php:3"

d=$(tree); member "$d/api/tests/Functional/MfaTest.php" "\$code = (new OtphpTotpCodes())->codeAt(\$secret, new \\DateTimeImmutable('+30 seconds'));"
out=$(run "$d"); check "an authenticator code for the wall's now is refused: the API checks it against its held clock" $? 1 "$out" "MfaTest.php:3"

d=$(tree); member "$d/api/tests/Functional/ProofTest.php" "Clock::set(new MockClock(new \\DateTimeImmutable('2026-10-06 23:30:00', new \\DateTimeZone('UTC')))); \$c = (new OtphpTotpCodes())->codeAt(\$s, \$at);"
out=$(run "$d"); check "a fixed moment for a mock clock, or a code for a moment the test holds, passes" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/FactorTest.php" "\$user->confirm(\$codes->verify(\$s, \$codes->codeAt(\$s, \$earlier), \$earlier) ?? 0, self::heldNow());"
out=$(run "$d"); check "a code for a moment the test holds passes" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/PairTest.php" "Clock::set(new MockClock(new \\DateTimeImmutable(\"+\$minute minutes\")));"
out=$(run "$d"); check "a move from the wall's now written in double quotes is refused too" $? 1 "$out" "PairTest.php:3"

d=$(tree); member "$d/api/tests/Functional/NoteTest.php" "\$before = new \\DateTimeImmutable('-1 second');"
out=$(run "$d"); check "a functional test's moment off the wall is refused: the API stamps the held one, heldNow() says it" $? 1 "$out" "NoteTest.php:3"

d=$(tree); member "$d/api/tests/Functional/LockTest.php" "\$until = (new \\DateTimeImmutable(\"+\$minutes minutes\"))->format('Y-m-d H:i:s');"
out=$(run "$d"); check "a moment off the wall in double quotes is refused too" $? 1 "$out" "LockTest.php:3"

d=$(tree); member "$d/api/tests/Functional/LockTest.php" "\$until = self::heldNow()->modify('+15 minutes'); \$day = new \\DateTimeImmutable('@'.\$stamp);"
out=$(run "$d"); check "a moment from the held clock, or from a timestamp, passes" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/GrantTest.php" "\$grant = new SupportGrant(\$this->company, \$this->owner, 24, new \\DateTimeImmutable());"
out=$(run "$d"); check "a functional test's bare stamp is refused: it lands after the held now, a second after once rounded" $? 1 "$out" "GrantTest.php:3"

d=$(tree); mkdir -p "$d/api/tests/Concurrency/Support"; member "$d/api/tests/Concurrency/Support/Step.php" "\$now = new \\DateTimeImmutable(); // test-clock: a racer is a process of its own, on its own clock"
out=$(run "$d"); check "a bare stamp saying why passes" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/GrantTest.php" "\$hours = (\$until - time()) / 3600; \$t = \$this->time(); \$u = \$row['time']();"
out=$(run "$d"); check "PHP's time() in a functional test is refused, a method or key named time is not" $? 1 "$out" "GrantTest.php:3"

d=$(tree); member "$d/api/tests/Functional/GrantTest.php" "\$t = \$this->time(); \$u = Clock::time(); \$v = \$row['time']();"
out=$(run "$d"); check "a method, static call or key named time is not PHP's time()" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Unit/ClockTest.php" "\$service = new Reminders(new NativeClock()); Clock::set(new MockClock(new \\DateTimeImmutable()));"
out=$(run "$d"); check "a unit test holds no clock, so the wall's is no refusal there" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/WallTest.php" "Clock::set(new NativeClock()); // test-clock: proves the wall clock is not the held one"
out=$(run "$d"); check "a reason on the line lets the wall clock in" $? 0 "$out" "OK"

d=$(tree); sed -i 's/function moveClock/function shiftClock/' "$d/api/tests/Functional/ApiTestCase.php"
out=$(run "$d"); check "an ApiTestCase without moveClock is refused: the helper the gate sends a move to is gone" $? 1 "$out" "moveClock"

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

d=$(tree); member "$d/api/tests/Functional/LockTest.php" "\$locked = \$db->fetchOne('SELECT locked_until > now() FROM \"user\"');"
out=$(run "$d"); check "the database's now() in a functional test is refused: it is not the clock the API reads" $? 1 "$out" "LockTest.php:3"

d=$(tree); member "$d/api/tests/Functional/IssueTest.php" "\$db->executeStatement('UPDATE invoice SET issue_date = Current_Date WHERE id = ?', [\$id]);"
out=$(run "$d"); check "CURRENT_DATE, in any case, is refused: it is UTC's day, not the company's" $? 1 "$out" "IssueTest.php:3"

d=$(tree); mkdir -p "$d/api/tests/Concurrency"; member "$d/api/tests/Concurrency/StampRaceTest.php" "\$db->executeStatement('UPDATE quote SET updated_at = pg_catalog.now() - interval ''1 minute''');"
out=$(run "$d"); check "a qualified pg_catalog.now() in a race test is refused" $? 1 "$out" "StampRaceTest.php:3"

for form in "CURRENT_TIMESTAMP" "LOCALTIMESTAMP" "statement_timestamp()" "clock_timestamp()" "transaction_timestamp()" "current_time"; do
  d=$(tree); member "$d/api/tests/Functional/FormTest.php" "\$db->executeStatement('UPDATE quote SET updated_at = $form');"
  out=$(run "$d"); check "the database's $form is refused" $? 1 "$out" "FormTest.php:3"
done

d=$(tree); member "$d/api/tests/Functional/NamesTest.php" "\$a = \$clock->now(); \$b = Clock::get()->now(); \$current_date = self::apiNow(); \$c = \$current_dates; \$e = \$row->current_date;"
out=$(run "$d"); check "a clock's now() method and a variable named current_date are not the database's clock" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/NowTest.php" "    public function now(): \\DateTimeImmutable"
out=$(run "$d"); check "a method declared now() is not the database's clock" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/HealthTest.php" "            -- test-clock: the reading measures the queue on the database's clock
            FROM (SELECT now() AS at) placed"
out=$(run "$d"); check "an SQL comment naming the reason on the line above passes" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/HealthTest.php" "\$db->fetchOne('SELECT now()'); -- test-clock:"
out=$(run "$d"); check "an SQL reason left empty is no reason" $? 1 "$out" "HealthTest.php:3"

d=$(tree); member "$d/api/tests/Unit/ClockTest.php" "\$db->fetchOne('SELECT CURRENT_DATE');"
out=$(run "$d"); check "a unit test is left alone: no API clock stands beside it" $? 0 "$out" "OK"

d=$(tree); member "$d/api/tests/Functional/StampTest.php" "\$db->executeStatement('UPDATE quote SET updated_at = ? WHERE id = ?', [self::apiNow('-1 minute'), \$id]);"
out=$(run "$d"); check "a stamp bound from the held clock passes" $? 0 "$out" "OK"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
