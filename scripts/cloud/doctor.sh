#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# make cloud-doctor: what a run needs that a claude.ai cloud session can lack against the developer's computer, each
# checked and said with its fix, so it never turns up later as a red that reads like the code's (docs/SPEC.md § 7, a
# cloud session never silently worse than the computer). Run before a session's first `make check`; it also runs on the
# computer, where the cloud's own checks say so.
#   Docker          the daemon answers
#   history         the whole history and its release tags (decision-stamps, signed-commits and additive-migrations
#                   read them; a cloud clone holds 50 commits and no tag)
#   author          commits go out under the developer's name, never the harness's placeholder
#   proxy CA        in a cloud session, the file CCS_EXTRA_CA_FILE names (the agent proxy re-terminates TLS)
#   images          the toolchain's two images
#   bootstrap       what `make bootstrap` and `make playwright-browser` leave (.claude/cloud-setup.sh starts them)
#   Docker Hub      pulls left: it answers 429 to anonymous pulls here, and BuildKit asks it on every build
#   toolchain       git, curl and node inside the toolchain reach the network through the proxy's CA
# Exit 1 when any check fails. Usage: doctor.sh [--root DIR]   (TWES_BOOTSTRAP_DIR: where the setup's markers are)
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
marks=${TWES_BOOTSTRAP_DIR:-/tmp}/twes-in-bootstrap
failed=0 checks=0
ok() { checks=$((checks + 1)); echo "cloud-doctor: ok   $1"; }
bad() { checks=$((checks + 1)); failed=$((failed + 1)); echo "cloud-doctor: FAIL $1"; }

if docker info >/dev/null 2>&1; then ok "Docker: the daemon answers"; else
  bad "Docker: the daemon does not answer — start it once: dockerd >/tmp/dockerd.log 2>&1 & (claude-code-setup cloud/machine-notes.md), then wait for docker info"; fi

if [[ "$(git -C "$root" rev-parse --is-shallow-repository 2>&1)" != false ]]; then
  bad "history: a shallow clone — git fetch --unshallow --tags origin"
elif [[ -z "$(git -C "$root" tag -l 'v[0-9][0-9][0-9][0-9].[0-9][0-9].[0-9][0-9].*')" ]]; then
  bad "history: no release tag — git fetch --tags origin (three gates read the history since the newest one)"
else
  ok "history: whole, $(git -C "$root" tag -l 'v[0-9]*' | wc -l) release tag(s)"
fi

author=$(git -C "$root" config user.email 2>/dev/null)
expected=$(git -C "$root" log -1 --format=%ae origin/master 2>/dev/null || git -C "$root" log -1 --format=%ae HEAD)
if [[ -z "$author" || "$author" == noreply@anthropic.com || "$author" != "$expected" ]]; then
  bad "author: commits would go out as « ${author:-nobody} », not as $expected — git config user.name and user.email as CLAUDE.md § Git says"
else
  ok "author: $author"
fi

if [[ "${CLAUDE_CODE_REMOTE:-}" != true ]]; then
  ok "proxy CA: not a cloud session, none needed"
elif [[ -n "${CCS_EXTRA_CA_FILE:-}" && -s "$CCS_EXTRA_CA_FILE" ]]; then
  ok "proxy CA: $CCS_EXTRA_CA_FILE"
else
  bad "proxy CA: CCS_EXTRA_CA_FILE names no file (« ${CCS_EXTRA_CA_FILE:-} ») — select claude-code-setup as the session's second repository (CLAUDE.md, a cloud session)"
fi

images=yes
if docker image inspect twes-in-tools twes-in-web-tools >/dev/null 2>&1; then ok "images: twes-in-tools and twes-in-web-tools"; else
  images=no; bad "images: the toolchain's images are missing — make bootstrap"; fi

# The setup's run is alive while its process runs on this boot (.claude/cloud-setup.sh writes both in its mark); a
# mark not yet written is a run starting, for its first minute.
setup_alive() {
  local pid on
  if read -r pid on <"$marks.running/pid" 2>/dev/null; then
    [[ "$on" == "$(cat /proc/sys/kernel/random/boot_id 2>/dev/null)" ]] && kill -0 "$pid" 2>/dev/null
  else
    [[ -n "$(find "$marks.running" -maxdepth 0 -mmin -1 2>/dev/null)" ]]
  fi
}
missing=()
[[ -e "$root/api/vendor/autoload.php" ]] || missing+=(api/vendor)
[[ -e "$root/web/node_modules/.twes-installed" ]] || missing+=(web/node_modules)
for dir in web/src/app/api web/src/generated var/cache/ms-playwright; do
  [[ -n "$(ls -A "$root/$dir" 2>/dev/null)" ]] || missing+=("$dir")
done
if ((${#missing[@]} == 0)); then
  ok "bootstrap: packages, generated types, icon font and browsers"
elif [[ -d "$marks.running" ]] && setup_alive; then
  bad "bootstrap: ${missing[*]} not there yet, the setup's run still running — wait for $marks.done (or .failed), $marks.log says how far"
elif [[ -d "$marks.running" ]]; then
  bad "bootstrap: ${missing[*]} missing, the setup's run died (its machine restarted?) — make bootstrap, then make playwright-browser"
elif [[ -e "$marks.failed" ]]; then
  bad "bootstrap: ${missing[*]} missing, the setup's run failed with exit $(cat "$marks.failed") — read $marks.log, then make bootstrap and make playwright-browser"
else
  bad "bootstrap: ${missing[*]} missing — make bootstrap, then make playwright-browser"
fi

# Docker's documented way to read the pulls left: an anonymous token, then a HEAD of ratelimitpreview/test, which
# spends none. A limit not said means none is set.
ca=(); [[ -n "${CCS_EXTRA_CA_FILE:-}" && -s "$CCS_EXTRA_CA_FILE" ]] && ca=(--cacert "$CCS_EXTRA_CA_FILE")
if token=$(curl -sS --max-time 15 "${ca[@]}" 'https://auth.docker.io/token?service=registry.docker.io&scope=repository:ratelimitpreview/test:pull' 2>&1) \
  && token=$(sed -n 's/.*"token" *: *"\([^"]*\)".*/\1/p' <<<"$token") && [[ -n "$token" ]] \
  && headers=$(curl -sS --max-time 15 "${ca[@]}" -I -H "Authorization: Bearer $token" https://registry-1.docker.io/v2/ratelimitpreview/test/manifests/latest 2>&1); then
  left=$(tr -d '\r' <<<"$headers" | sed -n 's/^ratelimit-remaining: *\([0-9]*\).*/\1/Ip')
  if [[ -z "$left" ]]; then ok "Docker Hub: no pull limit said"
  elif ((left > 0)); then ok "Docker Hub: $left pulls left"
  else bad "Docker Hub: 0 pulls left — a build asking it answers 429: run a target whose toolchain images are built with KEEP_IMAGES=1 (make gate KEEP_IMAGES=1), or wait out the window"; fi
else
  bad "Docker Hub: could not ask Docker Hub ($(head -n 1 <<<"${headers:-$token}")) — check the network before reading a build's red"
fi

if [[ "$images" == no ]]; then
  bad "toolchain: not probed, its images are missing — make bootstrap"
else
  origin=$(git -C "$root" remote get-url origin 2>/dev/null || echo https://github.com)
  said=$( { make --no-print-directory -C "$root" -o tools-image tools \
      CMD="git ls-remote $origin HEAD >/dev/null 2>&1 && echo probe git ok || echo probe git fail; curl -sSf -o /dev/null https://repo.packagist.org/packages.json && echo probe curl ok || echo probe curl fail"
    make --no-print-directory -C "$root" -o web-tools-image web-tools \
      CMD="node -e \"fetch('https://registry.npmjs.org/').then(() => console.log('probe node ok'), () => console.log('probe node fail'))\""
  } 2>/dev/null | grep '^probe ')
  refused=() unsaid=()
  for tool in git curl node; do
    if grep -qx "probe $tool fail" <<<"$said"; then refused+=("$tool")
    elif ! grep -qx "probe $tool ok" <<<"$said"; then unsaid+=("$tool"); fi
  done
  if ((${#refused[@]})); then
    bad "toolchain: ${refused[*]} could not reach the network — the proxy's CA does not reach it: compose.extra-ca.yaml and \$(EXTRA_CA_RUN) (scripts/gates/build-extra-ca.sh)"
  elif ((${#unsaid[@]})); then
    bad "toolchain: the probe of ${unsaid[*]} said nothing — run make tools CMD=true to see why"
  else
    ok "toolchain: git, curl and node reach the network"
  fi
fi

if ((failed)); then
  echo "cloud-doctor: FAIL — $failed of $checks checks"
  exit 1
fi
echo "cloud-doctor: OK — $checks checks"
