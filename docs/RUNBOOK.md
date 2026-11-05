# Runbook

What to do when something has to be done to a running instance, or something went wrong with one. Each procedure says
where it was tried:

- **tried on the check stack**: a script in CI runs it end to end on this machine's Docker, against stand-ins (a local
  registry for GHCR, SeaweedFS for the bucket, Caddy's own authority for Let's Encrypt). The script is named.
- **untried**: written from the code and the tools' documentation; nobody has run it yet. Run it on staging before you
  need it on production, then change the mark.

Nothing here has been tried on a real staging machine yet: there is none. Until there is, « tried on the check stack »
is the strongest claim this page makes.

Bring-up, the values and the first deployment are in `docs/START.md` § 13–15; this page assumes them.

## The shape

| Machine | Runs | Gets new code |
|---|---|---|
| Production | the four deployment files with `compose.release.yaml`: the images CI published, by digest | `deploy.sh --release`, by hand, on a release tag |
| Staging | the same files, with its own values (`DEPLOY_ENV=staging`), its own bucket and keys | `staging-follow.sh`, every five minutes (`STAGING_FOLLOW_INTERVAL`), from the registry |
| Monitor | `infra/monitor/compose.yaml`, on a machine of its own | by hand, `git pull` then START § 15's start, which hands the mounted files' digest |

CI builds the three images (api, web, database) once per green master commit and pushes them to GHCR as
`twes-in-<part>:sha-<commit>`, then moves `:master` forward (`scripts/release/publish.sh`, the `publish` job, on while the
repository variable `PUBLISH_IMAGES` is `true`). Every image carries the commit it was built from as its
`org.opencontainers.image.revision` label, and `deploy.sh` refuses one that disagrees with its release. GitHub holds no
credential of any server: staging pulls, production is deployed by hand.

Every deployment by digest is written down beside its values: `releases/<commit>.env` (the three images it started) and
`releases/history` (one line per attempt: when, `deployed` or `failed`, the commit, `staging` or `release`).

## Setting up staging, once

Untried. A small VM, like production's but smaller, at a provider of your choice; a DNS name of its own.

1. Docker with the Compose plugin, `git`; only 22, 80 and 443 open at the provider's firewall (`docs/START.md` § 14).
2. A user that owns the checkout and the values and belongs to the `docker` group, here `twes`.
3. The checkout, which `staging-follow.sh` moves from commit to commit: `sudo install -d -o twes /srv/twes-in && sudo -u
   twes git clone https://github.com/tmessaoudi-official/twes-in.git /srv/twes-in`.
4. The values: `sudo install -d -m 700 -o twes /etc/twes-in` then, as `twes`,
   `bash /srv/twes-in/scripts/deploy/new-instance.sh --staging /etc/twes-in/deploy.env`. Fill in its facts as for
   production, with **staging's own bucket and keys**: keys that can reach nothing of production's, since anyone who
   gets into staging holds them. Its backups also go under the prefix `staging/`, so even production's bucket given by
   mistake would not be written over. Staging never restores production's data: test data only.
5. The monitor: staging gets its own GlitchTip project (`infra/monitor/bootstrap.py` run again with another project
   name) and its own Gatus lines, kept off the public status page and alerting you alone.
6. The timer: `sudo cp /srv/twes-in/infra/staging/twes-staging-follow.{service,timer} /etc/systemd/system/`, then
   `sudo cp /srv/twes-in/infra/staging/staging-follow.env.example /etc/twes-in/staging-follow.env` and edit it if the paths
   differ (`STAGING_FOLLOW_INTERVAL`, how often it asks the registry: at least `1min`, `5min` when unset), then write
   that interval over the timer (it refuses one below a minute):

   ```
   sudo bash /srv/twes-in/scripts/deploy/staging-follow-interval.sh /etc/twes-in/staging-follow.env /etc/systemd/system/twes-staging-follow.timer.d
   ```

   then `sudo systemctl daemon-reload && sudo systemctl enable --now twes-staging-follow.timer`. After changing
   the interval, run the script and `daemon-reload` again. Untried on a real machine; `staging-follow-interval.test.sh`
   reads the drop-in it writes.
7. The first operator, as in `docs/START.md` § 14.

Watch it work: `journalctl -u twes-staging-follow -f`. Each run says `waiting` (the three `:master` images do not agree
yet, a publish is under way), `already deployed here`, `already failed here`, `held by …`, or `deploying <commit>`.

## Deploy to staging

Tried on the check stack (`make staging-check`: publish, follow, every container proved to run its digest, nothing
built, a second follow doing nothing).

Nothing to do: merge to master; once CI is green it publishes, and staging follows within the interval (five minutes unless `STAGING_FOLLOW_INTERVAL` says otherwise). To follow now,
`sudo systemctl start twes-staging-follow`.

A commit that failed to start is written down as `failed` and not tried again until master moves on. Read why with
`journalctl -u twes-staging-follow` and the stack's logs (`docker compose … logs api`), fix it on master, and the next
commit is followed.

## Promote to production

Untried end to end; `tag-release.sh`, `point-master.sh` and `deploy.sh --release` each have their test
(`scripts/release/tests/`, `scripts/tests/deploy.test.sh`).

1. Pick the commit staging runs and you have tried there: `tail -1 /etc/twes-in/releases/history` on staging. Any
   commit master holds and CI published can be tagged, not only the last one; nothing is checked out.
2. On your machine, in the main checkout: `git fetch origin`, then
   `make release-tag TAG=v<YYYY.MM.DD.N> COMMIT=<commit>` (`scripts/release/tag-release.sh`; without `COMMIT`, HEAD).
   It refuses a commit master does not hold, a commit whose three images were never published, images that say they
   were built from another commit, and a tag that exists. It also refuses a commit whose tree the update rehearsal and
   `make onprem-check` have not proved (each writes `var/claude/gates/proofs/<rehearsal|onprem>-<tree>.ok` when it
   passes, so the same tree re-signed keeps its proofs), any commit since the last release tag without a good
   signature, and a tag dated in, or made during, the year-end freeze (`TWES_RELEASE_FREEZE`, 2026.12.20-2027.01.10 by
   default). `UNREHEARSED=<why>` and `HOTFIX=<why>` let one through, and the tag's message says why. It writes the three digests into the annotated tag, signs it
   with your git `user.signingkey` (or `TWES_RELEASE_KEY`), which shops verify before taking it, and verifies it once
   made. It reads each image's label and digest from the registry without pulling it (`docker buildx imagetools`, a
   few seconds an image, one line each). The date is the day you tag, `.N` that day's count of releases.
3. Push the tag: `git push origin v<YYYY.MM.DD.N>`. A pushed tag is never moved.
4. Announce the window (about 30 seconds of restart; migrations run at start, so a migration is written to be safe
   while the previous version still runs: expand first, contract in a later release).
5. On production: `git fetch --tags && git checkout v<YYYY.MM.DD.N>` then
   `bash scripts/deploy/deploy.sh --release /etc/twes-in/deploy.env`.
6. Check: the footer's build line, `/api/health`, the monitor's lines green, a sign-in.

## Point :master

`:master` is what staging follows; shops never read it. CI's publish job moves it only forward, so after a history
rewrite (the re-signing of 2026-10-24 left it on 19f0ee08) or to put staging back on an older commit, move it by hand,
logged in to GHCR with `write:packages`: `make point-master COMMIT=<commit>` (`scripts/release/point-master.sh`). It
refuses a commit master does not hold and one whose three images are missing or built from another commit, moves none
unless all three can, copies the per-commit images in the registry (nothing is built or pushed from your machine), and
prints the commit `:master` left. Tested against a stand-in registry; untried against GHCR.

## Release to the shops

Tried on the check stack (`make onprem-check`: two releases signed with a key made for the check and pinned by the shop,
the first installed, the second rolled back, then a newer unsigned tag refused with the release unchanged). Untried with
GHCR and a shop's own computer.

Once, before the first release:

1. The repository variable `PUBLISH_IMAGES=true` (GitHub, Settings, Secrets and variables, Actions, Variables), so CI's
   `publish` job pushes the images.
2. After that first publish, each of the three packages (`twes-in-api`, `twes-in-web`, `twes-in-database`) made public
   in its package settings: GitHub creates a package private, whatever the repository's visibility, and a shop pulls
   with no credential. Making a package public cannot be undone.
3. The release key: the public half is `infra/onprem/release-key.asc`, the fingerprint the one `docs/START.md` § 16
   gives and the one `scripts/onprem/install.ps1` carries; the shop gets `install.ps1` and the fingerprint from you, out
   of band (a USB key, a message, a call), never from the repository alone. The secret half never leaves your machine;
   keep a copy offline, since a lost key means every shop pins a new one by hand (`twes pin-key <file>`).
4. A tag ruleset on the repository for `v*` (GitHub, Settings, Rules, Rulesets): only you may create one, and none may
   be updated or deleted. A signed tag already stops a forged release at the shops; the ruleset stops one being pushed.

Before each release, the update a shop will take, on that shop's own files: `make update-rehearsal FILES=<directory of
its five import files> TO=<the commit to tag>` installs the release out (the newest tag, or `FROM=`) by `twes update`,
imports the files as the import screen sends them, publishes the commit and takes it by `twes update`, then reads every
table, the products list and the migrations back (about twenty minutes, on 80 and 443: never beside `make onprem-check`).
Tried on this machine: v2026.10.27.1 to c162caa3 on the hardware shop's files, nothing lost (2026-10-28).

Each release: steps 1 to 3 of « Promote to production ». Shops then take it by their policy: `auto` inside their daily
window, `notify` tells their owners, who update with `twes update`; never in the minutes after a start.

A container's logs are the container's: an update recreates every service whose image or configuration changed, and
`… logs` then reads only the new one's start. A page or a person asking a shop for a service's logs asks for them
before `twes update` (the release 28 day's backup log held the new container's first minute alone).

**A shop refused a release.** The mail and the operators' notification say « Mise à jour refusée »: the newest tag did
not verify against the key that shop pinned. Nothing changed there. Check the tag with `git verify-tag v<…>`; a tag you
did not make means someone could push to the repository: rotate the token or key that let them, delete the tag
(`git push origin :refs/tags/v<…>`), and tell the shops. A tag you made with another key: tag again with the right one,
under the next number.

## Patch a release

Tried in the scripts' own tests (`scripts/release/tests/tag-release.test.sh`, `publish.test.sh`,
`scripts/tests/twes.test.sh`: a signed patch tag on a release's branch is made, published without moving `:master`, and
taken by `twes update` with none of master's work since). Untried with GHCR and a shop.

For a fix the shops need while master carries work not ready to ship (a hotfix, the finance law's values of a new year):

1. A branch named for the release it patches, grown from its tag: `git switch -c release/2026.10.30 v2026.10.30.1`. The
   fix goes on it as signed commits (cherry-picked from master where it landed there first), then `git push origin
   release/2026.10.30`. CI runs on it as on master, and its `publish` job pushes the images by commit only
   (`publish.sh --no-master`): `:master`, which staging follows, stays on master's newest.
2. The proofs, on the branch's commit: `make update-rehearsal FILES=… FROM=v2026.10.30.1 TO=<the patch commit>` and
   `make onprem-check` from a clean checkout of it, as for any release.
3. The tag, dated after the release it patches: `make release-tag TAG=v2026.12.15.1 COMMIT=<the patch commit>`.
   `tag-release.sh` takes a commit `origin/master` does not hold only when an `origin/release/<YYYY.MM.DD>` branch holds
   it and grows from that day's release tag, and says « maintenance lane release/… from v… ». Inside the year-end freeze
   it also wants `--hotfix <why>`. Push the tag; each shop takes it as its newest by its policy.

The next release from master is tagged later, so it sorts after the patch, and a shop on the patch takes it as any other
update. The patch's own commits come back to master by cherry-pick if master does not hold them yet.

## Roll back

Tried on the check stack for staging (`make staging-check`: the deployment's images removed from the machine, the
recorded release started again, pulled back from the registry, the data kept).

A rollback starts the previous release's images again; it does not undo a migration. Check first whether the release
being left ran one: `git diff --stat <previous tag> <current tag> -- api/migrations`. If none, roll back freely. If one,
the previous code must still run on the newer schema (expand/contract makes that so); if it cannot, restore instead.

- **Production**: `git checkout <previous tag>` then `bash scripts/deploy/deploy.sh --release /etc/twes-in/deploy.env`.
- **Staging**: first stop the follower from undoing it, `echo "rolled back from <commit>: <why>" >
  /etc/twes-in/releases/hold`; then `git -C /srv/twes-in checkout --detach <previous commit>` and
  `bash /srv/twes-in/scripts/deploy/deploy.sh --staging /etc/twes-in/deploy.env /etc/twes-in/releases/<previous commit>.env`.
  Remove the hold once master carries the fix.

Before any risky release, note a restore point: the time, from the database's clock
(`docker compose … exec postgres psql -U twes -d twes -tAc 'SELECT now()'`). Point-in-time recovery reaches any moment of
the last 14 days.

## Restore

Tried on the check stack (`make backup-drill`: the database to a moment and to the latest, the files and the nightly
dump, from the bucket alone into empty volumes, then the API healthy and `app:data:check` clean).

The commands are in `docs/START.md` § 13. On the machine that lost its database:

1. Stop the stack: `docker compose --env-file /etc/twes-in/deploy.env -f compose.yaml -f compose.prod.yaml
   -f compose.backup.yaml -f compose.backup-bucket.yaml -f compose.deploy.yaml -f compose.release.yaml down` (no `--volumes`).
2. Keep what is left: `docker volume create twes-pg-broken-<date>` and copy the database volume into it, or rename the
   volume with your provider's snapshot. pgBackRest fills only an empty volume.
3. Empty the database volume (`docker volume rm <project>_postgres-data`), then the two `restore` commands of § 13, with
   the same files as above and `--env-file`.
4. Start: `deploy.sh --release` at the tag that ran.
5. `docker compose … exec api bin/console app:data:check` must find nothing, and `app:invoices:verify-chain` must answer
   every series intact. Tell the companies the window between the restore point and the incident; issued numbers in it
   are gone, and a gap in a series is a fiscal incident to explain to their accountant.

The ruling allows 4 hours back and 15 minutes lost. Neither has been measured on a real database: time the first real
restore on staging and write the figure here.

## A shop's backups say they stopped

The mail « Les sauvegardes ne tournent plus » (`installation.backup_stopped`), once a day while it lasts. Untried on a
shop's computer; each reading below is what `scripts/tests/twes.test.sh` and `backup-heartbeat.test.sh` prove of the
scripts. On the shop's computer, in the checkout, `…` standing for `bash scripts/deploy/deploy.sh --compose <values>`:

1. `bash scripts/onprem/twes status`: the backups' lines, each step's last outcome and the schedule's own,
   `due-age <seconds> ran` or `due-age <seconds> running <step>`.
2. `… logs --since 4h backup`: what each step said, a failure with its reason, « stopped after its time limit ». Read
   it before any `twes update`, which recreates the backup service and its logs with it.
3. `tail -n 40 <the values' folder>/state/twes.log`: the ticks' own account.

Then read which it was:

- **The computer slept or was off.** `twes.log` shows a gap between two ticks (they come every quarter hour) and
  « the computer slept or was off for N min ». From the release after v2026.10.25.1 a waking is never counted: the
  alert needs an hour and a half awake with the schedule silent. Up to v2026.10.25.1, the first tick after a long sleep
  alerted at once: a false alarm, nothing to do.
- **A step ran long.** The schedule's line reads `running <step>` and a fresh age: a first full backup, a weekly
  read-back, or the first off-site copy sending the whole folder. Nothing to do; every step ends within its time
  (`BACKUP_STEP_LIMIT`, 4 h; `BACKUP_OFFSITE_LIMIT`, 12 h), or fails as itself, which its own mail says, and the
  schedule tries it again at its next half hour. A step that keeps failing on its limit on a slow upload: raise
  `BACKUP_OFFSITE_LIMIT` in the values, then `twes start`.
- **The schedule itself died.** The age keeps growing with nothing running: `… ps backup` (restarting, or exited), then
  `… up -d backup` through `bash scripts/onprem/twes start`, and the logs' last lines for why.
- **The two clocks disagree.** `date -u` on the computer and `… exec -T backup date -u` minutes apart (Docker Desktop's
  virtual machine after a sleep): the age is read on the service's own clock, so the alert stays right; restart Docker
  Desktop to put its clock back.

Whichever it was, a step that failed is mailed on its own (« Sauvegarde en échec : <step> »); `… exec backup twes-backup
due` runs what is due at once.

## Restore a shop

Tried on the check stack (`make onprem-check`: a release with one migration more rolled back, then these commands run as
printed, in one block as a paste would, the database back to the backup made before the update, the operator still
signing in, the deep health green). Untried on a shop's Windows computer.

Never wipe a shop to start again: `make reset` refuses on a shop's computer (`scripts/reset-guard.sh`), and a bare
`docker compose down --volumes` in its checkout deletes the shop's database and issued PDFs with no question, since its
compose project is `twes-in` like development's. A shop goes back to a backup, through the commands below.

A shop's update backs up first and keeps that backup's label; when the update and its own rollback both fail, the mail
to `ALERT_EMAIL` carries the commands below, filled in. `twes restore-commands` prints them again at any time, for the
last update made there. They may be pasted as one block: each restore reads no input (`< /dev/null`), since `docker <!-- run-input: the command named in prose, as the reason; nobody pastes it -->
compose run` would otherwise take the lines after it as its own and they would never run. On the shop's computer, in the
checkout:

1. `bash scripts/onprem/twes hold on`: the scheduled tick, an update and the start at boot act on nothing until the hold
   ends; without it a tick in the middle of the restore starts the installation again on a database being written back.
2. `git checkout --detach <the release before the update>`: the code that ran on that backup.
3. `… deploy.sh --compose <values> stop`: every container stopped, no volume removed.
4. `… run --rm --no-deps restore gosu postgres pgbackrest … --set=<label> --type=immediate --target-action=promote --delta
   restore < /dev/null`: the database's volume written back to the end of that backup; `--delta` rewrites only what differs.
   If it stops on an invalid page checksum, that backup holds a damaged page and the database is left unable to start:
   run the line again on an older `--set` first; only when every set holds one, add `--checksum-page-error=n`, which
   restores the page as stored and lists it, and read `app:data:check` and `app:invoices:verify-chain` before the shop works.
5. `… run --rm --no-deps restore restic restore latest --tag before-update --host twes --include /files --target / < /dev/null`: the
   stored files as they were then.
6. `rm -f <the values' folder>/state/hold`: the hold ended by its file's name, since the release gone back to may predate
   `twes hold off`.
7. `bash scripts/onprem/twes start`: the release started again. Its migrate service waits until the restored database
   takes writes, as it does once promoted.
8. `twes status` must read health ok. Tell the shop the window between the backup and now: what was written in it is
   gone, and an invoice issued in it leaves a gap in its series to explain to their accountant.

## Restore a lost shop machine

Steps 5 to 8 are the ones `make backup-drill DRILL=local` runs every week in CI (scripts/backup/restore-drill.sh: the
folder lost, brought back from the off-site copy, the database to the latest, the files, the API healthy and the data
check clean). The sequence as a whole is untried on a shop's computer: try it once on a spare one before relying on it.
It needs the off-site copy (`twes offsite on`, docs/START.md § 16): without it, a lost machine is lost.

1. On the new computer, Docker and the checkout as for an install (docs/START.md § 16), but before `twes install` put
   the escrowed values back at `~/.twes-in/install.env`, mode 600, from the password manager. Without them install draws
   new passphrases, and every backup is unreadable.
2. In those values, `BACKUP_DIRECTORY` names an empty folder on the new backup drive and `BACKUP_FOLDER_ID=` is blank.
3. `bash scripts/onprem/twes install`: it asks only what the values lack (the operator: give any, the restore replaces
   them), pins the release key and starts the newest release on an empty database.
4. `bash scripts/onprem/twes hold on` (install scheduled the quarter-hour tick, which would otherwise start the stack again
   in the middle of steps 5 to 7 and write a new folder id), `… deploy.sh --compose ~/.twes-in/install.env stop`, then empty the backups' folder (it holds the empty database's
   first backup), and leave `BACKUP_FOLDER_ID=` blank again in the values.
5. The folder from the bucket: `… run --rm --no-deps -T restore sh -c 'restic -o s3.bucket-lookup=<host|path, as
   BACKUP_S3_URI_STYLE> -r "$OFFSITE_REPOSITORY" restore latest --host twes --include /backup --target /' < /dev/null`.
6. The database to the latest the folder holds: `… run --rm --no-deps restore gosu postgres pgbackrest --stanza=twes
   --spool-path=/tmp --type=default --delta restore < /dev/null`.
   If it stops on an invalid page checksum, that backup holds a damaged page and the database is left unable to start:
   run the line again on an older `--set` first; only when every set holds one, add `--checksum-page-error=n`, which
   restores the page as stored and lists it, and read `app:data:check` and `app:invoices:verify-chain` before the shop works.
7. The files: `… run --rm --no-deps restore restic restore latest --host twes --include /files --target / < /dev/null`.
8. `bash scripts/onprem/twes hold off`, then `bash scripts/onprem/twes start`: the folder is named again from its own
   `twes-in-backups.id`, the release started.
   `twes status` must read health ok, then `… exec -T api bin/console app:invoices:verify-chain` and `app:data:check`.
9. Tell the shop the window: what was written after the last off-site copy (an hour of the computer being on at most)
   is gone, and an invoice issued in it leaves a gap in its series to explain to their accountant.

## Rotate a secret

The ten values `new-instance.sh` draws. For each: change it in `/etc/twes-in/deploy.env` (keep the old one until the
procedure is done), then redeploy (`deploy.sh --release` at the running tag, or `--staging` with the running release).
Redeploy through `deploy.sh`, never a bare `docker compose up`: Compose keeps a running container whose secret's value
alone changed, reading the old one, and `deploy.sh` hands it the digest of the values (`TWES_SECRETS_DIGEST`), a label of
every service that mounts a secret, so a rotation recreates them (postgres and the backup among them, a few seconds down).
It hands the same for the files a service mounts from the checkout (`TWES_MOUNTS_DIGEST`, `scripts/deploy/mounts-digest.sh`):
an update that changes a Caddyfile or the secrets' shim recreates the edge or centrifugo, which a bare `up` would leave
reading the old file.

| Value | What changing it does | Procedure | Tried |
|---|---|---|---|
| `APP_MFA_KEY` | every enrolled authenticator becomes unreadable until resealed | stop api and both workers (`docker compose … stop api worker demo-worker`); new key in the values; `printf '%s\n' "<old key>" \| docker compose … run --rm --no-deps -T api bin/console app:keys:rotate-mfa` (it answers `Resealed N …`, or refuses and changes nothing if a secret opens with neither key); redeploy | on the check stack (`make staging-check`: an enrolled operator refused under the new key alone, signed in after the reseal) |
| `APP_SECRET` | the links already mailed to stop a kind of mail stop working; nothing else (sessions are stored in the database) | new value, redeploy | on the check stack (a link made before refused, one made after taken) |
| `REALTIME_TOKEN_KEY` | open realtime connections are dropped and reconnect with a new token | new value, redeploy (api and Centrifugo restart together) | on the check stack (the old token refused by Centrifugo, a new one taken) |
| `REALTIME_API_KEY` | nothing visible | new value, redeploy | on the check stack (the old key refused by Centrifugo's API, the new one taken) |
| `POSTGRES_PASSWORD` | nothing visible if done in this order | `docker compose … exec postgres psql -U twes -d twes -c "ALTER ROLE twes PASSWORD '<new>'"`, then the values, then redeploy | on the check stack (the old password refused, the deployment healthy) |
| `POSTGRES_APP_PASSWORD` | nothing visible: the migrate service sets the application role's password before api and worker start | new value, redeploy | untried |
| `BACKUP_FILES_PASSPHRASE` | nothing, done in place: restic keeps its repository and changes the key that opens it | `docker compose … exec backup flock /tmp/twes-backup.lock twes-secrets restic key passwd` (under the steps' lock, so no scheduled step meets it; it asks for the new passphrase), then the values, then redeploy; the escrowed copy is replaced | untried |
| `BACKUP_DATABASE_PASSPHRASE` | a new one means a new repository: pgBackRest 2.59 has no command that re-encrypts one (`pgbackrest help` lists none) | new passphrase in the values and a new `BACKUP_S3_PREFIX` (such as `2026b/`), redeploy, `docker compose … exec backup twes-backup init` then `twes-backup full`; keep the old prefix and its passphrase in escrow for 14 days, when its last backup expires, then delete it from the bucket | untried |
| `MONITOR_TOKEN` | the monitor's deep-health line goes red until both sides agree | new value in the instance's values and in the monitor's (`/etc/twes-monitor/monitor.env`), redeploy both | untried |
| `MONITOR_HEARTBEAT_TOKEN` | the backups' heartbeats are refused until both sides agree | as `MONITOR_TOKEN` | untried |

After any rotation, update the escrowed copies (`new-instance.sh` lists which). A suspected leak of one value is a
rotation now, not later.

## Replace a lost authenticator

First, whoever can still sign in replaces it themselves: with the old phone still working, a recovery code or a
passkey, they open « Vérification en deux étapes » from the account menu, choose « Remplacer l’application », prove
it is them with their password (or a passkey), scan the new code with the new app and type one of its codes. The old app
keeps working until then; once confirmed it stops, new recovery codes are shown and every other session ends
(`AuthenticatorReplacementTest`, `web/e2e/two-factor.spec.ts`). Nothing below is needed then.

The server command is for the rest. Untried on an instance; `ReplaceSecondFactorsCommandTest` runs the command against
the test database. For an account that must have a second factor (every operator, and the members of a company that requires one) and lost the phone its
authenticator lived on, with no passkey and no recovery code left: on screen it cannot remove its last factor, and no
operator can reset another's, so whoever runs the installation does it from the server.

1. Make sure it is the person: call them on a number you already had, never one the request gives.
2. `docker compose … exec -T api bin/console app:mfa:replace <their e-mail> --force` (without `--force`, from a terminal,
   it asks first). Add `--with-passkeys` when a passkey may have lived on the lost phone. It answers what it removed,
   ends every session of the account and records `auth.second_factors_replaced` in the audit, with no one as its author.
   The account is told by mail that its factors were reset, once the worker runs: if the worker is down, the mail
   leaves when it comes back (`docker compose … ps worker`).
3. They sign in with their password and are held at the set-up until they enrol a new authenticator or passkey; the new
   recovery codes come with it. Tell them to add a passkey too, so one lost phone no longer locks them out.

## The disk fills

Untried. The deep health turns red under 20 % free on the files volume (`app.health.disk_free_minimum`), and Gatus's
« Traitements » line alerts.

1. `df -h` and `docker system df` on the machine.
2. Logs cannot be it for long: every service rotates at 10 MB × 5 (`compose-log-rotation.sh` holds it).
3. The WAL spool: if the bucket is unreachable, pgBackRest queues up to 4 GiB in `pgbackrest-spool`, then drops the
   oldest and the point-in-time window gets a hole (the archive line of the deep health is red). Fix the bucket first.
4. Old images: `docker image prune -a` removes images no container uses; the previous release's are pulled back by a
   rollback anyway.
5. Still full: grow the disk at the provider. Do not delete volumes.

## A shop's clock ran ahead

Untried. A computer whose clock ran ahead numbers documents on days still to come. Once the clock is right again,
numbering refuses every new document: its day is before the last one numbered (`before_last_day`, said on the screen with
both days and « vérifiez l'horloge de l'ordinateur »), or in an earlier month or year than a sequence that has started
again there (`before_last_period`). Nothing is lost; nothing new is numbered until this is done.

1. Put the clock right first: Windows' date and time, then `twes status` (under WSL, a clock off by more than a minute is
   said there and alerted). Allowing earlier days on a clock still wrong moves the problem, it does not end it.
2. Find the company: `docker compose … exec -T api bin/console app:numbering:allow-earlier-days --help`, and its id in
   « Plateforme › Entreprises » (the address of its page).
3. `docker compose … exec -T api bin/console app:numbering:allow-earlier-days <company id> <hours>`: every series the
   company numbers with takes a day before its last number's until then (at most
   `app.numbering.earlier_days_hours_max`, 72), one line per series in its trail. It answers the moment it ends.
4. The documents numbered meanwhile keep their numbers in order, dated as issued: an invoice numbered after one dated
   on a day still to come carries an earlier date than it. Tell the company's accountant which numbers and days, from
   the invoices list sorted by number.
5. The allowance never takes a monthly or yearly sequence back into an earlier month or year once it has started again
   there: those numbers were given, and giving them again would number two documents alike. Such a series stays
   refused until the clock reaches its last number's period. Do not edit the series: call the developer, who asks the
   company's accountant what the law there wants (an open question, stable-release plan row S1.9b).
6. Backups need nothing: a step whose last success is dated after now is read as never done, so the next half-hourly
   run takes a full backup, the copy and the read-back again; `twes tick` counts the schedule's silence from the tick
   that first read its last word dated ahead, and alerts after an hour and a half of it as usual.

## The queue is stuck

Untried. Mails (invitations, password resets, notifications) leave through the Messenger queue on PostgreSQL; the deep
health turns red when a message waited over 600 seconds or any message failed for good.

1. Is the worker running? `docker compose … ps worker` and `… logs --tail 100 worker`. The demo sandboxes' builds are
   the `demo-worker`'s, on a queue of their own, so a mail never waits behind one.
2. What waits: `docker compose … exec api bin/console messenger:stats`.
3. What failed for good after three tries: `… exec api bin/console messenger:failed:show`. Fix the cause (the mail
   relay, usually), then `messenger:failed:retry`.
4. Restart the worker: `docker compose … restart worker`. With api and worker restarted together on a live cache, start
   one after the other.

## A stock movement went missing

Untried on staging; `app:stock:replay-delivery-note` and `app:stock:replay-invoice` have their tests.

The nightly `app:data:check` mails the operators the check, the company and a count, never a document's contents.
Stock moves when a delivery note is validated or cancelled, or an invoice or credit note issued, in a listener after the
commit, so a crash between the two can lose the move; the stock keepers are notified with the document's number.
A move that failed with an error is recorded: the document's page offers « Remettre le stock à jour » and « C'est réglé »
to whoever writes the stock, and « À surveiller » lists it, so the company puts it right itself. The steps below are for
a move lost without a record (a crash), or a company that asks.

1. Run the check by hand to see the current state: `docker compose … exec api bin/console app:data:check`.
2. For a delivery note whose move is missing: `… exec api bin/console app:stock:replay-delivery-note <company id>
   <delivery note id>`. It moves what the note says, and answers « already moved: nothing to do » for a note whose stock moved; either
   way the note's recorded failure is settled with it, and leaves its page.
3. For an issued invoice or credit note whose move is missing: `… exec api bin/console app:stock:replay-invoice <company id>
   <invoice id>`. It runs the move its issue ran, from the same lines, and answers the same way for one whose stock moved.
4. Anything else the check names has no replay command yet: read the record, correct it through the screens (an
   inventory adjustment says why), and tell the company.

## A suspected breach

Untried. The clocks that matter, under the RGPD: for the companies' data twes-in is their processor and tells each
affected company without undue delay (art. 33(2)), because each, as controller, has 72 hours from becoming aware to
notify its authority (art. 33(1)); for what twes-in controls itself (the accounts people sign in with), twes-in notifies
the CNIL within those 72 hours. The lawyer confirms both before the first French customer; for Tunisia (loi 2004-63),
ask the lawyer before the pilot what is owed and to whom [unverified: not researched here].

1. Write down what you saw and when, before touching anything.
2. Contain: if the machine itself is suspect, take a snapshot at the provider first (evidence), then stop the edge
   (`docker compose … stop edge`): the instance stops answering.
3. Every session ends: `docker compose … exec postgres psql -U twes -d twes -c 'TRUNCATE sessions'`.
4. Rotate every secret (above), the bucket keys, the mail relay's password, and your own accounts (GitHub, the
   provider, the registry). An attacker on the machine had the values file.
5. Read the audit log for what was done and by whom: `SELECT at, company_id, entity_type, entity_id, action, actor_user_id, ip FROM
   audit_log WHERE at > '<since>' ORDER BY at`. It records which fields changed, never their values.
6. If the machine is suspect, rebuild it: a new machine, a restore from before the intrusion, new values.
7. Tell the companies now and notify within the 72 hours where twes-in is controller, then write what happened and what
   changed here.

## The monitor alerts

The monitor check proves the readings, not the alerts (`make monitor-check`).

| Line | Means | First look |
|---|---|---|
| Site | the edge does not answer, or its certificate has under two weeks left | the machine, then `docker compose … logs edge` (Let's Encrypt renewals) |
| Site, 502 or 504 | the edge answers but the api does not | `docker compose … logs web`: a request's line ends `upstream="<address>" <status> <connect time>`, « - » for no connection (nginx writes no error line about a request: it would carry a link's token), then `logs api` |
| Traitements | `/api/health/deep` answered 503 | its body names the failing part: the queue (above), the disk (above), the archive (the bucket), or `startSteps`: a start step that failed (`readings.failedStartSteps` names it; `docker compose exec api cat var/start-steps/<step>.failed` shows what it said; the server started without it, and the next start runs it again) |
| Base / Copie / Relecture | a backup step did not report in time | `docker compose … logs backup`; run the step by hand, `… exec backup twes-backup diff` (or `copy`, `verify`) |
| Suivi des erreurs | GlitchTip itself is down | the monitor's machine |

A new error in GlitchTip that repeats is a bug for master; one that comes with a red line is an incident here.
