#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Run by a claude.ai cloud environment's setup (claude-code-setup, cloud/setup.sh), as root from the repository's root,
# inside a budget of 120 seconds shared by every repository's hook: what a fresh clone needs before `make check` (the
# toolchain's images, the API's and the web's packages, the generated types, the icon font, then the browsers) takes
# about five minutes, so it starts here detached and this returns at once (docs/SPEC.md § 7, the slow first steps).
# Its outcome is a file a session reads before its first `make check` (CLAUDE.md, a cloud session):
#   <dir>/twes-in-bootstrap.running   a directory while it runs (made atomically: a second start starts nothing)
#   <dir>/twes-in-bootstrap.done      it ended well
#   <dir>/twes-in-bootstrap.failed    it failed, holding the exit; <dir>/twes-in-bootstrap.log says why
# None of them: it never ran here (an environment restored from its cache keeps files, not processes): run
# `make bootstrap` then `make playwright-browser` yourself. <dir> is /tmp (TWES_BOOTSTRAP_DIR for the test).
# The proxy's CA is written by the harness after setup, so the run waits for it (TWES_CA_WAIT seconds, 600 by default)
# and hands it to make; without it, it goes on and says so. A shallow clone is fetched whole with its tags first, since
# three gates read the history (TWES_BOOTSTRAP_ROOT names another repository for the test). Off the cloud it does
# nothing. It always exits 0: a hook's
# failure would only be logged, and a failure of the run is the session's to read.
set -u
[[ "${CLAUDE_CODE_REMOTE:-}" == true ]] || exit 0
root=${TWES_BOOTSTRAP_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)} || exit 0
dir=${TWES_BOOTSTRAP_DIR:-/tmp}
mark=$dir/twes-in-bootstrap
ca=${CCS_EXTRA_CA_FILE-/root/.ccr/ca-bundle.crt}
wait=${TWES_CA_WAIT:-600}

# A run's mark holds its process and the machine's boot (the run writes it first): one killed with its machine (a worker
# restart, an environment restored from its cache) is read as dead, never waited for. A mark not yet written is a run
# starting, for its first minute. scripts/cloud/doctor.sh reads it the same way. Its limit: a dead run's number taken
# by another process on the same boot reads as alive.
boot=$(cat /proc/sys/kernel/random/boot_id 2>/dev/null)
alive() {
  local pid on
  if read -r pid on <"$mark.running/pid" 2>/dev/null; then
    [[ "$on" == "$boot" ]] && kill -0 "$pid" 2>/dev/null
  else
    [[ -n "$(find "$mark.running" -maxdepth 0 -mmin -1 2>/dev/null)" ]]
  fi
}
died=
if ! mkdir "$mark.running" 2>/dev/null; then
  alive && exit 0
  died=$(cat "$mark.running/pid" 2>/dev/null || echo "no process written, older than a minute")
  rm -rf "$mark.running"
  mkdir "$mark.running" 2>/dev/null || exit 0
fi
rm -f "$mark.done" "$mark.failed"

run() {
  local waited=0 status=0
  echo "$$ $boot" >"$mark.running/pid"
  [[ -n "$died" ]] && echo "cloud-setup: a run that died left its mark (process and boot: $died): started again"
  if [[ -n "$ca" ]]; then
    while [[ ! -s "$ca" ]] && ((waited < wait)); do sleep 1; waited=$((waited + 1)); done
  fi
  if [[ -n "$ca" && -s "$ca" ]]; then
    export CCS_EXTRA_CA_FILE=$ca
    echo "cloud-setup: the proxy CA is $ca"
  else
    export CCS_EXTRA_CA_FILE=
    echo "cloud-setup: no proxy CA after ${waited}s: going on without one"
  fi
  # A cloud clone is shallow and holds no tag; the gates reading the history (decision-stamps, signed-commits,
  # additive-migrations) refuse it, so it is fetched whole first. git reads the proxy's CA through its own variable.
  if [[ "$(git -C "$root" rev-parse --is-shallow-repository 2>&1)" == true ]]; then
    if ${CCS_EXTRA_CA_FILE:+env GIT_SSL_CAINFO="$CCS_EXTRA_CA_FILE"} git -C "$root" fetch --quiet --unshallow --tags origin; then
      echo "cloud-setup: the whole history fetched, with its tags"
    else
      echo "cloud-setup: the whole history could not be fetched (exit $?): the gates reading it will refuse this clone"
    fi
  fi
  cd "$root" && make bootstrap && make playwright-browser
  status=$?
  if ((status == 0)); then
    touch "$mark.done"
  else
    echo "$status" > "$mark.failed"
    echo "cloud-setup: failed with exit $status"
  fi
  rm -rf "$mark.running"
}

# Its own session and no descriptor of the hook's: the setup's budget does not wait for it.
export -f run
export root mark ca wait boot died
setsid bash -c run </dev/null >"$mark.log" 2>&1 &
exit 0
