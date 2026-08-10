#!/usr/bin/env bash
#
# Fetch the API tier's standalone tools, pinned by SHA-256.
#
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# WHY THIS EXISTS, and it is not a preference.
#
# THREE tools are fetched here as vendor-official phars: PHPUnit, php-cs-fixer and PHPStan.
#
# **`composer install` IS NOT BLOCKED, and this header said it was for three days after that was
# disproved.** It read: *"GitHub egress is restricted by organisation policy to this repository alone, so
# every Composer `dist` URL … returns HTTP 403 … while `composer install` stays blocked."* The 403 is real
# and is per-repository AUTHORIZATION on three hosts (api.github.com, codeload.github.com, github.com over
# plain HTTP), not a network block: general egress is open, `git clone` works, and
#
#     composer config -g use-github-api false && composer config -g github-protocols https
#     cd api && composer install --prefer-source
#
# installs the whole locked tree, dev dependencies included. [Verified 2026-08-05: `--dry-run` reports
# "Installing dependencies from lock file (including require-dev) … Nothing to install"; the lock holds 74
# runtime + 46 dev packages, ALL with a `source` URL.] `CLAUDE.md` § Gotchas carries the full correction.
#
# So this script is not a workaround for a blocked installer. It exists because a pinned, SHA-256-verified
# phar runs in a checkout with NO vendor tree at all — which is what keeps `gate:style`, `gate:test` and
# `gate:static` independent of a successful `composer install`, and is why `composer.json` points all three
# at `tools/bin/` rather than `vendor/bin/`.
#
# The hashes are the point. A phar downloaded without verification is arbitrary code from the network,
# and this repository's whole licensing position depends on knowing the provenance of what it runs.
# Update a hash only alongside a deliberate version bump.
#
# Note the URLs. PHPUnit's is VERSION-PINNED (phpunit-13.3.0.phar), not the moving `phpunit-13.phar`:
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
  "phpunit-13.phar|https://phar.phpunit.de/phpunit-13.3.0.phar|d2137959d6fc96197b188fae076b361df41e026ddccfe2061917a7fea4d37e33"
  # php-cs-fixer 3.95.18. The VERSION is written here even though the URL cannot carry it, so the pin identifies
  # an artifact rather than only asserting a hash — otherwise a mismatch tells you the bytes changed and nothing
  # about what they changed to. Bumped at round 17: the pin held 3.95.17's hash while the host served 3.95.18, so
  # a FRESH container could not obtain php-cs-fixer at all and the style gate was unrunnable there. Verified
  # before bumping: the served phar reports `PHP CS Fixer 3.95.18 (d1fc711)`, and `check` over this repo reports
  # `Found 0 of 69 files that can be fixed` — same verdict as 3.95.17, so the bump changes no style rule.
  "php-cs-fixer.phar|https://cs.symfony.com/download/php-cs-fixer-v3.phar|4109a4ea6b9a40a411b9bf15536813de656f548d4c5be492af2adb4e94972212"
  # PHPStan 2.2.6, fetched as a PHAR rather than installed by Composer -- which finally resolves the blocker
  # CLAUDE.md carried for twenty certification rounds. Two corrections to the record, both verified:
  #
  #   1. The cause was never network egress. `phpstan/phpstan` WAS the only package in `composer.lock` with no
  #      `source` URL, so `--prefer-source` could not route around the 403 on `api.github.com` for it alone.
  #      **Past tense as of 2026-08-02: it left the lock with `deptrac`, which was the only thing that ever
  #      pulled it in, so no locked package lacks a source today and `--no-dev` is no longer required.**
  #      [Verified 2026-08-05: 74 + 46 packages, 0 without source, `phpstan/phpstan in lock? NO`.]
  #   2. The remedy CLAUDE.md suggested -- a VCS `repositories` entry -- does NOT work either, and the reason is
  #      not the network: `phpstan/phpstan` is a DISTRIBUTION repo that carries the built phar for every release
  #      in its history, so `git clone --mirror` exceeded Composer's 300-second process timeout. [Verified
  #      2026-08-02: the clone was killed at 300s.] Adding that entry is actively harmful, because every later
  #      `composer install` inherits the timeout.
  #
  # The phar is served by `raw.githubusercontent.com`, which IS reachable [Verified: 200, 27798998 bytes, reports
  # `PHPStan - PHP Static Analysis Tool 2.2.6`]. So PHPStan joins PHPUnit and php-cs-fixer as a pinned phar, which
  # is the pattern this project already trusts and needs no vendor tree.
  "phpstan.phar|https://raw.githubusercontent.com/phpstan/phpstan/2.2.8/phpstan.phar|ab9ea72523fe453b9f4dd19f12b1e403a91efa894cd25d9b0cb3ef62b7d20bf2"
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
