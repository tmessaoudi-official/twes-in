#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
DIGEST=$(printf 'a%.0s' {1..64})
# A checkout with the deploy script and the version script, committed and tagged as a release, and a docker that writes
# down how compose was called and with which images, pulls whatever it is given, and says every image was built from
# LABEL_REV.
repo() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/scripts/deploy" "$d/api" "$d/web"
  cp "$HERE/deploy/deploy.sh" "$d/scripts/deploy/"; cp "$HERE/build-version.sh" "$d/scripts/"
  echo a > "$d/api/a.php"; echo w > "$d/web/w.ts"
  git -C "$d" init -q && git -C "$d" add -A && git -C "$d" commit -qm release
  git -C "$d" tag v2026.10.12.1
  echo "$d"
}
mkdir -p "$TMPDIR/bin"
cat > "$TMPDIR/bin/docker" <<'EOF'
#!/usr/bin/env bash
case "$1" in
  pull) echo "${@: -1}" >> "$TMPDIR/docker.pulled" ;;
  image) printf '%s\n' "${LABEL_REV:-}" ;;
  compose)
    printf '%s\n' "API_BUILD_VERSION=${API_BUILD_VERSION:-}" "TWES_API_IMAGE=${TWES_API_IMAGE:-}" \
      "TWES_WEB_IMAGE=${TWES_WEB_IMAGE:-}" "TWES_DATABASE_IMAGE=${TWES_DATABASE_IMAGE:-}" "$@" > "$TMPDIR/docker.args"
    exit "${COMPOSE_EXIT:-0}" ;;
esac
EOF
chmod +x "$TMPDIR/bin/docker"
envfile() { local d f; d=$(mktemp -d); f="$d/deploy.env"; printf 'TWES_HOST=x\n%s' "${1:-}" > "$f"; chmod 600 "$f"; echo "$f"; }
staging_values() { envfile $'DEPLOY_ENV=staging\nBACKUP_S3_PREFIX=staging/\n'; }
release_lines() { # commit
  printf 'TWES_REVISION=%s\nTWES_API_IMAGE=ghcr.invalid/o/twes-in-api@sha256:%s\nTWES_WEB_IMAGE=ghcr.invalid/o/twes-in-web@sha256:%s\nTWES_DATABASE_IMAGE=ghcr.invalid/o/twes-in-database@sha256:%s\n' \
    "$1" "$DIGEST" "$DIGEST" "$DIGEST"
}
annotate() { # repo, message: the release tag again, annotated
  git -C "$1" tag -d v2026.10.12.1 >/dev/null; git -C "$1" tag -a v2026.10.12.1 -m "$2"
}
run() { rm -f "$TMPDIR/docker.args" "$TMPDIR/docker.pulled"; local d=$1; shift; (cd "$TMPDIR" && PATH="$TMPDIR/bin:$PATH" LABEL_REV="${LABEL_REV:-$(git -C "$d" rev-parse HEAD)}" bash "$d/scripts/deploy/deploy.sh" "$@" 2>&1); }
called() { [[ -f "$TMPDIR/docker.args" ]] && tr '\n' ' ' < "$TMPDIR/docker.args"; }
echo "deploy"

# --build: a self-hosted instance, built here from the release it checked out.
d=$(repo); e=$(envfile)
out=$(run "$d" --build "$e"); check "a clean release tag deploys" $? 0 "$out" "v2026.10.12.1"
check "with the four files and the instance's own values" 0 0 "$(called)" "--env-file $e -f compose.yaml -f compose.prod.yaml -f compose.backup.yaml -f compose.deploy.yaml up -d --build --wait"
check "built as the version git says it is" 0 0 "$(called)" "API_BUILD_VERSION=2026."

d=$(repo); e=$(envfile); echo b > "$d/api/b.php"; git -C "$d" add -A; git -C "$d" commit -qm after
out=$(run "$d" --build "$e"); check "a commit past the tag is refused" $? 1 "$out" "release tag"
[[ -z "$(called)" ]]; check "and nothing is built" $? 0 "" ""

d=$(repo); e=$(envfile); git -C "$d" tag -d v2026.10.12.1 >/dev/null; git -C "$d" tag beta
out=$(run "$d" --build "$e"); check "a tag that is not a release's is refused" $? 1 "$out" "release tag"

d=$(repo); e=$(envfile); echo x > "$d/api/untracked.php"
out=$(run "$d" --build "$e"); check "an untracked file, which the images would copy, is refused" $? 1 "$out" "api/untracked.php"

d=$(repo); e=$(envfile); echo changed >> "$d/web/w.ts"
out=$(run "$d" --build "$e"); check "a changed file is refused" $? 1 "$out" "web/w.ts"

d=$(repo); e=$(envfile); chmod 644 "$e"
out=$(run "$d" --build "$e"); check "values others may read are refused" $? 1 "$out" "600"
[[ -z "$(called)" ]]; check "and nothing is built" $? 0 "" ""

d=$(repo)
out=$(run "$d" --build "$TMPDIR/none.env"); check "a missing file is refused" $? 1 "$out" "none.env"

d=$(repo); e=$(staging_values)
out=$(run "$d" --build "$e"); check "staging's values never deploy a production instance" $? 1 "$out" "staging"

out=$(run "$d" "$e"); check "a way of deploying must be named" $? 2 "$out" "Usage"
out=$(run "$d" --build); check "the values file must be named" $? 2 "$out" "Usage"
out=$(run "$d" --staging "$e"); check "staging names its release file" $? 2 "$out" "Usage"

# --release: production, from the images the release tag names, nothing built.
d=$(repo); e=$(envfile); head=$(git -C "$d" rev-parse HEAD); annotate "$d" "twes-in v2026.10.12.1

$(release_lines "$head")"
out=$(run "$d" --release "$e"); check "an annotated release tag deploys its images" $? 0 "$out" "v2026.10.12.1"
check "by their digests, over the release overlay, building nothing" 0 0 "$(called)" "-f compose.deploy.yaml -f compose.release.yaml up -d --no-build --wait"
check "the api image is the tag's" 0 0 "$(called)" "TWES_API_IMAGE=ghcr.invalid/o/twes-in-api@sha256:$DIGEST"
check "the database image is the tag's" 0 0 "$(called)" "TWES_DATABASE_IMAGE=ghcr.invalid/o/twes-in-database@sha256:$DIGEST"
check "each image is pulled by its digest first" 0 0 "$(tr '\n' ' ' < "$TMPDIR/docker.pulled")" "ghcr.invalid/o/twes-in-web@sha256:$DIGEST"
check "and the release is written down beside the values" 0 0 "$(cat "$(dirname "$e")/releases/$head.env" 2>&1)" "TWES_WEB_IMAGE=ghcr.invalid/o/twes-in-web@sha256:$DIGEST"
check "  with a line of history" 0 0 "$(tail -1 "$(dirname "$e")/releases/history" 2>&1)" "deployed $head release"

d=$(repo); e=$(envfile)
out=$(run "$d" --release "$e"); check "a lightweight tag, which names no image, is refused" $? 1 "$out" "annotated"
[[ -z "$(called)" ]]; check "and nothing starts" $? 0 "" ""

d=$(repo); e=$(envfile); annotate "$d" "$(release_lines "$(git -C "$d" rev-parse HEAD)" | sed 's/^TWES_WEB_IMAGE=.*/TWES_WEB_IMAGE=ghcr.invalid\/o\/twes-in-web:latest/')"
out=$(run "$d" --release "$e"); check "an image named by a tag, not a digest, is refused" $? 1 "$out" "web image by its digest"

d=$(repo); e=$(envfile); annotate "$d" "$(release_lines "$(git -C "$d" rev-parse HEAD)" | sed 's/twes-in-database@/twes-in-api@/')"
out=$(run "$d" --release "$e"); check "another part's image in a part's place is refused" $? 1 "$out" "database image by its digest"

d=$(repo); e=$(envfile); annotate "$d" "$(release_lines "$(printf 'b%.0s' {1..40})")"
out=$(run "$d" --release "$e"); check "a tag naming another commit's images is refused" $? 1 "$out" "bbbbbbbb"

d=$(repo); e=$(envfile); annotate "$d" "$(release_lines "$(git -C "$d" rev-parse HEAD)")"
out=$(LABEL_REV=$(printf 'c%.0s' {1..40}) run "$d" --release "$e"); check "an image built from another commit than it claims is refused" $? 1 "$out" "built from cccccccc"
[[ -z "$(called)" ]]; check "and nothing starts" $? 0 "" ""

d=$(repo); e=$(staging_values); annotate "$d" "$(release_lines "$(git -C "$d" rev-parse HEAD)")"
out=$(run "$d" --release "$e"); check "staging's values never deploy production's release" $? 1 "$out" "staging"

d=$(repo); e=$(envfile); annotate "$d" "$(release_lines "$(git -C "$d" rev-parse HEAD)" | sed 's|ghcr.invalid/o/|localhost:5099/o/|')"
out=$(run "$d" --release "$e"); check "a registry named with its port is a registry" $? 0 "$out" "running"

# --staging: any commit master published, from the release file the registry gave, nothing built.
d=$(repo); e=$(staging_values); echo c > "$d/api/c.php"; git -C "$d" add -A; git -C "$d" commit -qm next; head=$(git -C "$d" rev-parse HEAD)
r="$TMPDIR/release.env"; release_lines "$head" > "$r"
out=$(run "$d" --staging "$e" "$r"); check "a published commit with no tag deploys on staging" $? 0 "$out" "$head"
check "  by its digests, building nothing" 0 0 "$(called)" "-f compose.release.yaml up -d --no-build --wait"
check "  and is written down" 0 0 "$(tail -1 "$(dirname "$e")/releases/history" 2>&1)" "deployed $head staging"

d=$(repo); e=$(envfile $'BACKUP_S3_PREFIX=staging/\n'); r="$TMPDIR/release.env"; release_lines "$(git -C "$d" rev-parse HEAD)" > "$r"
out=$(run "$d" --staging "$e" "$r"); check "values that do not say DEPLOY_ENV=staging are refused" $? 1 "$out" "DEPLOY_ENV=staging"

d=$(repo); e=$(envfile $'DEPLOY_ENV=staging\n'); r="$TMPDIR/release.env"; release_lines "$(git -C "$d" rev-parse HEAD)" > "$r"
out=$(run "$d" --staging "$e" "$r"); check "staging's backups go under their own prefix, never production's" $? 1 "$out" "BACKUP_S3_PREFIX=staging/"

d=$(repo); e=$(staging_values); r="$TMPDIR/release.env"; release_lines "$(git -C "$d" rev-parse HEAD~0)" > "$r"
echo d > "$d/api/d.php"; git -C "$d" add -A; git -C "$d" commit -qm later
out=$(run "$d" --staging "$e" "$r"); check "a checkout at another commit than the release is refused" $? 1 "$out" "git checkout --detach"

d=$(repo); e=$(staging_values); r="$TMPDIR/release.env"
{ release_lines "$(git -C "$d" rev-parse HEAD)"; echo "TWES_API_IMAGE=\$(touch $TMPDIR/ran)"; } > "$r"
out=$(run "$d" --staging "$e" "$r"); check "a release file is read as data, never run" $? 1 "$out" "api image by its digest"
[[ ! -e "$TMPDIR/ran" ]]; check "  and what it held did not run" $? 0 "" ""

d=$(repo); e=$(staging_values); r="$TMPDIR/release.env"; head=$(git -C "$d" rev-parse HEAD); release_lines "$head" > "$r"
out=$(COMPOSE_EXIT=1 run "$d" --staging "$e" "$r"); check "a start that fails fails the deploy" $? 1 "$out" "did not start"
check "  and is written down as failed, so a follower does not try it again and again" 0 0 "$(tail -1 "$(dirname "$e")/releases/history" 2>&1)" "failed $head staging"

# DEPLOY_EXTRA_FILES: make staging-check's own layers, taken under its compose project with --staging alone.
d=$(repo); e=$(staging_values); r="$TMPDIR/release.env"; release_lines "$(git -C "$d" rev-parse HEAD)" > "$r"
out=$(COMPOSE_PROJECT_NAME=twes-staging-check DEPLOY_EXTRA_FILES=/x/a.yaml:/x/b.yaml run "$d" --staging "$e" "$r"); check "the staging check adds its own layers" $? 0 "$out" "running"
check "  after the release overlay, in order" 0 0 "$(called)" "-f compose.release.yaml -f /x/a.yaml -f /x/b.yaml up -d --no-build --wait"

d=$(repo); e=$(staging_values); r="$TMPDIR/release.env"; release_lines "$(git -C "$d" rev-parse HEAD)" > "$r"
out=$(COMPOSE_PROJECT_NAME=twes DEPLOY_EXTRA_FILES=/x/a.yaml run "$d" --staging "$e" "$r"); check "extra layers under any other project are refused" $? 1 "$out" "DEPLOY_EXTRA_FILES"
[[ -z "$(called)" ]]; check "  and nothing starts" $? 0 "" ""

d=$(repo); e=$(envfile); annotate "$d" "$(release_lines "$(git -C "$d" rev-parse HEAD)")"
out=$(COMPOSE_PROJECT_NAME=twes-staging-check DEPLOY_EXTRA_FILES=/x/a.yaml run "$d" --release "$e"); check "extra layers never reach a production deployment" $? 1 "$out" "DEPLOY_EXTRA_FILES"

d=$(repo); e=$(envfile)
out=$(COMPOSE_PROJECT_NAME=twes-staging-check DEPLOY_EXTRA_FILES=/x/a.yaml run "$d" --build "$e"); check "  nor a built one" $? 1 "$out" "DEPLOY_EXTRA_FILES"

echo "deploy: $pass passed, $fail failed"
((fail == 0))
