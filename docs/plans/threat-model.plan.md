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
- [2026-10-13 10:05] ASSUMED (review): a phone presenting a wrong pairing key is answered as a pairing that never was (404 unknown) and sees the existing « Ce lien n’existe pas. Scannez à nouveau le code » text; « Ce téléphone n’est pas celui qui a été relié » is retired. Alternatives: keep a distinct answer for a wrong key, which tells a guessed id from a real one
- [2026-10-13 12:22] AGREED: Journal wave also builds account deletion ('delete my account'), which calls the journal scrub; developer chose it over recording the gap as a residual.
- [2026-10-13 12:31] AGREED: Account deletion refuses while the person is the only owner of a company, names those companies, and offers to erase them (the existing company erasure) before retrying (developer).
- [2026-10-13 12:31] AGREED: Account deletion is cancellable: the account closes and signs out at once, a mail says so, signing in again cancels it; the days before it is final are a parameter, 7 by default; the nightly run then deletes it and scrubs the journal (developer).
- [2026-10-13 12:31] AGREED: Screen texts for « Mon compte » move to a file of their own loaded with that page, so the first page's 60 KB budget is kept (developer).
- [2026-10-13 12:36] AGREED: A sole owner asking to delete their account is offered two ways out: make another member owner (exists), or « Fermer l'entreprise », a new step: the company stops working at once, its issued documents stay read-only for the country's retention period (a preset value), then a scheduled run removes it (developer).
- [2026-10-13 12:39] AGREED: Account-deletion plan approved in five commits (journal functions, lazy account texts, account deletion API, company closing API, screens); a closed company stays read-only to its members, with the accounting export, until its country's retention period ends, then is removed (developer).
- [2026-10-13 14:24] ASSUMED (review): Account deletion: a platform operator's own account is refused (409 platform_operator); another operator deactivates it, since legal texts keep an operator's address — because the journal and the legal pages name operators. Alternatives: allow it and erase the legal-text address
- [2026-10-13 14:24] ASSUMED (review): Account deletion: an account that became a company's only owner after asking is kept at the nightly run and tried again the next night, with a warning in the log — because the company would be left with no one to run it. Alternatives: delete anyway, or cancel the request
- [2026-10-13 14:24] ASSUMED (review): Account deletion: a notification published after its recipient's account was deleted is dropped silently; one for an account that never existed still throws — because a stock alert raised before the deletion reaches the inbox after it. Alternatives: throw for both
- [2026-10-13 14:24] ASSUMED (review): Account deletion: DELETE /api/auth/account answers finalAt and ends the session that asked, as the rotated security stamp ends every other — because the account is closed at once. Alternatives: keep the asking session open
- [2026-10-13 14:53] AGREED: Closing a company can be undone by its owner alone during a grace period, a platform setting (7 days by default); after it the closing is final and the company stays read-only until its retention ends (developer).
- [2026-10-13 14:53] ASSUMED (review): Company closing: retention runs from the end of the year the company closes plus the preset's years (TN 10, CIRPP/IS art. 62; FR 10, C. com. L123-22), never shorter than the law, marked unvalidated — because neither source in docs/research names the start. Alternatives: the closing day plus N years
- [2026-10-13 14:53] ASSUMED (review): Company closing: on a closed company only GET passes, for *.read and accounting.export; every other request is 403 company_closed, the subscription is no longer judged, and approving, suspending or an invitation cannot reopen it; closing ends pending invitations and support grants — because a closed company is kept, not worked in. Alternatives: judge read-only by permission name alone
- [2026-10-13 15:54] ASSUMED (review): 3e dialogs name no number of days before acting (Me stays as it is for deletion); the exact date is given after the action: the deletion toast and mail carry finalAt, the closed-company banner carries reopenUntil and retainUntil — because a non-operator cannot read platform settings and exposing both day counts on Me widens the API for one sentence. Alternatives: both day counts on Me.
- [2026-10-13 15:54] ASSUMED (review): 3e a closed company opens in the shell read-only (only .read permissions and accounting.export, closed status taking precedence over the subscription's access) with a banner, instead of the awaiting-approval page — because the ruling keeps it readable to its members until its retention ends. Alternatives: an awaiting-page branch with the export alone.
- [2026-10-13 16:43] AGREED: The screens-maturity review rounds run after 3e lands, on that frozen commit; W4-W7 resume while the developer rules on its proposals (developer).
- [2026-10-13 17:01] AGREED: A fourth action kind, « Définitif à terme » (hourglass icon, drawn red like définitif): taken back during a delay, then final; account deletion and company closing use it; every définitif and définitif-à-terme confirmation draws its confirming button in the danger colour; account deletion, company closing and company erasure ask a typed word before the button enables (developer).

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
| 2 | W2 sign-in and session | L | done | 7bbc6c26 | api/** web/** infra/** docs/START.md |
| 3 | W3 tenancy and data | L | doing | 2064cc30 | api/** infra/postgres/** compose*.yaml scripts/** |
| 3a | W3 journal functions and the 12-month purge | M | done | 02e51a98 | api/migrations/** api/src/Audit/** api/tests/** |
| 3b | W3 « Mon compte » texts loaded with their page | M | done | 23bafd76 | web/** scripts/gates/** |
| 3c | W3 account deletion API | L | done | 9c50a361 | api/src/Identity/** api/tests/** |
| 3d | W3 company closing API | L | done | 965a8d53 | api/src/Tenancy/** api/config/fiscal/** docs/fiscal/** |
| 3e | W3 account deletion screen, the danger kind, the platform delays | M | done | 5fc6584c | web/** docs/** |
| 3f | W3 company closing and erasure screens (typed name, définitif à terme), after round 0 | M | todo | - | web/** |
| 4 | W4 realtime, PDF, web | L | todo | - | api/** web/** infra/** compose*.yaml |
| 5 | W5 delivery and operations | L | todo | - | .github/** scripts/** infra/** compose*.yaml |
| 6 | W6 invoice signing key | L | todo | - | api/src/** api/tests/** |
| 7 | THREAT-MODEL.md + docs | M | todo | - | docs/** CLAUDE.md |
<!-- /progress-block -->
### Resume 3e/3f (2026-10-13 17:30)
- 3e built: the 4th kind `definitif_a_terme` and `isDanger`, `ConfirmDialog` red for both final kinds with a typed word or name (`typed`),
  `.twes-danger` through Material's button tokens, « Supprimer mon compte » red with SUPPRIMER/DELETE typed and an operator told
  before any dialog, « Compte supprimé » in the journal, a « Délais » card on « Gérer la plateforme » drawing every whole-number
  platform setting from its declaration; e2e `account-deletion.spec.ts` measures the colour as drawn in both schemes (a sabotage of the
  CSS rule turned it red: rgb(0, 87, 195) received). Screenshots before/after in `var/claude/maturity/3e-*.png`.
- 3f comes AFTER round 0 (rulings 16:43, 16:59: 3e lands, freeze, `/compact`, round 0; W3's last step and W4-W7 resume while the
  developer rules the review's proposals). 3f, per the 15:54 entries: Me's company gains closedDownAt/retainUntil/reopenUntil; « Fermer l'entreprise » (kind
  définitif à terme, the company's name typed, kept apart from « Clôture » of a period); a closed status wins over access; reads plus
  accounting.export only; a `company_closed` interceptor; the shell read-only with a banner; e2e on a company the operator creates.
  Ruled § 7 2026-10-16 17:40: the data erasure is final after its delay too (its banner says « Définitif le … »), so its preview would
  move to the shared dialog with `definitif_a_terme` and the company's name typed (the 17:01 entry already asks a typed word for it).
  Budget: about 1.5 kB left on the first page, and the `company_closed` interceptor sits in `app.config`: measure before and after
  with the stats walk, lazy-load what it pulls in.
- 3e landed 5fc6584c (the unsaved-changes guard now fetches `ConfirmDialog` lazily: the first page was 1.12 kB over 1 MB, now
  998.47 kB, about 1.5 kB left). That commit is the review's frozen one (ruling 16:59).
- NEXT: `/compact`, then round 0 of the screens-maturity review on 5fc6584c (ledger seeded: `var/claude/maturity/ledger.md`).
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
- On the live stack (`make up`), the logout e2e lands on `/login?expired=1`: it reproduces at `1cb8cf78` too, before W2's sign-in changes, and CI passes it on the image stack.
