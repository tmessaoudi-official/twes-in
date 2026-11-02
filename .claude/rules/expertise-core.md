# EXPERTISE - twes-in   (L4 core; loaded every session in this project)
Review date: 2026-11-02 21:45   Validation mode: advisory   Packs (project skills in .claude/skills/): domain-twes-business-fiscal, domain-symfony-api-platform, domain-angular-web, domain-quality-gates-ci, domain-identifiers
Scope: the DELTA over CLAUDE.md (Process, Licensing invariants, Git, Lessons: loaded every session, NOT repeated). This core keeps what prevents the worst mistakes; EXPERTISE-REFERENCE.md holds the rest: read only the section the pointer table (§6) names. Tags: [Ruled: user <date>] = AGREED in SPEC §7 (`<date>` finds the entry); [Source: ...]; [Observed: ...]; [Unverified].

## Domains this project touches
| Domain | Pack | REFERENCE |
|---|---|---|
| A business identifier (matricule, RIB, IBAN, BIC, GTIN, phone, e-mail, SIREN/SIRET, VAT, postal code) | domain-identifiers | §6 |
| TN / FR tax, invoicing, till certification | domain-twes-business-fiscal | §6, §14, §15 |
| Symfony 8 / API Platform / Doctrine, hexagonal DDD | domain-symfony-api-platform | §4, §5, §13 |
| Angular web, design system, i18n | domain-angular-web | §3 |
| CI, gates, licensing, process | domain-quality-gates-ci | §9, §16 |

**Routing rule: load ONE pack (the first matching row) and at most ONE REFERENCE section from §6; never read EXPERTISE-REFERENCE.md whole; a second pack only when the task names a second domain.**  [Inferred: scout baseline 2026-10-02]

## 0. Doc map
Product, goals, done: SPEC §1, §2, §5. Rulings: SPEC §7 only (`git log -S"<phrase>" docs/SPEC.md`; change the ruling first, then the code). Status and the ONE build order: SPEC §8. Tax rules: docs/fiscal/<CC>.md, then the preset. Full map: REFERENCE §19.  [Source: CLAUDE.md l.3-5, SPEC §0]

## 1. The business in one screen
- NORTH STAR (SPEC §1): one modular platform, every capability a module switched on or off, seen per person, talking only through ports and events; real businesses first (TN, FR by evidence); exemplary by evidence; mistake-proofing that guides but never makes a fiscal or legal choice; data where the law allows; RGAA, fr/en/ar, phone first.  [Ruled: user 2026-10-11 23:52]
- Everything parametrized: a value a company could want otherwise is a company setting, one only the operator sets a parameter; only what the law fixes stays code.  [Ruled: user 2026-10-09 07:46]
- Invoicing SaaS for small businesses WITH A PLACE; TN first, FR second; multi-company. The first shop is PRODUCTION: an update never breaks what exists or loses data.  [Source: SPEC §1; Ruled: user 2026-10-17 16:31]
- Another country is DATA (preset + sourced rules + texts); a new tax KIND is code.  [Ruled: user 2026-09-20]
- Money: NUMERIC(14,3) amounts, (14,4) prices, (14,3) quantities; issued documents immutable, corrected by credit notes; numbering gapless at issue.  [Source: SPEC §3 Money]

## 2. Invariants an assistant must not break (detail in REFERENCE)
Law, fiscal, signing, licences (one-way doors):
- LAW vs SETTING: law fixes it = CODE; differs by country = PRESET; law silent = COMPANY setting. No switch makes an issued invoice editable. Watermark presence and text stay code, only its look is a setting.  [Ruled: user, see REFERENCE §10; 2026-10-11 01:24]
- Fix a fiscal defect only after sourcing it with citations in docs/fiscal/<CC>.md; never invent a fiscal rule; every rule stays `unvalidated` until an accountant confirms; trust the fiscal doc over a brief.  [Ruled: user 2026-10-14 15:13; Observed: 2026-09-24]
- Issued invoices hash-chained per company and series (PDF « Empreinte : » + 16 hex); key signature later. Imported foreign documents bring open balances only, never a series, the chain or a PDF.  [Ruled: user 2026-10-12 00:15, 03:06, 2026-10-16 17:40]
- Credit notes: the stamp given back once at most, on the note completing the invoice; a partial note prorates document and line discounts by quantity, carries no deposit line (only a full credit returns the deposit); a return above what is owed is refused.  [Ruled: user 2026-10-14 18:03, 21:54]
- Expenses use the invoices' DocumentCalculator; TEJ declares one operation per VAT rate. Mails and PDFs write amounts from the decimal string through one canon, never PHP intl.  [Ruled: user 2026-10-14 19:19, 2026-10-15 17:02]
- Tax components import matched by code (never a code or family changed), snapshots kept, dry-run first.  [Ruled: user 2026-10-16 17:40]
- Enforce a format only where a published standard defines it, never on free text; import set-aside never loosens the domain; phones by country via libphonenumber, stored E.164; an old stored value is refused only when changed.  [Ruled: user 2026-10-21 12:03, 2026-10-22 18:09, 2026-10-27 17:51]
- No law requires a cachet or signature; a signed copy sits beside the original. A stored issued PDF is never replaced (« Reçu par » prints on a « Copie — livrée »).  [Ruled: user 2026-10-26 19:21, 15:51]
- Data erasure: owner only behind step-up; records tied to issued documents archived, issued docs and payments kept. Every wipe scope backs up first, asks a typed confirmation, lists fiscal data and refuses it within retention unless acknowledged; a company with issued invoices only after its archive is verified, plus `--with-issued-documents`, its name and a typed sentence. `app:platform:reset` takes an unskippable backup first; `twes reset` moves backups aside and never deletes them.  [Ruled: user 2026-10-10 00:27, 2026-10-31 19:29, 21:14, 2026-10-29 13:49, 2026-10-25 18:59]
- A money or stock mistake is corrected by a new record; country and currency editable only before anything is issued.  [Ruled: user 2026-10-31 21:14]
- Sensitive appointment activities stay closed per preset until hosting is certified; deployment region is a setting, nothing provider-specific.  [Ruled: user 2026-10-11 23:03, 2026-10-12 00:23]
- Refused on licence: PrimeNG family, Transloco, `ng-openapi-gen`, Mercure, zbar, QZ Tray, Star SDKs, MinIO; TypeScript 7 held back.  [Ruled: user, see REFERENCE §10; 2026-10-12 10:12]
Tenancy and security:
- `CompanyFilter` is turned on by `CompanyGuard::companyForActing` from the URL PATH; raw SQL escapes it and must name its company; the browser never chooses a realtime channel.  [Ruled: user 2026-09-16, 2026-09-27]
- A non-active company answers like a stranger (404); a CLOSED one stays read-only to members with the accounting export until retention ends. Operators hold only platform endpoints; every platform write needs a recent operator proof.  [Ruled: user 2026-09-15, 2026-10-13 12:39, 20:21]
- Support access: owner-granted, time-boxed, read-only, audited, in the owner's own screens; no exports, no cost.  [Ruled: user 2026-09-17, 2026-10-12 08:53]
- Which actions ask step-up is code; its duration and attempts are boot-checked parameters. Installation acts (update now, demo, night window): operator and owners/admins behind the identity check, never a demo account; the demo is off on a shop.  [Ruled: user 2026-10-13 20:22, 2026-10-25 18:59, 2026-10-20 01:34]
- Sign-in: never by e-mailed link alone (passwordless = passkey); social sign-in server-side redirects, no third-party script, company MFA still applies. A lock or deactivation is told only to the right password, equal hashing cost; while locked no password is compared.  [Ruled: user 2026-10-11 09:01, 2026-10-09 23:19, 2026-10-13 07:35, 2026-10-25 22:38]
- While a second factor is required the authenticator is replaced, never removed (never zero factors); a lost one via console `app:mfa:replace`, never by an operator on screen; self-service replace while the account can still prove itself. Nobody cuts their own access (« Retirer », « Désactiver »).  [Ruled: user 2026-10-23 03:31, 2026-10-24 22:16, 2026-10-25 09:33, 22:57]
- Outside access: private Tailscale first; the public path built, tested, OFF. Tailscale on: owners/admins need a second factor; public on: everyone. Passkey address a platform setting, refused to change while anyone's only factor is a passkey. Master key escrowed only by `twes escrow`.  [Ruled: user 2026-11-01 10:20, 2026-10-31 21:14]
- Backups: Object Lock 14 days, the server's key cannot delete; a shop PC's key is write-only, the prune key stays with the developer.  [Ruled: user 2026-10-13 00:04, 2026-10-31 21:14]
- Audit: field NAMES only; the app cannot rewrite the journal (one DB function, for erasure). Open threat-model findings named only in general terms in the public tree.  [Ruled: user 2026-09-09, 2026-10-13 00:04]
- Client and shop data never in committed files, history or artifacts; ask by sheet number and column letter. GitHub holds no credential reaching a server.  [Ruled: user 2026-10-20 19:16, 2026-10-22 00:44, 2026-10-12 22:07]
- `product.cost.read` is the ONLY way to cost (else costPrice null, supplier codes and margin withheld); the customer screen is held by the API session (403 `customer_screen_locked`).  [Ruled: user 2026-09-23, 2026-10-06 19:44]
- Signup and invitations never reveal a registered address; passkey PIN refused; breach check fails OPEN. `UnauthenticatedSweepTest` walks the router.  [Ruled: user 2026-09-15, 2026-09-16, 2026-09-22]
Architecture:
- `Domain/` and `Application/` import no framework; events published AFTER commit; modules never call each other (inventory's and the delivery-note ports carry ids and plain values; other crossings await their ruling); Messenger on Doctrine is the outbox; a module lives at `api/src/Module/<Name>/`.  [Ruled: user 2026-09-14/16/20, 2026-10-20 15:17]
- Same validation on every way in: checks in domain value objects; imports refuse rows with a stable code, never the file. An import creates irreversible records only from a certain file; a doubt is an « à vérifier » reason.  [Ruled: user 2026-10-15 20:00, 2026-10-20 00:21]
- Stock on-hand is never stored, only summed from `stock_movement`. Selling beyond stock follows « Sortie sans stock enregistré » (allow+signal default / confirm / refuse) at validation or issue, never at a draft; lots, serials, moves keep their refusal.  [Ruled: user 2026-10-12 04:03, 2026-10-28 00:13]
- A system write locks the versioned row (FOR UPDATE), never refused; only a form's stale version answers 409.  [Ruled: user 2026-10-17 01:14]
- Delete classes: fiscal/legal never deleted; master data deletable while unnamed, else deactivated or merged (alias model: merged-away stays, deactivated, pointing at the survivor); every delete undoable 24 h.  [Ruled: user 2026-10-20 00:36, 01:32]
- Update rollback runs the previous release on the current schema (migrations additive, one transaction); a failing start step only warns; nothing wiped.  [Ruled: user 2026-10-17 17:08, 2026-11-01 21:13]
- Stack rewrite REJECTED; FrankenPHP worker mode everywhere.  [Ruled: user 2026-09-27, 2026-09-30]
UI and product:
- Descriptors (`DataList`/`DescriptorForm`); money from the decimal STRING via pipes; status six tones. Design values are tokens in three places only (tailwind.css, shared/theme, the styles.scss root block); `design-tokens.sh` refuses any other literal.  [Ruled: user 2026-09-13/16, 2026-11-02 00:39]
- Each record type declares its actions once (RecordAction); every surface reads it; no action offered to a role the API refuses. Annulable never asks (toast undo); définitif is red, last in ⋮.  [Ruled: user 2026-10-11 08:26, 2026-10-26 00:17]
- Bad network: nothing lost, unsent shown; issuing waits for the network; offline-first refused for fiscal documents.  [Ruled: user 2026-10-13 16:52]
- Fonts and icons self-hosted; Angular i18n REFUSED; screen texts one file per language per feature, each with its own gzip budget.  [Ruled: user 2026-09-13, 2026-10-13 00:17]
- Undo first, preview the rest; no "don't ask again"; no faked mockup element; logo raster only; RGAA; form-first.  [Ruled: user 2026-09-17/21/22/25, 2026-10-02]

## 3. Working style (REFERENCE §11)
- The developer tags releases and picks the commit (Claude notifies at ready-to-tag and keeps going) [Inferred: SPEC:5647, 5708, 5736]. Before each tag: CI green, `make onprem-check` on that commit, the update rehearsed on the shop's real files.  [Ruled: user 2026-10-24 22:16, 2026-10-28 01:04]
- Scope flexes, quality never: no CI-as-gate, no longer timeouts, no retries, no cut e2e.  [Ruled: user 2026-10-26 16:39]
- A class the developer names is hunted whole and laid out before any fix starts.  [Ruled: user 2026-10-27 22:45]
- Ideas mid-work are refined or rejected and re-asked; audit DDD/hexagonal layering every goal; judge by what people SEE and USE.  [Ruled: user 2026-09-16, 2026-09-22]
- Sabotage the INVARIANT, not the diff. Reviewers write to disk and return ONE line; a lost lens is completed inline, disclosed.  [Ruled: user 2026-08-19; Observed: 2026-08-28]
- Gates: `make gate` runs the halves in turn (CLAUDE.md lesson 2026-10-15); `make check` first; read each tool's own exit, never a pipe's.  [Observed: CLAUDE.md Lessons; contested, §5]

## 4. Machine facts that mislead (REFERENCE §12, §19)
- `make up` is LIVE (source mounted); `make up-images` serves static builds. A stack down at resume is expected: `make up`, then `make fixtures`.  [Observed: 2026-09-16/27]

## 5. Known stale or contested (full list REFERENCE §17, §19)
- The LATER entry wins; never cite ASSUMED (review) / DECIDED (revisit) / PROVISIONAL / TAKEN OVERNIGHT as AGREED.  [Source: r2-decisions]
- Gates: `make gate` runs its halves in turn (CLAUDE.md, which wins on process, after side by side starved the suites on 2026-10-15); the 2026-10-14 15:13 "side by side" (SPEC:5189) is superseded in practice.  [Source: CLAUDE.md Lessons 2026-10-15; SPEC:5189]
- Release signing: GPG this release ("no cosign this release", 2026-10-31 21:14, the later entry, over cosign 2026-10-13); the tree verifies `infra/onprem/release-key.asc`.  [Ruled: user 2026-10-31 21:14]
- Memories' resume state is likely stale: verify with `git log`, the plan file, SPEC §8.  [Source: r2-memories §4]
- Rulings here reflect SPEC §7 up to its 2026-11-02 00:39 entry (refreshed 2026-11-02 21:45); read §7 after that stamp.  [Observed: refresh-20261102]

## 6. Pointer table: EXPERTISE-REFERENCE.md sections
| Task touches | Section |
|---|---|
| Product, modules, till, buying, legal pages | §2 |
| Screens, design system, forms, a11y, scanner | §3 |
| Layering, events, lists, PDFs, imports, performance | §4 (traps also §12) |
| Auth, MFA, passkeys, roles, support, secrets, shop access | §5, §13, §19 |
| Tax, VAT, withholding, numbering, e-invoicing | §6, §14 |
| Stock, inventory, barcodes, valuation | §7 |
| Settings, modules, subscription, licensing | §8, §16 |
| Process, build order, release, tooling, CI | §9, §19 |
| A one-way door or a refusal | §10 |
| Till hardware and certification | §15 |
| Open questions | §18, §19 |

## 7. Open questions that most often bite (REFERENCE §18)
- TN homologated-register obligation for cafés and the accreditation route.  [Unverified]
- Lawyer items: dual-licence policy, CLA, AGPL §13 on hosting, legal-page drafts, loi 2004-63 for the region.  [Unverified; Ruled: user 2026-10-12 00:23]
- Accountant: the period lock's grain (month meanwhile), the one-millime line rounding (Q24).  [Ruled: user 2026-10-31 21:14, 2026-10-22 19:34]
