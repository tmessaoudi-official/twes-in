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
| Staging | the same files, with its own values (`DEPLOY_ENV=staging`), its own bucket and keys | `staging-follow.sh`, every five minutes, from the registry |
| Monitor | `infra/monitor/compose.yaml`, on a machine of its own | by hand, `git pull` then `up -d --wait` |

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
   differ, then `sudo systemctl daemon-reload && sudo systemctl enable --now twes-staging-follow.timer`.
7. The first operator, as in `docs/START.md` § 14.

Watch it work: `journalctl -u twes-staging-follow -f`. Each run says `waiting` (the three `:master` images do not agree
yet, a publish is under way), `already deployed here`, `already failed here`, `held by …`, or `deploying <commit>`.

## Deploy to staging

Tried on the check stack (`make staging-check`: publish, follow, every container proved to run its digest, nothing
built, a second follow doing nothing).

Nothing to do: merge to master; once CI is green it publishes, and staging follows within five minutes. To follow now,
`sudo systemctl start twes-staging-follow`.

A commit that failed to start is written down as `failed` and not tried again until master moves on. Read why with
`journalctl -u twes-staging-follow` and the stack's logs (`docker compose … logs api`), fix it on master, and the next
commit is followed.

## Promote to production

Untried end to end; `tag-release.sh` and `deploy.sh --release` each have their test (`scripts/release/tests/`,
`scripts/tests/deploy.test.sh`).

1. Pick the commit staging runs and you have tried there: `tail -1 /etc/twes-in/releases/history` on staging.
2. On your machine, at that commit of master (`git fetch && git checkout <commit>`):
   `bash scripts/release/tag-release.sh ghcr.io/tmessaoudi-official v<YYYY.MM.DD.N>`. It refuses a commit master does
   not hold and images that say they were built from another; it writes the three digests into the annotated tag.
3. Push the tag: `git push origin v<YYYY.MM.DD.N>`. A pushed tag is never moved.
4. Announce the window (about 30 seconds of restart; migrations run at start, so a migration is written to be safe
   while the previous version still runs: expand first, contract in a later release).
5. On production: `git fetch --tags && git checkout v<YYYY.MM.DD.N>` then
   `bash scripts/deploy/deploy.sh --release /etc/twes-in/deploy.env`.
6. Check: the footer's build line, `/api/health`, the monitor's lines green, a sign-in.

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

## Restore a shop

Tried on the check stack (`make onprem-check`: a release with one migration more rolled back, then these commands run as
printed, in one block as a paste would, the database back to the backup made before the update, the operator still
signing in, the deep health green). Untried on a shop's Windows computer.

A shop's update backs up first and keeps that backup's label; when the update and its own rollback both fail, the mail
to `ALERT_EMAIL` carries the commands below, filled in. `twes restore-commands` prints them again at any time, for the
last update made there. They may be pasted as one block: each restore reads no input (`< /dev/null`), since `docker
compose run` would otherwise take the lines after it as its own and they would never run. On the shop's computer, in the
checkout:

1. `git checkout --detach <the release before the update>`: the code that ran on that backup.
2. `… deploy.sh --compose <values> stop`: every container stopped, no volume removed.
3. `… run --rm --no-deps restore gosu postgres pgbackrest … --set=<label> --type=immediate --target-action=promote --delta
   restore < /dev/null`: the database's volume written back to the end of that backup; `--delta` rewrites only what differs.
4. `… run --rm --no-deps restore restic restore latest --tag before-update --host twes --include /files --target / < /dev/null`: the
   stored files as they were then.
5. `bash scripts/onprem/twes start`: the release started again. Its migrate service waits until the restored database
   takes writes, as it does once promoted.
6. `twes status` must read health ok. Tell the shop the window between the backup and now: what was written in it is
   gone, and an invoice issued in it leaves a gap in its series to explain to their accountant.

## Rotate a secret

The ten values `new-instance.sh` draws. For each: change it in `/etc/twes-in/deploy.env` (keep the old one until the
procedure is done), then redeploy (`deploy.sh --release` at the running tag, or `--staging` with the running release).

| Value | What changing it does | Procedure | Tried |
|---|---|---|---|
| `APP_MFA_KEY` | every enrolled authenticator becomes unreadable until resealed | stop api and worker (`docker compose … stop api worker`); new key in the values; `printf '%s\n' "<old key>" \| docker compose … run --rm --no-deps -T api bin/console app:keys:rotate-mfa` (it answers `Resealed N …`, or refuses and changes nothing if a secret opens with neither key); redeploy | on the check stack (`make staging-check`: an enrolled operator refused under the new key alone, signed in after the reseal) |
| `APP_SECRET` | the links already mailed to stop a kind of mail stop working; nothing else (sessions are stored in the database) | new value, redeploy | on the check stack (a link made before refused, one made after taken) |
| `REALTIME_TOKEN_KEY` | open realtime connections are dropped and reconnect with a new token | new value, redeploy (api and Centrifugo restart together) | on the check stack (the old token refused by Centrifugo, a new one taken) |
| `REALTIME_API_KEY` | nothing visible | new value, redeploy | on the check stack (the old key refused by Centrifugo's API, the new one taken) |
| `POSTGRES_PASSWORD` | nothing visible if done in this order | `docker compose … exec postgres psql -U twes -d twes -c "ALTER ROLE twes PASSWORD '<new>'"`, then the values, then redeploy | on the check stack (the old password refused, the deployment healthy) |
| `POSTGRES_APP_PASSWORD` | nothing visible: the migrate service sets the application role's password before api and worker start | new value, redeploy | untried |
| `BACKUP_FILES_PASSPHRASE` | nothing, done in place: restic keeps its repository and changes the key that opens it | `docker compose … exec backup restic key passwd` (it asks for the new passphrase), then the values, then redeploy; the escrowed copy is replaced | untried |
| `BACKUP_DATABASE_PASSPHRASE` | a new one means a new repository: pgBackRest 2.59 has no command that re-encrypts one (`pgbackrest help` lists none) | new passphrase in the values and a new `BACKUP_S3_PREFIX` (such as `2026b/`), redeploy, `docker compose … exec backup twes-backup init` then `twes-backup full`; keep the old prefix and its passphrase in escrow for 14 days, when its last backup expires, then delete it from the bucket | untried |
| `MONITOR_TOKEN` | the monitor's deep-health line goes red until both sides agree | new value in the instance's values and in the monitor's (`/etc/twes-monitor/monitor.env`), redeploy both | untried |
| `MONITOR_HEARTBEAT_TOKEN` | the backups' heartbeats are refused until both sides agree | as `MONITOR_TOKEN` | untried |

After any rotation, update the escrowed copies (`new-instance.sh` lists which). A suspected leak of one value is a
rotation now, not later.

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

## The queue is stuck

Untried. Mails (invitations, password resets, notifications) leave through the Messenger queue on PostgreSQL; the deep
health turns red when a message waited over 600 seconds or any message failed for good.

1. Is the worker running? `docker compose … ps worker` and `… logs --tail 100 worker`.
2. What waits: `docker compose … exec api bin/console messenger:stats`.
3. What failed for good after three tries: `… exec api bin/console messenger:failed:show`. Fix the cause (the mail
   relay, usually), then `messenger:failed:retry`.
4. Restart the worker: `docker compose … restart worker`. With api and worker restarted together on a live cache, start
   one after the other.

## A stock movement went missing

Untried on staging; `app:stock:replay-delivery-note` has its test.

The nightly `app:data:check` mails the operators the check, the company and a count, never a document's contents.
Stock moves when a delivery note is validated or cancelled in a listener after the commit, so a crash between the two can
lose the move.

1. Run the check by hand to see the current state: `docker compose … exec api bin/console app:data:check`.
2. For a delivery note whose move is missing: `… exec api bin/console app:stock:replay-delivery-note <company id>
   <delivery note id>`. It moves what the note says, and answers « already moved: nothing to do » for a note whose stock moved.
3. Anything else the check names has no replay command yet: read the record, correct it through the screens (an
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
| Traitements | `/api/health/deep` answered 503 | its body names the failing part: the queue (above), the disk (above), the archive (the bucket) |
| Base / Copie / Relecture | a backup step did not report in time | `docker compose … logs backup`; run the step by hand, `… exec backup twes-backup diff` (or `copy`, `verify`) |
| Suivi des erreurs | GlitchTip itself is down | the monitor's machine |

A new error in GlitchTip that repeats is a bug for master; one that comes with a red line is an incident here.
