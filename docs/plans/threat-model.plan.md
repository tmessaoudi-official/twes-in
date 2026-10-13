# threat-model Plan

Stage 1 step 7, second half: P7 of the readiness page. `docs/THREAT-MODEL.md` (STRIDE per trust boundary, every
control cited with the test that proves it, every residual risk named) and every finding of the evidence pass fixed, each
test first with the attack reproduced, then sabotaged. The evidence (`var/claude/threat-model-evidence.md`) stays out of
the tree. The repository is public: an open finding is described here and in commit messages only in general terms until
its fix is on master, and the threat model lands last. P10 (continuity) follows as its own goal.

## Decisions Log
- [2026-10-13 00:04] AGREED: plan approved: every finding of the threat-model evidence fixed in six waves, each test first and sabotaged, then docs/THREAT-MODEL.md; open findings named only in general terms in the public tree until fixed (developer).
- [2026-10-13 00:04] AGREED: personal data in the audit journal: the application cannot rewrite the journal; one narrow database function, called only by an account's erasure, replaces that person's address with the erased placeholder, and purges failed-sign-in addresses after 12 months from the nightly run (developer).
- [2026-10-13 00:04] AGREED: backups: the bucket keeps objects under S3 Object Lock, 14 days in governance mode; the server's key cannot delete; pruning runs from the monitor machine with a key the server never holds (developer).
- [2026-10-13 00:04] AGREED: release signing: CI signs each published image with cosign keyless (GitHub OIDC) and deploy.sh refuses an image not signed by this repository's publish workflow; release tags are signed with the developer's SSH key, checked against a committed allowed_signers file; cosign (Apache-2.0) is a new tool on CI and the servers (developer).
- [2026-10-13 00:04] ASSUMED (review): user-visible changes from the threat-model fixes: a step-up before adding or removing a second factor; one wording for any failed sign-in; everyone signed out once by the __Host- cookie rename; single-use links in mails carry the token after #; the mail-stop link keeps never expiring (idempotent, as List-Unsubscribe expects). Alternatives: per item, keep today's behaviour and record the residual risk.
- [2026-10-13 07:35] AGREED: a locked or deactivated account is named only to the right password; a wrong one answers invalid_credentials as an unknown address does (amends the 2026-09-22 14:05 entry, which told the lock to any attempt)

## Formal Plan

Each wave: failing tests reproducing the attack, the fix, one sabotage per guarantee, the gates, one commit, CI green
before the next push.

1. **W1, request handling and platform roles (High).** Every place that decides by the request's path decides on what the
   router decodes, once (`rawurldecode`), with functional cases for encoded letters, an encoded uuid digit, `%2565` (stays
   encoded) and `%2F`; reproduced once with curl through nginx. The platform's owner invitation is limited to what its own
   description says. Account for every `getPathInfo`/`getRequestUri` hit and every path rule in nginx.
2. **W2, sign-in and session.** Step-up around second-factor changes; one answer for a failed sign-in whatever the
   account's state, and the same work for an unknown address; second-factor spends under a row lock; the `__Host-` cookie and
   the session id renewed after the second factor; only `X-Forwarded-For`/`-Proto` trusted; limiters on the remaining public
   token routes; one answer for any phone-pairing failure, limiters after the key check; the unauthenticated sweep reads
   methods and exact routes; development options of `app:seed` and development secrets refused in prod.
3. **W3, tenancy and data.** A router-wide test that every `/companies/{companyId}` route answers 404 to a member of another
   company; a test that every handler and scheduled task names its company; the application connects as a role that is not a
   superuser and cannot rewrite the journal, migrations as the owner (reaches pgBackRest, the drills, every `*-check`, the
   test database, `make scale-data`: its own commit); personal data in the journal as ruled; imports capped by decompressed
   size and blank rows.
4. **W4, realtime, PDF, web.** Centrifugo drops a person from a company's channel when access ends; its options written
   out, with a test that a client neither publishes nor subscribes beyond its token; Gotenberg on a network with the api
   alone and Chromium denied every URL but its own `file:///tmp/` (judged on a rendered PDF); single-use tokens out of URL
   paths and out of logs and error reports; `Permissions-Policy` (keeping `camera=(self)` for the scanner and `/pair`), COOP,
   CORP; the tab header bound to its session; the client-errors body capped at the edge.
5. **W5, delivery and operations.** Backups per ruling; one heartbeat token per line, a limit at the monitor's edge;
   least-privilege `permissions:` and SHA-pinned actions in every workflow; release signing per ruling; Caddy, Centrifugo,
   Gotenberg by digest; the backup socket read-only, SMTP TLS required, dead letters purged, no tests or dev env files in
   the production image, no test database in prod; a limit on GlitchTip's sign-in; the P3 items.
6. **W6, invoice signing.** The ruled per-company key, sealed under an env master key, signing chain links forward from a
   cut-over link; issued invoices are never re-sealed; `app:invoices:verify-chain` checks signatures from the cut-over.
7. **`docs/THREAT-MODEL.md`** once every fix is on master: STRIDE per boundary, controls with file and test, each finding
   with its fix commit or its accepted residual risk; SPEC § 7, row 270, START.md and RUNBOOK where operations change.

Not certified until real machines exist: the production edge's header handling, a real bucket's retention, signing in the
real publish workflow.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | W1 request handling, platform roles | M | done | 5f811c55 | api/src/** api/tests/** |
| 2 | W2 sign-in and session | L | doing | - | api/** web/** |
| 3 | W3 tenancy and data | L | todo | - | api/** infra/postgres/** compose*.yaml scripts/** |
| 4 | W4 realtime, PDF, web | L | todo | - | api/** web/** infra/** compose*.yaml |
| 5 | W5 delivery and operations | L | todo | - | .github/** scripts/** infra/** compose*.yaml |
| 6 | W6 invoice signing key | L | todo | - | api/src/** api/tests/** |
| 7 | THREAT-MODEL.md + docs | M | todo | - | docs/** CLAUDE.md |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
