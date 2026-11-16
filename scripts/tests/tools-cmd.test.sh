#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# make tools CMD='…' and make web-tools CMD='…' run the command as it was written: quotes of either kind and runs of
# spaces reach the container's shell untouched, and the command's exit is make's. A stand-in docker on PATH runs what
# would run in the container, from a folder of its own, so nothing reaches Docker, npm or the tree.
set -uo pipefail
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# The stand-in: a build answers nothing; a run executes what follows the service's name, from a folder where the
# web toolchain's install mark is newer than its lock, so the install step is skipped as on a computer already set up.
mkdir -p "$TMPDIR/bin" "$TMPDIR/box/node_modules"
cat > "$TMPDIR/bin/docker" <<'EOF'
#!/usr/bin/env bash
args=("$@") running=0
for i in "${!args[@]}"; do
  [[ "${args[$i]}" == run ]] && running=1
  if ((running)) && [[ "${args[$i]}" == tools || "${args[$i]}" == web-tools ]]; then
    cd "$DOCKER_STANDIN_BOX" && exec "${args[@]:$((i+1))}"
  fi
done
exit 0
EOF
chmod +x "$TMPDIR/bin/docker"
: > "$TMPDIR/box/package-lock.json"; sleep 1; : > "$TMPDIR/box/node_modules/.twes-installed"
mk() { (cd "$ROOT" && PATH="$TMPDIR/bin:$PATH" DOCKER_STANDIN_BOX="$TMPDIR/box" make --no-print-directory -s "$@" 2>&1 < /dev/null); }
echo "make tools / web-tools CMD"

for target in tools web-tools; do
  out=$(mk "$target" CMD="printf '%s|\n' 'it is' two"); check "$target: single quotes in CMD reach the shell" $? 0 "$out" "it is|"
  out=$(mk "$target" CMD='echo "a  b"'); check "$target: double quotes keep their spaces" $? 0 "$out" "a  b"
  out=$(mk "$target" CMD='echo ran; exit 3'); check "$target: the command's failure is make's" $? 2 "$out" "ran"
done

# make bootstrap makes what make check reads but no clone carries: the API's vendor folder, the web's packages, the
# generated API types and the icon font. Read as make would run it (-n): carried out, it would install on this machine.
echo "make bootstrap"
out=$(cd "$ROOT" && make --no-print-directory -n bootstrap 2>&1 < /dev/null); status=$?
# The API's half runs as a target inside the tools container, whose own lines are read too.
[[ "$out" == *"make --no-print-directory in-api-openapi"* ]] && out+=$'\n'$(cd "$ROOT" && make --no-print-directory -n in-api-openapi 2>&1 < /dev/null)
for step in "composer install" "api:openapi:export" "npm ci" "npm run api:types" "npm run icons"; do
  check "bootstrap runs $step" "$status" 0 "$out" "$step"
done

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
