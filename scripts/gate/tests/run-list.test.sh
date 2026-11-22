#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SCRIPT=$HERE/../run-list.sh
pass=0; fail=0
ok() { pass=$((pass+1)); echo "  ok   $1"; }
ko() { fail=$((fail+1)); echo "  FAIL $1"; [[ -n "${2:-}" ]] && echo "$2" | sed 's/^/       /'; }
same() { if [[ "$2" == "$3" ]]; then ok "$1"; else ko "$1" "wanted [$3], got [$2]"; fi; }
has() { if [[ "$2" == *"$3"* ]]; then ok "$1"; else ko "$1" "wanted '$3' in: $2"; fi; }
hasnt() { if [[ "$2" != *"$3"* ]]; then ok "$1"; else ko "$1" "did not want '$3' in: $2"; fi; }

# A folder of small scripts a list names, each saying it ran; `marks` records each line's start and end for the job cap.
fixture() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/s"
  for n in a b c d e f; do printf '#!/usr/bin/env bash\necho "start %s" >> "%s/marks"; sleep "${1:-0}"; echo "ran %s"; echo "end %s" >> "%s/marks"\n' "$n" "$d" "$n" "$n" "$d" > "$d/s/$n.sh"; done
  printf '#!/usr/bin/env bash\necho "red said this"; exit 3\n' > "$d/s/red.sh"
  printf '#!/usr/bin/env bash\nmake --version >/dev/null; echo "made"\n' > "$d/s/makes.sh"
  printf '#!/usr/bin/env bash\nif read -r line; then echo "read [$line]"; else echo "read nothing"; fi\n' > "$d/s/reads.sh"
  echo "$d"
}
run() { (cd "$1" && shift && bash "$SCRIPT" "$@" 2>&1); }

echo "run-list: a gate list run some lines at once, every output whole and in order, every red said"

d=$(fixture)
printf '%s\n' '# kind mode command' '' \
  "test first bash s/a.sh" \
  "gate parallel bash s/b.sh 0.4" \
  "gate parallel bash s/c.sh 0.1" \
  "test long bash s/d.sh 0.3" \
  "gate serial bash s/e.sh" > "$d/list"
out=$(run "$d" --jobs 3 list); code=$?
same "a green list exits 0" "$code" 0
same "each line's output comes whole, in list order" "$(grep -E '^ran ' <<<"$out" | tr '\n' ' ')" "ran a ran b ran c ran d ran e "
same "each output follows its command" "$(grep -E '^\$ |^ran ' <<<"$out" | head -n 4 | tr '\n' '|')" "\$ bash s/a.sh|ran a|\$ bash s/b.sh 0.4|ran b|"
has "the tally counts every line run against every line listed" "$out" "run-list: 5 of 5 lines run, none red"
first_end=$(grep -n '^end a' "$d/marks" | cut -d: -f1); others=$(grep -nE '^start [bcde]' "$d/marks" | head -n 1 | cut -d: -f1)
same "the first line ends before any other starts" "$(( first_end < others ))" 1
serial_start=$(grep -n '^start e' "$d/marks" | cut -d: -f1); last_end=$(grep -nE '^end [bcd]' "$d/marks" | tail -n 1 | cut -d: -f1)
same "a serial line starts once the parallel ones have ended" "$(( serial_start > last_end ))" 1
# One at a time, so the order the runner starts them in is the order they run in, whatever the machine's load.
d=$(fixture)
printf '%s\n' "gate parallel bash s/b.sh" "gate parallel bash s/c.sh" "test long bash s/d.sh" > "$d/list"
run "$d" --jobs 1 list >/dev/null
same "a long line starts before the parallel ones listed above it" "$(grep '^start' "$d/marks" | tr '\n' ' ')" "start d start b start c "

d=$(fixture)
printf '%s\n' "gate parallel bash s/a.sh 0.3" "gate parallel bash s/b.sh 0.3" "gate parallel bash s/c.sh 0.3" \
  "gate parallel bash s/d.sh 0.3" "gate parallel bash s/e.sh 0.3" "gate parallel bash s/f.sh 0.3" > "$d/list"
run "$d" --jobs 2 list >/dev/null
peak=$(awk '/^start/{n++; if(n>m)m=n} /^end/{n--} END{print m}' "$d/marks")
same "no more lines run at once than --jobs" "$peak" 2
d=$(fixture)
printf '%s\n' "gate parallel bash s/a.sh 0.3" "gate parallel bash s/b.sh 0.3" "gate parallel bash s/c.sh 0.3" "gate parallel bash s/d.sh 0.3" > "$d/list"
run "$d" --jobs 4 list >/dev/null
same "--jobs lines do run at once" "$(awk '/^start/{n++; if(n>m)m=n} /^end/{n--} END{print m}' "$d/marks")" 4

d=$(fixture)
printf '%s\n' "gate parallel bash s/a.sh" "gate parallel bash s/red.sh" "test parallel bash s/b.sh" "gate serial bash s/red.sh" "gate serial bash s/c.sh" > "$d/list"
out=$(run "$d" --jobs 2 list); code=$?
same "a red line makes the list red" "$code" 1
same "every line runs after a red, the serial ones too" "$(grep -cE '^ran ' <<<"$out")" 3
has "a red line's own output is shown" "$out" "red said this"
has "the tally names every red line with its exit" "$out" "run-list: 5 of 5 lines run, 2 red"
same "each red is named once in the closing list" "$(grep -cE '^  red \(exit 3\): bash s/red.sh' <<<"$out")" 2

d=$(fixture)
printf '%s\n' "test first bash s/red.sh" "gate parallel bash s/a.sh" > "$d/list"
out=$(run "$d" list); code=$?
same "a red first line stops the list" "$code" 1
hasnt "nothing runs after a red first line" "$out" "ran a"
has "it says why it stopped" "$out" "the first line is red"

d=$(fixture)
printf '%s\n' "gate parallel bash s/reads.sh" "gate serial bash s/reads.sh" > "$d/list"
out=$(printf 'swallowed\nswallowed\n' | run "$d" list)
same "no line reads the runner's own input" "$(grep -c 'read nothing' <<<"$out")" 2

d=$(fixture)
mkdir -p "$d/bin"; printf '#!/usr/bin/env bash\necho "real make $*"\n' > "$d/bin/make"; chmod +x "$d/bin/make"
printf '%s\n' "gate parallel bash s/makes.sh" "gate serial bash s/makes.sh" > "$d/list"
out=$(cd "$d" && PATH="$d/bin:$PATH" bash "$SCRIPT" list 2>&1); code=$?
same "a parallel line starting make makes the list red" "$code" 1
has "it names the line and what it started" "$out" "ran make while others run: bash s/makes.sh (mark it serial)"
same "the stand-in still runs the real tool" "$(grep -c '^made' <<<"$out")" 2
same "a serial line may start it" "$(grep -c 'ran make while others run' <<<"$out")" 1
# `docker compose … config` reads files and nothing else: no container, network or volume another line could meet. It
# runs beside the others; any other docker call does not.
mkdir -p "$d/bin"; printf '#!/usr/bin/env bash\necho "real docker $*"\n' > "$d/bin/docker"; chmod +x "$d/bin/docker"
printf '%s\n' 'gate parallel docker compose -f a.yaml --profile "*" config --format json' 'gate parallel docker compose -f a.yaml up -d' \
  'gate parallel docker run --rm x' > "$d/list"
out=$(cd "$d" && PATH="$d/bin:$PATH" bash "$SCRIPT" list 2>&1)
hasnt "a parallel line reading compose's config stays green" "$out" "while others run: docker compose -f a.yaml --profile"
has "a parallel line starting compose's containers is named" "$out" "ran docker while others run: docker compose -f a.yaml up -d"
has "a parallel docker run is named" "$out" "ran docker while others run: docker run --rm x"
same "the runner's verdict counts them" "$(grep -c 'while others run' <<<"$out")" 2
d=$(fixture); mkdir -p "$d/bin"; printf '#!/usr/bin/env bash\necho "real make $*"\n' > "$d/bin/make"; chmod +x "$d/bin/make"
# A gate calling `env -i PATH="$PATH" docker compose … 2>&1` and parsing what comes back: the stand-in must still see the
# call, and must add nothing to the tool's output, or the gate reads a broken document.
printf '%s\n' 'gate parallel out=$(env -i PATH="$PATH" make 2>&1); printf "[%s]\n" "$out"' > "$d/list"
out=$(cd "$d" && PATH="$d/bin:$PATH" bash "$SCRIPT" list 2>&1)
has "a stand-in called with an emptied environment adds nothing to the tool's output" "$out" "[real make ]"
has "and the call is still seen" "$out" "ran make while others run"

d=$(fixture)
printf '%s\n' "gate parallel bash s/a.sh" "test parallel bash s/b.sh" "gate serial bash s/c.sh" "test first bash s/d.sh" > "$d/list"
out=$(run "$d" --kind gate list); code=$?
same "--kind gate runs the gate lines alone" "$(grep -E '^ran ' <<<"$out" | tr '\n' ' ')" "ran a ran c "
has "and counts only those" "$out" "run-list: 2 of 2 lines run, none red"

d=$(fixture)
printf '%s\n' "gate parallel bash s/a.sh" > "$d/list"
out=$(run "$d" --floor 2 list); code=$?
same "a list under its floor is refused" "$code" 2
has "saying how many it holds" "$out" "1 line(s), under the floor of 2"

for bad in "gate sometimes bash s/a.sh" "check parallel bash s/a.sh" "gate parallel"; do
  d=$(fixture); printf '%s\n' "$bad" > "$d/list"
  out=$(run "$d" list); code=$?
  same "a malformed line is refused before anything runs: $bad" "$code" 2
  hasnt "and nothing ran: $bad" "$out" "ran a"
done
d=$(fixture); out=$(run "$d" missing.list); same "a missing list is refused" "$?" 2

d=$(fixture)
printf '#!/usr/bin/env bash\necho $$ > "%s/pid"; sleep 30\n' "$d" > "$d/s/slow.sh"
printf '%s\n' "gate parallel bash s/slow.sh" > "$d/list"
(cd "$d" && exec bash "$SCRIPT" list >/dev/null 2>&1) & runner=$!
for _ in $(seq 50); do [[ -s "$d/pid" ]] && break; sleep 0.1; done
kill -TERM "$runner"; wait "$runner" 2>/dev/null
sleep 0.3
if [[ ! -s "$d/pid" ]]; then ko "a stopped runner stops what it started" "the slow line never started"
elif kill -0 "$(cat "$d/pid")" 2>/dev/null; then ko "a stopped runner stops what it started"; kill "$(cat "$d/pid")"; else ok "a stopped runner stops what it started"; fi

echo "run-list: $pass passed, $fail failed"
[[ $fail -eq 0 ]]
