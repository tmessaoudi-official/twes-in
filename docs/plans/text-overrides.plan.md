# text-overrides Plan

Row 130 of `docs/plans/first-client.plan.md`, update 1, on the local branch `update1` (never pushed or tagged before the
first client's install, 2026-10-26).

## Decisions Log
- [2026-10-24 18:25] AGREED: Update 1's feature is « the texts translation to modify any texts with the API »: any text
  the application shows is changed through the API, without a release (developer). Basis: 2026-09-13, translation
  overrides are configurable; tax arithmetic and legal mentions stay operator-owned preset data.
- [2026-10-24 18:27] AGREED: two levels, the installation's texts set by the operator, then a company's own set by its
  owner, which wins for that company; any screen text, the mails and the printed documents' own labels, the fiscal texts
  never; a release changing an original a shop changed keeps theirs and flags it in « Textes », with the new original
  beside it, kept or taken by the shop (developer, AskUserQuestion).
- [2026-10-24 18:36] ASSUMED (review): The printed labels a mandatory mention or a legal status prints through stay locked, the rest of pdf is open (rule: guide but never choose a fiscal or legal answer; advisor agreed): locked format.*, invoice.corrects, invoice.reason, invoice.supply_date, invoice.operations*, invoice.fingerprint, line.unit_price, line.net, totals.subtotal, totals.total_net, totals.total_tax, totals.total, totals.withheld, watermark.*, demo.band, copy.*; open the titles (the developer's own « Facture » → « Facture de vente »), parties' and dates' labels, remarks, reception and agreement blocks, payment instructions, paid stamps, the statement. Alternative: lock the titles too, or open everything but the fiscal domain.
- [2026-10-24 18:36] ASSUMED (review): company.texts.write goes to the owner's role alone, as 18:27 ruled « by its owner »; a custom role may be given it (rule: a recorded ruling dictates it). Alternative: the admin's role as well.
- [2026-10-24 18:36] ASSUMED (review): A screen text is named by its file, domain screen or screen:<part>: the parts reuse the main file's top-level names (invoices in deliveries and invoice-bulk, auth in two-factor, settings in settings-refusals), so a key alone does not say which store to lay it on (rule: one canon; read from the files, 18:33). Alternative: one flat key space, which collides.
- [2026-10-24 18:59] ASSUMED (review): One text_override table whose company_id is null for the installation's texts, its key (company, language, domain, text_key) NULLS NOT DISTINCT, as notification_preference; not CompanyOwned and named in CompanyColumnTest::NOT_SCOPED, every read naming its company, since the company filter would hide the installation's rows (rule: one canon, the notification_preference and setting precedents). Alternative: a level column and a level_id string, as setting.
- [2026-10-24 18:59] ASSUMED (review): No updated_by column: who changed a text is the audit trail's (text.overridden, text.reset, by address and level, never the words), so no column names a person and the account erasure has nothing new to cover (rule: data over code, one canon). Alternative: an updated_by user column, covered by the erasure.
- [2026-10-24 19:19] ASSUMED (review): The permission is company.texts, in the company group beside company.settings, rather than company.texts.write: one action, worded as its neighbours are; the owner holds it through the wildcard (rule: one canon). Alternative: company.texts.write.
- [2026-10-24 19:19] ASSUMED (review): Every refused text answers 422 {error: <reason>}, unknown_text included; a member without company.texts meets the guard's 404 as a stranger does; the reads by a member and with no session carry the screens' files alone, the API's texts (a payment instruction may hold a bank account) shown only in the writers' catalogue (rule: one canon, never silent; advisor 2026-10-24). Alternative: 404 for unknown_text, 403 for a member.
- [2026-10-24 19:19] ASSUMED (review): The texts' routes are plain controllers, a key's dots and a part's colon in the path, which API Platform's .{_format} would not let through; the controller answers a version saved since as the /problems/record-changed JSON itself, since a plain route's exception renders Symfony's error page (no other plain controller reads a version: git grep, 2026-10-24); the operator's step-up is PlatformWriteStepUpListener's, one wall over /api/platform (a second check in the controller survived its sabotage, so it was removed) (rule: one canon; advisor 2026-10-24). Alternative: API Platform resources with the key in the body.

## Formal Plan

My understanding: a person allowed to (the operator for the installation, a company's owner for the company) finds any
text by what it says, changes it in each language, and sees the change everywhere at once; nothing a release ships
silently replaces it, and nothing it does can drop a legal mention or break a placeholder.

Key assumption: the shipped texts stay the files they are (`web/public/i18n/**`, `api/translations/*`), and an override
is a row laid over them at read time, never a rewritten file.

### What is overridable
- Screen texts: every key of `web/public/i18n/<lang>.json` and of each part `web/public/i18n/<part>/<lang>.json`, in
  every language whose `interface` is on (`languages.json`).
- The API's own texts: the `emails`, `pdf`, `imports` and `roles` domains of `api/translations/`.
- Never: the `fiscal` domain; in `pdf`, every label a mandatory mention or a legal status prints through (the list
  below, ASSUMED in the Decisions Log); and any screen key naming a tax or a mention. The locked keys are data
  (`api/config/texts-locked.yaml`), and a test reds on a `pdf` key that is in neither the locked list nor the open one,
  so a new printed label is classified when it is added.
- `pdf` locked (TN Code de la TVA art. 18-II, docs/fiscal/TN.md § 7; FR CGI annexe II art. 242 nonies A, FR.md § 4):
  `format.*` (formats, not words); `invoice.corrects`, `invoice.reason` (a credit note names what it corrects, EN 16931
  BG-3); `invoice.supply_date`, `invoice.operations*` (FR's operation category and supply date); `invoice.fingerprint`;
  `line.unit_price`, `line.net`, `totals.subtotal`, `totals.total_net`, `totals.total_tax`, `totals.total`,
  `totals.withheld` (the HT and TTC figures and the withholding the law names); `watermark.*`, `demo.band`, `copy.*`
  (what makes a page a draft, a duplicate or of no fiscal value). Open: the titles (the developer's own example,
  « Facture » → « Facture de vente »), the parties' and dates' labels, remarks, the reception and agreement blocks,
  payment instructions, the paid stamps, the statement.
- A screen text is named by its file: domain `screen` for `web/public/i18n/<lang>.json`, `screen:<part>` for
  `web/public/i18n/<part>/<lang>.json`, as the parts reuse the main file's top-level names (`invoices` in `deliveries`
  and `invoice-bulk`, `auth` in `two-factor`): the overlay knows which service to lay it on.

### API (`api/src/Texts/`, a core context: no module switch, as Settings)
1. `text_override`: `id`, `company_id` (null for the installation), `language`, `domain` (`screen`, `screen:<part>` or
   an API domain), `text_key`, `value`, `original` (the shipped text when it was written), `version`, `updated_at`;
   unique on (company_id, language, domain, text_key) `NULLS NOT DISTINCT`. Not `CompanyOwned` (the filter would hide
   the installation's rows): named in `CompanyColumnTest::NOT_SCOPED`, every read naming its company. Who wrote it is
   the audit trail's. Tests run on the worktree's own database, `twes_test_w9` (`api/.env.test.local`, `TEST_TOKEN=_w9`).
2. `ShippedTexts` port: reads the shipped catalogue. Screen texts are copied into the API image at build
   (`COPY web/public/i18n/ resources/screen-texts/` in `dev` and `prod`); tests read the tree's own copy; a test holds the
   copy equal to `web/public/i18n` (the `languages.json` pattern).
3. `OverrideText` use case refuses (each with a `reason` the screen translates): an unknown key or domain, a locked
   key, a language whose interface is off, an empty value, a value whose `{{placeholders}}` (or `%param%` for the API's)
   differ from the original's, a plural whose form names differ from the original's, a value past `app.texts.max_length` (2,000).
   `ResetText` deletes the row. Both audited (`text.overridden`, `text.reset`: the key, never the value) and staged as a
   live change of kind `text`.
4. Reads: `GET /api/texts/{language}` (public: the installation's screen overrides, for signed-out pages) and
   `GET /api/companies/{id}/texts/{language}` (members: installation then company, merged), each a flat
   `{key: value}`; `GET …/texts/{language}/catalogue?q=` (writers: every overridable key with its original, the
   installation's and the company's value, and `originalChanged` when the stored `original` differs from what ships).
5. Writes: `PUT/DELETE /api/platform/texts/{language}/{domain}/{key}` (operator, behind a step-up) and
   `PUT/DELETE /api/companies/{id}/texts/{language}/{domain}/{key}` (new permission `company.texts`, the owner's
   role as ruled; a custom role may be given it), with `version`; « Garder le mien » rewrites `original` to today's, « Prendre l'original »
   resets.
6. Mails and PDFs: a translator decorator lays the company's then the installation's overrides over `emails`, `pdf`,
   `imports`, `roles` for the company the text is written for (`CompanyContext`), never over `fiscal`.

### Web
7. A loader merges the public overrides over the shipped file before the first paint; once a company is entered,
   `TextsOverlay` fetches the company's and `setTranslation(lang, overrides, true)` on the root and on every opened
   part (`TextsParts`), again on a language switch and on a live change of kind `text`.
8. « Textes » in Paramètres (company) and in /platform (installation): a `DataList` searched by what a text says or by
   its key, each row its original, the installation's value and the company's; editing in a dialog per language with
   the original beside it and the refusal under the field; « Rétablir l'original »; a filter « L'original a changé »
   with « Garder le mien » and « Prendre l'original ». Undo first: a toast « Annuler » after a change or a reset.
9. Texts in fr, en and ar for all of it; the guide and docs/START.md say where texts are changed.

### Gates and architecture tests each step meets
- Step 1: `CompanyColumnTest` (`CompanyOwned`), `RecordReferenceCoverage` for `company_id` and `updated_by`,
  `ScaleGenerator::unaccounted`, the account erasure's columns naming a person; the catalogue is file-backed, so its
  list pages and filters in its provider with a bounded page (no `SEARCH_TEXT` pair to declare).
- Step 2: `GET /api/texts/{language}` joins `UnauthenticatedSweepTest`'s listed set; it reads no session
  (`hasPreviousSession()`), and answers with an ETag and `no-cache`, as nginx serves `/i18n/`.
- Step 6: the translator's overrides are kept per request: `ResetInterface` and a `WorkerModeTest` case.
- Step 7: the overlay of the new language is fetched in `TextsParts`' pre-switch step, with the parts; at start it is
  fetched beside the shipped file, never after it.
- Step 8: `list-stances.sh`, `live-reload.sh` (`reloadOn(['text'])`), `refusal-answers.sh`, `outcome-sentences.sh`,
  `icons-declared.sh`; a planned « Textes » entry in `COMING_NAV` or `coming.*` is replaced, not doubled.

### Acceptance (each test-first, each guard sabotaged)
- A company's override shows on its screens, mails and printed labels; another company keeps the installation's.
- A fiscal key, a changed placeholder, a broken plural, an unknown key: refused with its reason, nothing stored.
- A release changing an original: the override stays, `originalChanged` is true, both buttons do what they say.
- Signed-out pages show the installation's overrides; another tab of the company takes its change without a reload.
- The 60 KB screen-texts budget is untouched (overrides come from the API, never into the files).

### Risks and rollback
- The shipped catalogue copy drifting from the web's: held by the equality test and the image build.
- A part opened before the overlay arrives shows the original for a moment: the overlay is applied to each part as it
  opens, and the e2e reads a changed text on a part opened first.
- Rollback: the migration's `down()` drops the table; the loader falls back to the shipped files on any error of the
  overrides' read, said once in the console report, never blocking a screen.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | Table, shipped catalogue port and its copy in the images, the override and reset use cases with every refusal | M | done | 65b45c7a | api/src/Texts/** api/migrations/** infra/api/Dockerfile |
| 2 | Read and write endpoints, permission, step-up, audit, live change | M | done | 7f97c7c7 | api/src/Texts/** |
| 2b | The writers' catalogue: every overridable text with its original, the installation's and the company's value, `originalChanged`, searched and paged | M | done | - | api/src/Texts/** |
| 3 | Mails and PDFs through the overrides | S | todo | - | api/src/Texts/** |
| 4 | Web: the merge at load, on entering a company, on parts, on a live change | M | todo | - | web/src/app/shared/i18n/** |
| 5 | « Textes » pages in Paramètres and /platform, texts in fr, en, ar, the guide | M | todo | - | web/src/app/settings/** web/src/app/platform/** web/public/i18n/** |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
- An installation-level change is staged as a live change with no company, which `StagedLiveChanges` publishes on the
  operator's own channel alone: other companies' open tabs take it on their next load, not live. The acceptance line
  « another tab takes a change without a reload » holds for a company's own texts.
