---
paths:
  - "infra/**"
  - "compose*.yaml"
  - "scripts/deploy/**"
  - "scripts/onprem/**"
  - "scripts/release/**"
  - "scripts/backup/**"
  - "scripts/monitor/**"
  - "scripts/staging/**"
  - "docs/START.md"
  - "docs/RUNBOOK.md"
  - "docs/UPDATE.md"
  - "TODO.md"
  - ".github/**"
---
# Images, deploying, a shop's computer, the monitor, backups — twes-in

What a session needs while working in those folders, beside CLAUDE.md, which keeps the process, the licensing
invariants, git, the commands and the lessons that apply before any file is read. A new lesson of this area goes at
the end of § Lessons here.

## Where things live

- `infra/api/Dockerfile` — FrankenPHP in worker mode in every target; two targets over one `base`: `dev`
  (`compose.yaml`, `make up`, CI's e2e) and `prod` (`compose.prod.yaml`, CI's `prod-image`: `php.ini-production`,
  `infra/api/conf.d/20-app.prod.ini` with preload, no dev packages, the cache warmed at build; `docs/START.md` § 10).
  PHP settings for every mode: `infra/api/conf.d/10-app.ini`.
- Backups (`docs/START.md` § 13): `compose.backup.yaml`, an opt-in layer over the production compose, builds the database
  from `infra/postgres/` (the same PostgreSQL plus pgBackRest, restic, supercronic; `pgbackrest.conf`, `backup/twes-backup`,
  `backup/crontab`) and adds the `backup` service; `make backup-drill` (`scripts/backup/restore-drill.sh`, compose project
  `twes-drill` only) restores from a stand-in bucket into empty volumes, as CI's `restore-drill` workflow does weekly.
  `BackupOverlayTest` pins the loss window and the encryption.
- Deploying (`docs/START.md` § 14): `compose.deploy.yaml` over compose.yaml + `compose.prod.yaml` + `compose.backup.yaml`
  adds the Caddy edge (`infra/edge/Caddyfile`: automatic certificates, HSTS for the host alone; every Caddy of the tree, the api's
  FrankenPHP included through `infra/api/log-filter.caddy`, writes a link's token [token] in its logs, `infra/edge/tests/token-free-logs.test.sh`), the only service that
  publishes a port, and derives every address from `TWES_HOST`; every value is required and comes from one file outside
  the checkout, written once by `scripts/deploy/new-instance.sh` (`--staging` for staging's) and handed to compose by
  `scripts/deploy/deploy.sh`, which refuses anything but a clean checkout and takes a mode: `--release` (production: an
  annotated `vYYYY.MM.DD.N` tag naming the three images by digest, written by `scripts/release/tag-release.sh`
  (`make release-tag TAG=… COMMIT=…`, any commit master published; `make point-master COMMIT=…` moves staging's `:master`
  by hand, `scripts/release/point-master.sh`), started
  over `compose.release.yaml` with nothing built), `--staging <release file>` and `--build` (self-hosted, built from the
  tag). CI's `publish` job (`scripts/release/publish.sh`, behind the repository variable `PUBLISH_IMAGES`) pushes the
  images to GHCR by commit and moves `:master` forward only; staging pulls it with `scripts/deploy/staging-follow.sh`
  (`infra/staging/`, a systemd timer). Each digest deployment is written down in `releases/` beside the values.
  Procedures, each marked tried or untried: `docs/RUNBOOK.md`. `make staging-check` (`scripts/staging/`, compose project
  `twes-staging-check`, a registry on 127.0.0.1:5099) proves the road and the secret rotations on this machine. The first operator: `app:seed --without-company
  --operator-password-stdin`. `scripts/gates/deploy-overlay.sh` holds the overlay's shape in CI; `make deploy-check`
  (`scripts/deploy/deploy-check.sh`, compose project `twes-deploy-check` only, CI's `deploy-check` workflow) boots it and
  asks the edge for HSTS, the redirect, a sign-in, the socket and the client address.
- A shop's own computer (`docs/START.md` § 16): `compose.onprem.yaml` (its edge, `infra/onprem/Caddyfile`: https://localhost
  for passkeys and https://<its network address> through an authority of its own, whose `root.crt` plain HTTP serves),
  `compose.backup-local.yaml` (backups in its own folder, run when due, `BACKUP_SCHEDULE=due`) and
  `compose.backup-offsite.yaml`; values from `scripts/deploy/new-instance.sh --onprem`, `TWES_INSTALL=onprem`. Everything
  runs through `scripts/onprem/twes` (install, start, update with its backup and rollback, policy, offsite, tick, status,
  restore-commands, pin-key, schedule: Linux's systemd units; test `scripts/tests/twes.test.sh`), which reaches the stack
  through `deploy.sh --compose`. A release is taken only from a tag `scripts/release/tag-release.sh` signed, verified
  against the key pinned at install (`infra/onprem/release-key.asc`, the developer's public key, pinned once its
  fingerprint is confirmed). Windows: `scripts/onprem/install.ps1` (WSL, then `-Engine Desktop`, Docker Desktop and the
  `\twes-in\` start and tick tasks, or `-Engine Wsl`, Docker Engine inside Ubuntu through `scripts/onprem/wsl-engine.sh`,
  systemd's units and the `keep` task; the checkout verified against the fingerprint the installer carries before
  anything from it runs, its `$verify` line run by twes.test.sh, `twes install`, the authority trusted; ASCII only;
  `make gate-powershell`, part of `make gate-licences`, parses it, runs PSScriptAnalyzer and its `.wslconfig` editing in
  `infra/powershell/`'s image, the .NET SDK's, which is where Microsoft ships PowerShell now; built once per Dockerfile,
  `make ps-tools-tag` its name, and kept in CI's cache, since PowerShell Gallery fails now and then). `make onprem-check`
  (`scripts/onprem/onprem-check.sh`, compose project `twes-onprem-check`, ports 80 and 443, about ten minutes) installs,
  rolls back, restores and refuses an unsigned tag for real; the restore steps are docs/RUNBOOK.md « Restore a shop »,
  releasing to shops « Release to the shops ». `make update-rehearsal FILES=<a shop's five import files> [FROM=<tag>]
  [TO=<commit>]` (`scripts/onprem/update-rehearsal.sh`, compose project `twes-update-rehearsal`, 80 and 443, about
  twenty minutes, never beside `make onprem-check`) takes the update that shop will take on its own files before each
  tag: the release out installed by `twes update`, the files imported, the commit taken by `twes update`, every table,
  the products list and the migrations read back.
- The monitor (`docs/START.md` § 15), on a machine of its own: `infra/monitor/compose.yaml` (Gatus reading `/api/health/deep`
  with `MONITOR_TOKEN` and taking the backups' heartbeats, alerting by mail and Telegram on a public page whose lines hide
  their details, `gatus.yaml`; GlitchTip all-in-one with no Valkey and its PostgreSQL; a Caddy edge), values from
  `scripts/monitor/new-monitor.sh`, the instance's project and DSN from `infra/monitor/bootstrap.py`. The API reports
  errors through `sentry/sentry-symfony` in prod only, through Monolog, scrubbed by `Shared/Infrastructure/ErrorReporting/
  ScrubPersonalData`; the web posts its own to `POST /api/client-errors` (`shared/errors/`). A heartbeat name carries no
  accent (Gatus takes none for one). `scripts/gates/monitor-config.sh` holds the monitor's shape; `make monitor-check`
  (`scripts/monitor/monitor-check.sh`, compose projects `twes-monitor-check` and `twes-monitor-check-app`, CI's
  `monitor-check` workflow) boots a deployment and a monitor side by side and reads Gatus and GlitchTip back.

## Gates (`scripts/gates/`)

  - `compose-log-rotation.sh` (every compose service rotates its output, 10 MB × 5, through the `*logging` anchor)
  - `compose-restart.sh` (every service of a server, a shop, development live and from the images, and the monitor says `restart: unless-stopped` if it runs or `"no"` if it does a job and exits: development's api once died of a PHP fault and stayed down while its workers came back)
  - `version-pins.sh` (every copy of a version pin agrees, `docs/UPDATE.md`)
  - `host-tools.sh` (CI names no `setup-php`, `setup-node` or bare host tool, and the Makefile runs one only inside an `in-*` target)
  - `action-pins.sh` (every action a workflow uses is pinned by its release's full commit SHA, the tag as a comment, `docs/UPDATE.md`)
  - `release-lane.sh` (every workflow a push to master runs also runs on a release's branch, `release/**`, where a patch for the shops is built)
  - `build-extra-ca.sh` (every downloading RUN of a Dockerfile under `infra/` mounts the optional secret `extra_ca`, a proxy's CA added for that step only and in no layer; `compose.extra-ca.yaml` hands it to every built service and the Makefile to every `docker build`, only when `CCS_EXTRA_CA_FILE` names a file; nothing switches a TLS check off)
  - `monitor-config.sh` (the monitor's compose: only its edge publishes, every value required, sign-up closed, every Gatus line hidden and alerting, no token written out, its heartbeats the backups' own)
  - `deploy-overlay.sh` (only the deploy edge publishes a port, every address derived from `TWES_HOST`, every value required, HSTS for the host alone, Gotenberg shut in as `lib/pdf-renderer.sh` says: no JavaScript, no URL but its own files, on an internal network only the api and the workers join)
  - `onprem-overlay.sh` (a shop's installation merged as `deploy.sh` merges it: only its edge publishes, on 80 and 443, passkeys bound to localhost and links to `LAN_HOST`, every secret a compose secret, Gotenberg shut in as on a server; its edge's certificates, and development's phone door's, last through a night asleep and are made again at every start; every value the files require and every secret they mount is one the newest release's installer wrote, or one `infra/onprem/values.defaults` fills, which `deploy.sh` adds to a shop's old values file on an update)
  - `release-overlay.sh` (`compose.release.yaml` makes every service on a built image run the published one by the digest given, building nothing on the server, each digest required, and every third-party image a server or a shop starts is named by tag and index digest)
  - `secrets-overlay.sh` (every installation's secrets reach their containers as compose secrets, files under `/run/secrets`, never an environment: no value of the installation written in its merged configuration, every one from a variable of the values file, each mounted where its tool reads it (the api image through `Shared/Infrastructure/Secrets/ContainerSecrets`, loaded with Composer's autoloader so an exec'd console reads them too; the others through `infra/postgres/twes-secrets`, which a `docker compose exec` into them must name, as `twes-backup` does itself), no api-image environment holding a variable its secrets set, not even empty, since FrankenPHP's workers read the environment over what was set at boot (the addresses are `DATABASE_URL_TEMPLATE` and its migration twin, the passwords as placeholders), the owner's password to postgres and migrate alone; every service mounting a secret labelled `twes.secrets-digest`, the digest of the values `deploy.sh` hands compose, since Compose keeps a running container whose secret's value alone changed; every service mounting a file from the checkout (a Caddyfile, the shim, a live entrypoint) labelled `twes.mounts-digest`, the digest `scripts/deploy/mounts-digest.sh` makes of those files, which `deploy.sh`, `make up` and the monitor's start hand compose, since a checkout writes the file anew and the mount keeps the old one, read from a server's, a shop's, development's and the monitor's files; `deploy.sh` refuses a secret left empty, which compose would start with nothing mounted, and `make deploy-check` and `make onprem-check` read every running container's environment for the values, `scripts/deploy/secrets-stay-files.sh`)
  - `production-image.php`, run inside the production api image by CI's `prod-image` job

## Lessons

- The images build from the WORKING TREE (`COPY api/ ./`), untracked files included: proving a bring-up from a dirty
  tree tests the work in progress, not HEAD. Prove it from `git worktree add <tmp> HEAD` (2026-09-19, `ImportExport/` WIP).
- `tests/Architecture/ComposePortsTest` reads `compose.yaml`: run it after any compose edit, not only before. And a plain
  `docker compose up` rebuild drops what `make up` exports (`LAN_ORIGIN`): bring the stack back with `make up` (2026-09-23).
  `docker compose up <service>` also recreates what it depends on from `compose.yaml` alone, out of live mode: the api then
  served its image, and an e2e of an API change failed against the old code (2026-10-06).
- `docker compose restart api` keeps the image's own `docker-entrypoint`: an edit to `infra/api/docker-entrypoint.sh` runs only after
  `docker compose build api` and `make up` (2026-10-07: the new start step was missing from a September image).
- A Django service (GlitchTip) answers any host outside `ALLOWED_HOSTS` 400, `127.0.0.1` included: its healthcheck sends
  its own name as `Host`, or the container never turns healthy and whatever waits on it never starts. And a Sentry event
  carries the source lines around each frame: a leak search over events skips a frame's `context*` fields, or it finds
  the test's own literals in our code (2026-10-12, `make monitor-check`).
- A `docker compose run` without `< /dev/null` reads the input of whatever runs it: in a `while read` loop or a block of
  commands pasted into a terminal, it swallows the lines after it, which then never run, and the block reads as done.
  Every `run` line a person may paste ends `< /dev/null` (`docs-run-lines.sh` reads the docs for it); a check running such
  lines counts that all of them ran (2026-10-17).
- A file another process reads is written whole: `>` empties it before it writes, and a reader in between finds nothing
  (CI red 2026-11-05: the schedule's `due` read empty; a tight loop saw 145 empty reads of 145,076). In `twes-backup` every
  outcome goes through `put` (a file beside it, then `mv`); `backup-heartbeat.test.sh` reds on a direct redirect there.
