#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/build-version.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
refuses() { if [[ "$4" != *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (found '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# Commits a change to one file at a moment given in UTC.
at() {
  local d=$1 when=$2 file=$3
  mkdir -p "$d/$(dirname "$file")"; echo "$when" >> "$d/$file"
  git -C "$d" add -A
  GIT_COMMITTER_DATE="$when" GIT_AUTHOR_DATE="$when" git -C "$d" -c user.name=t -c user.email=t@t commit -qm "$file at $when"
}
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; echo "$d"; }
short() { git -C "$1" rev-parse --short=8 HEAD; }
echo "build-version"

d=$(repo)
at "$d" 2026-10-06T10:00:00Z web/a.ts
at "$d" 2026-10-07T07:00:00Z web/a.ts
at "$d" 2026-10-07T08:00:00Z infra/web/nginx.conf
at "$d" 2026-10-07T09:00:00Z web/b.ts
web=$(short "$d")
at "$d" 2026-10-07T10:00:00Z api/x.php
api=$(short "$d")
out=$(bash "$SCRIPT" --root "$d" web); check "the web counts its own changes of the day, infra/web/ included" $? 0 "$out" "2026.10.07.3 $web"
out=$(bash "$SCRIPT" --root "$d" api); check "the API is versioned from its own changes alone" $? 0 "$out" "2026.10.07.1 $api"

at "$d" 2026-10-07T22:30:00Z web/c.ts
out=$(bash "$SCRIPT" --root "$d" web); check "the day is Paris's: 22:30 UTC is already the next day" $? 0 "$out" "2026.10.08.1 "
out=$(bash "$SCRIPT" --root "$d" api); check "a web change never moves the API's version" $? 0 "$out" "2026.10.07.1 $api"

echo dirty >> "$d/web/a.ts"
out=$(bash "$SCRIPT" --root "$d" web); check "an uncommitted change says the build is not the commit" $? 0 "$out" "2026.10.08.1-dirty "
out=$(bash "$SCRIPT" --root "$d" api); refuses "and leaves the other part clean" $? 0 "$out" "dirty"
git -C "$d" checkout -q -- web/a.ts; echo new > "$d/api/new.php"
out=$(bash "$SCRIPT" --root "$d" api); check "an untracked file is uncommitted too" $? 0 "$out" "2026.10.07.1-dirty $api"

out=$(bash "$SCRIPT" --root "$d" --env)
check "--env names the four values compose and CI pass to the images" $? 0 "$out" "WEB_BUILD_VERSION=2026.10.08.1
WEB_BUILD_COMMIT=$(git -C "$d" log -1 --format=%h --abbrev=8 -- web infra/web)
API_BUILD_VERSION=2026.10.07.1-dirty
API_BUILD_COMMIT=$api"

out=$(bash "$SCRIPT" --root "$d" docs 2>&1); check "an unknown part is refused" $? 2 "$out" "web or api"

c=$(mktemp -d); rmdir "$c"; git clone -q --depth 1 "file://$d" "$c"
out=$(bash "$SCRIPT" --root "$c" web 2>&1); check "a shallow clone is refused: it cannot count the day's changes" $? 1 "$out" "shallow"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
