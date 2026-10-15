#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# make check: a quick look at what changed, before the gate. Each tool is given the files changed (against HEAD, and
# the untracked ones, or FILES=…) within the scope the gate gives it, and a tool given nothing is not run at all:
# php-cs-fixer, prettier, eslint and ng test would each fall back to the whole tree. A file no tool here checks is
# named, never dropped. `make gate` stays the authority: PHPStan sees the whole tree, but only the tests named or
# beside a changed web file run, and nothing is built.
# Usage: check.sh plan <files…>             what each tool would be given, one line per tool
#        check.sh host [--fix] [files…]     the plan, then each toolchain it needs (CHECK_TOOLS, CHECK_WEB_TOOLS,
#                                           CHECK_NPM_INSTALL, CHECK_POSTGRES: the Makefile hands them in)
#        check.sh api|web [--fix] <files…>  inside a toolchain: run its tools (CHECK_DRY=1 prints each command)
#        check.sh tally-phpunit <log> | tally-vitest <spec count> <log>
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root" || exit 2

words() { (($# > 1)) || return 0; local name=$1; shift; local IFS=' '; local -a u
  mapfile -t u < <(printf '%s\n' "$@" | LC_ALL=C sort -u); printf '%s %s\n' "$name" "${u[*]}"; }

plan() {
  local f rel spec
  local -a api_fmt=() api_test=() api_src=() web_fmt=() web_lint=() web_test=() web_e2e=() unchecked=()
  for f in "$@"; do
    f=${f#"$root/"}; f=${f#./}
    if [[ "$f" =~ [[:space:]\'\"\$\`\\] ]]; then echo "check: refused a path with a space, a quote or a shell character: $f" >&2; return 2; fi
    [[ -f "$f" ]] || continue  # deleted: nothing left to check
    case "$f" in
      api/vendor/*|api/var/*) unchecked+=("$f") ;;
      api/*.php)
        rel=${f#api/}; api_fmt+=("$rel")
        [[ "$rel" == tests/*Test.php ]] && api_test+=("$rel")
        [[ "$rel" == src/* ]] && api_src+=("$rel") ;;
      # What web/.prettierignore leaves out is left out here too.
      web/node_modules/*|web/dist/*|web/.angular/*|web/src/app/api/*|web/test-results/*|web/playwright-report/*|web/package-lock.json)
        unchecked+=("$f") ;;
      web/*.ts|web/*.mts|web/*.cts|web/*.js|web/*.mjs|web/*.cjs|web/*.json|web/*.html|web/*.scss|web/*.css|web/*.md|web/*.yaml|web/*.yml)
        rel=${f#web/}; web_fmt+=("$rel")
        # ng lint's own patterns (angular.json lintFilePatterns), and the spec beside a source, as the gate's suite would run it.
        if [[ "$rel" == src/*.ts || "$rel" == src/*.html ]]; then
          web_lint+=("$rel")
          if [[ "$rel" == *.spec.ts ]]; then spec=$rel; else spec=${rel%.*}.spec.ts; fi
          [[ -f "web/$spec" ]] && web_test+=("$spec")
        fi
        [[ "$rel" == e2e*/*.spec.ts ]] && web_e2e+=("$rel") ;;
      *) unchecked+=("$f") ;;
    esac
  done
  words api-format "${api_fmt[@]}"
  ((${#api_fmt[@]})) && echo api-stan
  words api-test "${api_test[@]}"
  ((${#api_test[@]})) || words api-untested "${api_src[@]}"
  words web-format "${web_fmt[@]}"
  words web-lint "${web_lint[@]}"
  words web-test "${web_test[@]}"
  words web-e2e "${web_e2e[@]}"
  words unchecked "${unchecked[@]}"
  return 0
}

# One bucket of a plan, as words.
bucket() { sed -n "s/^$2 //p" <<<"$1"; }

host() {
  local fix=; [[ "${1:-}" == --fix ]] && { fix=' --fix'; shift; }
  local -a files=("$@")
  ((${#files[@]})) || mapfile -t files < <({ git diff --name-only HEAD; git ls-files --others --exclude-standard; } | LC_ALL=C sort -u)
  local p; p=$(plan "${files[@]}") || return 2
  [[ -n "$p" ]] || { echo "check: nothing changed against HEAD, nothing to check"; return 0; }
  local f; local -a kept=(); for f in "${files[@]}"; do [[ -f "$f" ]] && kept+=("$f"); done; files=("${kept[@]}")
  echo "$p"
  local status=0
  if grep -q '^api-format ' <<<"$p"; then
    if grep -q '^api-test ' <<<"$p"; then sh -c "${CHECK_POSTGRES:-docker compose up -d --wait postgres}" || return 1; fi
    sh -c "${CHECK_TOOLS:?run through make check} bash scripts/check/check.sh api$fix ${files[*]}" || status=1
  fi
  if grep -q '^web-format ' <<<"$p"; then
    sh -c "${CHECK_WEB_TOOLS:?run through make check} sh -c '${CHECK_NPM_INSTALL:?run through make check} && bash ../scripts/check/check.sh web$fix ${files[*]}'" || status=1
  fi
  if ((status)); then echo "check: FAIL (make gate stays the authority)"; else echo "check: ok (make gate stays the authority)"; fi
  return "$status"
}

results=(); failed=0
# step <name> <tally or ""> <dir> <command…>: its own exit decides, read beside the pipe that shows it, then its tally.
step() {
  local name=$1 tally=$2 dir=$3; shift 3
  if [[ -n "${CHECK_DRY:-}" ]]; then echo "RUN ($dir) $*"; return 0; fi
  local log; log=$(mktemp)
  (cd "$dir" && "$@") 2>&1 | tee "$log"
  local status=${PIPESTATUS[0]}
  if ((status == 0)) && [[ -n "$tally" ]]; then $tally "$log" || status=1; fi
  rm -f "$log"
  if ((status == 0)); then results+=("ok   $name"); else results+=("FAIL $name (exit $status)"); failed=1; fi
}
summary() { local part=$1 r; for r in "${results[@]}"; do echo "$part: $r"; done; return "$failed"; }

api() {
  local fix=; [[ "${1:-}" == --fix ]] && { fix=1; shift; }
  local p; p=$(plan "$@") || return 2
  local -a fmt test untested; read -ra fmt <<<"$(bucket "$p" api-format)"; read -ra test <<<"$(bucket "$p" api-test)"
  read -ra untested <<<"$(bucket "$p" api-untested)"
  ((${#fmt[@]})) || { echo "api: nothing to check"; return 0; }
  local -a verb=(check --diff); [[ -n $fix ]] && verb=(fix)
  step php-cs-fixer "" api vendor/bin/php-cs-fixer "${verb[@]}" --config=.php-cs-fixer.dist.php --path-mode=intersection "${fmt[@]}"
  step phpstan "" api composer stan
  if ((${#test[@]})); then
    # As composer test prepares it: the tests connect as the application role, granted after the migrations.
    step "test database" "" api bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
    step "application role" "" api bin/console app:database:app-role --no-interaction --env=test
    step phpunit tally_phpunit api vendor/bin/phpunit "${test[@]}"
  fi
  ((${#untested[@]})) && echo "api: no test file given, PHPUnit not run for ${untested[*]} (name the tests in FILES)"
  summary api
}

web() {
  local fix=; [[ "${1:-}" == --fix ]] && { fix=1; shift; }
  local p; p=$(plan "$@") || return 2
  local -a fmt lint test e2e includes=(); read -ra fmt <<<"$(bucket "$p" web-format)"; read -ra lint <<<"$(bucket "$p" web-lint)"
  read -ra test <<<"$(bucket "$p" web-test)"; read -ra e2e <<<"$(bucket "$p" web-e2e)"
  ((${#fmt[@]})) || { echo "web: nothing to check"; return 0; }
  step prettier "" web npx prettier "$([[ -n $fix ]] && echo --write || echo --check)" --ignore-unknown "${fmt[@]}"
  if ((${#lint[@]})); then
    if [[ -n $fix ]]; then step eslint "" web npx eslint --fix "${lint[@]}"; else step eslint "" web npx eslint "${lint[@]}"; fi
  fi
  if ((${#test[@]})); then
    local s; for s in "${test[@]}"; do includes+=("--include=$s"); done
    step "ng test" "tally_vitest ${#test[@]}" web npx ng test --watch=false "${includes[@]}"
  fi
  ((${#e2e[@]})) && echo "web: browser scenarios formatted, not run: make e2e E2E_ARGS='${e2e[*]}'"
  summary web
}

plain() { sed 's/\x1b\[[0-9;]*m//g' "$1"; }
# PHPUnit's verdict line: OK, or OK with issues (deprecations); "No tests executed!" exits 0 without one.
tally_phpunit() { plain "$1" | grep -qE '^OK( \(|, but)' || { echo "phpunit: no OK line in its output" >&2; return 1; }; }
# Vitest drops an --include that names no file and still passes: every spec given must be a file it ran.
tally_vitest() {
  local ran; ran=$(plain "$2" | sed -nE 's/^ *Test Files +([0-9]+) passed.*/\1/p' | tail -n 1)
  [[ "$ran" == "$1" ]] || { echo "ng test: ${ran:-0} of $1 spec files ran" >&2; return 1; }
}

mode=${1:-}; shift || true
case "$mode" in
  plan) plan "$@" ;;
  host) host "$@" ;;
  api) api "$@" ;;
  web) web "$@" ;;
  tally-phpunit) tally_phpunit "$@" ;;
  tally-vitest) tally_vitest "$@" ;;
  *) echo "usage: check.sh plan|host|api|web|tally-phpunit|tally-vitest …" >&2; exit 2 ;;
esac
