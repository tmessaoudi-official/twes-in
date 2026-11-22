#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The weekly read of the maintainers' own advisories (scripts/security/advisory-feeds.sh), its gh a stand-in that answers
# each repository's published advisories from a fixture: an advisory reaching the locked version fails the run unless
# .advisoryignore names it with a date not yet passed, an advisory naming a package by name is matched whichever
# repository published it, and a repository that cannot be read or a range that cannot be read is said, never skipped.
set -uo pipefail
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/scripts/security/advisory-feeds.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

mkdir -p "$TMPDIR/bin" "$TMPDIR/feeds"
# gh api repos/<owner>/<repo>/security-advisories?… answers feeds/<owner>__<repo>.json, or fails as GitHub does for a
# repository it cannot read; every repository asked is written down.
cat > "$TMPDIR/bin/gh" <<EOF
#!/usr/bin/env bash
repo=\$(printf '%s\n' "\$@" | sed -n 's|^repos/\([^/]*/[^/]*\)/security-advisories.*|\1|p')
echo "\$repo" >> "$TMPDIR/asked"
f="$TMPDIR/feeds/\${repo/\//__}.json"
[[ -f "\$f" ]] || { echo "gh: Not Found (HTTP 404)" >&2; exit 1; }
cat "\$f"
EOF
chmod +x "$TMPDIR/bin/gh"

lock() {  # name@version@repository …
  local entries=() item name version repo
  for item in "$@"; do
    IFS=@ read -r name version repo <<<"$item"
    entries+=("{\"name\":\"$name\",\"version\":\"$version\",\"source\":{\"type\":\"git\",\"url\":\"https://github.com/$repo.git\"}}")
  done
  local joined; joined=$(IFS=,; echo "${entries[*]}")
  printf '{"packages":[%s],"packages-dev":[]}\n' "$joined" > "$TMPDIR/composer.lock"
}
feed() {  # repository, then ghsa|package|range …
  local repo=$1; shift; local items=() item id package range
  for item in "$@"; do
    IFS='|' read -r id package range <<<"$item"
    [[ "$range" == null ]] && range=null || range="\"$range\""
    items+=("{\"ghsa_id\":\"$id\",\"severity\":\"high\",\"summary\":\"a flaw\",\"withdrawn_at\":null,\"vulnerabilities\":[{\"package\":{\"ecosystem\":\"composer\",\"name\":\"$package\"},\"vulnerable_version_range\":$range}]}")
  done
  local joined; joined=$(IFS=,; echo "${items[*]}")
  printf '[%s]\n' "$joined" > "$TMPDIR/feeds/${repo/\//__}.json"
}
run() { rm -f "$TMPDIR/asked"; touch "$TMPDIR/ignore"; env PATH="$TMPDIR/bin:$PATH" ADVISORY_EXTRA_REPOS="${EXTRA:-}" "$@" bash "$SCRIPT" "$TMPDIR/composer.lock" "$TMPDIR/ignore" 2>&1; }
echo "advisory feeds"

lock "acme/lib@v2.3.4@acme/lib" "beta/tool@1.0.0@beta/tool"
feed acme/lib "GHSA-aaaa-aaaa-aaaa|acme/lib|>= 3.0.0, < 3.1.0" "GHSA-bbbb-bbbb-bbbb|acme/bundle|< 9.0.0"
feed beta/tool
out=$(run); check "nothing reaching the locked versions passes" $? 0 "$out" "advisory-feeds: OK — 2 repositories"
check "  every repository a locked package comes from is read" 0 0 "$(sort "$TMPDIR/asked" | tr '\n' ' ')" "acme/lib beta/tool"

feed acme/lib "GHSA-cccc-cccc-cccc|acme/lib|>= 2.0.0, <= 2.3.4"
out=$(run); check "an advisory reaching the locked version fails" $? 3 "$out" "GHSA-cccc-cccc-cccc acme/lib v2.3.4 (>= 2.0.0, <= 2.3.4)"
feed acme/lib "GHSA-cccc-cccc-cccc|acme/lib|<2.3.5"
out=$(run); check "  a range written without spaces too" $? 3 "$out" "GHSA-cccc-cccc-cccc"
feed acme/lib "GHSA-cccc-cccc-cccc|acme/lib|>=2.3.5"
out=$(run); check "  and a version past it passes" $? 0 "$out" "advisory-feeds: OK"
lock "acme/lib@v8.1.0@acme/lib"
feed acme/lib "GHSA-cccc-cccc-cccc|acme/lib|>=8, <= 8.1"
out=$(run); check "  a bound shorter than the version reads as padded, 8.1.0 within <= 8.1" $? 3 "$out" "GHSA-cccc-cccc-cccc"
lock "acme/lib@v2.3.4@acme/lib" "beta/tool@1.0.0@beta/tool"
feed acme/lib "GHSA-cccc-cccc-cccc|acme/lib|>= 2.0.0, < 2.10.0"
out=$(run); check "  versions compare as numbers, 2.3.4 below 2.10.0" $? 3 "$out" "GHSA-cccc-cccc-cccc"

# Ranges as maintainers write them: alternatives joined by ||, and a wildcard for a whole minor.
feed acme/lib "GHSA-cccc-cccc-cccc|acme/lib|>=1.0.0, <1.4.0 || >= 2.0.0, <2.3.5"
out=$(run); check "an advisory reaching it through its second alternative fails" $? 3 "$out" "GHSA-cccc-cccc-cccc"
feed acme/lib "GHSA-cccc-cccc-cccc|acme/lib|>=1.0.0, <1.4.0 || >= 2.4.0, <2.5.0"
out=$(run); check "  and through none passes" $? 0 "$out" "advisory-feeds: OK"
feed acme/lib "GHSA-cccc-cccc-cccc|acme/lib|2.3.*,>=3.9.0,<3.26.0"
out=$(run); check "a wildcard holding the locked version fails" $? 3 "$out" "GHSA-cccc-cccc-cccc"
feed acme/lib "GHSA-cccc-cccc-cccc|acme/lib|2.16.*"
out=$(run); check "  and one that does not, 2.3.4 outside 2.16.*, passes" $? 0 "$out" "advisory-feeds: OK"

feed acme/lib "GHSA-cccc-cccc-cccc|acme/lib|< 3.0.0"
printf 'GHSA-cccc-cccc-cccc exp:2099-01-01\n#   HIGH acme/lib: NOT REACHABLE, a reason\n' > "$TMPDIR/ignore.kept"
out=$(env PATH="$TMPDIR/bin:$PATH" ADVISORY_EXTRA_REPOS= bash "$SCRIPT" "$TMPDIR/composer.lock" "$TMPDIR/ignore.kept" 2>&1)
check "a triaged advisory passes" $? 0 "$out" "1 triaged"
printf 'GHSA-cccc-cccc-cccc exp:2020-01-01\n' > "$TMPDIR/ignore.old"
out=$(env PATH="$TMPDIR/bin:$PATH" ADVISORY_EXTRA_REPOS= bash "$SCRIPT" "$TMPDIR/composer.lock" "$TMPDIR/ignore.old" 2>&1)
check "  but not once its date has passed" $? 3 "$out" "GHSA-cccc-cccc-cccc"

lock "acme/component@v8.1.3@acme/component"
feed acme/component
feed acme/mono "GHSA-dddd-dddd-dddd|acme/component|>=8, <8.1.4"
out=$(EXTRA=acme/mono run); check "an advisory a monorepo publishes for a split package is matched" $? 3 "$out" "GHSA-dddd-dddd-dddd acme/component v8.1.3"
feed symfony/symfony; feed symfony/polyfill; feed web-auth/webauthn-framework; feed async-aws/aws
rm -f "$TMPDIR/asked"; env -u ADVISORY_EXTRA_REPOS PATH="$TMPDIR/bin:$PATH" bash "$SCRIPT" "$TMPDIR/composer.lock" "$TMPDIR/ignore" >/dev/null 2>&1
for mono in symfony/symfony symfony/polyfill web-auth/webauthn-framework async-aws/aws; do
  grep -qx "$mono" "$TMPDIR/asked"; check "  and $mono, a monorepo publishing for its splits, is read unless told otherwise" $? 0 "$(cat "$TMPDIR/asked")" ""
done

lock "acme/lib@2.0.0@acme/lib"
feed acme/lib "GHSA-eeee-eeee-eeee|acme/lib|null"
out=$(run); check "an advisory naming no range is said, never skipped" $? 1 "$out" "GHSA-eeee-eeee-eeee names no version range"
lock "acme/lib@2.0.0@acme/lib" "gone/lib@1.0.0@gone/lib"
feed acme/lib
out=$(run); check "a repository that cannot be read fails the run" $? 1 "$out" "gone/lib: could not be read"
[[ "$out" != *"advisory-feeds: OK"* ]]; check "  and is never told as read" $? 0 "$out" ""

printf '{"packages":[],"packages-dev":[]}\n' > "$TMPDIR/composer.lock"
out=$(run); check "a lock naming no GitHub package reads nothing and fails" $? 2 "$out" "found no package"

echo "advisory feeds: $pass passed, $fail failed"
((fail == 0))
