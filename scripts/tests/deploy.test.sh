#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A checkout with the deploy script and the version script, committed and tagged as a release, and a docker that only
# writes down how it was called.
repo() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/scripts/deploy" "$d/api" "$d/web"
  cp "$HERE/deploy/deploy.sh" "$d/scripts/deploy/"; cp "$HERE/build-version.sh" "$d/scripts/"
  echo a > "$d/api/a.php"; echo w > "$d/web/w.ts"
  git -C "$d" init -q && git -C "$d" add -A && git -C "$d" -c user.name=t -c user.email=t@t commit -qm release
  git -C "$d" tag v2026.10.12.1
  echo "$d"
}
mkdir -p "$TMPDIR/bin"
printf '#!/usr/bin/env bash\nprintf "%%s\\n" "API_BUILD_VERSION=$API_BUILD_VERSION" "$@" > "%s/docker.args"\n' "$TMPDIR" > "$TMPDIR/bin/docker"
chmod +x "$TMPDIR/bin/docker"
envfile() { local f; f=$(mktemp); printf 'TWES_HOST=x\n' > "$f"; chmod 600 "$f"; echo "$f"; }
run() { rm -f "$TMPDIR/docker.args"; PATH="$TMPDIR/bin:$PATH" bash "$1/scripts/deploy/deploy.sh" "$2" 2>&1; }
called() { [[ -f "$TMPDIR/docker.args" ]] && tr '\n' ' ' < "$TMPDIR/docker.args"; }
echo "deploy"

d=$(repo); e=$(envfile)
out=$(run "$d" "$e"); check "a clean release tag deploys" $? 0 "$out" "v2026.10.12.1"
check "with the four files and the instance's own values" 0 0 "$(called)" "--env-file $e -f compose.yaml -f compose.prod.yaml -f compose.backup.yaml -f compose.deploy.yaml up -d --build --wait"
check "built as the version git says it is" 0 0 "$(called)" "API_BUILD_VERSION=2026."

d=$(repo); e=$(envfile); echo b > "$d/api/b.php"; git -C "$d" add -A; git -C "$d" -c user.name=t -c user.email=t@t commit -qm after
out=$(run "$d" "$e"); check "a commit past the tag is refused" $? 1 "$out" "release tag"
[[ -z "$(called)" ]]; check "and nothing is built" $? 0 "" ""

d=$(repo); e=$(envfile); git -C "$d" tag -d v2026.10.12.1 >/dev/null; git -C "$d" tag beta
out=$(run "$d" "$e"); check "a tag that is not a release's is refused" $? 1 "$out" "release tag"

d=$(repo); e=$(envfile); echo x > "$d/api/untracked.php"
out=$(run "$d" "$e"); check "an untracked file, which the images would copy, is refused" $? 1 "$out" "api/untracked.php"

d=$(repo); e=$(envfile); echo changed >> "$d/web/w.ts"
out=$(run "$d" "$e"); check "a changed file is refused" $? 1 "$out" "web/w.ts"

d=$(repo); e=$(envfile); chmod 644 "$e"
out=$(run "$d" "$e"); check "values others may read are refused" $? 1 "$out" "600"
[[ -z "$(called)" ]]; check "and nothing is built" $? 0 "" ""

d=$(repo)
out=$(run "$d" "$TMPDIR/none.env"); check "a missing file is refused" $? 1 "$out" "none.env"

out=$(PATH="$TMPDIR/bin:$PATH" bash "$d/scripts/deploy/deploy.sh" 2>&1); check "the file must be named" $? 2 "$out" "Usage"

echo "deploy: $pass passed, $fail failed"
((fail == 0))
