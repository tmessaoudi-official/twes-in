#!/usr/bin/env bash
#
# Fetch the API tier's standalone tools, pinned by SHA-256.
#
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# WHY THIS EXISTS, and it is not a preference.
#
# PHPUnit and php-cs-fixer would normally be Composer dev-dependencies. In the Claude Code cloud
# container this project is developed in, GitHub egress is restricted by organisation policy to this
# repository alone, so every Composer `dist` URL — which for a GitHub-hosted package means
# api.github.com, codeload.github.com or github.com — returns HTTP 403:
#
#     $ curl -o /dev/null -w '%{http_code}' \
#         https://api.github.com/repos/symfony/uid/zipball/7393f157...
#     403
#     {"message":"GitHub access to this repository is not enabled for this session."}
#
# Both tools also publish official phars from their own domains, which are reachable. So the API's
# tests and style check run today, from vendor-official downloads, while `composer install` stays
# blocked. `composer.lock` is committed and fully pinned, so a network that can reach Composer's dist
# URLs needs only `composer install` and this script becomes redundant.
#
# The hashes are the point. A phar downloaded without verification is arbitrary code from the network,
# and this repository's whole licensing position depends on knowing the provenance of what it runs.
# Update a hash only alongside a deliberate version bump.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly REPO_ROOT
readonly TOOLS_DIR="$REPO_ROOT/api/tools/bin"

# name|url|sha256
readonly -a TOOLS=(
  "phpunit-12.phar|https://phar.phpunit.de/phpunit-12.phar|c8af6400e0cd81da027e2b4d6387733983f1f97f64fe80ae639c84b421e9cd55"
  "php-cs-fixer.phar|https://cs.symfony.com/download/php-cs-fixer-v3.phar|918b9cc56969d4aa8a8d3e6520a9872b7491614e9b1d4387fb44c9b5f8717ba0"
)

mkdir -p "$TOOLS_DIR"

for entry in "${TOOLS[@]}"; do
  IFS='|' read -r name url expected <<< "$entry"
  target="$TOOLS_DIR/$name"

  if [[ -f "$target" ]] && [[ "$(sha256sum "$target" | cut -d' ' -f1)" == "$expected" ]]; then
    printf 'fetch-tools: %s already present and verified.\n' "$name"
    continue
  fi

  printf 'fetch-tools: downloading %s\n' "$name"
  curl -fsSL -o "$target.download" "$url"

  actual="$(sha256sum "$target.download" | cut -d' ' -f1)"

  if [[ "$actual" != "$expected" ]]; then
    rm -f "$target.download"
    printf 'fetch-tools: FAIL — %s hash mismatch.\n  expected %s\n  got      %s\n' \
      "$name" "$expected" "$actual" >&2
    printf 'Either the upstream release changed, or the download was tampered with. Do not "fix" this\n' >&2
    printf 'by updating the hash without checking which.\n' >&2
    exit 1
  fi

  mv "$target.download" "$target"
  chmod +x "$target"
  printf 'fetch-tools: %s verified.\n' "$name"
done

printf 'fetch-tools: OK — tools in %s\n' "${TOOLS_DIR#"$REPO_ROOT"/}"
