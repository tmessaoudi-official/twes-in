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
# and hands it to make; without it, it goes on and says so. Off the cloud it does nothing. It always exits 0: a hook's
# failure would only be logged, and a failure of the run is the session's to read.
set -u
[[ "${CLAUDE_CODE_REMOTE:-}" == true ]] || exit 0
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd) || exit 0
dir=${TWES_BOOTSTRAP_DIR:-/tmp}
mark=$dir/twes-in-bootstrap
ca=${CCS_EXTRA_CA_FILE-/root/.ccr/ca-bundle.crt}
wait=${TWES_CA_WAIT:-600}

mkdir "$mark.running" 2>/dev/null || exit 0
rm -f "$mark.done" "$mark.failed"

run() {
  local waited=0 status=0
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
  cd "$root" && make bootstrap && make playwright-browser
  status=$?
  if ((status == 0)); then
    touch "$mark.done"
  else
    echo "$status" > "$mark.failed"
    echo "cloud-setup: failed with exit $status"
  fi
  rmdir "$mark.running"
}

# Its own session and no descriptor of the hook's: the setup's budget does not wait for it.
export -f run
export root mark ca wait
setsid bash -c run </dev/null >"$mark.log" 2>&1 &
exit 0
