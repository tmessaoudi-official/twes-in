# Updating versions

Every version twes-in depends on, where it is written, which other files hold a copy of it, how to bump it and how
to prove the bump worked. To bring the project up, create users or start clean, see `docs/START.md`.

**This file holds no version numbers.** Numbers written in prose drift from what is actually pinned (`docs/SPEC.md`
§ 6 said Gotenberg 8.36 while `compose.yaml` pinned 8.37.0). The current values are printed from the files
themselves:

```sh
make versions
```

## The rules

1. **One subject per commit.** One image, one framework or one batch of patch releases. A red gate then points
   at one cause.
2. **Move every copy.** Several pins are written in more than one file (the "Copies" column below). The ones
   marked **gate** are checked by `scripts/gates/version-pins.sh`, which fails `make gate` and CI when two copies
   disagree. The ones marked **by hand** are not: follow the row.
3. **Any dependency change regenerates the notices and passes the licence gate**, even a patch release. Licences
   do change between versions. See "After every bump" below.
4. **A new licence identifier is a licensing decision, not a build fix** (`CLAUDE.md` § Licensing invariants). If
   the licence gate refuses a package after a bump, stop: do not widen the allowed list.

## Find what is out of date

| What                        | Command or place                                                                             |
|-----------------------------|----------------------------------------------------------------------------------------------|
| PHP packages                | `make tools CMD='cd api && composer outdated --direct'`                                      |
| npm packages                | `make web-tools CMD='npm outdated'`                                                          |
| Angular (framework + CLI)   | `make web-tools CMD='npx ng update'` (lists what `ng update` would migrate)                  |
| Docker images               | Docker Hub tags: `hub.docker.com/_/postgres`, `/_/node`, `/_/nginx`, `/_/composer`, `/r/dunglas/frankenphp`, `/r/gotenberg/gotenberg`, `/r/centrifugo/centrifugo`, `/r/axllent/mailpit`, `/_/caddy`, `/r/twinproduction/gatus`, `/r/glitchtip/glitchtip`, `/_/registry`, `/_/docker`, `/r/chrislusf/seaweedfs` |
| GitHub Actions              | each action's releases page, for example `github.com/actions/checkout/releases`; pinned by the release's full commit SHA with its tag as a comment (`@3d3c42e… # v7.0.1`), as GitHub's security hardening guide recommends: a tag can be moved, a commit cannot. The tag's commit: `gh api repos/actions/checkout/git/ref/tags/v7.0.1`, then its `object.sha` (an annotated tag: one `git/tags/<sha>` more) |
| PHP and Node themselves     | `php.net/supported-versions.php`, `nodejs.org/en/about/previous-releases`                    |

`composer outdated` never shows a new Symfony **minor**: `extra.symfony.require` in `api/composer.json` (`8.1.*`) makes
Flex filter every Symfony package to that line. Read `symfony.com/releases` for one, and move that pin and the
`symfony/*` constraints together. `composer recipes:update` refuses a tree with changes; run it in a fresh local clone
and carry its diff over.

`composer` and `npm` run in the toolchain containers (`docs/START.md` § 1): `make tools CMD='…'` for PHP, `make web-tools CMD='…'` for Node. Nothing is installed on the host.

## Every pin

### Docker images and runtimes

| Pin | Where | Copies that move with it | How to bump | Watch out for |
|---|---|---|---|---|
| **PostgreSQL** image | `compose.yaml`, service `postgres`, `image:` | none: CI starts this service. The same tag in `infra/postgres/Dockerfile`'s `FROM postgres:`, the backup overlay's image, and in `infra/monitor/compose.yaml`, service `glitchtip-db`, the error tracker's database (**gate**). The major also appears as `serverVersion=` in `compose.yaml` and `api/.env`, and in `pg1-path=/var/lib/postgresql/<major>/docker` in `infra/postgres/pgbackrest.conf` (**gate**) | Edit the `image:` line and the Dockerfile's `FROM`. For a new major, also every `serverVersion=` and `pg1-path`, and where backups run, `twes-backup` stops archiving until `pgbackrest --stanza=twes stanza-upgrade` has run in the backup service, then a full backup (`twes-backup full`) | **A major bump does not upgrade your data.** The image keeps its data in `/var/lib/postgresql/<major>/docker` and the volume is mounted at `/var/lib/postgresql`, so PostgreSQL 19 starts an empty cluster next to the 18 one. Your data looks gone, and `infra/postgres/init.sql` runs again. In development, run `make reset`. To keep the data, run `pg_dump` before the bump and `pg_restore` after it. A minor bump (18.6 → 18.7) is a plain restart |
| **pgBackRest** (backups, MIT) | `infra/postgres/Dockerfile`, `ARG PGBACKREST_VERSION` (the PGDG package version for the image's Debian) | none | Change the ARG to a version `apt-cache policy pgbackrest` offers in the image, then `make backup-drill` | Read its release notes (pgbackrest.org/release.html). The database server and the backup service run the same image, so they never differ |
| **restic** (copy of the files and the nightly dump, BSD-2-Clause) | `infra/postgres/Dockerfile`, `ARG RESTIC_VERSION` (Debian's package) | none | Change the ARG to a version Debian offers, then `make backup-drill` | A new repository format version is read by newer restic only: never downgrade after a bump |
| **curl** (the backup steps' heartbeats to the monitor, curl licence, MIT-style) | `infra/postgres/Dockerfile`, `ARG CURL_VERSION` (Debian's package) | none | Change the ARG to a version `apt-cache policy curl` offers in the image (Debian removes a security release's predecessor from its mirror, so a stale pin stops the build), then `make backup-drill` | `scripts/tests/backup-heartbeat.test.sh` holds what each step sends |
| **supercronic** (the backup schedule, MIT) | `infra/postgres/Dockerfile`, two `ADD --checksum=sha256:…` URLs, one per architecture, each in its own stage so a build fetches only its own | none | Change both URLs and both checksums: download each asset, compare its sha1 with the one its release notes publish, then take its `sha256sum` | The build refuses an asset whose checksum differs |
| **SeaweedFS** (the drill's stand-in bucket, Apache-2.0, never shipped) | `scripts/backup/drill.compose.yaml`, service `s3` | none | Change the tag, then `make backup-drill` | The drill only: production writes to the instance's own bucket |
| **Trivy** (the weekly image scan, Apache-2.0, never shipped) | `scripts/security/image-scan.sh`, `TRIVY_IMAGE=aquasec/trivy:<tag>@sha256:<index digest>` | none: the workflow names no version of its own | Read the new digest with `docker buildx imagetools inspect aquasec/trivy:<tag>`, set both, then run the script once (`TRIVY_CACHE=var/cache/trivy bash scripts/security/image-scan.sh ghcr.io/<owner>`) | Never through `aquasecurity/trivy-action`, and never a release before reading its advisories: Trivy's releases and its action were compromised in March 2026 (binary 0.69.4, images 0.69.5 and 0.69.6, GHSA-69fq-xp46-6x23); 0.67.2 was built before it. The script refuses an image not pinned by digest |
| **Distribution registry** (`registry`, the staging check's stand-in for GHCR, Apache-2.0, never shipped) | `scripts/staging/staging-check.sh`, `REGISTRY_IMAGE` | `scripts/onprem/onprem-check.sh`, `REGISTRY_IMAGE` (**gate**) | Change both tags, then `make staging-check` | The check only: CI publishes to GHCR |
| **FrankenPHP + PHP** | `infra/api/Dockerfile`, stage `base`, `FROM dunglas/frankenphp:<frankenphp>-php<X.Y>-<debian>` (the `dev`, `tools` and `prod` stages build on it, so it is written once) | PHP `X.Y`: `require.php` in `api/composer.json` (**gate**). Nothing on the host or in CI: the gates run in the `tools` stage | Change the tag. A FrankenPHP-only bump keeps the PHP part | A PHP minor bump: read the PHP migration guide, then run `make gate` (it rebuilds the `tools` image on the new PHP) |
| **PHP extensions** | `infra/api/Dockerfile`, stage `base`, `RUN install-php-extensions …` | none: the tools image builds on `base`, so the gates run on the same extensions | Add or remove the name | `ext-*` in `api/composer.json` `require` declares what the code needs. Keep the two lists coherent |
| **Composer** | `infra/api/Dockerfile`, stage `base`, `COPY --from=composer:<v>` | none: the `tools` stage builds on `base` | Change the tag | — |
| **Node** | `infra/web/Dockerfile`, `FROM node:<X.Y.Z>-alpine` (full version) | the same full version in `infra/web/tools.Dockerfile`'s `FROM node:<X.Y.Z>-trixie-slim` (**gate**), and the major in `web/.nvmrc` and `engines.node` in `web/package.json` (**gate**) | A patch: the two Dockerfiles. A new major: those and the other two | The gates run on the tools image's Node, the app on the image's: the gate refuses the two to differ |
| **Docker CLI** (inside the `tools` image) | `infra/api/Dockerfile`, stage `tools`, `COPY --from=docker:<v>-cli` (two lines: the CLI and the Compose plugin) | the second line (**gate**) | Change both tags together, then `make tools-image` | Only the gates that start containers use it (`compose-log-rotation`, `forwarded-proto`, `access-log`, `live-proxy`); keep it near the host daemon's version |
| **nginx** | `infra/web/Dockerfile`, `FROM nginx:<v>-alpine-slim` | none | Change the tag, keeping `-alpine-slim` | Config lives in `infra/web/nginx.conf`. Check the WebSocket proxy (`/connection/websocket`) still upgrades. The slim variant has the full one's compiled-in modules (`nginx -V`: `sub_filter`, gzip, realip) but none of its dynamic ones (image_filter, xslt, geoip, njs, acme): a `load_module` added to the configuration needs the full image back, and the libraries it brings (the full 1.31.7 carried three HIGH findings, the slim none) |
| **Centrifugo** | `compose.yaml`, service `centrifugo`, `image: <name>:<tag>@sha256:<index digest>` (**gate**: a started third-party image by tag and digest, `release-overlay.sh`) | none | Change the tag and the digest together (see "A third-party image's digest" below) | A major can rename `CENTRIFUGO_*` settings: read its migration notes. The realtime e2e scenarios prove it |
| **Gotenberg** | `compose.yaml`, service `gotenberg`, `image: <name>:<tag>@sha256:<index digest>` (**gate**, as Centrifugo) | none | Change the tag and the digest together (below) | Look at a rendered invoice PDF, not only the tests: a renderer change moves margins (`api/tests/Unit/Shared/Infrastructure/PdfTemplateMarginsTest.php`) |
| **Mailpit** | `compose.yaml`, service `mailpit` | none | Change the tag | Development-only mail catcher, behind the `mailpit` profile: no shop or server starts it, so it keeps a tag alone |
| **Caddy** | `compose.yaml`, service `lan`, `image: caddy:<tag>@sha256:<index digest>` | The deploy overlay's edge, `compose.deploy.yaml`, service `edge`, a shop's, `compose.onprem.yaml`, service `edge`, and the monitor's, `infra/monitor/compose.yaml`, service `edge`, tag and digest alike (**gate**) | Change the four lines' tag and digest together (below) | In development the HTTPS door for a phone (`infra/lan/Caddyfile`), proved by the phone-pairing e2e run locally; in a deployment the edge (`infra/edge/Caddyfile`), proved by `make deploy-check` (docs/START.md § 14). Read Caddy's release notes for its handling of `X-Forwarded-*` and `Strict-Transport-Security` |
| **Gatus** (the monitor, Apache-2.0) | `infra/monitor/compose.yaml`, service `gatus` | none | Change the tag, then `make monitor-check` | Runs on the monitor's own machine (docs/START.md § 15). Read its release notes for changes to the configuration keys `infra/monitor/gatus.yaml` uses (`ui.hide-*`, `external-endpoints`, `heartbeat`, the email and Telegram alerts); `scripts/gates/monitor-config.sh` reads that file |
| **GlitchTip** (the error tracker, MIT) | `infra/monitor/compose.yaml`, service `glitchtip` | none | Change the tag, then `make monitor-check` | Started with `bin/run-all-in-one.sh` and no Valkey; its migrations run at start. Read its release notes for the settings the compose file names (`ENABLE_USER_REGISTRATION`, `VALKEY_URL`, `GLITCHTIP_MAX_EVENT_LIFE_DAYS`) and for the models `infra/monitor/bootstrap.py` uses |

#### A third-party image's digest

Every third-party image a shop or a server starts (Caddy, Gotenberg, Centrifugo) is named by its tag **and** its index
digest, `caddy:2.11.7-alpine@sha256:…`. A tag is resolved on each machine when it pulls, and its publisher may rebuild it
in place (Docker's official tags are), so two shops on one signed release could run different edges, neither the bytes
staging ran. The digest fixes them, and the signed release tag fixes the commit that names them.
`scripts/gates/release-overlay.sh` refuses a started service on a third-party image without both.

Take the **index** (manifest list) digest, never one platform's manifest, so an arm64 machine finds its own image:

```sh
docker buildx imagetools inspect caddy:<new tag>     # the "Digest:" line under "MediaType: …index…" or "…manifest.list…"
```

Write `<name>:<tag>@sha256:<that digest>` in every copy, then `make gate-licences`. The weekly image scan
(`.github/workflows/image-scan.yml`, `scripts/security/image-scan.sh`) says when a pinned image, or one of our three at
`:master`, has a CRITICAL or HIGH with a fix that `.trivyignore` does not hold: that is when to move it, or to triage
the finding into `.trivyignore` with its verdict and an `exp:` date.

### Frameworks and packages

| Pin | Where | Copies that move with it | How to bump | Watch out for |
|---|---|---|---|---|
| **Symfony** (minor, e.g. 8.1) | `api/composer.json`: `extra.symfony.require` | every `symfony/*` pinned `X.Y.*` in `require` and `require-dev` (**gate**) | Edit all of them, then `make tools CMD='cd api && composer update "symfony/*" --with-all-dependencies'`, then `composer recipes` to see recipe updates (`composer recipes:update <package>` applies one) | Read Symfony's `UPGRADE-X.Y.md`. Clear deprecations first: `bin/console debug:container --deprecations`. `symfony/flex`, `symfony/monolog-bundle` and `symfony/maker-bundle` version separately |
| **API Platform, Doctrine, other PHP packages** | `api/composer.json` → `api/composer.lock` | none | `make tools CMD='cd api && composer update <package> --with-all-dependencies'`. `bump-after-update` is on, so `composer.json` is rewritten to the new floor | After an API Platform update: `bin/console cache:clear` **and** `bin/console cache:pool:clear --all` (also `--env=test`), or the OpenAPI export stays stale (`CLAUDE.md` § Lessons) |
| **Sentry's SDK** (`sentry/sentry-symfony`, MIT) | `api/composer.json` → `api/composer.lock` | none | `make tools CMD='cd api && composer update "sentry/*" -W'`, then `make notices` | Production only (`config/packages/sentry.yaml`, `when@prod`), reaching GlitchTip through Monolog alone with every listener of its own off. Read the release notes for `before_send`, `send_default_pii` and the HTTP timeouts that file sets; `ErrorReportingConfigTest` reads them. Then `make monitor-check`, which proves GlitchTip still takes what the new SDK sends |
| **PHPStan, PHPUnit, php-cs-fixer** | `api/composer.json` `require-dev` | none | `make tools CMD='cd api && composer update <package> -W'` | A new PHPStan or php-cs-fixer version may add rules: fix what it reports, do not baseline it |
| **Angular** (major, e.g. 22) | `web/package.json`: every `@angular/*` | all `@angular/*` share a major (**gate**). `angular-eslint` follows Angular's major (**by hand**) | `make web-tools CMD='npx ng update @angular/core @angular/cli @angular/cdk @angular/material'`, one major at a time. It runs the official migrations. Then `make web-tools CMD='npm install angular-eslint@<same major>'` | `typescript` must stay in the range Angular supports: `ng update` says so |
| **Other npm packages** | `web/package.json` → `web/package-lock.json` | none | `make web-tools CMD='npm install <package>@<version>'` | `@ngx-translate/core` and `@ngx-translate/http-loader` bump together, as do `eslint` and `@eslint/js`, and `tailwindcss` and `@tailwindcss/postcss`. `@hey-api/openapi-ts` generates the API types: the types are gitignored, so after a bump `make gate-web` (which regenerates them and builds) is what shows a changed shape |
| **zxing-wasm** (the camera's barcode decoder) | `web/package.json`, pinned exactly (see `make versions`) | `web/src/third-party/zxing-reader/COMPONENTS.json`: `version` and `integrity` must equal the lock's (**gate**) | Read the new tarball first: the zxing-cpp commit it builds (`ZXING_CPP_COMMIT`) and what that compiles in, as `var/claude/decoder-licence.md` did. Then `make web-tools CMD='npm install --save-exact zxing-wasm@<v>'`, update the manifest's version, integrity, `audit` line and components, and `make notices` | The licence gate refuses a bump the manifest has not caught up with, on purpose: a new wasm is unread code. Import `zxing-wasm/reader` only, never the bare package (its default is the full build with the writer) |
| **material-symbols** (the icon font) | `web/package.json` | `web/src/app/shared/icons/icons.ts`, the icons the font is cut to (**gate**: `scripts/gates/icons-declared.sh`, and the `IconName` type) | `make web-tools CMD='npm install material-symbols@<v>'` | The next build cuts the new font by itself (`web/scripts/subset-icons.mjs`). An icon renamed or dropped upstream fails the build, naming it: pick its new name in `icons.ts` and where it is used |
| **PowerShell and PSScriptAnalyzer** | `infra/powershell/Dockerfile`: `FROM mcr.microsoft.com/dotnet/sdk:<X.Y>-noble@sha256:…` (PowerShell comes with the .NET SDK) and `ARG PSSCRIPTANALYZER_VERSION` | none | Read the new digest with `docker buildx imagetools inspect mcr.microsoft.com/dotnet/sdk:<X.Y>-noble`, set both, then `make gate-powershell` | A new analyzer rule may find something in `install.ps1`: fix it there, the gate takes no exception |
| **Playwright** | `web/package.json`: `@playwright/test` | `ARG PLAYWRIGHT_VERSION` in `infra/web/tools.Dockerfile`, which installs the system libraries for that browser build (**gate**: it must equal `web/package-lock.json`) | `make web-tools CMD='npm install -D @playwright/test@<v>'`, set the ARG, then `make web-tools-image` | `make e2e` downloads the new Chromium and WebKit into `var/cache/ms-playwright` on its next run |
| **GitHub Actions** | `.github/workflows/*.yml` (`ci.yml`, `decision-stamps.yml`, `deploy-check.yml`, `image-scan.yml`, `monitor-check.yml`, `restore-drill.yml`, `staging-check.yml`): every `uses: <action>@<major>` | the same action at the same major in each workflow (by hand) | Change the major | Read the action's release notes: a major can drop or change inputs |

### Not pinned here on purpose

- **The operating system inside the images** (`-trixie`, `-alpine`) comes from the image tag. Moving it, say to
  a newer Debian, is a tag change like any other.
- **`install-php-extensions` itself** ships inside the FrankenPHP image, so it moves when that tag moves.
- **The host has no PHP or Node to pin**: the toolchain runs in the images above (`docs/START.md` § 1).

## After every bump

Run these in order. Each one catches something the one before it cannot:

```sh
make notices                          # THIRD-PARTY-NOTICES.md regenerated from the lock files (commit it)
make gate-licences                    # licences permitted, every pin's copies agree, headers, gate self-tests
make gate                             # + API gate (style, PHPStan, PHPUnit on PostgreSQL) + web gate (lint, tests, build)
docker compose up -d --build --wait   # the images actually build and come up healthy
make e2e                              # a real browser through the rebuilt stack
```

`make gate-api` starts the PostgreSQL service itself. A green `make gate` now runs on the toolchain images (the same PHP
as the API image, the Node of the web build's pin), but the last two steps are still the only proof that the **runtime**
images build and come up healthy.

## Adding a new pin

A new image, service or runtime gets a row in the right table above. If its version is also written anywhere else,
add that pair to `scripts/gates/version-pins.sh`, with a case in `scripts/gates/tests/version-pins.test.sh` that
fails when the copies disagree. Otherwise the new copy can drift silently.
