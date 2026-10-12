# deploy Plan

Stage 1 step 5 (P1, SPEC § 8 row 270): a production deployment whose region is a setting, with TLS, HSTS and real
secrets (SPEC § 7 2026-10-12 00:23; the readiness page § 2).

## Decisions Log
- [2026-10-12 12:46] AGREED: the deployment is compose.deploy.yaml layered over compose.yaml, compose.prod.yaml and compose.backup.yaml (backups mandatory): a Caddy edge with automatic certificates, only 80 and 443 published, no mailpit; images built on the server from a clean checkout of a release tag; deploy by GHCR digest comes with staging (developer)
- [2026-10-12 12:46] AGREED: the region is a setting: nothing provider-specific (any VM, bucket and SMTP relay through their variables), and the operator records the host (name, address, country) as platform settings the legal pages' mentions read (developer)
- [2026-10-12 12:46] AGREED: HSTS max-age=31536000 on the app host only, no includeSubDomains, no preload (developer)
- [2026-10-12 12:46] AGREED: secrets reach the server in one env file outside the repo (/etc/twes-in/deploy.env, mode 600) passed with --env-file, every variable required with :?, the database password included; scripts/deploy/new-instance.sh writes the random ones once and says which to escrow (developer)
- [2026-10-12 12:50] AGREED: plan approved: the edge overlay with HSTS, addresses from TWES_HOST, app:seed --without-company with the password from stdin, new-instance.sh and deploy.sh from a clean release tag vYYYY.MM.DD.N, host country and backup provider settings, make deploy-check and its workflow (developer)

## Formal Plan

1. **Tests first.**
   - `DeployOverlayTest` reads the four production files merged (`compose.yaml`, `compose.prod.yaml`, `compose.backup.yaml`, `compose.deploy.yaml`) and asserts:
     - only the edge publishes, on 80, 443 and 443/udp;
     - api and worker resolve the same `DEFAULT_URI`, `MAILER_*`, `DATABASE_URL`, passkey RP id and origins, all derived from `TWES_HOST`;
     - `POSTGRES_PASSWORD`, `MAILER_*`, `TWES_HOST` and `EDGE_TLS` are required with `:?` and have no default;
     - mailpit sits behind a profile;
     - the Caddyfile's HSTS line is exactly `max-age=31536000`.
   - The version-pins gate reads the edge's Caddy pin against `lan`'s.
   - Log rotation and `ProductionRestartTest` read the new overlay.
   - Each check reds before the files exist.
2. **The edge and the overlay.**
   - `infra/edge/Caddyfile`: `{$TWES_HOST}`, `tls {$EDGE_TLS}` (an ACME e-mail address, or `internal` for checks), HSTS, `reverse_proxy web:80`; admin off.
   - `compose.deploy.yaml`:
     - the `edge` service, with restart, rotated logs and volumes for its data and config;
     - `ports: !reset []` everywhere else; mailpit behind a profile;
     - the derived addresses on api, worker and centrifugo, each written out, since anchors do not cross files;
     - the required variables; and `FILES_STORAGE` and the `S3` variables passed through.
3. **First operator in production.**
   - `app:seed --without-company`: roles, the operator and the presets' regimes, and no company or membership.
   - `--operator-password-stdin` reads the password from stdin.
   - Both written test-first in `SeedPlatformTest` and `SeedCommandTest`.
4. **New instance.**
   - `scripts/deploy/new-instance.sh <file>`:
     - writes random secrets (app, MFA, realtime ×2, postgres, both backup passphrases) and blank facts (`TWES_HOST`, `EDGE_TLS`, `MAILER_*`, `BACKUP_S3_*`);
     - refuses an existing file, sets mode 600, and lists the values to escrow apart from the server.
   - `scripts/deploy/deploy.sh <env file>`: refuses unless HEAD is exactly a release tag and `build-version.sh` reports nothing `-dirty`, then runs `up -d --build --wait` with the four files.
   - Tags `vYYYY.MM.DD.N` are made by hand and pushed only on the developer's word.
   - Both scripts have shell tests through `no-leftovers.sh`.
5. **Region as settings.**
   - `legal.host.country` (where the data sits) and `legal.backup.provider` (who holds the off-site copies, and where), with fr/en labels and placeholders in the DPA and privacy drafts (fr/en/ar).
   - The question of transfers out of the country stays « À compléter » for the lawyer.
   - Tripwire: `fr.json` has about 285 B of gzip budget left. If it overflows, stop and ask rather than trim.
6. **`make deploy-check`, and its own workflow** (on deploy paths, `framework.yaml` and `nginx.conf` included; weekly; by hand).
   - It boots the four files plus the drill's SeaweedFS and CA (`EDGE_TLS=internal`, its own project, edge ports remapped), with backup init proven healthy.
   - Then it checks through the edge:
     - the HSTS value;
     - HTTP→HTTPS 308;
     - `/api/health` ok;
     - a sign-in, then `GET /api/auth/me` returns 200 with the cookie;
     - the WebSocket upgrade on `/connection/websocket`;
     - a password-reset burst with a forged `X-Forwarded-For` still reaches 429.
7. **Sabotages.** HSTS removed, postgres port left published, the worker's address differing, Caddy trusting client `X-Forwarded-For`, `--without-company` still creating one, `deploy.sh` on a dirty tree. Each must go red, then be restored.
8. **Docs.**
   - START.md § 14, « Déployer »: the server, DNS, the firewall (published Docker ports bypass ufw), `new-instance.sh`, escrow, a tag, `deploy.sh`, the first operator, the host settings.
   - UPDATE.md: the Caddy copies.
   - SPEC § 7 (these rulings) and § 8 row 270; CLAUDE.md « Where things live ».

Not certified by this goal: real ACME issuance and renewal, a real SMTP relay, DNS, HTTP/3, a first deploy on a real VM (the runbook step), and GHCR digests (staging).

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | Tests and gates read the deploy overlay | S | done | f23d8371 | api/tests/Architecture/** scripts/gates/** |
| 2 | Edge Caddyfile and compose.deploy.yaml | M | done | f23d8371 | infra/edge/** compose.deploy.yaml |
| 3 | app:seed --without-company, password from stdin | S | done | f23d8371 | api/src/Tenancy/** api/tests/** |
| 4 | new-instance.sh, deploy.sh, release tags | M | done | f23d8371 | scripts/deploy/** |
| 5 | Host country and backup provider settings, drafts | S | done | f23d8371 | api/src/Legal/** api/resources/legal/** web/public/i18n/** |
| 6 | make deploy-check and its workflow | M | done | f23d8371 | scripts/deploy/** .github/workflows/** Makefile |
| 7 | Sabotages | S | done | f23d8371 | - |
| 8 | Docs, SPEC, CLAUDE.md | S | done | f23d8371 | docs/** CLAUDE.md |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
