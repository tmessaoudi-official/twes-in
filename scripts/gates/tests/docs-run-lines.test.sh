#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/docs-run-lines.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: a runbook whose restore steps each read no input, written as the docs write them.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/docs"
  cat > "$d/docs/RUNBOOK.md" <<'MD'
# Runbook

1. `… run --rm --no-deps restore gosu postgres pgbackrest --set=<label> --delta
   restore < /dev/null`: the database written back.
2. `… run --rm --no-deps restore restic restore latest --target / < /dev/null`: the files.

```sh
B="docker compose -f compose.yaml"
$B run --rm --no-deps restore pgbackrest --stanza=twes \
  --type=time restore < /dev/null
docker compose run --rm tools true </dev/null
$B up -d --wait
```
MD
  printf '# To do\n\n- `printf %%s key | docker compose … run --rm -T api bin/console app:keys:rotate`\n' > "$d/TODO.md"
  echo "$d"
}
run() { bash "$GATE" --root "$1" --floor 4 2>&1; }
echo "docs-run-lines gate"

d=$(tree); out=$(run "$d"); check "run lines reading no input, wrapped or continued, and one fed by a pipe pass" $? 0 "$out" "OK"

d=$(tree); printf '\n3. `… run --rm --no-deps restore restic snapshots`: the snapshots.\n' >> "$d/docs/RUNBOOK.md"
out=$(run "$d"); check "an inline run line without < /dev/null is refused with its file and line" $? 1 "$out" "RUNBOOK.md:15"

d=$(tree); printf '\n```sh\ndocker compose -f compose.yaml run --rm restore \\\n  restic check\n```\n' >> "$d/docs/RUNBOOK.md"
out=$(run "$d"); check "a fenced run line continued with a backslash and no redirect is refused" $? 1 "$out" "RUNBOOK.md:16"

d=$(tree); printf '\n- `docker compose --profile tools run --rm tools composer install`\n' >> "$d/TODO.md"
out=$(run "$d"); check "TODO.md is read too" $? 1 "$out" "TODO.md:5"

d=$(tree); printf '\n`docker compose run …` is the shape. <!-- run-input: a shape named in prose, nobody pastes it -->\n' >> "$d/docs/RUNBOOK.md"
out=$(run "$d"); check "a marker giving a reason passes" $? 0 "$out" "OK"

d=$(tree); printf '\n`docker compose run …` is the shape. <!-- run-input: -->\n' >> "$d/docs/RUNBOOK.md"
out=$(run "$d"); check "a marker left empty is no reason" $? 1 "$out" "RUNBOOK.md:15"

d=$(tree); printf '\nA `docker compose` run from another directory once recreated the database.\n' >> "$d/docs/RUNBOOK.md"
out=$(run "$d"); check "prose naming a run is no command" $? 0 "$out" "OK"

d=$(tree); printf '\n- `… exec backup twes-backup diff`, `npm run gate`\n' >> "$d/docs/RUNBOOK.md"
out=$(run "$d"); check "exec and npm run are no compose run" $? 0 "$out" "OK"

d=$(tree); mkdir -p "$d/docs/plans"; printf -- '- `docker compose run --rm tools true`\n' > "$d/docs/plans/x.plan.md"
out=$(run "$d"); check "a plan below docs/ is not a page a person follows" $? 0 "$out" "OK"

d=$(tree); out=$(bash "$GATE" --root "$d" --floor 9 2>&1); check "fewer run lines than the floor reds" $? 1 "$out" "floor"

d=$(tree); rm -r "$d/docs"; out=$(run "$d"); check "a missing docs/ is refused, not skipped" $? 1 "$out" "found no docs"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
