#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The weekly image scan (.github/workflows/image-scan.yml; docs/UPDATE.md): every image a shop or a server runs, read by
# Trivy for its operating system's and its binaries' known vulnerabilities. Fails on a CRITICAL or HIGH that has a fix,
# unless .trivyignore names it with its verdict and an expiry date (exp:YYYY-MM-DD, after which it fails again). One
# without a fix is shown and does not fail: there is nothing to take yet.
#   ours         the three published images at :master, <prefix>/twes-in-{api,web,database}, as scripts/release/publish.sh
#                names them
#   third-party  every image the compose files of a shop or a server name by digest (scripts/gates/release-overlay.sh
#                requires one for each it starts), read from the files, so a bump moves one place
# Trivy itself is pinned by its index digest and run as an image, never through aquasecurity/trivy-action: the March
# 2026 compromise (GHSA-69fq-xp46-6x23) reached the binary 0.69.4, the images 0.69.5 and 0.69.6, and the action's tags.
# 0.76.1 is past it and past what a crafted database could do to the scanner itself (GHSA-mcj4-mphf-j9ff, a path
# traversal, fixed in 0.71.1). It reads the registries itself (--image-src remote), so it is handed no Docker socket. Its
# vulnerability database is fetched at each run and is not pinned: the job holds no secret.
# Usage: image-scan.sh <registry prefix, such as ghcr.io/owner>      (TRIVY_CACHE=<dir> keeps the database between runs)
set -uo pipefail
TRIVY_IMAGE=aquasec/trivy:0.76.2@sha256:8f6bb947dbc528902e11f2f5be52f7b519f6a39d5d148f41166f2040499768fb
[[ $# -eq 1 && -n "$1" ]] || { echo "Usage: image-scan.sh <registry prefix>" >&2; exit 2; }
prefix=${1%/}
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "$TRIVY_IMAGE" =~ ^aquasec/trivy:[0-9.]+@sha256:[0-9a-f]{64}$ ]] || { echo "image-scan: Trivy is not pinned by its digest" >&2; exit 2; }
[[ -f "$root/.trivyignore" ]] || { echo "image-scan: found no .trivyignore" >&2; exit 2; }

mapfile -t third < <(grep -hoE '^[[:space:]]*image: [^[:space:]"]+@sha256:[0-9a-f]{64}' \
  "$root/compose.yaml" "$root/compose.deploy.yaml" "$root/compose.onprem.yaml" | sed 's/^[[:space:]]*image: //' | sort -u)
((${#third[@]})) || { echo "image-scan: found no image named by digest in the compose files: the scan would read nothing of theirs" >&2; exit 2; }
images=("$prefix/twes-in-api:master" "$prefix/twes-in-web:master" "$prefix/twes-in-database:master" "${third[@]}")

cache=${TRIVY_CACHE:-}
own_cache=no
if [[ -z "$cache" ]]; then cache=$(mktemp -d); own_cache=yes; fi
# docker reads a relative path as a volume's name and refuses it: the folder is named from where the scan runs.
[[ "$cache" == /* ]] || cache="$PWD/$cache"
cleanup() { [[ "$own_cache" == yes ]] && rm -rf "$cache"; }
trap cleanup EXIT

failed=() found=no
for image in "${images[@]}"; do
  echo "::group::$image"
  # Exit 3 says what was found, so a scan that could not run (exit 1: a registry or the database unreachable) is told apart.
  docker run --rm -v "$root/.trivyignore:/scan/.trivyignore:ro" -v "$cache:/root/.cache/trivy" "$TRIVY_IMAGE" \
    image --image-src remote --scanners vuln --severity CRITICAL,HIGH --ignore-unfixed \
    --ignorefile /scan/.trivyignore --exit-code 3 --no-progress --skip-version-check "$image"
  status=$?
  echo "::endgroup::"
  case $status in
    0) echo "image-scan: $image — nothing fixable at HIGH or CRITICAL outside .trivyignore" ;;
    3) failed+=("$image: a fixable CRITICAL or HIGH (the table above)"); found=yes ;;
    *) failed+=("$image: Trivy could not scan it (exit $status)") ;;
  esac
done

if ((${#failed[@]})); then
  printf 'image-scan: FAIL — %d of %d image(s):\n' "${#failed[@]}" "${#images[@]}"
  printf '  %s\n' "${failed[@]}"
  [[ "$found" == no ]] || echo "Move the image to a fixed release (docs/UPDATE.md), or triage the finding into .trivyignore with its verdict and exp:."
  ! printf '%s\n' "${failed[@]}" | grep -q 'could not scan' \
    || echo "A scan that could not run says nothing of its image: settle its error above, then run it again."
  exit 1
fi
printf 'image-scan: OK — %d images, nothing fixable at HIGH or CRITICAL outside .trivyignore\n' "${#images[@]}"
