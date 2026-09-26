#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/stored-items.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A repository the gate passes: one cookie, three keys (one built with a placeholder), a CSP naming no other host.
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q
  mkdir -p "$d/web/src/app/shared/legal" "$d/web/src/app/x" "$d/api/config/packages" "$d/api/src/X" "$d/infra/web"
  cat > "$d/web/src/app/shared/legal/stored-items.ts" <<'EOF'
export const STORED_ITEMS: readonly StoredItem[] = [
  { id: 'session', name: 'twes_session', kind: 'cookie', lasts: 'session' },
  { id: 'display', name: 'twes.settings.<person>.<setting>', kind: 'local', lasts: 'kept' },
  { id: 'camera', name: 'twes.scan.camera', kind: 'local', lasts: 'kept' },
  { id: 'phone', name: 'twes.scan.phone', kind: 'session', lasts: 'tab' },
];
EOF
  cat > "$d/web/src/app/x/storage.ts" <<'EOF'
export const SETTINGS_STORAGE = new InjectionToken<Storage>('SETTINGS_STORAGE', {
  factory: () => inject(DOCUMENT).defaultView?.localStorage,
});
const key = (id: string) => `twes.settings.${id ?? 'anonymous'}.${name}`;
export const CAMERA = 'twes.scan.camera';
EOF
  cat > "$d/web/src/app/x/phone.ts" <<'EOF'
export const PAIRING_STORAGE = new InjectionToken<Storage>('PAIRING_STORAGE', {
  factory: () => inject(DOCUMENT).defaultView?.sessionStorage,
});
export const PAIRING_KEY = 'twes.scan.phone';
EOF
  # A channel name is not a stored key: this file touches no storage.
  printf "const channel = \`twes.customer-display.\${id}\`;\n" > "$d/web/src/app/x/display.ts"
  printf '<!doctype html><html><head><link rel="icon" href="icon.svg"></head><body><app-root></app-root></body></html>\n' > "$d/web/src/index.html"
  printf 'framework:\n    session:\n        handler_id: X\n        name: twes_session\n        cookie_secure: true\n' > "$d/api/config/packages/framework.yaml"
  printf "<?php\nfinal class Nothing {}\n" > "$d/api/src/X/Nothing.php"
  printf "    add_header Content-Security-Policy \"default-src 'self'; script-src 'self' 'nonce-\$request_id' 'wasm-unsafe-eval'; style-src 'self'\" always;\n" > "$d/infra/web/nginx.conf"
  echo "$d"
}
run() { git -C "$1" add -A; STORED_ITEMS_FLOOR="${FLOOR:-4}" bash "$GATE" --root "$1" 2>&1; }
echo "stored-items gate"

d=$(repo); out=$(run "$d"); check "what is stored is what is declared, a placeholder matching what a template builds" $? 0 "$out" "OK — 4 stored items"

d=$(repo); printf "/**\n * One key per user, \`twes.settings.<user>.<key>\` (see 'twes.old').\n */\n// 'twes.older' was the name before\n" >> "$d/web/src/app/x/storage.ts"; out=$(run "$d")
check "a comment naming a key is not a key" $? 0 "$out" "OK — 4 stored items"

d=$(repo); printf "export const X = 'twes.tracker';\n" >> "$d/web/src/app/x/phone.ts"; out=$(run "$d")
check "a key a storage file uses that the list does not declare is named" $? 1 "$out" "twes.tracker"

d=$(repo); sed -i "/twes.scan.camera/d" "$d/web/src/app/x/storage.ts"; out=$(run "$d")
check "a declared key nothing stores any more is named, so the page never lists what is gone" $? 1 "$out" "twes.scan.camera"

d=$(repo); sed -i 's/name: twes_session/name: PHPSESSID/' "$d/api/config/packages/framework.yaml"; out=$(run "$d")
check "a session cookie renamed without its declaration is named" $? 1 "$out" "PHPSESSID"

d=$(repo); printf "<?php\n\$r->headers->setCookie(Cookie::create('_ga', 'x'));\n" > "$d/api/src/X/Track.php"; out=$(run "$d")
check "a cookie the API sets that the list does not declare is named" $? 1 "$out" "_ga"

d=$(repo); printf "<?php\n\$r->headers->setCookie(new Cookie(\$name, 'x'));\n" > "$d/api/src/X/Track.php"; out=$(run "$d")
check "a cookie whose name cannot be read is refused rather than skipped" $? 1 "$out" "Track.php"

d=$(repo); printf "localStorage.setItem('seen', '1');\n" > "$d/web/src/app/x/sneaky.ts"; out=$(run "$d")
check "browser storage reached outside a *_STORAGE token's factory is named" $? 1 "$out" "sneaky.ts"

d=$(repo); printf "document.cookie = 'seen=1';\n" > "$d/web/src/app/x/sneaky.ts"; out=$(run "$d")
check "a cookie written by the page itself is refused" $? 1 "$out" "sneaky.ts"

d=$(repo); printf "const db = indexedDB.open('x');\n" > "$d/web/src/app/x/storage-db.ts"; out=$(run "$d")
check "IndexedDB is refused: the Cookies page declares none" $? 1 "$out" "storage-db.ts"

d=$(repo); sed -i "s/script-src 'self'/script-src 'self' https:\/\/www.googletagmanager.com/" "$d/infra/web/nginx.conf"; out=$(run "$d")
check "a script source other than the page's own in the CSP is named" $? 1 "$out" "googletagmanager"

d=$(repo); sed -i "s/script-src [^;]*; //" "$d/infra/web/nginx.conf"; out=$(run "$d")
check "a CSP with no script-src is refused, since nothing then keeps a third-party script out" $? 1 "$out" "script-src"

d=$(repo); sed -i 's#<app-root>#<script src="https://cdn.example.com/a.js"></script><app-root>#' "$d/web/src/index.html"; out=$(run "$d")
check "a script tag loading from another origin is named" $? 1 "$out" "index.html"

d=$(repo); printf "const s = document.createElement('script');\n" > "$d/web/src/app/x/inject.ts"; out=$(run "$d")
check "a script element built by the page is refused" $? 1 "$out" "inject.ts"

d=$(repo); printf "localStorage.setItem('twes.x', '1');\ndocument.cookie = 'x';\n" > "$d/web/src/app/x/a.spec.ts"; out=$(run "$d")
check "a spec touching storage is not the app touching it" $? 0 "$out" "OK"

d=$(repo); printf 'export const STORED_ITEMS = [];\n' > "$d/web/src/app/shared/legal/stored-items.ts"; out=$(run "$d")
check "a list that yields nothing reds rather than passing over nothing" $? 1 "$out" "no item"

d=$(repo); out=$(FLOOR=5 run "$d")
check "fewer stored items found than the floor reds, so a discovery that stops matching is seen" $? 1 "$out" "floor"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
