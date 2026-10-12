# backups Plan

Stage 1 step 4 (P2, absorbs SPEC § 8 row 58): point-in-time backups off-site and a restore drill, against
SPEC § 7 2026-10-12 00:23 (at most 15 minutes lost, back within 4 hours, a nightly dump, a drill in CI and by hand).

## Decisions Log
- [2026-10-12 10:12] AGREED: PostgreSQL backups by pgBackRest (MIT, 2.59.3, from the PGDG repo the postgres image already uses): WAL archive for point-in-time restore plus base backups, encrypted, to an S3 repository (developer)
- [2026-10-12 10:12] AGREED: the files volume and the nightly pg_dump go off-site by restic (BSD-2-Clause), encrypted snapshots in the same bucket (developer)
- [2026-10-12 10:12] AGREED: SeaweedFS (Apache-2.0) stands in for the off-site bucket in CI and local drills, never shipped; MinIO is out, archived and AGPL (developer)
- [2026-10-12 10:12] AGREED: the CI restore drill runs weekly, by hand, and on every push touching the backup files (developer)
- [2026-10-12 10:32] AGREED: plan approved: opt-in overlay compose.backup.yaml, pgBackRest sidecar, restic, the drill on the production images under its own compose project (developer)
- [2026-10-12 10:32] AGREED: pgBackRest, restic and supercronic inside the postgres image are aggregated like the base images: named in the notices sentence and their licences recorded beside their pins in docs/UPDATE.md, the licence gate unchanged (developer)
- [2026-10-12 10:32] AGREED: row 58's file deletion with legal retention moves to its own SPEC § 8 row after stage 1; row 58 closes with the backups once CI is green (developer)

## Formal Plan

Shape (spiked 2026-10-12, scratchpad, removed): an opt-in overlay `compose.backup.yaml` over compose.yaml and
compose.prod.yaml. The postgres service is built from `infra/postgres/Dockerfile` (the same tag, plus pgBackRest
2.59.3, restic, supercronic) and archives WAL asynchronously (`archive_timeout=300`, `archive-push-queue-max`); a
`backup` sidecar on the same image, as the postgres uid over the shared socket, runs the schedule. pgBackRest and restic
write under separate prefixes of one bucket, both encrypted, secrets from the environment (`:?`). The spike proved TLS
to SeaweedFS with our own CA and a restore to a point in time into an EMPTY volume (row after the backup kept, row after
the target absent, timeline 2).

1. Gates first: version-pins reads the Dockerfile's `FROM postgres:` and the `pg1-path` major against compose.yaml;
   compose-log-rotation and ProductionRestartTest read the overlay too. Each gate test reds before the files exist.
2. The image and the overlay: Dockerfile (spool directory owned by postgres, supercronic by `ADD --checksum`),
   `pgbackrest.conf`, the crontab (full weekly, differential nightly, `pg_dump` nightly, restic of the dump and the
   files volume, `forget --keep-within 14d --prune`, a weekly `restic check` and `pgbackrest verify`).
3. The drill, `scripts/backup/restore-drill.sh` and `make backup-drill`: its own compose project `twes-drill` (refuses
   any other), the production images, SeaweedFS with a throwaway CA; backup, write row 1, read T from the database's
   `now()`, write row 2, switch WAL; restore into fresh volumes twice (to T expects only row 1, to latest expects both);
   the restored api answers health and `app:data:check` exits 0; restic restores the files and the dump, checksums
   compared. Prints each timing.
4. CI `.github/workflows/restore-drill.yml`: weekly, by hand, and on pushes touching infra/postgres, the overlay,
   scripts/backup or itself; not a required check.
5. Sabotages: archive_command broken, restore target ignored, restic skipped, each gate's mutant: each red, restored.
6. Docs: START.md (turning backups on, the secrets, restoring), UPDATE.md (four new pins; `stanza-upgrade` on a major
   bump), the notices sentence, SPEC § 7 and § 8 rows 58 and 270.

Not certified by this step: the 4-hour return at real size (a CI timing on fixtures is not it); the drill by hand on a
fresh machine from the real bucket (after P1); the unforced loss window (certified by configuration only:
`archive_timeout` ≤ 300 s); WAL dropped once the queue is full (silent until P3 alerts); key escrow (P4); a
`FILES_STORAGE=s3` deployment relies on its bucket's own versioning.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | Gates read the image, the major and the overlay | S | todo | - | scripts/gates/** api/tests/Architecture/** |
| 2 | Postgres image, pgBackRest, restic, schedule, overlay | M | todo | - | infra/postgres/** compose.backup.yaml |
| 3 | Restore drill script and make target | M | todo | - | scripts/backup/** Makefile |
| 4 | CI restore-drill workflow | S | todo | - | .github/workflows/** |
| 5 | Sabotages | S | todo | - | - |
| 6 | Docs, notices, SPEC rows 58 and 270 | S | todo | - | docs/** THIRD-PARTY-NOTICES.md scripts/notices/** |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
