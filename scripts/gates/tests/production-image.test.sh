#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The production image check, run with this machine's PHP against a fixture application and the ini values given
# on the command line: green on what the production stage sets, red naming each departure.
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/production-image.php
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# An application as the production stage leaves it: no dev packages, a warmed prod cache with its preload list.
app() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/vendor/composer" "$d/var/cache/prod" "$d/config"
  printf '<?php return [%s];\n' "'root' => ['dev' => ${1:-false}]" > "$d/vendor/composer/installed.php"
  [[ "${2:-yes}" == yes ]] && echo '<?php' > "$d/var/cache/prod/App_KernelProdContainer.preload.php"
  echo '<?php' > "$d/config/preload.php"
  echo "$d"
}
# The production ini, with one value replaced when a case names it.
run() {
  local d=$1; shift
  local -A ini=(
    [display_errors]=0 [zend.assertions]=-1 [opcache.validate_timestamps]=0 [opcache.memory_consumption]=256
    [opcache.max_accelerated_files]=32531 [opcache.interned_strings_buffer]=32 [realpath_cache_ttl]=600
    [opcache.preload]="$d/config/preload.php"
  )
  local kv; for kv in "$@"; do ini[${kv%%=*}]=${kv#*=}; done
  local args=() k; for k in "${!ini[@]}"; do args+=(-d "$k=${ini[$k]}"); done
  APP_ENV=${APP_ENV_UNDER_TEST:-prod} php -n "${args[@]}" "$GATE" "$d" 2>&1
}

echo "production-image check"
d=$(app); out=$(run "$d"); check "the production stage's settings pass" $? 0 "$out" "production-image: OK"
out=$(APP_ENV_UNDER_TEST=dev run "$d"); check "an image left in dev mode is refused" $? 1 "$out" "APP_ENV"
out=$(run "$d" zend.assertions=1); check "assertions still compiled in are refused" $? 1 "$out" "zend.assertions"
out=$(run "$d" display_errors=1); check "errors shown to the visitor are refused" $? 1 "$out" "display_errors"
out=$(run "$d" opcache.validate_timestamps=1); check "OPcache checking every file's time is refused" $? 1 "$out" "opcache.validate_timestamps"
out=$(run "$d" opcache.memory_consumption=128); check "the default OPcache memory is refused" $? 1 "$out" "opcache.memory_consumption"
out=$(run "$d" opcache.max_accelerated_files=10000); check "the default OPcache file count is refused" $? 1 "$out" "opcache.max_accelerated_files"
out=$(run "$d" opcache.interned_strings_buffer=8); check "the default interned strings buffer is refused" $? 1 "$out" "opcache.interned_strings_buffer"
out=$(run "$d" realpath_cache_ttl=120); check "the default realpath cache lifetime is refused" $? 1 "$out" "realpath_cache_ttl"
out=$(run "$d" opcache.preload=); check "no preload is refused" $? 1 "$out" "opcache.preload"
out=$(run "$d" opcache.preload=/elsewhere/preload.php); check "a preload of another file is refused" $? 1 "$out" "opcache.preload"
d=$(app true); out=$(run "$d"); check "dev packages installed are refused" $? 1 "$out" "dev packages"
d=$(app false no); out=$(run "$d"); check "a cache not warmed at build, with nothing to preload, is refused" $? 1 "$out" "var/cache/prod"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
