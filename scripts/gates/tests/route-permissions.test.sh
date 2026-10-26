#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/route-permissions.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree with one module's manifest: its menu entry needs vendor.read, its command vendor.write, its tab nothing.
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/vendors" "$d/web/src/app/shell"
  cat > "$d/web/src/app/vendors/vendors-nav.ts" <<'EOF'
export const VENDORS_NAV: readonly NavEntry[] = [
  {
    key: 'vendors',
    route: '/vendors',
    permission: 'vendor.read',
  },
];
export const VENDORS_COMMANDS: readonly NavigateCommand[] = [
  { key: 'new-vendor', route: '/vendors/new', permission: 'vendor.write' },
];
export const VENDORS_TABS: readonly PageTab[] = [{ labelKey: 'x', route: '/vendors/groups', testId: 't' }];
EOF
  echo 'export const SETTINGS_NAV = [{ key: "a", route: `/company/${x}`, permission: "p" }];' > "$d/web/src/app/shell/nav-manifest.ts"
  echo "$d"
}
routes() { # the routes file, with $1 as the vendors list's guards and $2 as the record page's
  cat > "$1/web/src/app/app.routes.ts" <<EOF
export const routes: Routes = [
  {
    path: '',
    children: [
      { path: 'vendors', canActivate: [moduleGuard(V), $2] },
      {
        path: 'vendors/new',
        ...textsPart('x', moduleGuard(V), permissionGuard(VENDORS_COMMANDS, '/vendors/new')),
      },
      { path: 'vendors/groups', canActivate: [permissionGuard(VENDORS_NAV, '/vendors')] },
      {
        path: 'vendors/:vendorId',
        canActivate: [$3],
      },
    ],
  },
];
EOF
  git -C "$1" add -A
}
echo "route-permissions gate"
d=$(repo); routes "$d" "permissionGuard(VENDORS_NAV, '/vendors')" "permissionGuard(VENDORS_NAV, '/vendors')"
out=$(bash "$GATE" --root "$d" --min 2 2>&1); check "every declared route guarded from its own manifest, inside canActivate or a textsPart, passes" $? 0 "$out" "OK — 2 declarations, 4 routes guarded"
routes "$d" "textsOpened(X)" "permissionGuard(VENDORS_NAV, '/vendors')"
out=$(bash "$GATE" --root "$d" --min 2 2>&1); check "a declared route without its guard is named" $? 1 "$out" "'vendors' lacks permissionGuard(VENDORS_NAV, '/vendors')"
routes "$d" "permissionGuard(VENDORS_COMMANDS, '/vendors')" "permissionGuard(VENDORS_NAV, '/vendors')"
out=$(bash "$GATE" --root "$d" --min 2 2>&1); check "a guard reading another manifest than the one declaring the route is named" $? 1 "$out" "STALE permissionGuard(VENDORS_COMMANDS, '/vendors')"
routes "$d" "permissionGuard(VENDORS_NAV, '/vendors')" "moduleGuard(V)"
out=$(bash "$GATE" --root "$d" --min 2 2>&1); check "a record page below a guarded route without a guard is named" $? 1 "$out" "'vendors/:vendorId' sits below a guarded route"
routes "$d" "permissionGuard(VENDORS_NAV, '/vendors')" "moduleGuard(V)] // route-open: its page reads nothing the role is refused, [x"
out=$(bash "$GATE" --root "$d" --min 2 2>&1); check "a route left open with its reason passes" $? 0 "$out" "1 open with their reason"
d=$(repo); sed -i "s#route: '/vendors',#route: '/suppliers',#" "$d/web/src/app/vendors/vendors-nav.ts"
routes "$d" "permissionGuard(VENDORS_NAV, '/vendors')" "permissionGuard(VENDORS_NAV, '/vendors')"
out=$(bash "$GATE" --root "$d" --min 2 2>&1); check "a declared route the routes file does not have is named" $? 1 "$out" "NOROUTE VENDORS_NAV '/suppliers'"
d=$(repo); routes "$d" "permissionGuard(VENDORS_NAV, '/vendors')" "permissionGuard(VENDORS_NAV, '/vendors')"
out=$(bash "$GATE" --root "$d" 2>&1); check "fewer declarations than the floor reds rather than passing over nothing" $? 1 "$out" "fewer than 40"
d=$(repo); printf 'export const X = [];\n' > "$d/web/src/app/vendors/vendors-nav.ts"; routes "$d" "a" "b"
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a manifest that yields nothing reds" $? 1 "$out" "0 declarations"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
