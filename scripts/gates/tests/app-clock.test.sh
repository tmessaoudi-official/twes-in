#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/app-clock.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: a use case reading the application's clock, a date parsed from data, a fixed day,
# an array key and a method named like a clock.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/src/Tenancy"
  cat > "$d/api/src/Tenancy/Step.php" <<'PHP'
<?php
$now = $this->clock->now();
$day = new \DateTimeImmutable($row['day'], $zone);
$from = new \DateTimeImmutable('2000-01-01T00:00:00Z');
$numbers[] = $series->allocate(new \DateTimeImmutable(Rows::text($row['issue_date'])), $now);
$at = new \DateTimeImmutable('@'.$stamp);
$params = ['today' => $today->format('Y-m-d'), 'time' => 3];
$this->date($node, $day);
$ok = checkdate(2, 30, 2026);
$moment = Clock::get()->now();
$elapsed = hrtime(true);
PHP
  echo "$d"
}
line() { printf '<?php\n%s\n' "$2" > "$1"; }
run() { bash "$GATE" --root "$1" --floor 1 2>&1; }
echo "app-clock gate"

d=$(tree); out=$(run "$d"); check "the application's clock, data parsed, a fixed day, a key and a method pass" $? 0 "$out" "OK"

d=$(tree); line "$d/api/src/Tenancy/User.php" '        $this->updatedAt = $now ?? new \DateTimeImmutable();'
out=$(run "$d"); check "a default to the system clock is refused with its file and line" $? 1 "$out" "User.php:2"

d=$(tree); line "$d/api/src/Tenancy/Mutable.php" '$at = new DateTime();'
out=$(run "$d"); check "a mutable DateTime, unqualified, is the system clock too" $? 1 "$out" "Mutable.php:2"

d=$(tree); line "$d/api/src/Tenancy/Search.php" "->setParameter('today', new \\DateTimeImmutable(\$search->today ?? 'today'), Types::DATE_IMMUTABLE);"
out=$(run "$d"); check "a clock word handed to the constructor after ?? is refused" $? 1 "$out" "Search.php:2"

d=$(tree); line "$d/api/src/Tenancy/Zone.php" "\$day = new \\DateTimeImmutable('now', new \\DateTimeZone(\$zone));"
out=$(run "$d"); check "'now' in a zone is still the system clock" $? 1 "$out" "Zone.php:2"

d=$(tree); line "$d/api/src/Tenancy/Zoned.php" "\$at = new \\DateTimeImmutable(timezone: \$zone);"
out=$(run "$d"); check "a DateTime given a zone alone is now in that zone" $? 1 "$out" "Zoned.php:2"

d=$(tree); line "$d/api/src/Tenancy/Shift.php" "\$soon = new \\DateTimeImmutable('+3 days');"
out=$(run "$d"); check "a shift from now is a read of the system clock" $? 1 "$out" "Shift.php:2"

for call in 'time()' 'microtime(true)' "date('Y-m-d')" "gmdate('c')" "strtotime('tomorrow')" 'mktime(0, 0, 0)' 'date_create()' "\\time()" 'getdate()' 'localtime()' 'gettimeofday(true)' "idate('Y')"; do
  d=$(tree); line "$d/api/src/Tenancy/Call.php" "\$x = $call;"
  out=$(run "$d"); check "PHP's $call is refused" $? 1 "$out" "Call.php:2"
done

d=$(tree); line "$d/api/src/Tenancy/Server.php" "\$x = \$_SERVER['REQUEST_TIME'];"
out=$(run "$d"); check "the request's own time is the system clock" $? 1 "$out" "Server.php:2"

d=$(tree); printf '<?php\n// system-clock: the rate limiter answers on the system time\n$seconds = $at - time();\n' > "$d/api/src/Tenancy/Budget.php"
out=$(run "$d"); check "a reason on the line above passes" $? 0 "$out" "OK"

d=$(tree); line "$d/api/src/Tenancy/Budget.php" '$now = time(); // system-clock: PdoSessionHandler compares with time()'
out=$(run "$d"); check "a reason on the line passes" $? 0 "$out" "OK"

d=$(tree); line "$d/api/src/Tenancy/Budget.php" '$now = time(); // system-clock:'
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "Budget.php:2"

d=$(tree); printf '<?php\n/** Never default to new \\DateTimeImmutable() or time(). */\n// date() reads the server\n' > "$d/api/src/Tenancy/Doc.php"
out=$(run "$d"); check "a clock named in a comment is no read" $? 0 "$out" "OK"

d=$(tree); printf '<?php\nfunction date(): void {}\nprivate function time(): int {}\n' > "$d/api/src/Tenancy/Named.php"
out=$(run "$d"); check "a function declared with a clock's name is no read" $? 0 "$out" "OK"

d=$(tree); rm -r "$d/api/src"
out=$(run "$d"); check "a missing api/src is refused, not skipped" $? 1 "$out" "found no api/src"

d=$(tree); out=$(bash "$GATE" --root "$d" --floor 5 2>&1); check "fewer PHP files than the floor is refused, not passed" $? 1 "$out" "under the floor of 5"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
