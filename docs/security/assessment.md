# Security assessment — 2026-10-25

What was checked on master at d60b916e, by what means, and what is left. No review proves an application safe. This one
ties each OWASP category to the code, test or gate that holds it, names every gap found, and checks every image a shop
runs against the public CVE databases. The four lens reports (file:line evidence, each claim graded) were written to
`var/claude/security/map-{access,auth,injection,supply}.md` on the developer's machine.

## How

- **Dependencies**: `make audit` (composer audit, npm audit on production dependencies): 0 vulnerabilities.
- **Images**: Trivy 0.67.2 (a version predating the March 2026 compromise of Trivy's releases, with a fresh database),
  HIGH and CRITICAL, over the seven images a shop runs: the release's api, web and database by digest
  (v2026.10.25.1), `caddy:2.11.7-alpine`, `centrifugo/centrifugo:v6.10.1`, `gotenberg/gotenberg:8.38.1`,
  `postgres:18.6-trixie`. Each critical traced to the process that loads it and the input that reaches it (`ldd`,
  `php -i`, `dpkg -l`, symbols in the binary).
- **Code**: four read-only reviews against OWASP Top 10:2025 and API Security Top 10:2023: access control; authentication,
  sessions and cryptography; injection, SSRF and errors; supply chain, configuration, integrity and logging.
- **Named recent vulnerabilities**: PHP2Shell (CVE-2026-109618, CVE-2026-109619, 2026-10-21): the release's api runs
  PHP 8.5.12 under FrankenPHP 1.13.1, the fixed release; upload progress is also turned off (8ee7a7ff). React2Shell
  (CVE-2025-55182): no React or Next.js in the tree. PHP-CGI argument injection (CVE-2024-4577): no php-cgi.

Not done: no attack was sent to a running stack (OWASP ZAP is the next step, never rushed), and no exploit was
reproduced.

## Result

| | P0 | P1 | P2 | P3 |
|---|---|---|---|---|
| Access control (A01, API1, API3, API4, API5, API6) | 0 | 0 | 4 | 3 |
| Authentication, sessions, cryptography (A04, A07, API2) | 0 | 1 | 3 | 8 |
| Injection, SSRF, exceptional conditions (A05, A10, API7, API10) | 0 | 0 | 1 | 4 |
| Supply chain, configuration, integrity, logging (A02, A03, A08, A09, API8, API9) | 0 | 1 | 6 | 7 |
| **Total** | **0** | **2** | **14** | **22** |

No exploitable injection, cross-company read or authentication bypass was found. No image carries a CVE an
unauthenticated network actor can exploit with a fix to take today.

## CVE triage of the images

| CVE | Image | Verdict | Why, and the fix |
|---|---|---|---|
| CVE-2026-6653 libxml2 (critical) | api | reachable, DoS | An uploaded xlsx is parsed by libxml2; with the demo on, any visitor can upload. No Debian fix. Fix in the app: refuse an OOXML part holding `<!DOCTYPE` (OOXML never has one), and switch the demo off on a shop. |
| CVE-2026-6653 libxml2 | database, postgres, gotenberg | not reachable | no XML from outside reaches them; their ports are not published |
| CVE-2026-93221 linux-libc-dev | api | not reachable | kernel headers left by the build toolchain; purging the toolchain from the prod image drops it and 59 HIGHs |
| GHSA-cxf3-9h37-7rr8 labstack/echo | gotenberg | not reachable | the vulnerable proxy middleware is not compiled in; no Gotenberg release carries the fix yet |
| CVE-2025-68121 Go stdlib in gosu | database, postgres | not reachable | gosu runs once at start and opens no connection; arrives with the next postgres rebuild |
| CVE-2026-78669, CVE-2026-78667 Go stdlib | caddy (the edge) | reachable, DoS, low | HTTP/2 and range-request CPU floods on the only published port; no Caddy release carries Go 1.26.9 yet |
| brotli, expat, libx11 | web | not reachable | nginx loads none of them; `nginx:1.31.8-alpine-slim` ships none |

## Findings, worst first

| # | Sev | Finding | Fix | Size |
|---|---|---|---|---|
| A1 | P1 | A locked account answers the right password differently from a wrong one, so the lock never stops guessing spread over many addresses | while locked, answer every password alike; tell the owner by mail instead (touches the 2026-09-22 ruling: the developer's call) | S |
| S1 | P1 | Caddy, Gotenberg, Centrifugo and Mailpit reach a shop by a mutable tag, outside the signed digests | pin them by digest in the compose files, held by a gate | S |
| S3 | P2 | libxml2 CVE-2026-6653 reachable through the xlsx import | refuse `<!DOCTYPE` in OOXML parts before parsing; gate every XML read | S |
| S4 | P2 | the demo is on by default on a shop's installation | off once `twes install` has run (ruled 2026-10-20); today the operator unticks it (install page § 4) | S |
| S2 | P2 | no image or OS CVE scan in CI, no scheduled audit or rebuild | weekly Trivy job, pinned by digest, triage kept in `.trivyignore` | S |
| I1 | P2 | Gotenberg's Chromium runs JavaScript with no outbound restriction on a flat network: a future HTML injection into a PDF becomes SSRF | allow-list `file:///tmp/`, JavaScript off, an internal network with the api and worker alone; test logos and fonts still render (threat-model plan W4) | S-M |
| C1 | P2 | a removed member still lists that company's notifications in the bell | scope the notification centre to current memberships | S |
| C2 | P2 | no sweep holds each company route to its permission for a member who lacks it | a functional sweep over the router, as `UnauthenticatedSweepTest` does for public routes | M |
| C3 | P2 | long in-request work has no rate or concurrency limit on the shared worker pool | limiter on exports, imports and PDFs per company | S |
| C4 | P2 | no gate requires a raw SQL read to name its company | gate over raw reads in `api/src` | S |
| A2 | P2 | the operator's password skips the password policy | run it through the same policy at seed and change | S |
| A3 | P2 | the ruled per-company fiscal signing key is not built: the invoice chain is unkeyed | build it (ruled 2026-09-20) | M |
| A4 | P2 | phones fetch the root certificate over plain HTTP with no fingerprint to compare | show the authority's fingerprint on the PC and on the page that serves it | S |
| S5 | P2 | no container hardening (`cap_drop`, `no-new-privileges`, read-only); the edge runs as root | a hardening anchor in compose, proved by deploy-check and onprem-check | S-M |
| S6 | P2 | the prod api image ships a C toolchain | purge it in the prod stage, asserted by `production-image.php` | S |
| S7 | P2 | the edge carries Go stdlib DoS CVEs with no fixed Caddy release | build the edge on a fixed Go, or take Caddy 2.11.8 when out | M |

The 22 P3 are in the lens reports: among them, raw exception text in some 4xx answers, an unbounded id list in a query
string, session ids stored raw, a notification stop token that never expires, `npm ci` running lifecycle scripts, no
top-level `permissions:` in the workflows, and base images in Dockerfiles by tag rather than digest.

## Class: an action that cuts a person's own access, or strands a company or the platform

Swept on 2026-10-25 after the developer saw « Retirer » offered on their own row. Leaving a company, deleting one's
account and removing someone are three different acts; none may happen by a slip, and none may leave a company with
no owner or the platform with no operator.

| Action | Guard | Evidence |
|---|---|---|
| Remove oneself from a company (« Retirer » on one's own row) | refused, own reason `own_membership`; not offered on one's own row | 7c5f82e2; `MembersTest` (only owner, one of two), `RemoveMemberTest`, `members-page.spec` |
| Remove a company's last owner | refused, `last_owner` | `RemoveMember.php`; `RemoveMemberTest` |
| Delete one's own account as a company's only owner, or as an operator | refused, the companies named | `DeleteOwnAccount.php:61` |
| An operator deactivating their own account | refused | `ManageAccounts.php:67`; `PlatformAccountsTest::testAnOperatorCannotDeactivateTheirOwnAccount` |
| Remove the last second factor a company requires (a passkey) | refused, `mfa_last_factor` | `RemovePasskey.php:63` |
| Remove a required authenticator | refused: replaced, never removed | ruling 2026-10-25 09:40, `RemoveAuthenticator.php` |
| Edit or delete a built-in role (the owner's) | refused | `RoleNotEditable` |
| Every factor lost (phone and recovery codes) | not stranded: `app:mfa:replace <e-mail>` on the installation's console; an operator's on-screen reset stays refused | `ReplaceSecondFactorsCommand.php`; docs/RUNBOOK.md « Replace a lost authenticator » |
| Password forgotten | reset by mail | the reset flow |

Left as they are, with the reason:
- A member with « user.write » on a custom role can remove permissions from the role they hold; the owner keeps every
  right, so the company is never stranded.
- Two operators deactivating each other in the same moment would leave none active; the installation's console seeds
  an operator again (`app:seed`). Not worth a lock at one operator per shop.
- Leaving a company on purpose has no screen yet: « Quitter l'entreprise » is its own act if it is ever wanted.

## Certified by execution, and not

- By execution: the audits, the seven image scans, library linkage and loaded versions inside the images, the PHP
  version of the release image, tag existence for every proposed bump.
- Not by execution: every code finding above was read in the code and its tests, not reproduced against a running
  stack; whether a crafted xlsx triggers CVE-2026-6653 was not tried.
