#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Runs a gate list, `scripts/gate/licences.list`: one line per check, `<kind> <mode> <command…>`.
#   kind  gate: a check of the tree;  test: a gate's own test, on fixtures of its own
#   mode  first:    alone, before anything; a red stops the list (the test of the runner every test goes through)
#         long:     started before the others, so the longest do not end the run alone
#         parallel: up to --jobs at once
#         serial:   alone, once the others are done: it starts docker, make or compose, or writes outside its own folder
# One after another these took about nine minutes of every gate while the machine sat idle; at once they take a third.
# Every line runs, a red one included, and the list ends red naming each; each line's output is printed whole, in list
# order, after its command. A parallel line that starts docker, docker-compose or make is caught by a stand-in that runs
# the real tool and makes the list red, as two such lines at once can trip over each other: mark it serial. Reading
# compose's config (`docker compose … config`) is the one call allowed beside the others. No line reads
# the runner's own input.
# Usage: run-list.sh [--jobs N] [--kind gate|test] [--floor N] <list>. Exit 0 green, 1 red, 2 a list that cannot be run.
set -uo pipefail

jobs=4 kind='' floor=0
while [[ $# -gt 1 ]]; do
  case $1 in
    --jobs) jobs=$2; shift 2 ;;
    --kind) kind=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) break ;;
  esac
done
[[ $# -eq 1 ]] || { echo "usage: $(basename "$0") [--jobs N] [--kind gate|test] [--floor N] <list>" >&2; exit 2; }
list=$1
[[ -f "$list" ]] || { echo "run-list: found no list at $list" >&2; exit 2; }
[[ "$jobs" =~ ^[1-9][0-9]*$ && "$floor" =~ ^[0-9]+$ ]] || { echo "run-list: --jobs and --floor take whole numbers" >&2; exit 2; }
[[ -z "$kind" || "$kind" == gate || "$kind" == test ]] || { echo "run-list: --kind is gate or test" >&2; exit 2; }

modes=() commands=() number=0
while IFS= read -r line || [[ -n "$line" ]]; do
  number=$((number+1))
  [[ "$line" =~ ^[[:space:]]*(#|$) ]] && continue
  read -r k m c <<<"$line"
  if [[ ! "$k" =~ ^(gate|test)$ || ! "$m" =~ ^(first|long|parallel|serial)$ || -z "${c:-}" ]]; then
    echo "run-list: $list:$number is not <gate|test> <first|long|parallel|serial> <command>: $line" >&2
    exit 2
  fi
  [[ -n "$kind" && "$k" != "$kind" ]] && continue
  modes+=("$m"); commands+=("$c")
done < "$list"
total=${#commands[@]}
((total >= floor)) || { echo "run-list: $list holds $total line(s), under the floor of $floor: lines were lost" >&2; exit 2; }

work=$(mktemp -d) || { echo "run-list: no temporary folder could be made" >&2; exit 2; }
declare -A index_of=()
cleanup() { rm -rf "$work"; }
stop() {
  local pid
  for pid in "${!index_of[@]}"; do kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid" 2>/dev/null; done
  wait 2>/dev/null
  cleanup
  exit 143
}
trap cleanup EXIT
trap stop INT TERM

# The stand-ins: each notes the tool it was asked for in its line's own file, then runs the real one, found before any is
# put in front of it. The file is written into the stand-in, not passed in the environment: gates call
# `env -i PATH="$PATH" docker compose … 2>&1` and parse the result, so a stand-in must see the call with nothing to read
# and add nothing to the output.
declare -A real=()
for tool in docker docker-compose make; do real[$tool]=$(command -v "$tool") || unset "real[$tool]"; done
stand_ins() {
  local i=$1 tool
  mkdir "$work/bin.$i"
  for tool in "${!real[@]}"; do
    printf '#!/usr/bin/env bash\nprintf "%%s %%s\\n" %q "$*" >> %q\nexec %q "$@"\n' "$tool" "$work/calls.$i" "${real[$tool]}" > "$work/bin.$i/$tool"
    chmod +x "$work/bin.$i/$tool"
  done
}

# Each line in a session of its own, so stopping the runner stops everything a line started.
detach=()
command -v setsid >/dev/null && detach=(setsid)
start() {
  local i=$1 watched=$2
  [[ "$watched" == yes ]] && stand_ins "$i"
  (
    [[ "$watched" == yes ]] && PATH="$work/bin.$i:$PATH"
    exec "${detach[@]}" bash -c "${commands[$i]}"
  ) < /dev/null > "$work/out.$i" 2>&1 &
  index_of[$!]=$i
}

declare -A status=()
printed=0
flush() {
  while ((printed < total)) && [[ -n "${status[$printed]:-}" ]]; do
    printf '$ %s\n' "${commands[$printed]}"
    cat "$work/out.$printed"
    printed=$((printed+1))
  done
}
finish() {
  local pid
  wait -n -p pid "${!index_of[@]}"
  local code=$?
  status[${index_of[$pid]}]=$code
  unset "index_of[$pid]"
}

for ((i = 0; i < total; i++)); do
  [[ "${modes[$i]}" == first ]] || continue
  start "$i" no; finish; flush
  if [[ "${status[$i]}" -ne 0 ]]; then
    echo "run-list: the first line is red, so nothing after it ran: ${commands[$i]}"
    exit 1
  fi
done

queue=()
for ((i = 0; i < total; i++)); do [[ "${modes[$i]}" == long ]] && queue+=("$i"); done
for ((i = 0; i < total; i++)); do [[ "${modes[$i]}" == parallel ]] && queue+=("$i"); done
next=0
while ((next < ${#queue[@]})) || ((${#index_of[@]} > 0)); do
  while ((next < ${#queue[@]} && ${#index_of[@]} < jobs)); do start "${queue[$next]}" yes; next=$((next+1)); done
  finish; flush
done

for ((i = 0; i < total; i++)); do
  [[ "${modes[$i]}" == serial ]] || continue
  start "$i" no; finish; flush
done
flush

# `docker compose … config` reads the files and nothing else, no container, network or volume another line could meet, so
# it may run beside the others; every other call is one that can trip over another's.
read_only='^docker compose( (-f|--file|-p|--project-name|--profile|--env-file|--project-directory) [^ ]+| --?[a-z-]+)* config( |$)'
tripping() {
  local call
  while IFS= read -r call; do [[ "$call" =~ $read_only ]] || echo "${call%% *}"; done < "$1" | sort -u | paste -sd, -
}
red=() beside=()
for ((i = 0; i < total; i++)); do
  [[ "${status[$i]:-x}" == 0 ]] || red+=("  red (exit ${status[$i]:-none}): ${commands[$i]}")
  if [[ "${modes[$i]}" =~ ^(long|parallel)$ && -s "$work/calls.$i" ]]; then
    tools=$(tripping "$work/calls.$i")
    [[ -z "$tools" ]] || beside+=("  ran $tools while others run: ${commands[$i]} (mark it serial)")
  fi
done
ran=${#status[@]}
if ((${#red[@]} == 0)); then echo "run-list: $ran of $total lines run, none red"; else echo "run-list: $ran of $total lines run, ${#red[@]} red"; printf '%s\n' "${red[@]}"; fi
((${#beside[@]} == 0)) || { echo "run-list: a line run beside others started a tool that may trip over another's:"; printf '%s\n' "${beside[@]}"; }
((ran == total && ${#red[@]} == 0 && ${#beside[@]} == 0))
