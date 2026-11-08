#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/error-codes.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: four {error} answers, a literal, a constant of the class, one of another class and a
# reason the comment names by its class; its list declares each code once. The floor the cases run with is 4.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/src/Things/Infrastructure/Http" "$d/api/src/Things/Application" "$d/scripts/gates"
  cat > "$d/api/src/Things/Infrastructure/Http/ThingsController.php" <<'PHP'
<?php
final class ThingsController
{
    private const string GONE = 'thing_gone';

    public function show($thing)
    {
        if (null === $thing) {
            return new JsonResponse(['error' => 'step_up_required'], 403);
        }
        if ($thing->gone) {
            return new JsonResponse(['error' => self::GONE], 410);
        }
        if ($thing->closed) {
            return new JsonResponse(['error' => ThingRefused::CLOSED], 403);
        }
        try {
            return new JsonResponse($thing->toArray());
        } catch (ThingRefused $refused) {
            // error-codes: ThingRefused
            return new JsonResponse(['error' => $refused->reason], 422);
        }
    }
}
PHP
  cat > "$d/api/src/Things/Application/ThingRefused.php" <<'PHP'
<?php
final class ThingRefused extends \DomainException
{
    public const string CLOSED = 'thing_closed';
    public const string NAME = 'name_required';
    public const int LIMIT = 3;
}
PHP
  cat > "$d/scripts/gates/error-codes.allowed" <<'TXT'
# SPDX-License-Identifier: AGPL-3.0-or-later
step_up_required act the proof of who is at the screen is not recent
thing_gone act the thing is no longer there
thing_closed act the thing is closed
name_required moving the name is a field: a violation on name
TXT
  echo "$d"
}
run() { ERROR_CODES_FLOOR=4 ERROR_CODES_MOVING_CEILING=1 bash "$GATE" --root "$1" 2>&1; }
add() { printf '%s\n' "$2" > "$1/api/src/Things/Infrastructure/Http/$3"; }
echo "error-codes gate"

d=$(tree); out=$(run "$d"); check "a tree whose every code is declared passes, the constants resolved and the reasons named by their class" $? 0 "$out" "OK — 4 "

d=$(tree); add "$d" "<?php
return new JsonResponse(['error' => 'brand_new'], 409);" New.php
out=$(run "$d"); check "an undeclared literal code is refused with its file and line" $? 1 "$out" "New.php:2: brand_new"

d=$(tree); add "$d" "<?php
return new JsonResponse([\"error\" => \"brand_new\"], 409);" Quoted.php
out=$(run "$d"); check "the key and the code in double quotes too" $? 1 "$out" "Quoted.php:2: brand_new"

d=$(tree); add "$d" "<?php
return new JsonResponse([
    'error' => 'brand_new',
    'companies' => \$list,
], 409);" Split.php
out=$(run "$d"); check "a body written over several lines is read, and named at its answer" $? 1 "$out" "Split.php:2: brand_new"

d=$(tree); mkdir -p "$d/api/src/Things/Infrastructure/Listener"; printf "<?php\n\$event->setResponse(new JsonResponse(['error' => 'brand_new'], 403));\n" > "$d/api/src/Things/Infrastructure/Listener/Lock.php"
out=$(run "$d"); check "outside a controller's folder too" $? 1 "$out" "Lock.php:2: brand_new"

d=$(tree); add "$d" "<?php
final class Other
{
    private const string MINE = 'brand_new';
    public function a() { return new JsonResponse(['error' => self::MINE], 409); }
}" Other.php
out=$(run "$d"); check "a constant of the class is read, and its code held to the list" $? 1 "$out" "Other.php:5: brand_new"

d=$(tree); add "$d" "<?php
return new JsonResponse(['error' => Nowhere::THING], 409);" Unknown.php
out=$(run "$d"); check "a constant of a class the tree does not hold, with no comment, is refused" $? 1 "$out" "Unknown.php:2: Nowhere::THING"

d=$(tree); add "$d" "<?php
return new JsonResponse(['error' => \$refused->reason], 409);" Bare.php
out=$(run "$d"); check "a code made at run time, with no comment naming its codes, is refused" $? 1 "$out" "Bare.php:2: \$refused->reason"

d=$(tree); add "$d" "<?php
return new JsonResponse(['error' => \$refused->reason], 409); // error-codes: thing_gone, step_up_required" Said.php
out=$(run "$d"); check "a code made at run time whose comment names declared codes passes" $? 0 "$out" "OK — 5 "

d=$(tree); add "$d" "<?php
// error-codes: thing_gone brand_new
return new JsonResponse(['error' => \$refused->reason], 409);" SaidWrong.php
out=$(run "$d"); check "a comment naming an undeclared code is refused" $? 1 "$out" "SaidWrong.php:3: brand_new"

d=$(tree); add "$d" "<?php
// error-codes: ThingRefused::NAME
return new JsonResponse(['error' => \$refused->reason], 409);" SaidConst.php
out=$(run "$d"); check "a comment may name one constant of a class" $? 0 "$out" "OK — 5 "

d=$(tree); add "$d" "<?php
// error-codes: Missing
return new JsonResponse(['error' => \$refused->reason], 409);" SaidMissing.php
out=$(run "$d"); check "a comment naming a class the tree does not hold is refused" $? 1 "$out" "SaidMissing.php:3: Missing"

# An exception built from an enum carries the enum's codes besides its own constants: TextOverrideRefused::because
# (TextRefusal) answered five codes no comment named. The class's comment names the enum too, which resolves to its cases.
problem() {
  cat > "$1/api/src/Things/Application/ThingProblem.php" <<'PHP'
<?php
enum ThingProblem: string
{
    case Late = 'thing_late';
}
PHP
  sed -i 's/^    public const int LIMIT = 3;$/    public const int LIMIT = 3;\n\n    public static function because(ThingProblem $problem): self { return new self(); }/' "$1/api/src/Things/Application/ThingRefused.php"
}
d=$(tree); problem "$d"; printf 'thing_late act the thing came late\n' >> "$d/scripts/gates/error-codes.allowed"
out=$(run "$d"); check "a class built from an enum whose comment names the class alone is refused, the enum named" $? 1 "$out" "ThingRefused is built from ThingProblem"

d=$(tree); problem "$d"; printf 'thing_late act the thing came late\n' >> "$d/scripts/gates/error-codes.allowed"
sed -i 's|// error-codes: ThingRefused$|// error-codes: ThingRefused ThingProblem|' "$d/api/src/Things/Infrastructure/Http/ThingsController.php"
out=$(run "$d"); check "naming the enum too passes, its cases read as codes" $? 0 "$out" "OK — 4 "

d=$(tree); problem "$d"
sed -i 's|// error-codes: ThingRefused$|// error-codes: ThingRefused ThingProblem|' "$d/api/src/Things/Infrastructure/Http/ThingsController.php"
out=$(run "$d"); check "an enum's case is held to the list like any code" $? 1 "$out" "thing_late is no code"

d=$(tree); add "$d" "<?php
// It once answered new JsonResponse(['error' => 'brand_new']), a body of its own.
/* new JsonResponse(['error' => 'brand_new']) */" Comment.php
out=$(run "$d"); check "a comment that names such a body is not one" $? 0 "$out" "OK — 4 "

d=$(tree); printf 'brand_unused act nothing answers it\n' >> "$d/scripts/gates/error-codes.allowed"
out=$(run "$d"); check "a declared code nothing answers is named, so the list shrinks with the code" $? 1 "$out" "brand_unused is declared"

d=$(tree); printf 'thing_gone act twice\n' >> "$d/scripts/gates/error-codes.allowed"
out=$(run "$d"); check "a code declared twice is named" $? 1 "$out" "thing_gone is declared twice"

d=$(tree); printf 'brand_new later\n' >> "$d/scripts/gates/error-codes.allowed"
out=$(run "$d"); check "a line whose kind is neither act nor moving, or with no reason, is named" $? 1 "$out" "error-codes.allowed:6"

d=$(tree); sed -i 's/^thing_gone act/thing_gone moving/' "$d/scripts/gates/error-codes.allowed"
out=$(run "$d"); check "more codes moving than the ceiling reds: the ceiling only comes down" $? 1 "$out" "ceiling of 1"

d=$(tree); out=$(ERROR_CODES_FLOOR=5 ERROR_CODES_MOVING_CEILING=1 bash "$GATE" --root "$d" 2>&1); check "under the floor of answers read, the gate is blind and reds" $? 1 "$out" "floor"

d=$(tree); rm "$d/scripts/gates/error-codes.allowed"; out=$(run "$d"); check "a tree with no list reds" $? 1 "$out" "error-codes.allowed"

d=$(mktemp -d); out=$(run "$d"); check "a tree with no api/src reds" $? 1 "$out" "no api/src"

echo "error-codes: $pass passed, $fail failed"
((fail == 0))
