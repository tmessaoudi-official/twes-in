# staging-runbook Plan

Stage 1 step 7, first half: P4 of the readiness page (staging on every green master, promoted by image digest;
`docs/RUNBOOK.md`, each procedure tried on staging). The threat model (P7) and continuity (P10) follow as their own goals.

## Decisions Log
- [2026-10-12 22:07] AGREED: staging runs on its own small VM, the same four compose files with its own values (DEPLOY_ENV=staging, its own host), so a runbook drill there never touches production (developer).
- [2026-10-12 22:07] AGREED: staging follows green master by pulling: a timer on the staging machine reads the newest master digest manifest from GHCR and deploys by digest; GitHub holds no credential reaching a server (developer).
- [2026-10-12 22:07] AGREED: the three images twes-in builds (api, web, postgres-backup) are published to GHCR as public packages, once checked to carry no secret; the first publish is shown to the developer before it runs (developer).
- [2026-10-12 22:07] AGREED: this goal builds app:keys:rotate-mfa, re-encrypting every stored second factor from the previous APP_MFA_KEY in one transaction, test first, so the runbook rotates all four secrets (developer).
- [2026-10-12 22:16] AGREED: plan approved: rotate-mfa, the digest overlay, publish behind PUBLISH_IMAGES, three deploy modes with the release tag carrying the digests, make staging-check, docs/RUNBOOK.md (developer).
- [2026-10-12 22:16] AGREED: staging has its own GlitchTip project and Gatus lines kept off the public status page, alerting the developer only (developer).
- [2026-10-12 22:16] AGREED: APP_MFA_KEY is rotated with api and worker stopped around app:keys:rotate-mfa; the cipher keeps one key (developer).
- [2026-10-12 22:36] ASSUMED (review): app:keys:rotate-mfa records no audit entry: it reseals the same secrets under a new key on the console, with the service stopped, and no person's account changes, so it is listed in CommandsAndQueriesTest beside the seed and the chain sealing. Alternative: one audit entry per resealed account (field totpSecret).
- [2026-10-12 23:19] ASSUMED (review): Staging's backups sit under BACKUP_S3_PREFIX=staging/ (pgBackRest and restic paths moved into compose.backup.yaml; production's stay at the bucket root), on top of staging having its own bucket and keys — because a staging handed production's bucket by mistake then still never writes over its backups. Alternatives: own bucket only; derive the path from DEPLOY_ENV (would move production's path).
- [2026-10-12 23:19] ASSUMED (review): A file releases/hold beside staging's values pauses staging-follow.sh, and a commit that failed to start is not retried until master moves — because otherwise a rollback on staging is undone on the next five-minute tick, and a broken commit is redeployed every tick. Alternatives: no hold (rollback impossible while following); stop the timer by hand.

## Formal Plan

1. `app:keys:rotate-mfa` (Identity): reads `APP_MFA_KEY_PREVIOUS`, opens every `user.totp_secret` with it first and
   refuses if any fails, then rewrites all in one transaction; run with api and worker stopped (the runbook says so).
   Test first; sabotage: a partial rewrite, a value opened with the wrong key.
2. `compose.release.yaml`: every service on a built image (api, worker, web, postgres, backup, restore) takes
   `${TWES_*_IMAGE:?}` pinned `@sha256:` and `build: !reset`; an architecture test merges the deploy config with it and
   refuses any `build` key left or an image not pinned by digest.
3. `scripts/release/publish.sh <prefix>`: builds the three images from a clean checkout with the build version and an
   `org.opencontainers.image.revision` label, refuses an image holding `.env.local` or a key file, pushes `sha-<sha>`, then
   moves `:master` only when the commit descends from the revision `:master` names. `ci.yml` gains a `publish` job
   (needs every job, push to master, `if: vars.PUBLISH_IMAGES == 'true'`, `packages: write`); the variable is set only
   after the developer approves the first publish.
4. Deploy modes: `deploy.sh --release` (production: HEAD at a release tag, digests read from the annotated tag's message,
   written by `scripts/release/tag-release.sh`, which resolves them once), `--staging <sha>` (any master commit, digests
   resolved and recorded on the machine), `--build` (today's behaviour, for self-hosters). `new-instance.sh --staging`:
   `DEPLOY_ENV=staging`, its own backup repository path, never production's. `scripts/deploy/staging-follow.sh` plus a
   systemd timer under `infra/staging/`: fetch, check the three revisions agree, check out, deploy by digest.
5. `make staging-check` (+ weekly workflow and on its paths): a local registry stands in for GHCR; publish, follow,
   prove the running containers are those digests and nothing was built, redeploy the recorded release after removing
   the local images (the rollback path), and drill the rotations (MFA with an enrolled user still signing in, APP_SECRET,
   the realtime keys, the database password).
6. `docs/RUNBOOK.md`: deploy, promote, rollback (previous digests plus a restore point), restore (§ 13), every one of the
   nine generated values' rotation (pgBackRest's passphrase means a new repository), a full disk, a stuck queue, a lost
   stock event, a suspected breach (the 72-hour notice), the monitor's alerts; each says « tried on the check stack »
   or « untried ». START.md, UPDATE.md (registry pin), CLAUDE.md, SPEC § 7 and row 270.

Not certified until a real staging VM exists: GHCR itself, public visibility, the timer, a real certificate.

### Step 5 notes (goal-start advisor, 2026-10-12)
- 5a round trip, then 5b rotations, two commits; each check run in the foreground, never beside a gate (swap near full).
- The worktree and the bare origin are built from a commit of the work in progress (`git stash create`), or the check runs old files; the header says which.
- The local registry is a `docker run` named for the check, bound to 127.0.0.1, removed by the trap, outside every file `deploy-overlay.sh` and `compose-log-rotation.sh` read; its image pinned with a row in docs/UPDATE.md.
- `DEPLOY_EXTRA_FILES` in deploy.sh only with `--staging` and `COMPOSE_PROJECT_NAME=twes-staging-check`, refused otherwise, with a refusal case in deploy.test.sh.
- Prove: each container's image id is its digest's; no `twes-staging-check-*` image built; sign-in through the edge; after `down` and removing the images, `releases/<rev>.env` redeploys by pulling.
- Rotations assert what each key really guards: APP_SECRET = `kernel.secret`, a mail-stop link made before is refused after (HmacMailStopTokens), not the session; realtime = the old HTTP API key refused by Centrifugo and a token issued after connects (the 101 upgrade alone proves nothing); MFA = an enrolled user, codes from `OtphpTotpCodes::codeAt` (as `make operator-code`), api and worker stopped; database password = `ALTER ROLE` then the values, redeploy, health ok.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | app:keys:rotate-mfa | M | done | f2fbe39f | api/src/Identity/** api/tests/** |
| 2 | compose.release.yaml digest overlay + gate | M | done | 9eba21b1 | compose.release.yaml scripts/gates/** Makefile |
| 3 | publish.sh + ci.yml publish job | M | done | 7cbbebe8 | scripts/release/** .github/workflows/ci.yml |
| 4 | deploy modes, new-instance --staging, staging-follow + timer | M | done | 531c4e84 | scripts/deploy/** infra/staging/** |
| 5 | make staging-check + workflow | L | doing | - | scripts/staging/** Makefile .github/workflows/** |
| 6 | RUNBOOK.md + docs | M | todo | - | docs/** CLAUDE.md |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
