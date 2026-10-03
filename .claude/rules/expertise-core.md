# EXPERTISE - twes-in   (L4 core; loaded every session in this project)
Review date: 2026-10-03   Validation mode: advisory   Packs (project skills in .claude/skills/): domain-twes-business-fiscal, domain-symfony-api-platform, domain-angular-web, domain-quality-gates-ci
Scope: the DELTA over CLAUDE.md (its Process, Licensing invariants, Git rules and ~70 "Lessons" are loaded every session and are NOT repeated). This core keeps only what prevents the worst mistakes. The full 18-section file (~236 tagged entries, ~76 KB) is EXPERTISE-REFERENCE.md: read the section named in the pointer table below WHEN the task touches that topic; do not load it whole. Tags: [Ruled: user <date>] = AGREED in SPEC §7; [Source: ...] = a document; [Observed: ...]; [Unverified]. `SPEC §7 <date>` = the Decisions Log entry of that date.

## Domains this project touches
| Domain | Pack | Detail in REFERENCE |
|---|---|---|
| Tunisian / French tax, invoicing, till certification | domain-twes-business-fiscal | §6, §14, §15 |
| Symfony 8 / API Platform / Doctrine, hexagonal DDD | domain-symfony-api-platform | §4, §5, §13 |
| Angular 22 web, design system, i18n | domain-angular-web | §3 |
| CI, gates, licensing, process | domain-quality-gates-ci | §9, §16 |

**Routing rule: load ONE pack (the first row above that matches the task) and at most ONE REFERENCE section named in the pointer table (section 6 of this file). Never read .claude/EXPERTISE-REFERENCE.md whole; open it at the named section only. Load a second pack only when the task names a second domain.**  [Inferred: the scout baseline of 2026-10-02 showed pilots reading several packs and one whole reference without it]

## 0. Doc map (which document answers which question)
| Question | Open | Authority |
|---|---|---|
| Product, scope, goal order, definition of done | docs/SPEC.md §1, §2, §5 | SPEC wins on product |
| Why was X decided; is X ruled | SPEC §7 Decisions Log (dated, ~3,600 lines) | only ledger of rulings |
| What exists / is open / is fragile | SPEC §8 | machine-maintained |
| How work is delivered, gates | CLAUDE.md | CLAUDE.md wins on process |
| A tax rule and its source | docs/fiscal/TN.md, FR.md, then api/config/fiscal/<CC>.yaml | .md changes first, preset in the same change |
| Till certification, hardware | docs/research/till-certification.md, till-hardware.md | research, not ruling |
| Roles, voters, establishments | SPEC §3 Authorization; docs/research/authorization.md | |
| Bring-up, seed, reset; version pins | docs/START.md; docs/UPDATE.md (`make versions`) | |
| May I add a dependency | LICENSING.md, THIRD-PARTY-NOTICES.md (generated) | one-way door |
Before changing anything ruled: `git log -S"<phrase>" docs/SPEC.md` finds the §7 entry; change the ruling first, then the code.  [Source: CLAUDE.md l.3-5, SPEC §0]

## 1. The business in one screen
- Invoicing/billing SaaS for small businesses WITH A PLACE (shops, cafes, restaurants, workshops, warehouses). Launch market Tunisia, second France; built, sold and hosted by the developer; one plan per company; multi-company from the start (a person belongs to several companies and switches).  [Source: SPEC §1]
- First two customers: a hardware shop (quincaillerie) and a machining workshop (tourneur); the product serves them without being built for them alone.  [Source: SPEC §1, §2]
- "Supporting another country is DATA, never code": fiscal preset + sourced rules + translations; a new tax KIND is code. Enforced by a test.  [Ruled: user 2026-09-20]
- Money: NUMERIC(14,3) amounts, prices (14,4), quantities (14,3), rates (6,3); scale from the currency (TND 3, EUR 2); issued documents immutable, corrections are credit notes; numbering gapless, assigned at issue.  [Source: SPEC §3 Money]
- Subscriptions are computed from dates on every request, never by a scheduled job; payments are DECLARED by the company (cash is common) and confirmed by the operator.  [Ruled: user 2026-09-17]
- Glossary (as screens say it): facture, avoir, bon de livraison, TVA, FODEC, timbre fiscal, retenue a la source, matricule fiscal, SIREN/SIRET/NIC, franchise en base, autoliquidation, "TVA collectee" (never "a declarer").  [Source: docs/fiscal/TN.md, FR.md]

## 2. Invariants an assistant must not break (highest stakes; detail and the rest in REFERENCE)
Law, signing, licences (one-way doors, do not reopen without the developer):
- LAW vs SETTING: where the law fixes the answer it is CODE; where law differs by country it is a PRESET default; only where the law is silent is it a COMPANY setting ("a switch that can take a non-compliant value IS the compliance hole"). No switch may make an issued invoice editable; correction = credit note or corrective invoice.  [Ruled: user, date truncated in the digest, see REFERENCE §10]
- Per-company fiscal signing key (generated at provisioning, encrypted at rest under an env master key); AGPL public edition (a closed or wrapped build only under the commercial licence; no PHP encoder); the web address (passkeys bind to the RP id/host); issued numbers, frozen figures and stored PDFs.  [Ruled: user 2026-09-20, REFERENCE §10]
- Refused on licence: PrimeNG family, Transloco, `ng-openapi-gen`, Mercure (AGPL hub), zbar, QZ Tray (LGPL-2.1), Star SDKs; held back on purpose: TypeScript 7 (`material-color-utilities` 0.4.0 released from the hold 2026-10-03, pinned EXACTLY, inlined in Vitest).  [Ruled: user 2026-09-13 (held back), 2026-10-03 (released); refusal dates in REFERENCE §10]
- Trust the fiscal doc over a brief or a memory (a brief said retenue 1,5 %, docs/fiscal/TN.md says 1 %). Every fiscal rule stays `unvalidated` until a chartered accountant confirms it; nothing here is legal advice.  [Observed: 2026-09-24, REFERENCE §6]
Tenancy and security:
- Tenancy: Doctrine `CompanyFilter` is turned on by `CompanyGuard::companyForActing` with the company in the URL PATH; the browser never chooses a realtime channel; plain SQL escapes the filter, so any raw read must name its company.  [Ruled: user 2026-09-16, 2026-09-27]
- A non-active company answers members and `PermissionVoter` with the SAME 404 as a stranger; operators hold ONLY platform endpoints, keep NO standing access; support access is owner-granted, time-boxed, read-only, audited.  [Ruled: user 2026-09-15, 2026-09-17]
- Audit records field NAMES only, never values; auth events are rows of the single `audit_log`.  [Ruled: user 2026-09-09, 2026-09-13]
- `product.cost.read` is the ONLY way to cost: without it `costPrice` is null, supplier codes left out, margin withheld; "Customer view" hides fields on screen only (PENDING, SPEC 2026-10-03 08:20: to be replaced by a locked allow-list screen; NOT built, `hides()` is still the code).  [Ruled: user 2026-09-23]
- Signup and invitation answers never reveal whether an address is registered; MFA operator reset REFUSED; passkey PIN REFUSED; the breach-password check fails OPEN (audited).  [Ruled: user 2026-09-15, 2026-09-22]
- `UnauthenticatedSweepTest` walks the ROUTER: a new public route must be added to its listed set deliberately.  [Ruled: user 2026-09-16]
Architecture:
- `Domain/` and `Application/` import no Symfony/Doctrine/API Platform (ruled carve-outs only); domain events are plain objects published by the use case AFTER commit; modules NEVER call each other; no outbox.  [Ruled: user 2026-09-14/16, REFERENCE §4]
- A module context must live at `api/src/Module/<Name>/` or it escapes the module switch (ModuleOwnership maps by prefix).  [Ruled: user 2026-09-20]
- Rewrite-in-another-stack is REJECTED unless new measurements contradict (slowness was configuration/design, not the stack); FrankenPHP WORKER mode in EVERY target incl. dev and CI e2e.  [Ruled: user 2026-09-27, 2026-09-30]
- Import refusals carry a stable `reason` code; never match on an English message.  [Ruled: user 2026-09-18, 2026-09-22]
UI and product:
- Screens are descriptors (`DataList`/`DescriptorForm`); money is formatted from the decimal STRING, never a float, only through the `amount`/`day`/`moment` pipes; no literal colour outside `shared/theme`; status takes one of six fixed tones.  [Ruled: user 2026-09-13/15/16/19]
- Fonts and icons self-hosted, NEVER from Google; Angular built-in i18n REFUSED; screen texts one hashed file per language, CI budget 60 KB gzipped.  [Ruled: user 2026-09-13, 2026-09-17]
- "Undo first, preview the rest": nothing undoable asks a question; irreversible/fiscal actions get a consequence dialog; destructive confirmations have NO "don't ask again"; every bulk action gets a dry-run preview.  [Ruled: user 2026-09-17, 2026-09-21]
- A mockup element with no data behind it is left out, never faked; unbuilt screens say "Bientot".  [Ruled: user 2026-09-25]
- Company logo: one raster only; SVG and URL fields REFUSED. Accessibility target is RGAA (stronger than WCAG 2.1 AA). Form-first, never pointer-only (dragging is an accelerator).  [Ruled: user 2026-09-22, 2026-09-26, 2026-10-02]
- Treat any "out" as provisional unless it names a legal reason: purchase orders, recurring, portal, reservations and cafe were all reversed within days.  [Source: r2-decisions contradictions, REFERENCE §17]

## 3. Developer working style and recorded corrections (REFERENCE §11)
- Always audit architecture while working (DDD/hexagonal, SPEC §3); each goal's completion note states the §3 layering check.  [Ruled: user 2026-09-16]
- Ideas thrown mid-work are integrated, refined or REJECTED with reasons and re-asked via AskUserQuestion; each gets a roadmap placement, not immediate code.  [Ruled: user 2026-09-16]
- The developer judges by what people can SEE and USE and prefers to test when implemented. Feature priority order: clients, invoices, delivery notes, products, settings, translations, invoice design, then the rest.  [Ruled: user 2026-09-22, 2026-10-01]
- Economize ruling: one advisor() per gate; the full 3-lens panel only at a wave boundary on a frozen commit; panels on the developer's word.  [Ruled: user 2026-08-19]
- Sabotage the INVARIANT, not the diff: derive invariants from CLAUDE.md Lessons + scripts/gates/ and re-run the mutants the diff touches.  [Ruled: user 2026-08-19]
- Reviewer subagents froze the parent twice on 2026-08-28: lens prompts write the full report incrementally to disk and RETURN ONE LINE; suggest /compact BEFORE a panel; a lost lens is completed INLINE (disclosed self-graded); a round with a lost lens is a FLOOR.  [Observed: 2026-08-28]
- Heavy runs: foreground, one at a time, `timeout 580`, each gate half separately, `free -h` first; background tests were OOM-killed with swap near full.  [Observed: 2026-09-14]
- CORRECTION 2026-09-19: never pass a Makefile to bash; re-read compound commands for leftover fragments; no `2>/dev/null` on unpredicted commands. CORRECTION 2026-09-22/23: a CI monitor on an invented SHA stays empty with exit 0: `SHA=$(git rev-parse HEAD)` in the same command, FULL 40-char SHA, read every job's conclusion. CORRECTION: read the body, not the signature; prove session-buffer claims against `git reflog`/`git stash list`.  [Observed: 2026-09-01/19/22]

## 4. Machine facts that mislead (REFERENCE §12)
- `make up` is LIVE mode (source mounted): the first e2e after it can time out on route compilation: rerun. A stack that is down at resume is expected (wiped on purpose 2026-09-27): `make up`, then `make fixtures`.  [Observed: 2026-09-26/27]
- Recovery of a frozen session: `git stash create` + `git update-ref refs/recovery/<date> <sha>`; a drafted file is recoverable from the dead session's `tool_use` input; a reboot wipes /tmp.  [Observed: 2026-09-26]

## 5. Known stale or contested (check before quoting; full list REFERENCE §17)
- The LATER entry wins: do not resurrect superseded rulings (examples: invitation of an existing account, recovery-code hashing now SHA-256, status tones, barcode now unique per company, cookie banner now wanted).  [Source: r2-decisions contradictions]
- Not user rulings, never cite as AGREED: entries tagged ASSUMED (review) / DECIDED (revisit) / PROVISIONAL / TAKEN OVERNIGHT.  [Source: r2-decisions]
- Resume state in memories is likely stale (uncommitted rework, a wiped scale stack, ideas pending rulings): verify with `git log`, the plan file and SPEC §8 before acting.  [Source: r2-memories §4; Unverified now]
- START.md and SPEC §7 disagree about the dev operator password; `make versions` is the truth for version numbers; fiscal article numbers move on 2027-01-01 (FR CIBS recodification).  [Source: r2-research-ops §5, docs/fiscal/FR.md]
- Ruling counts reflect SPEC §7 as read 2026-10-02 (456 entries); a later ruling is not here.  [Observed: r2-decisions header]

## 6. Pointer table: what to read in EXPERTISE-REFERENCE.md, and when
| Task touches | Read REFERENCE section |
|---|---|
| Product behaviour, modules, portal, till/counter, buying, jobs, legal pages | §2 |
| Screens, design system, forms, feedback, a11y, scanner, keyboard | §3 |
| Layering, events, lists/paging, PDFs, imports, performance | §4; list/JSON-LD/Doctrine query traps also §12 and the Symfony pack |
| Auth, MFA, passkeys, roles, support access, secrets | §5, §13 |
| Tax, VAT, withholding, numbering, e-invoicing | §6, §14 |
| Stock, inventory, barcodes, valuation | §7 |
| Settings, modules, subscription, licensing procedure | §8, §16 |
| Process, build order, tooling, CI | §9 |
| Anything that looks like a one-way door or a refusal | §10 |
| Till hardware and certification | §15 |
| Open questions before relying on a fiscal or hardware claim | §18 |

## 7. Open questions that most often bite (REFERENCE §18)
- Does a cafe's monthly-account ordering sit outside the Tunisian homologated-register obligation, and does the accreditation route allow twes-in at all?  [Unverified]
- French text of the arrete 3 Dec 1987 was read only through a ministry summary; the FR archived-data format norm is not yet published.  [Unverified]
- Lawyer items: dual-licence dependency policy, CLA wording, whether hosting triggers AGPL §13, the shipped legal-page drafts.  [Unverified]
