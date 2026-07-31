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
#
# Note the URLs. PHPUnit's is VERSION-PINNED (phpunit-12.5.33.phar), not the moving `phpunit-12.phar`:
# with a moving URL, the next 12.5.x release turns a fresh clone's setup into a hard failure whose own
# message says not to fix it by updating the hash. php-cs-fixer publishes no versioned URL on that host
# (both `php-cs-fixer-v3.95.17.phar` and `-v3.95.18.phar` are 404s [Verified: `curl -o /dev/null -w '%{http_code}'`
# → 404 for the versioned name, 200 for the moving one]), so for that one the moving URL is forced and a version
# bump surfaces here as a mismatch.
#
# **THAT MISMATCH IS A TASK, NOT A RESTING STATE**, and the previous wording — "which is the correct place for it
# to surface" — read as though surfacing were the whole story. It is not: until the pin is bumped, a FRESH
# container cannot obtain php-cs-fixer at all, so `composer gate`'s style step is unrunnable there while every
# existing checkout stays green off its already-downloaded phar. Round 17 found exactly that, four commits into a
# session, because the failure is invisible to anyone who already has the file.
#
# The procedure when it fires, which is what "a deliberate version bump" above means concretely: fetch the phar,
# run `--version` and record that version in the entry's comment, run `check` over the repo and confirm the
# verdict is unchanged (a bump that alters a style rule is a separate decision from a bump that restores the
# download), then update the hash. Do NOT point this at a mirror or a third-party rebuild — that is a provenance
# decision this project cannot make casually, for the reason § "Licensing invariants" gives.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly REPO_ROOT
readonly TOOLS_DIR="$REPO_ROOT/api/tools/bin"

# name|url|sha256
readonly -a TOOLS=(
  "phpunit-12.phar|https://phar.phpunit.de/phpunit-12.5.33.phar|c8af6400e0cd81da027e2b4d6387733983f1f97f64fe80ae639c84b421e9cd55"
  # php-cs-fixer 3.95.18. The VERSION is written here even though the URL cannot carry it, so the pin identifies
  # an artifact rather than only asserting a hash — otherwise a mismatch tells you the bytes changed and nothing
  # about what they changed to. Bumped at round 17: the pin held 3.95.17's hash while the host served 3.95.18, so
  # a FRESH container could not obtain php-cs-fixer at all and the style gate was unrunnable there. Verified
  # before bumping: the served phar reports `PHP CS Fixer 3.95.18 (d1fc711)`, and `check` over this repo reports
  # `Found 0 of 69 files that can be fixed` — same verdict as 3.95.17, so the bump changes no style rule.
  "php-cs-fixer.phar|https://cs.symfony.com/download/php-cs-fixer-v3.phar|4109a4ea6b9a40a411b9bf15536813de656f548d4c5be492af2adb4e94972212"
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
