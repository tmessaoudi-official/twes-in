#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The production image check, run with this machine's PHP against a fixture application and the ini values given
# on the command line: green on what the production stage sets, red naming each departure.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
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
  printf 'log {\n\tformat filter {\n\t\trequest>uri regexp [0-9A-Fa-f]{32,} [token]\n\t}\n}\n' > "$d/log-filter.caddy"
  # The served process's status, as the kernel writes it: real, effective, saved and filesystem uid.
  printf 'Name:\tfrankenphp\nUid:\t%s\nGid:\t10001\t10001\t10001\t10001\n' "${3:-10001	10001	10001	10001}" > "$d/status"
  # The packages dpkg holds, as the purged production stage leaves them: the runtime's own, gcc-14-base among them, and
  # a compiler removed with its configuration kept; and a path holding no compiler.
  package libc6 'install ok installed' > "$d/dpkg-status"
  package gcc-14-base 'install ok installed' >> "$d/dpkg-status"
  package libstdc++6 'install ok installed' >> "$d/dpkg-status"
  package gcc 'deinstall ok config-files' >> "$d/dpkg-status"
  mkdir -p "$d/bin"; printf '#!/bin/sh\n' > "$d/bin/php"; chmod +x "$d/bin/php"
  echo "$d"
}
# One stanza of /var/lib/dpkg/status: name, then status.
package() { printf 'Package: %s\nStatus: %s\nPriority: optional\nVersion: 1\n\n' "$1" "$2"; }
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
  CADDY_GLOBAL_OPTIONS=${CADDY_UNDER_TEST-import $d/log-filter.caddy} FRANKENPHP_CONFIG=${WORKER_UNDER_TEST-worker ./public/index.php} APP_ENV=${APP_ENV_UNDER_TEST:-prod} php -n "${args[@]}" "$GATE" "$d" "$d/status" "$d/dpkg-status" "$d/bin" 2>&1
}

echo "production-image check"
d=$(app); out=$(run "$d"); check "the production stage's settings pass" $? 0 "$out" "production-image: OK"
out=$(APP_ENV_UNDER_TEST=dev run "$d"); check "an image left in dev mode is refused" $? 1 "$out" "APP_ENV"
out=$(WORKER_UNDER_TEST= run "$d"); check "an image serving in classic mode, a kernel booted per request, is refused" $? 1 "$out" "worker"
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
d=$(app false yes "0	0	0	0"); out=$(run "$d"); check "a server running as root is refused" $? 1 "$out" "root"
d=$(app false yes "10001	0	10001	10001"); out=$(run "$d"); check "a server whose effective uid is root is refused" $? 1 "$out" "root"
d=$(app); out=$(CADDY_UNDER_TEST= run "$d"); check "a server whose logs keep a link's token is refused" $? 1 "$out" "CADDY_GLOBAL_OPTIONS"
d=$(app); : > "$d/log-filter.caddy"; out=$(run "$d"); check "an imported file that filters nothing is refused" $? 1 "$out" "CADDY_GLOBAL_OPTIONS"
d=$(app); rm "$d/status"; out=$(run "$d"); check "a status that cannot be read is refused, not taken as fine" $? 1 "$out" "status"
d=$(app); package gcc 'install ok installed' >> "$d/dpkg-status"; out=$(run "$d"); check "an image still holding gcc is refused" $? 1 "$out" "C toolchain is installed (gcc)"
d=$(app); package gcc-14 'install ok installed' >> "$d/dpkg-status"; out=$(run "$d"); check "an image holding gcc's versioned package alone is refused" $? 1 "$out" "C toolchain is installed (gcc-14)"
d=$(app); package linux-libc-dev 'install ok installed' >> "$d/dpkg-status"; out=$(run "$d"); check "an image still holding the kernel headers is refused" $? 1 "$out" "linux-libc-dev"
d=$(app); package make 'install ok installed' >> "$d/dpkg-status"; out=$(run "$d"); check "an image still holding make is refused" $? 1 "$out" "C toolchain is installed (make)"
d=$(app); printf '#!/bin/sh\n' > "$d/bin/cc"; chmod +x "$d/bin/cc"; out=$(run "$d"); check "a cc on the path, whatever put it there, is refused" $? 1 "$out" "$d/bin/cc"
d=$(app); printf '#!/bin/sh\n' > "$d/bin/gcc-14"; chmod +x "$d/bin/gcc-14"; out=$(run "$d"); check "a versioned gcc on the path is refused" $? 1 "$out" "$d/bin/gcc-14"
d=$(app); printf '#!/bin/sh\n' > "$d/bin/make"; chmod +x "$d/bin/make"; out=$(run "$d"); check "make on the path is refused" $? 1 "$out" "$d/bin/make"
d=$(app); rm "$d/dpkg-status"; out=$(run "$d"); check "a package list that cannot be read is refused, not taken as fine" $? 1 "$out" "dpkg-status cannot be read"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
