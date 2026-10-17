# tool-audit Plan

## Decisions Log
- [2026-10-16 21:51] ASSUMED (review): The sweep's five research agents run on the session's model (no agent override is set: model-override.sh get agent is empty), unnamed, read-only on the tree, each writing its report to var/claude/tool-audit/raw/ and returning one line. Alternatives: a cheaper model per group (riskier on documentation reading).
- [2026-10-16 22:17] ASSUMED (review): E-12 fixed at once (P1, wrong stock under a fault): the publisher guards every domain-event listener; advisor agreed at 3C. Alternatives: per-listener catches with an architecture test.
- [2026-10-16 22:35] ASSUMED (review): B-6 fixed at once (security): symfony/lock on flock for every rate limiter; the advisor placed it after the page. Alternatives: PostgreSQL advisory locks, or a recorded departure.
- [2026-10-16 22:43] ASSUMED (review): Optimistic locking takes Doctrine's own mechanism: #[ORM\Version] on each edited aggregate root, checked by a Shared Application port (RecordVersions) whose Doctrine adapter calls EntityManager::lock(LockMode::OPTIMISTIC, version), the flush-time UPDATE … WHERE version closing the race; HTTP always sends the version (required on every PUT), internal callers (imports, fixtures) pass none and skip the check; advisor agreed at goal start. Alternatives: a domain-managed counter under a pessimistic lock; If-Match/ETag headers.
- [2026-10-16 22:43] ASSUMED (review): The stale-version 409 is the refusal contract's first problem type (record-changed, a ProblemExceptionInterface API Platform renders with its type), so the web tells it from the other 409s of the same PUT (a customer number taken); the web reloads, merges through mergeSavedVersion and says so, never retrying by itself; advisor agreed. Alternatives: status alone (ambiguous), an automatic retry after a clean merge.
- [2026-10-16 22:43] ASSUMED (review): Left out of the version check, each listed in the gate with its reason: a person's own toggles where the last word is the intent (module interest, the company opened at sign-in) and the company's single switches (second factor required, period closing), which still bump the company's version that the profile form checks; item POST actions and deletes stay out of « edit » as the 17:17 ruling wrote it, a follow-on under Needs input. Alternatives: every company PUT checked.
- [2026-10-17 01:03] AGREED: All 62 findings marked « fix » by the developer (no notes, no reordering); step 2 done.
- [2026-10-17 01:03] ASSUMED (review): Step 5 follows the findings page's ranked order (unchanged by the developer's marks; advisor agreed).
- [2026-10-17 01:14] AGREED: System writes lock the versioned row (SELECT … FOR UPDATE); the product's save and the cost on receipt first (SPEC § 7).
- [2026-10-17 04:27] ASSUMED (review): Settings slice: the single key's PUTs stay, listed without a version (a person's own preference, a value set by a script: the last word is the intent, and the row is held); every page saving several keys goes through the batch PUT, each key with its version, an item checked by RecordVersionsTest's BATCHES list. Alternatives: remove the single key's PUT (breaks scripts and the click-as-you-go preferences).
- [2026-10-17 04:27] ASSUMED (review): Forms without a field-by-field merge (the platform's support channels and delays, the legal identity, the document design) answer a save made since by showing what is saved now, dropping what was typed, with the « Rien n'a été enregistré » toast; the settings, customer and product defaults merge through liveRecord, made generic over a version per key. Alternatives: give those three forms liveRecord too (more code for operator-only and single-owner screens).
- [2026-10-17 05:04] ASSUMED (review): Editors opened inside a list (units, taxes, then every catalogue with an inline form) take liveRecord over the row the list now holds, with the banner, and merge a save made since field by field; basis: the AGREED ruling of 2026-09-17 (2), « a record another person changes while I edit it merges per field », which no later ruling narrows. It also corrects the 04:27 pick for the document design, the platform's support channels and delays and the legal identity, which drop what was typed: they follow in step 3. Alternatives: reload and reopen the row, dropping what was typed (contradicts the ruling).
- [2026-10-17 07:08] ASSUMED (review): The roles editor, which has no descriptor form, merges another person's save as two parts over a form with no controls: the name and the set of ticks, each one field (the ticks compared as a sorted set), as the 2026-09-17 (2) ruling merges a document's lines as one field; a save sends the version the open role stands on, so a form opened before a permission was taken away is refused rather than giving it back. Alternatives: one tick per field (a conflict per permission, more precise, a second merge mechanism for one screen); refuse the stale save without merging (no merge, the person reopens).
- [2026-10-17 08:56] AGREED: the stock map's group moves send each rectangle's version (one stale refuses the group, the floor is read again, the selection stays); « Annuler » sends the version the save produced and is refused if changed since; a product's barcodes and home locations send and move the product's version (SPEC § 7 2026-10-17 08:55).
- [2026-10-17 10:50] ASSUMED (review): slice 5i: a product's homes move its version through a port the inventory owns (HomedProducts, answered in Products/Infrastructure/Inventory), which holds the product under its row lock on every homes write, versioned or not — because CLAUDE.md says a module reaches another only through a port it owns, and a write with no version still flushes a versioned row (row lock on system writes, slice 3). Alternatives: the inventory writing the product through the products' repository it already reads.
- [2026-10-17 10:50] ASSUMED (review): slice 5i: the codes tab keeps rows being typed when another person's codes arrive, shows « Prendre la version enregistrée » / « Garder ma saisie », and rows nobody touched take the saved list — because SPEC § 7 2026-09-17 rules that a form being typed in keeps what is typed and shows the banner. Alternatives: rows start again from the saved list (what typed is lost, as before).
- [2026-10-17 10:50] ASSUMED (review): slice 5i: PUT …/home-locations (one place made the main home, no screen calls it) takes no version and still moves the product's, listed WITHOUT; the replace PUT of an establishment's list requires it — because the 08:55 ruling names the sets replaced by a PUT of their own. Reorder points (their own row, upserted) are split to a slice of their own. Alternatives: versioning the main-home PUT too.
- [2026-10-17 11:48] ASSUMED (review): slice 5j: a product's reorder points send the product's version and move it, as its homes do (the same HomedProducts port, renamed ProductsHeld/changedBeside since it now serves two tabs), not a version of their own — because they sit on the same product page, it needs no migration and no 0-for-no-row value, and advisor() agreed. The DELETE moves it too, unversioned like a home's clear. The tab hears other people's changes and keeps a typed threshold over a changed one with the conflict line (2026-09-17 ruling). Alternatives: a version on each reorder point row, with 0 for none.
- [2026-10-17 12:54] ASSUMED (review): slice 5k: the price list editor, built by hand rather than from a descriptor, merges another person's save field by field where it has a control (name, validity days, active) and as two parts where it has none: the scope (whom it is for: scope, group and customer together, since the group alone would land unseen behind another scope) and the prices (one part, as a document's lines) — because a field with a control merges on its own for free, and advisor() agreed. Alternatives: one part for every term of the list, as the roles editor does for its name; converting the editor to a descriptor form first.
- [2026-10-17 14:51] ASSUMED (review): slice 5l: a recurring expense's revision refused as saved since reopens its dialog over the version saved, the typed values merged against it with mergeSavedVersion (typed kept, theirs taken where nothing was typed, a field both changed offered through DescriptorForm's conflict line), rather than refreshing and dropping what was typed — because the 2026-09-17 ruling keeps what is typed, DescriptorForm already shows conflicts, and advisor() agreed. The pause row actions only refresh and say live.saved_since, having nothing typed. Recurrence::revise no longer rewrites an equal next day, which Doctrine read as a change and which would have moved the version over a revision that changed nothing. Alternatives: refresh and toast only; a version only people's writes move.
- [2026-10-17 16:04] AGREED: The platform subscription panel becomes a descriptor form now (plain fields, gaining the field-by-field conflict offers of mergeSavedVersion); the price list editor moves once a kind: 'rows' descriptor field exists, built with the document line editors.
- [2026-10-17 16:04] AGREED: Pausing and resuming a recurring invoice or expense become actions of their own (POST .../pause, .../resume) that carry no version, instead of the full revision PUT.
- [2026-10-17 16:04] AGREED: A delete of an editable record sends the version it was read at and one saved since answers 409; item actions (issue, validate, cancel, pay...) carry no version, guarded by their status checks under the row lock.

## Formal Plan

The tool-by-tool audit (docs/SPEC.md § 7, 2026-10-16 17:24; § 8 head, item 3): every tool used here is read against
its own documentation at the version pinned (`make versions`), and each finding is fixed or recorded in § 7 as a
departure with its reason. Lens 55 « code layout and architecture conformance » folds in (§ 7 2026-10-16 17:25).
Optimistic locking and the one refusal contract are the first two fixes (§ 7 2026-10-16 17:17); the rest go in the
order the developer ranks them on the findings page.

1. Sweep: five read-only research agents, one per group of tools, each finding with the documentation page and its
   words, the place in the tree (`file:line`), what the documentation recommends, a severity, the fix, and whether
   § 7 already records it as a departure. Raw reports in `var/claude/tool-audit/raw/`.
   - A: Angular, TypeScript, Angular Material, Tailwind, Vitest
   - B: Symfony, PHP, Monolog, Messenger and Scheduler
   - C: API Platform, Doctrine (ORM, migrations), PostgreSQL
   - D: Docker and Compose, nginx, Caddy, GitHub Actions
   - E: Playwright, and lens 55 (layout and architecture against § 3 and the DDD reference)
2. Synthesis: one ranked list, duplicates merged, each finding checked against the tree before it is kept; a findings
   page for the developer to rank (agree, reject, note), and a § 7 NOTED entry pointing at it.
3. Optimistic locking: a version on every editable record, 409 when it changed since it was opened.
4. The refusal contract: one problem+json refusal with a stable reason, one web mapper in place of the per-feature ones.
5. The other fixes in the developer's order, departures recorded in § 7.

Verification: each fix test-first with its sabotage; the gate, the e2e it touches, the production image and the
deploy check before each push.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | Sweep, five groups of tools | L | done | - | var/claude/tool-audit/** |
| 2 | Synthesis and the findings page | M | done | - | docs/SPEC.md |
| 3 | Optimistic locking (done: the mechanism and the customer; vendor, product, expense; invoice, quote, delivery note; the company profile; the settings batch save, 236d859c; units and taxes, 2a0ed3cf; custom fields, establishments, numbering series, 6d9178df; customer groups, expense and product categories, 9cacee28; roles, merged by name and by ticks, 087f6973; contacts, aa4a1857; stock locations, 37843ee7; stock floors and structures, « Annuler » on the version the save produced, 99d14b55; drawings, one and as a group, d50a5077; a product's codes and homes on the product's version, c8bbe02f; its reorder points on it too, a621c65d; price lists, the scope and the prices merged as parts, 84287b79; recurring invoices and expenses, a refused dialog asked again merged, d5bc9530; the platform subscription, 0 for none, its payment's confirmation holding the declaration and the subscription. The WAITING list of RecordVersionsTest is gone, de163e19) | L | done | de163e19 | api/** web/** |
| 3a | The platform's subscription panel as a descriptor form, with the field-by-field conflict offers (§ 7 2026-10-17 16:04) | M | done | - | web/src/app/platform/** |
| 3b | Pause and resume of recurring invoices and expenses as actions of their own, no version (§ 7 2026-10-17 16:04) | M | todo | - | api/src/Module/Recurring*/** web/src/app/recurring*/** |
| 3c | A delete of an editable record names its version, 409 when saved since (§ 7 2026-10-17 16:04) | L | todo | - | api/** web/** |
| 4 | The refusal contract | L | todo | - | api/** web/** |
| 5 | The other fixes, in the developer's order | L | todo | - | |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
- The sweep reports live in `var/claude/tool-audit/raw/` only (not in git); the page carries each finding's fix, documentation and grade.
- Sealing every unsealed document at once (`SealInvoiceChains::all`, a maintenance run) reads them without holding their rows: a payment saved on one of them meanwhile ends that slice as `RecordChanged`, rolled back and run again. Issuing seals inside the transaction that already holds the document, so only the maintenance run meets it; a locked read there cannot be shown by a single-process test, so it was left out.
### Known issues
