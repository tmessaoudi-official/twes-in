#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# make mutants SPEC=<file>: does a test notice when the guarantee it stands for is broken? Each mutant in the spec is
# written into its file, its test is run, and the file is put back byte for byte. A mutant counts as killed only when
# a test case failed: one that does not parse, or whose test died before its tally, is INVALID, since it tested
# nothing (a parse error reaches PHPUnit as an ordinary error, so php -l and bash -n come first). Every test is run
# once unmutated before any mutant, and a red one refuses the run. The file's copy is kept in the tree, under
# var/mutants/, until it is back: a toolchain killed mid-run leaves it, and the next run says how to restore it.
#
# The spec, one block per mutant (anything before the first block is a comment; trailing blank lines are dropped):
#   ## <name>
#   file: <path>                   the file mutated, inside the tree
#   test: <path> [arguments…]      api/… a PHPUnit file, web/… a spec for ng test, else a bash test with a tally line
#   old:
#   <exactly one occurrence, one or more lines>
#   new:
#   <its replacement, or nothing to delete it>
#
# Usage: mutants.sh host <spec>              each toolchain the spec needs (MUTANTS_TOOLS, MUTANTS_WEB_TOOLS,
#                                            MUTANTS_NPM_INSTALL, MUTANTS_POSTGRES: the Makefile hands them in)
#        mutants.sh run <api|web|all> <spec>  inside a toolchain: api runs the PHPUnit and bash tests, web the specs
#        mutants.sh list <spec>               the mutants as read, one per line
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root" || exit 2
state=var/mutants; pending=$state/pending
export MUTANTS_RUNNER_PID=$$

# A path as the tree names it, or nothing when it would leave the tree.
inside() {
  local p=$1
  [[ "$p" == "$root"/* ]] && p=${p#"$root"/}
  [[ -n "$p" && "$p" != /* && "/$p/" != */../* ]] || return 1
  printf '%s' "${p#./}"
}

names=(); files=(); tests=(); olds=(); news=()
parse() {
  local line mode= name= file= test= cur=() old=
  flush() {
    [[ -n "$mode" ]] || return 0
    while ((${#cur[@]})) && [[ -z "${cur[-1]}" ]]; do unset 'cur[-1]'; done
    local IFS=$'\n'
    [[ "$mode" == new && -n "$file" && -n "$test" && -n "$old" ]] \
      || { echo "mutants: « $name » needs a file:, a test:, an old: and a new:" >&2; return 1; }
    names+=("$name"); files+=("$file"); tests+=("$test"); olds+=("$old"); news+=("${cur[*]}")
  }
  while IFS= read -r line || [[ -n "$line" ]]; do
    if [[ "$line" == "## "* ]]; then flush || return 1; mode=meta; name=${line#"## "}; file=; test=; old=; cur=(); continue; fi
    case "$mode" in
      '') ;;
      meta)
        case "$line" in
          'file: '*) file=${line#'file: '} ;;
          'test: '*) test=${line#'test: '} ;;
          old:) mode=old; cur=() ;;
          '') ;;
          *) echo "mutants: « $name »: what is « $line »?" >&2; return 1 ;;
        esac ;;
      old)
        if [[ "$line" == new: ]]; then
          while ((${#cur[@]})) && [[ -z "${cur[-1]}" ]]; do unset 'cur[-1]'; done
          local IFS=$'\n'; old="${cur[*]}"; unset IFS; mode=new; cur=()
        else cur+=("$line"); fi ;;
      new) cur+=("$line") ;;
    esac
  done < "$1"
  flush || return 1
  ((${#names[@]})) || { echo "mutants: no mutant in $1" >&2; return 1; }
  local i bad=0 path
  for i in "${!names[@]}"; do
    path=$(inside "${files[$i]}") && [[ -f "$path" ]] && files[i]=$path \
      || { echo "mutants: « ${names[$i]} »: ${files[$i]} is outside the tree or not a file" >&2; bad=1; }
    read -r path _ <<<"${tests[$i]}"
    path=$(inside "$path") && [[ -f "$path" ]] \
      || { echo "mutants: « ${names[$i]} »: its test ${tests[$i]%% *} is outside the tree or not there" >&2; bad=1; }
  done
  return "$bad"
}

kind() { case "$1" in api/*) echo phpunit ;; web/*) echo vitest ;; *) echo bash ;; esac; }
belongs() { local k; k=$(kind "$2"); case "$1" in all) return 0 ;; api) [[ "$k" != vitest ]] ;; web) [[ "$k" == vitest ]] ;; esac; }

# run_test <test> <log>: the test's own exit, read on its own.
run_test() {
  local -a parts; read -ra parts <<<"$1"; local path=${parts[0]}
  case "$(kind "$path")" in
    phpunit) (cd api && vendor/bin/phpunit "${path#api/}" "${parts[@]:1}") > "$2" 2>&1 ;;
    vitest) (cd web && npx ng test --watch=false "--include=${path#web/}" "${parts[@]:1}") > "$2" 2>&1 ;;
    bash) bash "$path" "${parts[@]:1}" > "$2" 2>&1 ;;
  esac
}

plain() { sed 's/\x1b\[[0-9;]*m//g' "$1"; }
# pass | killed (failure) | killed (error) | killed | invalid. A pass is what make check calls one, for both tools.
verdict() {
  local k=$1 status=$2 log=$3
  case "$k" in
    phpunit)
      if ((status == 0)) && bash scripts/check/check.sh tally-phpunit "$log" >/dev/null 2>&1; then echo pass
      elif plain "$log" | grep -qE '^Tests: [0-9]+,.*Failures: [1-9]'; then echo "killed (failure)"
      elif plain "$log" | grep -qE '^Tests: [0-9]+,.*Errors: [1-9]'; then echo "killed (error)"
      else echo invalid; fi ;;
    vitest)
      if ((status == 0)) && bash scripts/check/check.sh tally-vitest 1 "$log" >/dev/null 2>&1; then echo pass
      elif plain "$log" | grep -qE '^ *Tests +.*[0-9]+ failed'; then echo killed
      else echo invalid; fi ;;
    bash)
      if ((status == 0)) && grep -qE '[0-9]+ passed, 0 failed' "$log"; then echo pass
      elif ((status != 0)) && grep -qE '[0-9]+ passed, [1-9][0-9]* failed' "$log"; then echo killed
      else echo invalid; fi ;;
  esac
}

active_file=; active_backup=
put_back() {
  [[ -n "$active_file" ]] || return 0
  cp -- "$active_backup" "$active_file" && cmp -s -- "$active_backup" "$active_file" \
    || { echo "mutants: COULD NOT PUT BACK $active_file; its copy is $active_backup" >&2; return 1; }
  rm -f -- "$pending" "$active_backup"; active_file=
}
on_signal() { local file=$active_file; put_back && [[ -n "$file" ]] && echo "$1: $file put back"; trap - EXIT; exit "$2"; }
trap 'on_signal terminated 143' TERM
trap 'on_signal interrupted 130' INT
trap 'put_back' EXIT

slug() { local s=${1,,}; s=${s//[^a-z0-9]/-}; printf '%s' "$s"; }

run() {
  local group=$1 spec; spec=$(inside "$2") && [[ -f "$spec" ]] || { echo "mutants: the spec $2 is outside the tree or not there" >&2; return 2; }
  if [[ -e "$pending" ]]; then
    local file backup; IFS=$'\t' read -r file backup < "$pending"
    echo "mutants: a mutant of an earlier run is still in $file." >&2
    echo "  A run may still be going: docker ps | grep tools-run. If none is, put the file back with:" >&2
    echo "  cp -- $backup $file && rm -f -- $pending" >&2
    return 2
  fi
  parse "$spec" || return 2
  local -a mine=(); local i
  for i in "${!names[@]}"; do belongs "$group" "${tests[$i]%% *}" && mine+=("$i"); done
  ((${#mine[@]})) || { echo "mutants: nothing in $spec for the $group toolchain"; return 0; }
  rm -rf -- "$state/logs"; mkdir -p "$state/logs" "$state/backup"

  local -A seen=(); local t log v status needs_db=0
  for i in "${mine[@]}"; do [[ "$(kind "${tests[$i]}")" == phpunit ]] && needs_db=1; done
  if ((needs_db)); then
    # As composer test prepares it: the tests connect as the application role, granted after the migrations.
    (cd api && bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test \
      && bin/console app:database:app-role --no-interaction --env=test) > "$state/logs/database.log" 2>&1 \
      || { echo "mutants: the test database could not be prepared ($state/logs/database.log)" >&2; return 2; }
  fi
  local n=0
  for i in "${mine[@]}"; do
    t=${tests[$i]}; [[ -z "${seen[$t]:-}" ]] || continue; seen[$t]=1; n=$((n+1))
    log=$state/logs/baseline-$n.log; run_test "$t" "$log"; status=$?
    v=$(verdict "$(kind "$t")" "$status" "$log")
    [[ "$v" == pass ]] || { echo "mutants: red before any mutant: $t (exit $status, $log)" >&2; return 2; }
  done

  local killed=0 survived=0 invalid=0 missed=0 file old new content rest found mutated
  for i in "${mine[@]}"; do
    file=${files[$i]}; old=${olds[$i]}; new=${news[$i]}; log=$state/logs/$((i+1))-$(slug "${names[$i]}").log
    IFS= read -rd '' content < "$file"
    rest=$content; found=0; while [[ "$rest" == *"$old"* ]]; do found=$((found+1)); rest=${rest#*"$old"}; done
    if ((found != 1)); then echo "NOT LANDED  ${names[$i]} (found $found times)"; missed=$((missed+1)); continue; fi
    mutated=${content/"$old"/"$new"}
    active_backup=$state/backup/${file//\//__}
    cp -- "$file" "$active_backup" && cmp -s -- "$file" "$active_backup" || { echo "mutants: could not copy $file" >&2; return 2; }
    printf '%s\t%s\n' "$file" "$active_backup" > "$pending"; active_file=$file
    printf '%s' "$mutated" > "$state/mutant"; cp -- "$state/mutant" "$file"
    cmp -s -- "$state/mutant" "$file" || { echo "mutants: could not write the mutant into $file" >&2; put_back; return 2; }
    v=
    case "$file" in
      *.php) php -l "$file" > "$log" 2>&1 || v="INVALID   ${names[$i]} (php -l)  log: $log" ;;
      *.sh) bash -n "$file" > "$log" 2>&1 || v="INVALID   ${names[$i]} (bash -n)  log: $log" ;;
    esac
    if [[ -z "$v" ]]; then
      run_test "${tests[$i]}" "$log"; status=$?
      case "$(verdict "$(kind "${tests[$i]}")" "$status" "$log")" in
        "killed (failure)") v="killed    ${names[$i]} (failure)" ;;
        "killed (error)") v="killed    ${names[$i]} (error)" ;;
        killed) v="killed    ${names[$i]}" ;;
        pass) v="SURVIVED  ${names[$i]}  log: $log" ;;
        *) v="INVALID   ${names[$i]} (no tally, exit $status)  log: $log" ;;
      esac
    fi
    put_back || return 3
    rm -f -- "$state/mutant"
    echo "$v"
    case "$v" in killed*) killed=$((killed+1)) ;; SURVIVED*) survived=$((survived+1)) ;; *) invalid=$((invalid+1)) ;; esac
  done
  echo "mutants ($group): $killed killed, $survived survived, $invalid invalid, $missed not landed"
  ((survived + invalid + missed == 0))
}

host() {
  local spec; spec=$(inside "$1") && [[ -f "$spec" ]] || { echo "mutants: the spec $1 is outside the tree or not there" >&2; return 2; }
  parse "$spec" || return 2
  local i api=0 web=0 db=0 status=0
  for i in "${!names[@]}"; do
    case "$(kind "${tests[$i]}")" in phpunit) api=1; db=1 ;; bash) api=1 ;; vitest) web=1 ;; esac
  done
  if ((db)); then sh -c "${MUTANTS_POSTGRES:-docker compose up -d --wait postgres}" || return 2; fi
  if ((api)); then sh -c "${MUTANTS_TOOLS:?run through make mutants} bash scripts/mutants/mutants.sh run api $spec" || status=1; fi
  if ((web)); then
    sh -c "${MUTANTS_WEB_TOOLS:?run through make mutants} sh -c '${MUTANTS_NPM_INSTALL:?run through make mutants} && bash ../scripts/mutants/mutants.sh run web $spec'" || status=1
  fi
  return "$status"
}

list() {
  local spec; spec=$(inside "$1") && [[ -f "$spec" ]] || { echo "mutants: the spec $1 is outside the tree or not there" >&2; return 2; }
  parse "$spec" || return 2
  local i o w
  for i in "${!names[@]}"; do
    o=${olds[$i]//$'\n'/\\n}; o=${o//$'\t'/\\t}; w=${news[$i]//$'\n'/\\n}; w=${w//$'\t'/\\t}
    printf '%s\t%s\t%s\t%s\t%s\n' "${names[$i]}" "${files[$i]}" "${tests[$i]}" "$o" "$w"
  done
}

mode=${1:-}; shift || true
case "$mode" in
  host) [[ $# -eq 1 ]] && host "$1" ;;
  run) [[ $# -eq 2 && "$1" =~ ^(api|web|all)$ ]] && run "$1" "$2" ;;
  list) [[ $# -eq 1 ]] && list "$1" ;;
  *) false ;;
esac
status=$?
[[ "$mode" =~ ^(host|run|list)$ ]] || { echo "usage: mutants.sh host <spec> | run <api|web|all> <spec> | list <spec>" >&2; status=2; }
exit "$status"
