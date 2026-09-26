#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What the product stores on a visitor's device, and every script it runs, is declared on the Cookies page
# (docs/SPEC.md § 7, 2026-09-26 08:52, row 149): the page renders web/src/app/shared/legal/stored-items.ts, so a cookie,
# a storage key or a third-party script appearing without that declaration reds here, and adding analytics forces the
# consent decision instead of slipping in. Checked:
#   - the cookies the API sets (framework.yaml's session name, Cookie::create / new Cookie in api/src) against the
#     declared cookies, both ways;
#   - the twes.* keys in every web file that reaches browser storage (one naming a *_STORAGE token) against the
#     declared keys, both ways; a `<placeholder>` in a declared name matches a `${…}` in a built one;
#   - browser storage itself reached only inside a *_STORAGE token's factory; document.cookie, IndexedDB, the Cache API,
#     cookieStore and a service worker nowhere;
#   - the CSP's script-src (infra/web/nginx.conf) naming no host, and no <script src> off-origin nor a script element
#     built by the page.
# Specs are not the app. STORED_ITEMS_FLOOR (default 6) is the fewest items discovery must find.
# Usage: stored-items.sh [--root DIR]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
floor=${STORED_ITEMS_FLOOR:-6}
list=web/src/app/shared/legal/stored-items.ts
csp=infra/web/nginx.conf
status=0
say() { printf 'stored-items: FAIL — %s\n' "$1"; shift; (($#)) && printf '  %s\n' "$@"; status=1; }

# Declared: "kind name" per item, a <placeholder> read as *.
mapfile -t declared < <(perl -ne 'print "$2 $1\n" if /name:\s*\x27([^\x27]+)\x27,\s*kind:\s*\x27([a-z]+)\x27/' "$root/$list" 2>/dev/null | sed 's/<[^>]*>/*/g' | sort -u)
if ((${#declared[@]} == 0)); then
  echo "stored-items: FAIL — no item read from $list (STORED_ITEMS missing or empty)"
  exit 1
fi
declared_cookies=$(printf '%s\n' "${declared[@]}" | awk '$1 == "cookie" { print $2 }' | sort -u)
declared_keys=$(printf '%s\n' "${declared[@]}" | awk '$1 != "cookie" { print $2 }' | sort -u)

mapfile -t web < <(git -C "$root" ls-files -- 'web/src/*.ts' 'web/src/*.html' | grep -v '\.spec\.ts$' | grep -v '^web/src/app/api/')
mapfile -t php < <(git -C "$root" ls-files -- 'api/src/*.php')

# Cookies the API sets.
cookies=$(perl -ne 'if (/^\s{4}session:/) { $in = 1; next } if ($in && /^\s{0,4}\S/) { $in = 0 } print "$1\n" if $in && /^\s+name:\s*[\x27"]?([A-Za-z0-9_.-]+)/' "$root/api/config/packages/framework.yaml" 2>/dev/null)
if ((${#php[@]})); then
  cookies+=$'\n'$(cd "$root" && perl -ne 'print "$1\n" while /(?:Cookie::create|new\s+Cookie)\(\s*\x27([^\x27]+)\x27/g' "${php[@]}")
  mapfile -t unreadable < <(cd "$root" && grep -lE "(Cookie::create|new[[:space:]]+Cookie|setcookie)\([[:space:]]*[^'[:space:]]" "${php[@]}" 2>/dev/null)
  ((${#unreadable[@]})) && say "a cookie set with a name this gate cannot read (write it as a literal):" "${unreadable[@]}"
fi
cookies=$(printf '%s\n' "$cookies" | grep -v '^$' | sort -u)

# Browser storage reached outside a token's factory, or a store the page declares none of.
mapfile -t token_files < <(cd "$root" && grep -lE 'export const [A-Z_]+_STORAGE = new InjectionToken' "${web[@]}" /dev/null 2>/dev/null)
mapfile -t raw < <(cd "$root" && grep -lE '\b(localStorage|sessionStorage)\b' "${web[@]}" /dev/null 2>/dev/null | grep -vxF -f <(printf '%s\n' "${token_files[@]}" '-'))
((${#raw[@]})) && say "browser storage reached outside a *_STORAGE token's factory (inject the token instead):" "${raw[@]}"
mapfile -t forbidden < <(cd "$root" && grep -lE '\bdocument\.cookie\b|\bindexedDB\b|\bcookieStore\b|\bcaches\.(open|match)\b|serviceWorker\.register' "${web[@]}" /dev/null 2>/dev/null)
((${#forbidden[@]})) && say "a cookie, a database, a cache or a service worker the Cookies page declares none of:" "${forbidden[@]}"

# Keys in every file that reaches storage.
mapfile -t storage_files < <(cd "$root" && grep -lE '\b[A-Z_]+_STORAGE\b' "${web[@]}" /dev/null 2>/dev/null)
keys=""
if ((${#storage_files[@]})); then
  # Comments first: a doc comment quoting a key's shape is not a key.
  keys=$(cd "$root" && perl -0777 -ne '
    s{/\*.*?\*/}{}gs; s{^\s*//.*$}{}gm;
    while (/\x27(twes\.[^\x27\n]*)\x27|"(twes\.[^"\n]*)"|`(twes\.(?:[^`\\]|\\.)*)`/g) {
      my $k = $1 // $2 // $3; $k =~ s/\$\{(?:[^{}]|\{[^{}]*\})*\}/*/g; print "$k\n";
    }' "${storage_files[@]}" | sort -u)
fi

compare() { # what, declared, found
  local missing undeclared
  missing=$(comm -23 <(printf '%s\n' "$2" | grep -v '^$') <(printf '%s\n' "$3" | grep -v '^$'))
  undeclared=$(comm -13 <(printf '%s\n' "$2" | grep -v '^$') <(printf '%s\n' "$3" | grep -v '^$'))
  # shellcheck disable=SC2086
  [[ -n "$missing" ]] && say "$1 declared in $list that nothing sets any more:" $missing
  # shellcheck disable=SC2086
  [[ -n "$undeclared" ]] && say "$1 set that $list does not declare (the Cookies page lists them):" $undeclared
}
compare cookies "$declared_cookies" "$cookies"
compare "storage keys" "$declared_keys" "$keys"

# Third-party scripts.
script_src=$(perl -ne 'print "$1\n" if /Content-Security-Policy\s+"[^"]*?\bscript-src\s+([^;"]*)/' "$root/$csp" 2>/dev/null | head -1)
if [[ -z "$script_src" ]]; then
  say "no script-src in the Content-Security-Policy of $csp: nothing would keep a third-party script out"
else
  # shellcheck disable=SC2086
  foreign=$(printf '%s\n' $script_src | grep -vE "^'(self|wasm-unsafe-eval|nonce-[^']+)'$")
  # shellcheck disable=SC2086
  [[ -n "$foreign" ]] && say "a script source other than the page's own in $csp:" $foreign
fi
mapfile -t tags < <(cd "$root" && grep -liE '<script[^>]*\bsrc=["'"'"']?(https?:)?//' "${web[@]}" /dev/null 2>/dev/null)
((${#tags[@]})) && say "a script loaded from another origin:" "${tags[@]}"
mapfile -t built < <(cd "$root" && grep -lE "createElement\(\s*['\"]script['\"]|import\(\s*['\"\`]https?:" "${web[@]}" /dev/null 2>/dev/null)
((${#built[@]})) && say "a script element or module built by the page:" "${built[@]}"

found=$(printf '%s\n%s\n' "$cookies" "$keys" | grep -vc '^$')
((found < floor)) && say "$found stored items found, below the floor of $floor: the discovery stopped matching"

((status)) && exit 1
printf 'stored-items: OK — %d stored items, each declared on the Cookies page; no third-party script\n' "$found"
