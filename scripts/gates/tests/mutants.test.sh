#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../mutants" && pwd)/mutants.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
absent() { if [[ "$2" != *"$3"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (found '$3')"; echo "$2" | sed 's/^/       /'; fi; }
same() { if cmp -s "$2" "$3"; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 ($2 differs from $3)"; fi; }

# The file mutated: a tab, an ampersand, guillemets around a no-break space, and no newline at its end.
A=$'x="GOOD"\n\ty=1 && z=2\nw="« a b »"\nv=last'
# The tails PHPUnit 13 and Vitest (through ng test) print, cut from real runs here.
PU_OK=$'Time: 00:00.035, Memory: 30.00 MB\n\nOK (7 tests, 38 assertions)'
PU_FAIL=$'/x/api/tests/CTest.php:181\n\x1b[37;41mFAILURES!\x1b[0m\n\x1b[37;41mTests: 7, Assertions: 38, Failures: 1.\x1b[0m'
PU_ERR=$'/x/api/tests/CTest.php:176\n\x1b[37;41mERRORS!\x1b[0m\n\x1b[37;41mTests: 7, Assertions: 1, Errors: 7.\x1b[0m'
VT_OK=$'\x1b[2m Test Files \x1b[22m \x1b[1m\x1b[32m1 passed\x1b[39m\x1b[22m\x1b[90m (1)\x1b[39m\n\x1b[2m      Tests \x1b[22m \x1b[1m\x1b[32m16 passed\x1b[39m\x1b[22m\x1b[90m (16)\x1b[39m'
VT_FAIL=$'\x1b[2m Test Files \x1b[22m \x1b[1m\x1b[31m1 failed\x1b[39m\x1b[22m\x1b[90m (1)\x1b[39m\n\x1b[2m      Tests \x1b[22m \x1b[1m\x1b[31m1 failed\x1b[39m\x1b[22m\x1b[2m | \x1b[22m\x1b[1m\x1b[32m15 passed\x1b[39m\x1b[22m\x1b[90m (16)\x1b[39m'
VT_TS=$'\xe2\x9c\x98 [ERROR] TS2307: Cannot find module \'./no-such-module\' or its corresponding type declarations. [plugin angular-compiler]'

tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/scripts/mutants" "$d/lib" "$d/expected" "$d/api/src" "$d/api/tests" "$d/api/vendor/bin" "$d/api/bin" \
    "$d/web/src" "$d/bin"
  cp "$SCRIPT" "$d/scripts/mutants/mutants.sh"
  mkdir -p "$d/scripts/check"; cp "$(dirname "$SCRIPT")/../check/check.sh" "$d/scripts/check/check.sh"
  printf '%s' "$A" > "$d/lib/a.sh"; cp "$d/lib/a.sh" "$d/expected/pristine"
  # A test that reads the file: it passes while x is GOOD.
  printf '%s\n' '#!/usr/bin/env bash' '. ./lib/a.sh' '[[ "$x" == GOOD ]] && { echo "t: 1 passed, 0 failed"; exit 0; }' 'echo "t: 0 passed, 1 failed"; exit 1' \
    > "$d/scripts/t.test.sh"
  # A test that fails only on exactly the bytes expected: killing it proves the mutant was written byte for byte.
  printf '%s\n' '#!/usr/bin/env bash' 'cmp -s lib/a.sh "expected/$1" && { echo "x: 0 passed, 1 failed"; exit 1; }' 'echo "x: 1 passed, 0 failed"' \
    > "$d/scripts/exact.test.sh"
  # A test the runner is killed from, as a container is when its client is.
  printf '%s\n' '#!/usr/bin/env bash' 'grep -q BAD lib/a.sh && kill "-$1" "$MUTANTS_RUNNER_PID"; sleep 1; echo "k: 1 passed, 0 failed"' > "$d/scripts/kill.test.sh"
  printf '<?php\necho 1;\n' > "$d/api/src/B.php"; cp "$d/api/src/B.php" "$d/expected/b"
  printf '<?php\n$c = "GOOD";\n' > "$d/api/src/C.php"; echo x > "$d/api/tests/CTest.php"
  printf '#!/bin/sh\ngrep -q "BAD" src/C.php && { printf "%%s\\n" "$PU_FAIL"; exit 1; }\ngrep -q "ERR" src/C.php && { printf "%%s\\n" "$PU_ERR"; exit 2; }\ngrep -q "FATAL" src/C.php && { echo "PHP Fatal error:  Cannot redeclare class C"; exit 255; }\nprintf "%%s\\n" "$PU_OK"\n' \
    > "$d/api/vendor/bin/phpunit"
  printf '#!/bin/sh\nexit 0\n' > "$d/api/bin/console"
  printf 'export const a = "GOOD";\n' > "$d/web/src/a.ts"; echo x > "$d/web/src/a.spec.ts"
  printf '#!/bin/sh\ngrep -q "BAD" src/a.ts && { printf "%%s\\n" "$VT_FAIL"; exit 1; }\ngrep -q "GONE" src/a.ts && { printf "%%s\\n" "$VT_TS"; exit 1; }\nprintf "%%s\\n" "$VT_OK"\n' \
    > "$d/bin/npx"
  # php -l, stood in for: it refuses an unclosed parenthesis.
  printf '#!/bin/sh\ngrep -q "(1;" "$2" && { echo "PHP Parse error: syntax error"; exit 255; }\necho "No syntax errors detected in $2"\n' > "$d/bin/php"
  chmod +x "$d/api/vendor/bin/phpunit" "$d/api/bin/console" "$d/bin/npx" "$d/bin/php"
  echo "$d"
}
run() { local d=$1; shift; (cd "$d" && PATH="$d/bin:$PATH" PU_OK="$PU_OK" PU_FAIL="$PU_FAIL" PU_ERR="$PU_ERR" VT_OK="$VT_OK" VT_FAIL="$VT_FAIL" VT_TS="$VT_TS" \
  bash scripts/mutants/mutants.sh "$@" 2>&1); }
spec() { cat > "$1/spec.txt"; }
echo "mutants: the runner"

d=$(tree); spec "$d" <<'EOF'
Each block is one mutant; what comes before the first is a comment.
## kills
file: lib/a.sh
test: scripts/t.test.sh
old:
x="GOOD"
new:
x="BAD"

## two lines
file: lib/a.sh
test: scripts/t.test.sh
old:
x="GOOD"
	y=1 && z=2
new:
x="GOOD"

	y=2
EOF
out=$(run "$d" list spec.txt)
check "a spec lists its mutants, the blank line before the next block dropped" $? 0 "$out" $'kills\tlib/a.sh\tscripts/t.test.sh\tx="GOOD"\tx="BAD"\ntwo lines'
check "an old and a new run over several lines, blank ones inside kept, the trailing ones dropped" 0 0 "$out" \
  $'two lines\tlib/a.sh\tscripts/t.test.sh\tx="GOOD"\\n\\ty=1 && z=2\tx="GOOD"\\n\\n\\ty=2'

d=$(tree); spec "$d" <<'EOF'
## kills
file: lib/a.sh
test: scripts/t.test.sh
old:
x="GOOD"
new:
x="BAD"
## survives
file: lib/a.sh
test: scripts/t.test.sh
old:
y=1
new:
y=2
EOF
out=$(run "$d" run all spec.txt)
check "a mutant the test notices is killed" $? 1 "$out" "killed    kills"
check "a mutant it does not notice survives, and the run fails" 0 0 "$out" "SURVIVED  survives"
check "a survivor's log is named" 0 0 "$out" "var/mutants/logs/2-survives.log"
same "the file is back, byte for byte" "$d/lib/a.sh" "$d/expected/pristine"
[[ ! -e "$d/var/mutants/pending" ]]; check "nothing is left pending" $? 0 "" ""

d=$(tree)
printf '%s' $'x="GOOD"\n\ty=1 && z=2 && q=& «\nw="« a b »"\nv=last' > "$d/expected/amp"
printf '%s' $'x="GOOD"\n\ty=1 && z=2\n' > "$d/expected/cut"
spec "$d" <<'EOF'
## an ampersand and guillemets are written as they are
file: lib/a.sh
test: scripts/exact.test.sh amp
old:
	y=1 && z=2
new:
	y=1 && z=2 && q=& «
## an empty new deletes, up to a last line with no newline
file: lib/a.sh
test: scripts/exact.test.sh cut
old:
w="« a b »"
v=last
new:
EOF
out=$(run "$d" run all spec.txt)
check "the replacement is the bytes written (&, tab, «)" $? 0 "$out" "killed    an ampersand"
check "an empty new deletes exactly what old names" 0 0 "$out" "killed    an empty new"
same "…and the file is back" "$d/lib/a.sh" "$d/expected/pristine"

d=$(tree); spec "$d" <<'EOF'
## nowhere
file: lib/a.sh
test: scripts/t.test.sh
old:
NOPE
new:
YES
## twice
file: lib/a.sh
test: scripts/t.test.sh
old:
=
new:
-
## does not parse
file: lib/a.sh
test: scripts/t.test.sh
old:
x="GOOD"
new:
x="GOOD"; if then
## the test dies before its tally
file: lib/a.sh
test: scripts/t.test.sh
old:
x="GOOD"
new:
exit 3
## PHP that does not parse
file: api/src/B.php
test: scripts/t.test.sh
old:
echo 1;
new:
echo (1;
EOF
out=$(run "$d" run all spec.txt)
check "an old not found did not land" $? 1 "$out" "NOT LANDED  nowhere (found 0 times)"
check "an old found twice is refused as ambiguous" 0 0 "$out" "NOT LANDED  twice (found 5 times)"
check "a shell mutant that does not parse is invalid, never run" 0 0 "$out" "INVALID   does not parse (bash -n)"
check "a test that dies with no tally is invalid, not killed" 0 0 "$out" "INVALID   the test dies before its tally (no tally"
check "a PHP mutant that does not parse is invalid (php -l)" 0 0 "$out" "INVALID   PHP that does not parse (php -l)"
absent "nothing invalid is counted killed" "$out" "killed "
same "the shell file is back" "$d/lib/a.sh" "$d/expected/pristine"
same "the PHP file is back" "$d/api/src/B.php" "$d/expected/b"

d=$(tree); spec "$d" <<'EOF'
## a failing PHPUnit case
file: api/src/C.php
test: api/tests/CTest.php
old:
GOOD
new:
BAD
## a PHPUnit error only
file: api/src/C.php
test: api/tests/CTest.php
old:
GOOD
new:
ERR
## PHPUnit dies before its tally
file: api/src/C.php
test: api/tests/CTest.php
old:
GOOD
new:
FATAL
## a Vitest case fails
file: web/src/a.ts
test: web/src/a.spec.ts
old:
GOOD
new:
BAD
## a spec that no longer compiles
file: web/src/a.ts
test: web/src/a.spec.ts
old:
GOOD
new:
GONE
EOF
out=$(run "$d" run all spec.txt)
check "a PHPUnit failure kills" $? 1 "$out" "killed    a failing PHPUnit case (failure)"
check "a PHPUnit error kills, said apart" 0 0 "$out" "killed    a PHPUnit error only (error)"
check "a failed Vitest case kills" 0 0 "$out" "killed    a Vitest case fails"
check "a PHPUnit run with no tally is invalid, not an error kill" 0 0 "$out" "INVALID   PHPUnit dies before its tally (no tally, exit 255)"
check "a spec that does not compile is invalid" 0 0 "$out" "INVALID   a spec that no longer compiles (no tally"

out=$(run "$d" run api spec.txt)
check "the PHP toolchain runs only what it can" $? 1 "$out" "killed    a PHPUnit error only"
absent "…not the web mutants" "$out" "Vitest"
out=$(run "$d" run web spec.txt)
absent "the web toolchain does not run PHPUnit" "$out" "PHPUnit"

# host: which toolchain starts. The Makefile hands the commands in; here each one only says it ran.
host() { local d=$1; shift; (cd "$d" && MUTANTS_TOOLS='echo TOOLS' MUTANTS_WEB_TOOLS='echo WEB' MUTANTS_NPM_INSTALL=true MUTANTS_POSTGRES='echo POSTGRES' \
  bash scripts/mutants/mutants.sh host "$@" 2>&1); }
out=$(host "$d" spec.txt)
check "PHP tests start the database, then the PHP toolchain" $? 0 "$out" $'POSTGRES\nTOOLS bash scripts/mutants/mutants.sh run api spec.txt'
check "web tests start the web toolchain" 0 0 "$out" "WEB sh -c true && bash ../scripts/mutants/mutants.sh run web spec.txt"
spec "$d" <<'EOF'
## kills
file: lib/a.sh
test: scripts/t.test.sh
old:
x="GOOD"
new:
x="BAD"
EOF
out=$(host "$d" spec.txt)
check "a gate test runs in the PHP toolchain" $? 0 "$out" "TOOLS bash scripts/mutants/mutants.sh run api spec.txt"
absent "…without the database" "$out" "POSTGRES"
absent "…or the web toolchain" "$out" "WEB"

# Refusals, before anything is touched.
d=$(tree); spec "$d" <<'EOF'
## red before any mutant
file: lib/a.sh
test: scripts/exact.test.sh pristine
old:
x="GOOD"
new:
x="BAD"
EOF
out=$(run "$d" run all spec.txt)
check "a test red before any mutant refuses the run" $? 2 "$out" "red before any mutant: scripts/exact.test.sh pristine"
same "…and nothing was mutated" "$d/lib/a.sh" "$d/expected/pristine"
for bad in ../outside.sh /etc/hostname lib/../../x; do
  printf '## out\nfile: %s\ntest: scripts/t.test.sh\nold:\na\nnew:\nb\n' "$bad" > "$d/spec.txt"
  out=$(run "$d" run all spec.txt); check "a file outside the tree is refused: $bad" $? 2 "$out" "outside"
done
out=$(cd "$d" && bash scripts/mutants/mutants.sh run all /etc/hostname 2>&1); check "a spec outside the tree is refused" $? 2 "$out" "outside"
printf '## t\nfile: lib/a.sh\ntest: scripts/none.test.sh\nold:\na\nnew:\nb\n' > "$d/spec.txt"
out=$(run "$d" run all spec.txt); check "a test that is not there is refused" $? 2 "$out" "scripts/none.test.sh"

# Killed from outside: TERM is caught and the file put back; KILL leaves the backup, and the next run says how to restore.
d=$(tree); spec "$d" <<'EOF'
## terminated
file: lib/a.sh
test: scripts/kill.test.sh TERM
old:
x="GOOD"
new:
x="BAD"
EOF
out=$(run "$d" run all spec.txt)
check "a terminated run says so" $? 143 "$out" "terminated: lib/a.sh put back"
same "…and puts the file back" "$d/lib/a.sh" "$d/expected/pristine"
[[ ! -e "$d/var/mutants/pending" ]]; check "…and leaves nothing pending" $? 0 "" ""

d=$(tree); spec "$d" <<'EOF'
## killed
file: lib/a.sh
test: scripts/kill.test.sh KILL
old:
x="GOOD"
new:
x="BAD"
EOF
out=$(run "$d" run all spec.txt)
check "a killed run leaves its mutant pending" $? 137 "x" "x"
spec "$d" <<'EOF'
## kills
file: lib/a.sh
test: scripts/t.test.sh
old:
x="GOOD"
new:
x="BAD"
EOF
out=$(run "$d" run all spec.txt)
check "the next run refuses while one is pending" $? 2 "$out" "docker ps"
restore=$(grep -o 'cp -- [^ ]* lib/a.sh && rm -f -- var/mutants/pending' <<<"$out")
check "…and prints the restore line" $? 0 "$restore" "cp -- "
(cd "$d" && eval "$restore"); same "…which puts the file back" "$d/lib/a.sh" "$d/expected/pristine"
out=$(run "$d" run all spec.txt); check "…after which a run goes ahead" $? 0 "$out" "killed    kills"

echo "mutants: $pass passed, $fail failed"
((fail == 0))
