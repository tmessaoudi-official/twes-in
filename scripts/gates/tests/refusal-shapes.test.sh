#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/refusal-shapes.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: three JSON answers, none a refusal of its own (the floor the cases run with is 3).
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/src/Things/Infrastructure/Http"
  cat > "$d/api/src/Things/Infrastructure/Http/ThingsController.php" <<'PHP'
<?php
final class ThingsController
{
    public function show($thing)
    {
        if (null === $thing) {
            return new JsonResponse(['error' => 'step_up_required'], 403);
        }
        try {
            return new JsonResponse($thing->toArray());
        } catch (ThingRefused $refused) {
            throw DomainRule::refused('file', $refused->getMessage(), $refused->reason, $refused->params, $refused);
        }
        return new JsonResponse(['id' => $thing->id, 'code' => $thing->code], 201);
    }
}
PHP
  echo "$d"
}
run() { REFUSAL_SHAPES_FLOOR=3 bash "$GATE" --root "$1" 2>&1; }
echo "refusal-shapes gate"

d=$(tree); out=$(run "$d"); check "a tree whose refusals are violations passes, a record holding a code field among them" $? 0 "$out" "OK"

d=$(tree); printf "<?php\nreturn new JsonResponse(['code' => \$refused->reason, 'params' => (object) \$refused->params], 422);\n" > "$d/api/src/Things/Infrastructure/Http/Photo.php"
out=$(run "$d"); check "a body of its own carrying the refusal's code is refused with its file and line" $? 1 "$out" "Photo.php:2"

d=$(tree); printf "<?php\nreturn new JsonResponse(\n    [\"code\" => \$refused->reason],\n    422,\n);\n" > "$d/api/src/Things/Infrastructure/Http/Split.php"
out=$(run "$d"); check "the body written over several lines, in double quotes, is refused at its answer" $? 1 "$out" "Split.php:2"

d=$(tree); mkdir -p "$d/api/src/Things/Infrastructure/Listener"; printf "<?php\nreturn new JsonResponse(['code' => 'locked'], 423);\n" > "$d/api/src/Things/Infrastructure/Listener/Lock.php"
out=$(run "$d"); check "outside a controller's folder too" $? 1 "$out" "Lock.php:2"

d=$(tree); printf "<?php\n// refusal-shape: the declaration answers the list of what is missing beside its reason\nreturn new JsonResponse(['code' => \$refused->reason, 'gaps' => \$gaps], 422);\n" > "$d/api/src/Things/Infrastructure/Http/Said.php"
out=$(run "$d"); check "a body that says why its shape is its own, on the line above, passes" $? 0 "$out" "OK"

d=$(tree); printf "<?php\n// It once answered new JsonResponse(['code' => …]), a body of its own.\n" > "$d/api/src/Things/Infrastructure/Http/Comment.php"
out=$(run "$d"); check "a comment that names such a body is not one" $? 0 "$out" "OK"

d=$(tree); out=$(REFUSAL_SHAPES_FLOOR=4 bash "$GATE" --root "$d" 2>&1); check "under the floor of answers read, the gate is blind and reds" $? 1 "$out" "floor"

d=$(mktemp -d); out=$(run "$d"); check "a tree with no api/src reds" $? 1 "$out" "no api/src"

echo "refusal-shapes: $pass passed, $fail failed"
((fail == 0))
