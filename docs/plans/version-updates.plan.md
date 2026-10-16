# version-updates Plan

Order item 2 at the head of SPEC § 8: every package, image, tool and CI action to its latest stable release, one
ecosystem a commit (§ 7 2026-10-16 17:24). The TypeScript 7 hold is kept; a licence change stops the bump.
Procedure: docs/UPDATE.md, "After every bump" for each commit.

## Decisions Log
- [2026-10-16 18:44] AGREED: one ecosystem a commit; TypeScript 7 stays held; a licence change stops the bump (SPEC § 7 2026-10-16 17:24).
- [2026-10-16 19:01] ASSUMED (review): the framework-bundle recipe's AGENTS.md and CLAUDE.md stay out of api/, as G1a removed them (459a705c): the project's own CLAUDE.md and SPEC carry the process, and the recipe's generic conventions (AbstractController, ask before generating) contradict the code's. Upstream's only change since install drops the CLAUDE.md shim, so recipes:update brings neither back. Alternatives: a two-line AGENTS.md pointing at CLAUDE.md (advisor's suggestion), or the recipe's file verbatim.
- [2026-10-16 19:56] AGREED: the MCP packages' MIT → Apache-2.0 transition, dev-only under @angular/cli 22.2.3, is accepted (SPEC § 7, asked under the 17:24 ruling)

## Formal Plan
Inventory on 2026-10-16 (`composer outdated --direct`, `npm outdated`, Packagist): Symfony 8.1.9 is the newest
release, so PHP moves by patches and two minors (PHPStan 2.3, phpstan-symfony 2.1); npm by patches and minors
(Angular 22.2.3, Playwright 1.64.1, angular-eslint 22.6, material-symbols 0.48, zxing-wasm 3.1.5); TypeScript 7.0
is out and held.

1. PHP packages: `composer update` of the direct packages, `make notices`, the gates.
2. npm packages but zxing-wasm: Angular through `ng update`, then the rest; Playwright with its ARG in
   `infra/web/tools.Dockerfile`; material-symbols re-cuts the font; `make notices`, the gates, the e2e.
3. zxing-wasm 3.1.5: the tarball read first (its zxing-cpp commit and what it compiles in), then the manifest.
   Read 2026-10-16 19:15: 3.1.5 (MIT, published 2026-10-06) ships the same file list as 3.1.4 and compiles zxing-cpp
   2ecec3f5be0ee803f6e14a5a2c7028c0cfe525b4 (from 0b2d9a8f…), wasm sha256
   aecc1876de036c62c8419f67a5e1a16b1698a325bcd190aa84810d516e263931. The 41 commits between add no file outside test/;
   the code they touch is zxing-cpp's own (librscpp included: Apache-2.0, its headers say so), and the wasm carries the
   same component markers. The manifest's components stay; its version, integrity and audit line move.
4. Docker images: each tag in `make versions` against its registry, every copy moved (`version-pins.sh`).
   Registries read 2026-10-16 19:05: mailpit v1.31.4 → v1.31.6, caddy 2.11.6 → 2.11.7 (app, deploy and monitor),
   gotenberg 8.37.0 → 8.38.1, frankenphp 1.12.7 → 1.13.0 (php8.5-trixie), docker 29.8.2 → 29.9.0 (cli),
   node 26.10.0 → 26.12.0 (alpine), nginx 1.31.6 → 1.31.7 (alpine), supercronic v0.2.49 → v0.2.50 (sha256 re-taken
   against its release notes' sha1). Current: centrifugo v6.9.7, postgres 18.6-trixie, seaweedfs 4.48, composer 2.10.3,
   gatus v5.37.0, glitchtip 6.2.6; pgBackRest and restic are trixie packages, moved only with Debian.
5. GitHub Actions: each `uses:` against its releases.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | PHP packages | M | done | 45a5e43f | api/composer.json api/composer.lock THIRD-PARTY-NOTICES.md |
| 2 | npm packages (zxing-wasm apart) | M | done | 16a2593d | web/package.json web/package-lock.json infra/web/tools.Dockerfile THIRD-PARTY-NOTICES.md |
| 3 | zxing-wasm | S | doing | - | web/package.json web/package-lock.json web/src/third-party/** |
| 4 | Docker images | M | todo | - | compose*.yaml infra/** |
| 5 | GitHub Actions | S | todo | - | .github/workflows/** |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
