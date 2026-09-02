# RECONCILIATION — every dated ruling, and where it went

The five plan files in this directory were archived VERBATIM on 2026-09-01, superseded by
`docs/SPEC.md`. This table is the safety condition for that move: **every dated ruling in those
files, and every `§ Gotchas` entry in `CLAUDE.md`, is accounted for here.** Nothing was dropped
silently.

**276 entries, 276 dispositioned, 0 unmapped** — asserted in both directions
(no entry without a disposition, no disposition without an entry).

**All 178 `carried` rows carry a LITERAL anchor string from `docs/SPEC.md`, and all
178 were asserted present.** Re-run the assertion rather than trusting this sentence.

It is not decoration. It caught, in three passes: 25 anchors written as summaries rather than
quotations; **ten rulings that had not in fact been carried** — `FixedCharge::MAX_LABEL_LENGTH`,
the two runtime documentation-parser dependencies, the unasserted migration CHECK constraints, the
`+ 1` working-scale guard band, `SessionStateReleaser` and the no-pool reinterpretation, the
rejection of vendoring Flutter's whole Noto set, the hand-written `clientId` regex, PHPStan's
level-6 configuration, `VARCHAR(32)`-not-native-enum, and `DocumentState` as a closed set; and one
wrong Angular version taken from a `package.json` range floor. **A disposition table written by the
author of the spec agrees with itself unless something checks it.**

## Vocabulary

| Disposition | Meaning |
|---|---|
| `carried` | the ruling is IN FORCE and restated in `docs/SPEC.md`; the **anchor** is a literal string from that file. |
| `carried-as-pointer` | in force, and deliberately NOT restated — see `SPEC.md` § 0 rule 7. |
| `process-rule` | a rule about HOW work is done here, so it lives in `CLAUDE.md`, not in the spec. |
| `superseded` | a later ruling replaced it. The reason column names the successor. |
| `dropped` | certification-round narrative, upstream analysis, or spent one-off sequencing. It travels VERBATIM inside the archived file beside this table — dropped from the SPEC, never from the record. |

## Tally

| Disposition | Count |
|---|---|
| `carried` | 177 |
| `process-rule` | 64 |
| `superseded` | 18 |
| `dropped` | 16 |
| `carried-as-pointer` | 1 |

## A caveat about line numbers

`archived line` is the line in the file **as it sits in this directory** (each archived file carries
a two-line header, so it is the original line + 2). `original line` is the line at commit `5dfebf1`,
before the move. `CLAUDE.md` line numbers are **as at `5dfebf1`** — that file was rewritten in the
same commit as this move, so its numbers no longer resolve; the date and the headline do.

## The table

> **If you regenerate this table, redact any permitted-licence identifier from the `entry` column.**
> The generator truncates each entry to 130 characters, and one of them quotes the closed list — which
> makes this file a licence SURFACE and turns `test-gates.sh`'s inventory case red. It did, at the
> consolidation commit, invisibly: the inventory enumerates from `git ls-files` **cached only**, so
> while this file was still untracked the case passed on a file it could not see.

| # | file | archived line | original line | date | verb | entry | disposition | anchor / reason |
|---|---|---|---|---|---|---|---|---|
| 1 | `build-waves.plan.md` | 16 | 14 | 2026-08-29 | AGREED | **round 5's seven findings all close (or are explicitly ruled deferred) BEFORE Wave 1 | `carried` | close (or are explicitly ruled deferred) BEFORE |
| 2 | `build-waves.plan.md` | 30 | 28 | 2026-08-23 | AGREED | **a LINE discount REDUCES the VAT base.** Worked: qty 10 x 15.000 TND = 150.000 gross, | `carried` | docs/spec/pricing-vectors.json |
| 3 | `build-waves.plan.md` | 36 | 34 | 2026-08-23 | AGREED | **a DOCUMENT-level discount is allocated PRO-RATA BY BASE across rate groups.** Worked: | `carried` | DOCUMENT-level discount is allocated PRO-RATA BY BASE across rate groups. |
| 4 | `build-waves.plan.md` | 42 | 40 | 2026-08-23 | AGREED | **INCLUSIVE tax extracts PER RATE GROUP, reusing `VatRoundingPoint` rather than adding a | `carried` | INCLUSIVE tax extracts PER RATE |
| 5 | `build-waves.plan.md` | 48 | 46 | 2026-08-23 | AGREED | **when extracting INCLUSIVE tax, the NET is rounded half-up and the VAT absorbs the | `carried` | rounded half-up and the VAT absorbs the |
| 6 | `build-waves.plan.md` | 60 | 58 | 2026-08-23 | AGREED | **the runtime role's table privileges come from an IDEMPOTENT GRANT STEP shipping with | `carried` | role's table privileges come from an IDEMPOTENT GRANT STEP shipping |
| 7 | `build-waves.plan.md` | 67 | 65 | 2026-08-23 | AGREED | **Wave 1's certification cap is extended from 5 rounds to 6, for the sole purpose of | `superseded` | cap re-ruled 2026-08-29 |
| 8 | `build-waves.plan.md` | 75 | 73 | 2026-08-23 | AGREED | **all 21 of round 4's open findings close (or are explicitly ruled deferred) BEFORE | `superseded` | round 4 closed; round 5 ran |
| 9 | `build-waves.plan.md` | 78 | 76 | 2026-08-22 | AGREED | a DRAFT may have no client and an ISSUED document may not — the requirement attaches to | `carried` | document_client_required_once_issued |
| 10 | `build-waves.plan.md` | 83 | 81 | 2026-08-22 | AGREED | `Invoice` holds a client ID and never a `Client` object — one aggregate references | `carried` | rule, where an amount is copied BY VALUE because it must never move under |
| 11 | `build-waves.plan.md` | 87 | 85 | 2026-08-22 | AGREED | the foreign key RESTRICTS and does not cascade — deleting a client must never delete | `carried` | foreign key RESTRICTS and does not cascade |
| 12 | `build-waves.plan.md` | 92 | 90 | 2026-08-22 | AGREED | the CHECK ships `NOT VALID` — documents issued before the column existed carry no | `carried` | documents issued before the column existed carry no client, no correct value can be |
| 13 | `build-waves.plan.md` | 97 | 95 | 2026-08-22 | AGREED | the client is a PREDICATE in the repository's state-only UPDATE, written | `carried` | state-only `UPDATE` |
| 14 | `build-waves.plan.md` | 101 | 99 | 2026-08-22 | AGREED | a well-formed `clientId` naming no client this tenant can see is a 422, not a 500 — | `carried` | is an existence oracle over |
| 15 | `build-waves.plan.md` | 108 | 106 | 2026-08-22 | AGREED | the edge constraint on `clientId` is a hand-written regex rather than `#[Assert\Uuid]`, | `carried` | HAND-WRITTEN REGEX, not `#[Assert\Uuid]` |
| 16 | `build-waves.plan.md` | 111 | 109 | 2026-08-22 | AGREED | **`Product` WRAPS `ProductPricing` and re-expresses none of F4's arithmetic.** Every rule about cost, profit rate and net price al | `carried` | a foodstuff and a service are taxed differently inside one |
| 17 | `build-waves.plan.md` | 112 | 110 | 2026-08-22 | AGREED | **only the AUTHORED price field is stored, enforced by a CHECK rather than by discipline.** `profit_rate` and `net_price_amount` a | `carried` | Enforced by a CHECK, not by discipline |
| 18 | `build-waves.plan.md` | 113 | 111 | 2026-08-22 | AGREED | **the SKU is optional and NOT unique.** Uniqueness needs an answer for what a collision returns to a caller and whether it is scop | `carried` | and whether it is scoped to non-deleted |
| 19 | `build-waves.plan.md` | 114 | 112 | 2026-08-22 | AGREED | **`BehaviouralIsolationTest`'s row synthesiser may return `null`.** `product` is the first table whose CHECK requires a column to  | `carried` | BehaviouralIsolationTest |
| 20 | `build-waves.plan.md` | 115 | 113 | 2026-08-22 | AGREED | **the client surface ships `POST` and `GET` only, and each missing verb is argued rather than deferred by default.** No `PUT`, bec | `carried` | full replacement must first answer whether a caller may supply |
| 21 | `build-waves.plan.md` | 116 | 114 | 2026-08-22 | AGREED | **contact ids are minted server-side, so the aggregate's duplicate-contact and contact-not-found refusals are unreachable from the | `carried` | in the change that makes |
| 22 | `build-waves.plan.md` | 117 | 115 | 2026-08-22 | AGREED | **the fifteen `client.*` keys land WITH the surface even though nothing resolves them.** They pay nothing down — `locale-key-parit | `carried` | gendered and pluralised differently in French and |
| 23 | `build-waves.plan.md` | 118 | 116 | 2026-08-22 | AGREED | **Client (+ contacts) lands in THREE commits, and is recorded as PART-LANDED between them rather than struck whole.** Domain, then | `carried` | InvoiceMapper |
| 24 | `build-waves.plan.md` | 119 | 117 | 2026-08-22 | AGREED | **a contact is an ENTITY with its own id; a document line stays POSITIONAL.** The two are deliberately different and the reason is | `carried` | ENTITY with its own id; a document line stays POSITIONAL. |
| 25 | `build-waves.plan.md` | 120 | 118 | 2026-08-22 | AGREED | **the client field set is DERIVED from EN 16931 — BG-7, BG-8, BG-9 — and the country code is validated by SHAPE only.** Licensing  | `carried` | — BG-7, BG-8, BG-9 — and the country code is validated by SHAPE only. |
| 26 | `build-waves.plan.md` | 121 | 119 | 2026-08-21 | AGREED | **the tenant settings table LANDED, and `vatRoundingPoint` is no longer a field on `CreateInvoice` at all.** The 2026-08-07 ruling | `carried` | much tax a document declares |
| 27 | `build-waves.plan.md` | 122 | 120 | 2026-08-21 | AGREED | **the settings READ requires an active transaction, and that is the control rather than ceremony.** An unbound session and a tenan | `carried` | services.yaml |
| 28 | `build-waves.plan.md` | 123 | 121 | 2026-08-21 | AGREED | **`BehaviouralIsolationTest`'s GOAL 7 now CHECKS what it previously ASSERTED.** Its finding message claimed *"some uniqueness mech | `carried` | every tenant-owned table had a surrogate key beside the tenant |
| 29 | `build-waves.plan.md` | 124 | 122 | 2026-08-07 | AGREED | **the `e2e` suite FAILS without a live stack and is therefore NOT in `composer gate`.** Skipping is the shape this project refuses | `carried` | require a built image and |
| 30 | `build-waves.plan.md` | 125 | 123 | 2026-08-07 | AGREED | **a header the edge owns is set with Caddy's `>` deferred prefix, on every field, even though one suffices.** A `header` block wit | `carried` | deferred operation is applied as a unit, |
| 31 | `build-waves.plan.md` | 126 | 124 | 2026-08-07 | AGREED | **the dev provisioner refuses on WHOSE objects a database holds, not WHETHER it holds any.** `provision-test-database.sh`'s "refus | `carried` | provision-test-database.sh |
| 32 | `build-waves.plan.md` | 127 | 125 | 2026-08-07 | AGREED | **the gapless counter is ONE atomic `INSERT … ON CONFLICT DO UPDATE … RETURNING`, not `SELECT … FOR UPDATE`.** The three-statement | `carried` | SELECT … FOR UPDATE |
| 33 | `build-waves.plan.md` | 128 | 126 | 2026-08-07 | AGREED | **`POST /api/invoices` creates a DRAFT and `POST /api/invoices/{id}/issue` issues it** — two single-purpose operations rather than | `carried` | POST /api/invoices/{id}/issue |
| 34 | `build-waves.plan.md` | 129 | 127 | 2026-08-07 | AGREED | **a write response is the document READ BACK inside the write transaction**, never the aggregate just built. `NUMERIC(21,6)` retur | `carried` | write response is the document READ BACK inside the write |
| 35 | `build-waves.plan.md` | 130 | 128 | 2026-08-07 | AGREED | **a client may not choose `vatRoundingPoint`.** `PerRateGroup` and `PerLine` produce numerically different tax figures, so a per-r | `carried` | per-request choice would be a client choosing how much tax a document declares. It |
| 36 | `build-waves.plan.md` | 131 | 129 | 2026-08-07 | AGREED | **`FixedCharge::MAX_LABEL_LENGTH = 64`, measured in CHARACTERS**, a derived bound rather than a ruled one. It was the last persist | `carried` | was the last persisted value in the domain with no bound at either end |
| 37 | `build-waves.plan.md` | 132 | 130 | 2026-08-07 | AGREED | **`phpstan/phpdoc-parser` and `phpdocumentor/type-resolver` are RUNTIME dependencies** (both MIT, recorded in `THIRD-PARTY-NOTICES | `carried` | `phpstan/phpdoc-parser` and `phpdocumentor/type-resolver` are RUNTIME dependencies |
| 38 | `build-waves.plan.md` | 133 | 131 | 2026-08-07 | AGREED | **the operation collects denormalization errors**, so a JSON number where a decimal string belongs is a 422 naming `lines[0].unitN | `carried` | lines[0].unitNet |
| 39 | `build-waves.plan.md` | 134 | 132 | 2026-08-06 | AGREED | no gate re-implements a parser its real consumer already has. DSN users come from DBAL's own DsnParser, JSON from json_decode (and | `carried` | own resolver, never a second parser beside it |
| 40 | `build-waves.plan.md` | 135 | 133 | 2026-08-06 | AGREED | the worker ORACLE is live — `frankenphp adapt` on the rendered configuration, asserting zero workers, with three distinct verdicts | `carried` | workers, with three distinct verdicts |
| 41 | `build-waves.plan.md` | 136 | 134 | 2026-08-06 | AGREED | the worker-mode control STOPS text-scanning. A `frankenphp adapt` ORACLE on the rendered configuration replaces detection (it asks | `carried` | frankenphp adapt |
| 42 | `build-waves.plan.md` | 137 | 135 | 2026-08-06 | AGREED | the Caddy-config and seam derivation lives ONCE, in `scripts/gates/lib/caddy-configs.sh`, sourced by both worker-mode gates — a ne | `carried` | Caddy-config and seam derivation lives |
| 43 | `build-waves.plan.md` | 138 | 136 | 2026-08-06 | AGREED | the worker-mode value axis is REBUILT rather than patched — the analysis moves to `scripts/gates/lib/worker-mode-analyse.php`, doe | `carried` | value analysis is a real parser |
| 44 | `build-waves.plan.md` | 139 | 137 | 2026-08-05 | AGREED | `extra.runtime` in any `composer.json` must be ABSENT or hold nothing but `class` equal to the permitted runtime — an allow-list o | `carried` | symfony/runtime |
| 45 | `build-waves.plan.md` | 140 | 138 | 2026-08-05 | AGREED | the worker-mode control INVERTS its three polarities rather than extending its lists — scope by exclusion, seam set derived from t | `carried` | deleting them would invent a project-specific spelling of a conventional |
| 46 | `build-waves.plan.md` | 142 | 140 | 2026-08-06 | AGREED | **a gate must enumerate the SURFACE, not the spellings — and not the locations | `carried` | a gate must enumerate the SURFACE, not the spellings — |
| 47 | `build-waves.plan.md` | 151 | 149 | 2026-08-06 | AGREED | **a security check that needs a daemon belongs in a gate that does not.** | `carried` | compose-config.sh |
| 48 | `build-waves.plan.md` | 155 | 153 | 2026-08-06 | AGREED | **a gate that names the DANGEROUS values is a gate that can be incomplete; name the | `carried` | enumerate the SURFACE, not the spellings |
| 49 | `build-waves.plan.md` | 163 | 161 | 2026-08-06 | AGREED | **a mutation harness must fail when its own mutation fails.** Two of round 27's new | `process-rule` |  |
| 50 | `build-waves.plan.md` | 169 | 167 | 2026-08-05 | RULED | **a UUIDv7 identifier is an ORDERING artefact and never a secret; `symfony/uid` is | `carried` | failed to ascend on about half of all consecutive same-millisecond |
| 51 | `build-waves.plan.md` | 196 | 194 | 2026-08-05 | RULED | **`symfony/uid` is adopted for identifier generation; the hand-written UUIDv7 layout is | `carried` | composer.json |
| 52 | `build-waves.plan.md` | 212 | 210 | 2026-08-05 | AGREED | **the Material Icons Apache-2.0 grant is verified, and the 2026-07-30 ruling STANDS | `process-rule` |  |
| 53 | `build-waves.plan.md` | 221 | 219 | 2026-08-05 | AGREED | **PHPStan is CONFIGURED AND WIRED at level 6, and every one of its findings is fixed | `carried` | CONFIGURED AND WIRED at level 6 |
| 54 | `build-waves.plan.md` | 233 | 231 | 2026-08-05 | AGREED | **the commit message for `d75003a` says "49 findings" and the reproducible figure is | `process-rule` |  |
| 55 | `build-waves.plan.md` | 239 | 237 | 2026-08-02 | AGREED | **only the KEY-SHAPE axis leaves `schema-tenancy.php`; the other eight stay** | `carried` | an attack suite MUTATES data, so it |
| 56 | `build-waves.plan.md` | 258 | 256 | 2026-08-01 | AGREED | **the behavioural suite STAYS and the gate deletion is REVERTED — the two are | `carried` | attack suite MUTATES data, so |
| 57 | `build-waves.plan.md` | 305 | 303 | 2026-08-01 | AGREED | **quantity representation is NOT stable across a save/reload; only its VALUE is.** | `carried` | representation is NOT stable across a save/reload; only its VALUE |
| 58 | `build-waves.plan.md` | 322 | 320 | 2026-08-07 | AGREED | **money crosses the HTTP boundary as a DECIMAL STRING, never a JSON number**, and the same for quantities and rates. JSON has one  | `carried` | crosses the HTTP boundary as a DECIMAL STRING, never a |
| 59 | `build-waves.plan.md` | 323 | 321 | 2026-08-07 | AGREED | **the server computes totals and the clients display them.** All three clients could compute from the lines; they must not. The ca | `carried` | three clients could compute from the lines; they must not. |
| 60 | `build-waves.plan.md` | 324 | 322 | 2026-08-07 | AGREED | **the read resource declares no `Delete`, `Put`, `Patch`, `Post` or `GetCollection`, and each omission has its own reason.** No de | `carried` | GetCollection |
| 61 | `build-waves.plan.md` | 325 | 323 | 2026-08-07 | RECORDED | **an ill-formed id answers 404, not 400, deliberately.** Distinguishing "malformed" from "absent" tells an unauthenticated prober  | `carried` | ill-formed id answers 404, not 400, deliberately. |
| 62 | `build-waves.plan.md` | 327 | 325 | 2026-08-07 | AGREED | **request-time tenancy is a PORT with a development-only adapter that a GATE refuses in production**, not a header the application | `carried` | TWES_TRUST_TENANT_HEADER |
| 63 | `build-waves.plan.md` | 328 | 326 | 2026-08-07 | AGREED | **a tenant-less request CLEARS the context rather than leaving it alone**, and this is the most important line in the seam. `InMem | `carried` | tenant-less request CLEARS the context rather than |
| 64 | `build-waves.plan.md` | 329 | 327 | 2026-08-07 | FOUND | **`TenantId::fromString()` NORMALISES a non-canonical id and `DocumentIdentity` REFUSES one**, and the asymmetry is correct in bot | `carried` | `TenantId::fromString()` NORMALISES a non-canonical id and `DocumentIdentity` REFUSES one |
| 65 | `build-waves.plan.md` | 330 | 328 | 2026-08-07 | RECORDED | **the new gate's first version reported its own subject's PHP docblock as configuration**, and its second missed a `//` comment wh | `process-rule` |  |
| 66 | `build-waves.plan.md` | 332 | 330 | 2026-08-06 | RULED | (developer, against a measurement): **the acquire-time provisioning guards run ONCE PER (ROLE, DATABASE) PER TTL WINDOW, not per a | `carried` | acquire-time provisioning guards run ONCE PER (ROLE, DATABASE) PER TTL |
| 67 | `build-waves.plan.md` | 333 | 331 | 2026-08-06 | FOUND | , and it invalidated the first implementation: **an in-process cache amortises across NOTHING under PHP-FPM.** PHP is shared-nothi | `carried` | amortise across nothing |
| 68 | `build-waves.plan.md` | 334 | 332 | 2026-08-06 | AGREED | **a failed verification is cached in NEITHER direction.** Not as success — one bad start-up would disable the guard for the whole  | `carried` | the fix for a wrongly-provisioned database is to fix the |
| 69 | `build-waves.plan.md` | 335 | 333 | 2026-08-06 | RECORDED | three fixture defects found while testing this, each a real thing to know. `twes_in_test` has NO row-level security enabled, so th | `process-rule` |  |
| 70 | `build-waves.plan.md` | 337 | 335 | 2026-08-06 | AGREED | **the release half of the connection lifecycle is `SessionStateReleaser`, a `ResetInterface` service — and "when a connection is R | `carried` | and "when a connection is RETURNED" had to be reinterpreted |
| 71 | `build-waves.plan.md` | 338 | 336 | 2026-08-06 | MEASURED | , AND IT RAISES A DECISION THIS LIST PREDATES: **the ACQUIRE-time obligations cost ~10.8 ms per connection.** `assertConnectionCan | `carried` | completeness-reviewer |
| 72 | `build-waves.plan.md` | 340 | 338 | 2026-08-06 | AGREED | **the `InvoiceRepository` PORT TAKES NO TENANT, correcting a ruling that was not buildable.** `DocumentIdentity`'s docblock said * | `carried` | parameter is satisfied by whatever tenant id the caller happens to hold, including the |
| 73 | `build-waves.plan.md` | 341 | 339 | 2026-08-06 | AGREED | **`save()` writes with DBAL, not through the UnitOfWork, and that is a measurement rather than a preference.** Whole-rewrite of th | `carried` | writes with DBAL, not through |
| 74 | `build-waves.plan.md` | 342 | 340 | 2026-08-06 | AGREED | **`save()` REFUSES outside an active transaction rather than opening one.** A document number is gapless, so allocating one and pe | `carried` | `save()` REFUSES outside an active transaction rather than opening one. |
| 75 | `build-waves.plan.md` | 343 | 341 | 2026-08-06 | AGREED | **the port declares no `delete()` and no query methods.** An issued document is `cancel()`ed, never deleted — adding a delete woul | `carried` | The port declares no `delete()` and no query methods |
| 76 | `build-waves.plan.md` | 344 | 342 | 2026-08-06 | FOUND | **a `Domain/` port gets NO auto-alias, so the binding must be explicit configuration.** Symfony auto-aliases an interface to its s | `carried` | in an autowired resource, and |
| 77 | `build-waves.plan.md` | 345 | 343 | 2026-08-06 | RECORDED | **the repository's own test caught me committing the exact defect its class docblock describes.** Two assertions compared a `quant | `process-rule` |  |
| 78 | `build-waves.plan.md` | 347 | 345 | 2026-08-06 | AGREED | (decision 1 of 2 the savepoint section reserved for Wave 1): **`assertStillBoundTo()` lives on the `TenantIsolationStrategy` PORT* | `carried` | `assertStillBoundTo()` lives on the `TenantIsolationStrategy` PORT |
| 79 | `build-waves.plan.md` | 348 | 346 | 2026-08-06 | AGREED | (decision 2 of 2): **the re-check is driven from the DBAL seam that emits the savepoint, not from repository code.** `SavepointTen | `carried` | re-check is driven from the DBAL seam that emits the |
| 80 | `build-waves.plan.md` | 349 | 347 | 2026-08-06 | FOUND | **the prescription's "after any savepoint release or rollback" was half wrong, and a FULL rollback must be excluded.** `RELEASE SA | `carried` | on every rolled-back request — |
| 81 | `build-waves.plan.md` | 350 | 348 | 2026-08-06 | FOUND | **PostgreSQL accepts four spellings of a savepoint rollback and the `SAVEPOINT` keyword is OPTIONAL** [Verified: `ROLLBACK TO sp1` | `carried` | predicate is derived from the |
| 82 | `build-waves.plan.md` | 351 | 349 | 2026-08-06 | RECORDED | the guard **refuses to run rather than degrading** when the driver's native connection is not a `\PDO`. The tempting `if (!$native | `process-rule` |  |
| 83 | `build-waves.plan.md` | 353 | 351 | 2026-08-06 | AGREED | **the rendered document number's PATTERN is derived from the stored string, and `InvoiceMapper` is therefore stateless.** Implemen | `carried` | [Verified: 48 of 48 combinations |
| 84 | `build-waves.plan.md` | 354 | 352 | 2026-08-06 | AGREED | **the AUTHORED pattern width is not recovered when a sequence outran its padding, and that is accepted rather than worked around.* | `carried` | every width from 1 to 5 renders |
| 85 | `build-waves.plan.md` | 355 | 353 | 2026-08-06 | FOUND | **the migrations' CHECK constraints were asserted by NOTHING**, five of them, since `Version20260801120000`. Lower risk than the u | `carried` | Lower risk than the usual unenforced-control shape — PostgreSQL cannot quietly stop applying a |
| 86 | `build-waves.plan.md` | 356 | 354 | 2026-08-06 | RECORDED | **two existing tests refused the new column until it was declared deliberate, and both were right.** `BehaviouralIsolationTest` we | `process-rule` |  |
| 87 | `build-waves.plan.md` | 358 | 356 | 2026-08-01 | AGREED | **the RENDERED document number is persisted alongside the sequence** — resolving item 3 | `carried` | is persisted alongside the sequence |
| 88 | `build-waves.plan.md` | 378 | 376 | 2026-08-01 | AGREED | **certification rounds PAUSE until the behavioural suite lands**, then one MAXIMAL round | `dropped` | certification-round process record, archived verbatim |
| 89 | `build-waves.plan.md` | 386 | 384 | 2026-08-01 | AGREED | **the schema gate is split by DISCOVERY versus VERDICT, and the shape-enumeration | `carried` | every relation discovery finds: read, |
| 90 | `build-waves.plan.md` | 414 | 412 | 2026-08-01 | AGREED | , **AND SUPERSEDED 2026-08-06 — API Platform IS INSTALLED AND IS THE HTTP SURFACE.** | `superseded` | API Platform IS installed and IS the HTTP surface |
| 91 | `build-waves.plan.md` | 425 | 423 | 2026-07-29 | AGREED | build in **waves**, each independently reviewable, each ending in a | `carried` | each independently reviewable, each ending in a |
| 92 | `build-waves.plan.md` | 428 | 426 | 2026-07-29 | AGREED | the wave list below is the **baseline**, not the final scope. The | `carried` | baseline, not the final scope |
| 93 | `build-waves.plan.md` | 431 | 429 | 2026-07-29 | AGREED | **Wave 0 exists and is not optional.** The seams decided in it — money | `carried` | are the ones that cannot |
| 94 | `build-waves.plan.md` | 434 | 432 | 2026-07-29 | RULED | **tests and code-quality enforcers in every tier, not just the API** | `carried` | infra/README.md |
| 95 | `build-waves.plan.md` | 439 | 437 | 2026-07-29 | RULED | **the domain layer has zero Composer dependencies.** Arithmetic is `bcmath`, | `carried` | tested across all eight modes including negative |
| 96 | `build-waves.plan.md` | 445 | 443 | 2026-07-29 | RULED | **tenant isolation is PostgreSQL row-level security**, superseding the | `carried` | isolation is PostgreSQL row-level security, |
| 97 | `build-waves.plan.md` | 450 | 448 | 2026-07-29 | FOUND | **`qossmic/deptrac` is abandoned** in favour of `deptrac/deptrac` | `carried` | `qossmic/deptrac` is abandoned |
| 98 | `build-waves.plan.md` | 453 | 451 | 2026-07-29 | RULED | **the Flutter client ships all six targets** — Android, iOS, Linux, Windows, | `carried` | six targets — Android, iOS, Linux, Windows, macOS and Web |
| 99 | `build-waves.plan.md` | 459 | 457 | 2026-07-29 | RULED | **the profit-rate fix is BOTH parts, combined** (developer ruling). A product | `carried` | and the typed field is |
| 100 | `build-waves.plan.md` | 466 | 464 | 2026-07-29 | RULED | **Flutter Web ships — twes-in offers two admin interfaces**, Flutter and | `carried` | second admin interface |
| 101 | `build-waves.plan.md` | 470 | 468 | 2026-07-29 | AGREED | certification tier per wave is **MAXIMAL** for any wave touching money, | `carried` | Wave boundaries get MAXIMAL |
| 102 | `build-waves.plan.md` | 473 | 471 | 2026-07-31 | RECORDED | , because round 13 found none of it in any Decisions Log — Wave 1's pure domain | `carried` | `DocumentState` is a CLOSED set |
| 103 | `build-waves.plan.md` | 488 | 486 | 2026-07-30 | RULED | **R8-16's remedy is an INFRA rule, not app code — any GET under the | `carried` | font either contains a codepoint or it |
| 104 | `build-waves.plan.md` | 498 | 496 | 2026-07-30 | RECORDED | **vendoring Flutter's whole Noto fallback set is rejected on evidence**, so no | `carried` | version-hashed paths that break on every Flutter |
| 105 | `build-waves.plan.md` | 502 | 500 | 2026-07-30 | RECORDED | **R8-16 is WEB-ONLY.** `fontFallbackBaseUrl` appears in the compiled web engine | `carried` | appears in the compiled web engine and |
| 106 | `build-waves.plan.md` | 506 | 504 | 2026-07-30 | RULED | **the Flutter transitive-licence check is DELIVERED, by walking the pub cache.** | `carried` | flutter pub get |
| 107 | `build-waves.plan.md` | 513 | 511 | 2026-07-30 | CORRECTION | found by measurement: `THIRD-PARTY-NOTICES.md` claimed the transitive set was | `carried` | THIRD-PARTY-NOTICES.md |
| 108 | `build-waves.plan.md` | 517 | 515 | 2026-07-30 | RECORDED | **R2-12's premise was FALSE and the defect reproduces today in nine lines.** | `process-rule` |  |
| 109 | `build-waves.plan.md` | 531 | 529 | 2026-07-30 | RECORDED | **R2-13's three named evasions were still live seven rounds after being | `process-rule` |  |
| 110 | `build-waves.plan.md` | 537 | 535 | 2026-07-30 | RECORDED | **R3-2 was a real gap, not a tidiness item.** A 20-case property sweep (no | `dropped` | certification-round process record, archived verbatim |
| 111 | `build-waves.plan.md` | 543 | 541 | 2026-07-30 | RULED | (developer accepting the recommendation): **the two gates written during round 11's | `dropped` | certification-round process record, archived verbatim |
| 112 | `build-waves.plan.md` | 552 | 550 | 2026-07-30 | RULED | (developer accepting the recommendation): **freeze the gates; round 11 verifies the | `dropped` | certification-round process record, archived verbatim |
| 113 | `build-waves.plan.md` | 585 | 583 | 2026-07-30 | RECORDED | , so the next session does not have to rediscover it: **a reviewer agent that | `process-rule` |  |
| 114 | `build-waves.plan.md` | 594 | 592 | 2026-07-30 | RULED | (R4-18, developer accepting the recommendation): **leave the empty-string gap open | `carried` | the empty-string gap is deliberately left open |
| 115 | `build-waves.plan.md` | 607 | 605 | 2026-07-30 | RULED | (schema gate, developer accepting the recommendation): **build it WITH Wave 1's first | `dropped` | certification-round process record, archived verbatim |
| 116 | `build-waves.plan.md` | 614 | 612 | 2026-07-30 | RULED | (PHPStan/deptrac, developer accepting the recommendation): **leave both owed; do NOT | `superseded` | PHPStan landed 2026-08-05; deptrac carried to the open register |
| 117 | `build-waves.plan.md` | 625 | 623 | 2026-07-30 | RULED | (the `+ 1` working-scale guard band, developer accepting the recommendation): | `carried` | working-scale guard band |
| 118 | `build-waves.plan.md` | 633 | 631 | 2026-07-30 | RULED | **`MaterialIcons-Regular.otf` is complied with under the STRICTER of its two | `process-rule` |  |
| 119 | `build-waves.plan.md` | 646 | 644 | 2026-07-30 | RULED | **round 9 is scoped to the round-8/9 diff**, not all of Wave 0 (developer ruling). | `dropped` | certification-round process record, archived verbatim |
| 120 | `build-waves.plan.md` | 650 | 648 | 2026-07-30 | RULED | **OFL-1.1 is permitted for a vendored FONT ASSET only** (developer ruling, | `process-rule` |  |
| 121 | `build-waves.plan.md` | 656 | 654 | 2026-07-31 | AGREED | **`TenantId` STAYS in `Infrastructure/` — round 13's "move it into `Domain/`" | `carried` | tenant-less path may hydrate a domain aggregate |
| 122 | `build-waves.plan.md` | 663 | 661 | 2026-07-31 | AGREED | **a document number sequence is GAPLESS, so a PostgreSQL `SEQUENCE` / `nextval()` | `carried` | number sequence is GAPLESS, so a PostgreSQL |
| 123 | `build-waves.plan.md` | 674 | 672 | 2026-07-31 | AGREED | **`Invoice::issue()`'s two failure causes get distinct exception types** — a | `carried` | `Invoice::issue()`'s two failure causes get distinct exception types |
| 124 | `build-waves.plan.md` | 679 | 677 | 2026-07-31 | RULED | **a PER-LINE VAT figure is REQUIRED, and it is ALLOCATED rather than recomputed** | `carried` | document ORDER significant to a tax figure |
| 125 | `build-waves.plan.md` | 692 | 690 | 2026-08-01 | AGREED | **persistence was never blocked by the network.** `composer install` runs here with | `process-rule` |  |
| 126 | `build-waves.plan.md` | 698 | 696 | 2026-08-01 | AGREED | **the Doctrine mapping goes on a SEPARATE MUTABLE PERSISTENCE MODEL in | `carried` | instance per row, diffed against a snapshot. Mapping the aggregate directly is insert-only and |
| 127 | `build-waves.plan.md` | 710 | 708 | 2026-08-01 | AGREED | **the Wave 1 schema gate is CLOSED** — `scripts/gates/schema-tenancy.php` landed with | `carried` | scripts/gates/schema-tenancy.php |
| 128 | `build-waves.plan.md` | 716 | 714 | 2026-08-01 | AGREED | **migrations run on their OWN Doctrine connection, as the owning role** — a second | `carried` | scripts/dev/provision-dev-database.sh |
| 129 | `build-waves.plan.md` | 737 | 735 | 2026-08-01 | AGREED | **"enum" in this plan's column tables means `VARCHAR(32)` + `CHECK`, not a native | `carried` | `VARCHAR(32)` + `CHECK` |
| 130 | `build-waves.plan.md` | 742 | 740 | 2026-07-31 | RULED | **the Symfony ecosystem is the ONLY vocabulary — never a Laravel/Eloquent pattern** | `carried` | and that behaviour is expressed in Laravel idiom, so every |
| 131 | `build-waves.plan.md` | 807 | 805 | 2026-08-01 | RULED | **persistence is a SEPARATE MODEL in `Infrastructure/` mapped with Doctrine | `carried` | ATTRIBUTES, and a repository translates |
| 132 | `claude-bundle-integration.plan.md` | 18 | 16 | 2026-07-29 | AGREED | the integration targets the **repo**, not `~/.claude`. Cloud sessions load the repo's `CLAUDE.md`, `.claude/{settings.json,skills, | `superseded` | container era dead; bootstrap removed 2026-08-18 |
| 133 | `claude-bundle-integration.plan.md` | 19 | 17 | 2026-07-29 | AGREED | source of truth is **pdfturbo's port**, with phorj consulted for the pieces pdfturbo lacks (its `hooks/test-precompact-handoff.sh` | `dropped` | bundle provenance history, archived verbatim |
| 134 | `claude-bundle-integration.plan.md` | 20 | 18 | 2026-07-29 | AGREED | `install.sh` is ported **one-directional only** (`cp -u` three docs into `~/.claude`, create `var/claude/`; **`cp -u` was replaced | `superseded` | scripts/claude-bootstrap removed 2026-08-18 |
| 135 | `claude-bundle-integration.plan.md` | 21 | 19 | 2026-07-29 | AGREED | `AskUserQuestion` is **forbidden project-wide** — it times out in this container, so a question asked that way can hang the turn a | `superseded` | AskUserQuestion protocol RETIRED 2026-08-18; the tool is now mandatory |
| 136 | `claude-bundle-integration.plan.md` | 22 | 20 | 2026-07-29 | AGREED | **12 skills**, pdfturbo's set unchanged in roster — `ask-human`, `converge`, `sweep`, `expanding-context`, `sleuth`, `inspect`, `g | `superseded` | global-is-reference 2026-08-18; repo keeps two skills |
| 137 | `claude-bundle-integration.plan.md` | 23 | 21 | 2026-07-29 | AGREED | **three reviewer agents**, one per certification lens, renamed and re-chartered for this domain rather than copied: `domain-correc | `carried` | three fresh-context, read-only, adversarial reviewer subagents |
| 138 | `claude-bundle-integration.plan.md` | 24 | 22 | 2026-07-29 | AGREED | certification tier is **MAXIMAL by default** — three lenses, two consecutive fully-clean rounds, any finding resets the counter, c | `superseded` | per-task tier suspended 2026-08-19; panel at the milestone boundary |
| 139 | `claude-bundle-integration.plan.md` | 25 | 23 | 2026-07-29 | AGREED | **zero `deny` rules**, inheriting pdfturbo's developer ruling. In a cloud session a denied command is an unrecoverable dead end —  | `process-rule` | no deny and no ask tier |
| 140 | `claude-bundle-integration.plan.md` | 26 | 24 | 2026-07-29 | AGREED | **plans live in the repo** at `docs/plans/<topic>.plan.md`, each with its own `## Decisions Log`. The container is reclaimed and o | `superseded` | one live Decisions Log in docs/SPEC.md |
| 141 | `claude-bundle-integration.plan.md` | 27 | 25 | 2026-07-29 | FOUND | — **deviation from pdfturbo's documented behaviour**: `.claude/settings.json` is **writable by Claude in this container.** pdfturb | `process-rule` | .claude/settings.json is writable in THIS container |
| 142 | `claude-bundle-integration.plan.md` | 28 | 26 | 2026-07-29 | FOUND | +FIXED: the bulk project-name rename (`pdfturbo` → `twes-in`, `PDFTURBO_` → `TWES_`) left **four passages that were still factuall | `dropped` | bundle-rename history, archived verbatim |
| 143 | `claude-bundle-integration.plan.md` | 29 | 27 | 2026-07-29 | FOUND | +FIXED: Rule 10 in the global framework carried a **direct contradiction with this container's harness** — it pinned the commit au | `superseded` | closed by the 2026-07-29 12:45 identity ruling |
| 144 | `claude-bundle-integration.plan.md` | 30 | 28 | 2026-07-29 | AGREED | the distinctive addition for this project is `CLAUDE.md` § **"Licensing invariants"** — numbered rules making the clean-room bound | `process-rule` | Licensing invariants |
| 145 | `claude-bundle-integration.plan.md` | 31 | 29 | 2026-07-29 | AGREED | (developer, explicit): **`master` is the only branch.** Work is committed and pushed directly to it; no feature, topic or `claude/ | `process-rule` | master is the ONLY branch |
| 146 | `claude-bundle-integration.plan.md` | 32 | 30 | 2026-07-29 | RULED | (developer, closing the open item): **commit identity is `Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>` for auth | `process-rule` | Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com> |
| 147 | `claude-bundle-integration.plan.md` | 33 | 31 | 2026-07-29 | DONE | the six bootstrap commits were **retroactively re-authored** at the developer's explicit request, via `git filter-branch` (author  | `dropped` | one-off history rewrite, archived verbatim |
| 148 | `claude-bundle-integration.plan.md` | 35 | 33 | 2026-08-06 | RULED | (developer, explicit): **`permissions.deny` and `permissions.ask` both stay EMPTY, permanently.** *"there should be no permissions | `process-rule` | deny and ask both stay EMPTY, permanently |
| 149 | `claude-bundle-integration.plan.md` | 36 | 34 | 2026-08-06 | AGREED | **the `PostToolUse` deferral from 2026-07-29 is closed by building it**, adapted rather than ported — see § "Rejected, with reason | `dropped` | deferral closed by building it; its gotcha already in CLAUDE.md |
| 150 | `claude-bundle-integration.plan.md` | 37 | 35 | 2026-08-06 | AGREED | **an adaptation is not a rename.** A ported artefact must be re-grounded on this project's own subjects, and two instances in one  | `process-rule` | an adaptation is not a rename |
| 151 | `dev-prod-separation.plan.md` | 23 | 21 | 2026-08-04 | AGREED | dev and prod get SEPARATE Dockerfile TARGETS (`dev`, `runtime`) sharing one `base` | `carried` | a shared `base` stage with `dev` and `runtime` targets |
| 152 | `dev-prod-separation.plan.md` | 26 | 24 | 2026-08-04 | AGREED | **`api/vendor` is BIND-MOUNTED from the host in dev, not held in a named volume** — | `carried` | `api/vendor` is BIND-MOUNTED from the host in dev |
| 153 | `dev-prod-separation.plan.md` | 33 | 31 | 2026-08-04 | AGREED | dev keeps a **named volume for `api/var`** only. Cache and logs are the one thing | `carried` | Dev keeps a named volume |
| 154 | `dev-prod-separation.plan.md` | 36 | 34 | 2026-08-04 | AGREED | Xdebug ships in the `dev` target ONLY, in `xdebug.mode=off` by default and armed | `carried` | Xdebug \| present, `xdebug.mode=off` until `make debug-on` \| absent from the image entirely |
| 155 | `dev-prod-separation.plan.md` | 39 | 37 | 2026-08-04 | AGREED | the front-end tiers take a BUILD ARGUMENT for their configuration | `carried` | The front-end tiers take a BUILD ARGUMENT for their configuration |
| 156 | `dev-prod-separation.plan.md` | 43 | 41 | 2026-08-04 | AGREED | `--no-web-resources-cdn` stays on the Flutter build in BOTH modes. It is a GDPR | `carried` | `--no-web-resources-cdn` stays on the Flutter build in BOTH modes |
| 157 | `dev-prod-separation.plan.md` | 47 | 45 | 2026-08-05 | AGREED | production drops ALL Linux capabilities on EVERY service, adding back only what each | `carried` | Production drops ALL Linux capabilities on EVERY service |
| 158 | `dev-prod-separation.plan.md` | 51 | 49 | 2026-08-05 | AGREED | pid limits are expressed as `deploy.resources.limits.pids`, not the top-level | `carried` | Pid limits are expressed as `deploy.resources.limits.pids` |
| 159 | `pricing-and-documents.plan.md` | 15 | 13 | 2026-08-23 | AGREED | , recorded as a POINTER rather than a restatement so the two files cannot drift: | `carried` | A LINE discount reduces the VAT base |
| 160 | `pricing-and-documents.plan.md` | 21 | 19 | 2026-07-29 | RULED | **default currency TND, multi-currency from the start.** | `carried` | The default currency is TND |
| 161 | `pricing-and-documents.plan.md` | 22 | 20 | 2026-07-29 | FOUND | — **the most consequential fact in this spec.** TND is one of only seven | `carried` | TND, BHD, JOD, KWD, OMR, LYD, IQD are the 3-decimal set |
| 162 | `pricing-and-documents.plan.md` | 30 | 28 | 2026-07-29 | RULED | **exchange rate is captured at document date and stored with the document**, | `carried` | exchange rate is captured at document date and stored with the document |
| 163 | `pricing-and-documents.plan.md` | 32 | 30 | 2026-07-30 | RULED | **a profit rate is pure arithmetic; a NEGATIVE SELLING PRICE is refused at the | `carried` | A profit rate is pure arithmetic; a NEGATIVE SELLING PRICE is refused at the aggregate |
| 164 | `pricing-and-documents.plan.md` | 47 | 45 | 2026-07-31 | RULED | **a PER-LINE VAT figure is required** (developer rejecting the recommendation to omit | `carried` | A PER-LINE VAT figure is required |
| 165 | `pricing-and-documents.plan.md` | 59 | 57 | 2026-07-29 | RULED | **merging invoices: drafts only.** Anything carrying a real invoice number | `carried` | Merging invoices: drafts only |
| 166 | `pricing-and-documents.plan.md` | 62 | 60 | 2026-07-29 | RULED | **VAT applies per line AND per document, plus fixed charges.** Lines may | `carried` | VAT applies per line AND per document, plus fixed charges |
| 167 | `pricing-and-documents.plan.md` | 66 | 64 | 2026-07-29 | RULED | **profit rate is bidirectional: last-edited-wins.** `cost`, `profit_rate` | `carried` | Profit rate is bidirectional, last-edited-wins |
| 168 | `pricing-and-documents.plan.md` | 69 | 67 | 2026-07-29 | RULED | **the profit-rate fix is BOTH parts**: a product persists `authored_by` | `carried` | a product persists `authored_by` |
| 169 | `pricing-and-documents.plan.md` | 73 | 71 | 2026-07-29 | RULED | **delivery notes are PERSISTENT, independently numbered documents**, not | `carried` | Delivery notes are PERSISTENT, independently numbered documents |
| 170 | `reimplementation-strategy.plan.md` | 17 | 15 | 2026-07-29 | AGREED | adopt **API Platform** (MIT) for the REST tier, in **DTO + State Provider/Processor mode ONLY** — `#[ApiResource]` on a domain ent | `carried` | **API Platform, in DTO + State Provider/Processor mode ONLY.** |
| 171 | `reimplementation-strategy.plan.md` | 18 | 16 | 2026-07-29 | AGREED | **both JSON and JSON-LD**, content-negotiated. Near-free with API Platform (one | `carried` | Both JSON and JSON-LD, content-negotiated |
| 172 | `reimplementation-strategy.plan.md` | 23 | 21 | 2026-07-29 | AGREED | **GraphQL deferred, not refused.** The problem GraphQL solves is *many clients | `carried` | GraphQL deferred, not refused |
| 173 | `reimplementation-strategy.plan.md` | 32 | 30 | 2026-07-29 | AGREED | **a transport-agnostic gateway interface in BOTH clients, mandatory from the | `carried` | A transport-agnostic gateway interface in BOTH clients |
| 174 | `reimplementation-strategy.plan.md` | 37 | 35 | 2026-07-29 | AGREED | **Flutter Web stays**, and the justification is recorded correctly as *one | `carried` | the Web build is a SECOND admin interface |
| 175 | `reimplementation-strategy.plan.md` | 42 | 40 | 2026-07-29 | AGREED | the permissive set for anything **distributed** is exactly nine identifiers — [REDACTED: see `LICENSING.md`] | `carried-as-pointer` | Do not restate the permitted-licence identifiers in this file |
| 176 | `reimplementation-strategy.plan.md` | 44 | 42 | 2026-07-29 | AGREED | the four upstream repos are **studied, never forked into this tree**. Clones live at `/tmp/xxx/**` and `.gitignore` blocks `/refer | `process-rule` | Reference clones live outside the repo, read-only |
| 177 | `reimplementation-strategy.plan.md` | 45 | 43 | 2026-07-29 | FOUND | the four repos carry **three different licences**, not one. `invoiceninja` (API) and `ui` (React) are **Elastic License 2.0**; `ad | `process-rule` | the four upstream repos carry three different licences |
| 178 | `reimplementation-strategy.plan.md` | 46 | 44 | 2026-07-29 | FOUND | ELv2 permits derivative works, modification and distribution. Its three limitations are (1) no providing the software to third par | `process-rule` | ELv2 forbids circumventing licence-key functionality |
| 179 | `reimplementation-strategy.plan.md` | 47 | 45 | 2026-07-29 | FOUND | **2379 of 2441** PHP files in the API carry an explicit ELv2 copyright header, and roughly **1540 of 1641** `.ts`/`.tsx` files in  | `process-rule` | 2379 of 2441 PHP files carry an explicit copyright header |
| 180 | `reimplementation-strategy.plan.md` | 48 | 46 | 2026-07-29 | AGREED | **clean-room reimplementation, not a port.** Translating PHP/Laravel into PHP/Symfony is a derivative work — a translation is not  | `process-rule` | clean-room reimplementation, not a port |
| 181 | `reimplementation-strategy.plan.md` | 49 | 47 | 2026-07-29 | NOTED | (the useful paradox): the ~4% of the React app that is *technically* portable (interfaces, enums, constants) is precisely the part | `dropped` | upstream-analysis narrative, archived verbatim |
| 182 | `reimplementation-strategy.plan.md` | 50 | 48 | 2026-07-29 | FOUND | upstream gates its own branding removal behind a paid white-label plan — `Account::FEATURE_WHITE_LABEL` / `FEATURE_REMOVE_CREATED_ | `process-rule` | Never reproduce, disable, or reimplement a licence-key or branding gate |
| 183 | `reimplementation-strategy.plan.md` | 51 | 49 | 2026-07-29 | FOUND | the Flutter app is under the **Attribution Assurance License**, which requires — on **every launch** — a prominent display (splash | `process-rule` | Attribution Assurance License |
| 184 | `reimplementation-strategy.plan.md` | 52 | 50 | 2026-07-29 | FOUND | `dockerfiles` is GPL-2.0 — stronger copyleft than ELv2. Copying a Dockerfile or Helm template makes that artifact GPL-2.0 and obli | `process-rule` | invoiceninja/dockerfiles is GPL-2.0 |
| 185 | `reimplementation-strategy.plan.md` | 53 | 51 | 2026-07-29 | FOUND | the upstream repo is **not complete**. `modules_statuses.json` declares `{"Admin": true, "Accounting": false}`, `composer.json` ha | `dropped` | upstream-analysis narrative, archived verbatim |
| 186 | `reimplementation-strategy.plan.md` | 54 | 52 | 2026-07-29 | NOTED | upstream scale, measured. ~344k LOC of hand-written backend PHP (excluding 188k LOC of generated data tables), 71 live DB tables,  | `dropped` | upstream-analysis narrative, archived verbatim |
| 187 | `reimplementation-strategy.plan.md` | 55 | 53 | 2026-07-29 | AGREED | therefore **scope is the whole decision**, not stack choice. A narrow MVP (see § Recommended scope) is ~18–30 person-months and ac | `carried` | Scope is the whole decision, not stack choice |
| 188 | `reimplementation-strategy.plan.md` | 56 | 54 | 2026-07-29 | AGREED | Symfony and Angular are both **sound choices, and neither is the risk.** The risk is scope, the API contract, and the two irreduci | `carried` | Symfony and Angular are both sound choices and neither is the risk |
| 189 | `reimplementation-strategy.plan.md` | 57 | 55 | 2026-07-29 | FOUND | the genuinely hard domain knowledge is in **framework-agnostic PHP packages that Symfony consumes unchanged** — `horstoeko/zugferd | `process-rule` | Third-party libraries are the legitimate shortcut |
| 190 | `reimplementation-strategy.plan.md` | 58 | 56 | 2026-07-29 | FOUND | the React app has **zero client-side validation** — no yup, no zod, nothing. Every rule lives in Laravel's 408 FormRequest classes | `carried` | There is no validation ruleset to port |
| 191 | `reimplementation-strategy.plan.md` | 59 | 57 | 2026-07-29 | FOUND | the 45-locale / 6,179-key i18n corpus is plain JSON and **framework-agnostic** — but it carries no per-file header and is upstream | `process-rule` | translations are content to be re-created or sourced |
| 192 | `reimplementation-strategy.plan.md` | 60 | 58 | 2026-07-29 | FOUND | (decisive for the Flutter decision): the Flutter client uses `built_value` + `StandardJsonPlugin`, which is **strictly typed at de | `dropped` | upstream-analysis narrative; moot once the client is ours |
| 193 | `reimplementation-strategy.plan.md` | 61 | 59 | 2026-07-29 | NOTED | "keep the Flutter app unchanged" therefore means **the greenfield Symfony API must become a bug-for-bug reimplementation of Invoic | `superseded` | Q2 ruled the Flutter client is written from scratch |
| 194 | `reimplementation-strategy.plan.md` | 62 | 60 | 2026-07-29 | NOTED | `pages/documents` in the React app (13,523 LOC, 133 referencing files) depends on `@docuninja/builder2.0`, a **pre-built React com | `superseded` | Q4 ruled e-signature out of scope |
| 195 | `reimplementation-strategy.plan.md` | 63 | 61 | 2026-07-29 | NOTED | PDF preview is nearly free on the client (`InvoiceViewer.tsx` is 138 LOC — it POSTs to `/api/v1/live_preview` and puts the returne | `carried` | Live preview of an unsaved document is a real backend requirement |
| 196 | `reimplementation-strategy.plan.md` | 64 | 62 | 2026-07-29 | AGREED | three foundational choices are made **now**, on day zero, because all three are unfixable later and all three are things upstream  | `superseded` | Wave 0 changed both mechanisms; the requirements stand |
| 197 | `reimplementation-strategy.plan.md` | 72 | 70 | 2026-07-29 | AGREED | this plan file is the record of truth; it is committed. Four questions remain OPEN for the developer and are listed at the end. No | `superseded` | docs/SPEC.md is the record of truth |
| 198 | `reimplementation-strategy.plan.md` | 134 | 132 | 2026-07-29 | RULED | **Q1 — purpose: both (a) internal AND (b) a product sold to others**, plus a showcase for the phorj language later. Consequence, a | `carried` | both as the developer's own internal invoicing and as a product sold to others |
| 199 | `reimplementation-strategy.plan.md` | 135 | 133 | 2026-07-29 | RULED | **Q2 — the Flutter client is written from scratch.** Developer: *"I want my own version that is 100% mine, same for all the rest." | `process-rule` | the Flutter client is written from scratch too |
| 200 | `reimplementation-strategy.plan.md` | 136 | 134 | 2026-07-29 | RULED | **Q3 — no upstream branding, and no AAL obligation** (it cannot attach: no admin-portal code is reused). Deployment is **Docker-on | `process-rule` | All branding is ours and configurable from day one |
| 201 | `reimplementation-strategy.plan.md` | 137 | 135 | 2026-07-29 | RULED | **Q4 — e-signature is not in scope** (implied by "100% mine": the DocuNinja path was only ever attractive as a pre-built React pac | `carried` | E-signature is not in scope |
| 202 | `reimplementation-strategy.plan.md` | 138 | 136 | 2026-07-29 | RULED | **Q5 — licence: AGPL-3.0-or-later + a commercial licence**, copyright wholly Takieddine MESSAOUDI. Satisfies the stated requiremen | `process-rule` | AGPL-3.0-or-later plus a commercial licence |
| 203 | `reimplementation-strategy.plan.md` | 139 | 137 | 2026-07-29 | RULED | **Q6 — commit identity**: author and committer are `Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>`, **no `Co-Auth | `process-rule` | Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com> |
| 204 | `reimplementation-strategy.plan.md` | 140 | 138 | 2026-07-29 | RULED | **architecture: TDD + DDD + hexagonal + clean**, "really structured/scalable and flawless and exemplary". Treated as a hard invari | `carried` | **TDD, DDD, hexagonal, clean** |
| 205 | `reimplementation-strategy.plan.md` | 141 | 139 | 2026-07-29 | CORRECTION | (developer, same session): **phorj is VISION, not a target** — *"the phorj is not for now! it's in the vision! i did not finish th | `carried` | is **vision, not a target** |
| 206 | `reimplementation-strategy.plan.md` | 142 | 140 | 2026-07-29 | AGREED | concrete, mechanically-checkable architecture rules pinned in `CLAUDE.md` § "Architecture": no framework `use` in `Domain/`, ~~**D | `carried` | Persistence uses a SEPARATE MUTABLE MODEL |
| 207 | `reimplementation-strategy.plan.md` | 143 | 141 | 2026-07-29 | AGREED | Flutter is for **mobile and native desktop**, which is why it stays in the plan at all rather than being dropped in favour of the  | `carried` | Android, iOS, Linux, Windows, macOS and Web |
| 208 | `reimplementation-strategy.plan.md` | 145 | 143 | 2026-07-29 | RULED | (developer, overriding the 12:10 correction): **Node 26.7.0**, not 24 LTS. Angular 22.1.3 explicitly supports it (`engines.node` i | `carried` | Node 26.7.0 |
| 209 | `reimplementation-strategy.plan.md` | 146 | 144 | 2026-07-29 | RULED | **Decision 4 — money**: our own `Money` value object over Postgres `NUMERIC(19,4)`, four decimals so unit prices and tax rates kee | `carried` | NUMERIC(19,4) |
| 210 | `reimplementation-strategy.plan.md` | 147 | 145 | 2026-07-29 | RULED | **Decision 5 — monorepo**, and the developer caught an omission I had made: **`infra/` belongs in the layout too.** Four tiers — ` | `carried` | Four tiers, so one commit can change the API and every consumer together |
| 211 | `reimplementation-strategy.plan.md` | 148 | 146 | 2026-07-29 | RULED | **Decision 3 — build in WAVES**, with a full plan written first so the shape is clear, and a **certification review with the three | `carried` | **Build in WAVES**, each independently reviewable |
| 212 | `reimplementation-strategy.plan.md` | 149 | 147 | 2026-07-29 | RULED | **Decision 1 — sequence**: run the 5th certification round, then stop the documentation loop and start building. The ladder's two- | `dropped` | one-off sequencing decision, spent |
| 213 | `reimplementation-strategy.plan.md` | 150 | 148 | 2026-07-29 | OPEN | **Decision 2 — tenancy**: the developer asked for **both** modes — one shared database with tenant scoping, *and* database-per-ten | `carried` | Both isolation modes are in scope |
| 214 | `reimplementation-strategy.plan.md` | 221 | 219 | 2026-07-29 | AGREED | PHP **8.5.9**, Symfony **8.1**, Angular **22.1.3**, PostgreSQL **18.4**. Confirms the developer's proposal in all four cases. | `carried` | PHP **8.5.9** · Symfony **8.1** · PostgreSQL **18.4** |
| 215 | `reimplementation-strategy.plan.md` | 222 | 220 | 2026-07-29 | CORRECTION | (developer proposed "Symfony 8.x… maybe 8.2"): the latest *released* Symfony is **8.1.4**. `8.2` appears in `maintained_versions`  | `dropped` | version-research narrative, superseded by the 12:10 pin |
| 216 | `reimplementation-strategy.plan.md` | 223 | 221 | 2026-07-29 | CORRECTION | (developer proposed "node 26 latest"), **SUPERSEDED at 13:40 — see below**: 26.7.0 *is* the latest and Angular 22 does support it, | `superseded` | Node 26.7.0 ruled at 13:40 |
| 217 | `reimplementation-strategy.plan.md` | 224 | 222 | 2026-07-29 | AGREED | exact versions are pinned in `.nvmrc`, `composer.json` `config.platform.php`, and the Docker base images — not floated with `^`. A | `carried` | **Exact versions are pinned, not floated with `^`** |
| 218 | `CLAUDE.md` | 992 | 992 | 2026-07-29 | GOTCHA | `.claude/settings.json` is writable in THIS container.** pdfturbo's bundle documents | `process-rule` |  |
| 219 | `CLAUDE.md` | 999 | 999 | 2026-07-29 | GOTCHA | NEVER commit while a certification panel is reading. Freeze means freeze.** Round 5 of | `process-rule` |  |
| 220 | `CLAUDE.md` | 1008 | 1008 | 2026-07-29 | GOTCHA | a guard on one write path is not a guard.** The `<!-- manual -->` handoff protection was | `process-rule` |  |
| 221 | `CLAUDE.md` | 1017 | 1017 | 2026-07-29 | GOTCHA | the container's Stop hook will tell you to undo the commit-identity ruling. Do not | `superseded` | container era dead; Stop hook gone |
| 222 | `CLAUDE.md` | 1030 | 1030 | 2026-07-29 | GOTCHA | a correction appended below a false statement is not a correction. THREE times in one | `process-rule` |  |
| 223 | `CLAUDE.md` | 1041 | 1041 | 2026-07-29 | GOTCHA | a meta-gate needs its OWN adversary. `test-gates.sh` reported 33/33 for a gate that | `process-rule` |  |
| 224 | `CLAUDE.md` | 1050 | 1050 | 2026-07-29 | GOTCHA | CORRECTED 2026-08-01 — `composer install` DOES run. The blocker was never network egress, and | `process-rule` |  |
| 225 | `CLAUDE.md` | 1118 | 1118 | 2026-07-29 | GOTCHA | tenant isolation is PostgreSQL row-level security, not (only) a Doctrine filter.** | `carried` | applied by the server to every statement whatever issued it. Three things silently defeat |
| 226 | `CLAUDE.md` | 1131 | 1131 | 2026-07-29 | GOTCHA | NEVER record a coverage gap as an impossibility. I did it twice in one round and both were | `process-rule` |  |
| 227 | `CLAUDE.md` | 1143 | 1143 | 2026-07-29 | GOTCHA | a control asserted in prose and enforced nowhere is not a control, and round 4 found the | `process-rule` |  |
| 228 | `CLAUDE.md` | 1155 | 1155 | 2026-07-29 | GOTCHA | a fix is not delivered until a MUTANT proves it load-bearing.** Rounds 1–3 each closed real | `process-rule` |  |
| 229 | `CLAUDE.md` | 1164 | 1164 | 2026-07-29 | GOTCHA | money is never a float.** Recorded here on day zero because it is unfixable later. | `carried` | Money is never a float |
| 230 | `CLAUDE.md` | 1173 | 1173 | 2026-07-29 | GOTCHA | tenancy scoping must be default-on.** Upstream's `company_id` scope is an *opt-in* | `carried` | filter is bypassed by native |
| 231 | `CLAUDE.md` | 1179 | 1179 | 2026-07-30 | GOTCHA | a permission that nothing consults permits everything. `PERMISSIVE_FOR_FONT_ASSETS` was | `process-rule` |  |
| 232 | `CLAUDE.md` | 1187 | 1187 | 2026-07-30 | GOTCHA | "beside the file" is not "shipped", and the obligation attaches to what is DISTRIBUTED.** | `process-rule` |  |
| 233 | `CLAUDE.md` | 1196 | 1196 | 2026-07-30 | GOTCHA | an exemption inside a cross-check is where the drift hides.** `THIRD-PARTY-NOTICES.md` was | `process-rule` |  |
| 234 | `CLAUDE.md` | 1205 | 1205 | 2026-07-30 | GOTCHA | the integration suite SKIPPED the tenancy proof and reported `OK`, contradicting this very | `carried` | control that silently does not run is worse than one |
| 235 | `CLAUDE.md` | 1220 | 1220 | 2026-07-30 | GOTCHA | FOUR "impossible" claims refuted in one session. Treat every one in this repo as untried.** | `process-rule` |  |
| 236 | `CLAUDE.md` | 1233 | 1233 | 2026-07-30 | GOTCHA | PostgreSQL does not persist in this container, and that now FAILS the suite rather than | `superseded` | superseded by 2026-08-21 -- this machine has no PostgreSQL server |
| 237 | `CLAUDE.md` | 1240 | 1240 | 2026-07-30 | GOTCHA | this container's `LANG` makes Flutter Web render a BLANK PAGE under Playwright.** Headless | `carried` | failing test anywhere, just an empty screenshot. |
| 238 | `CLAUDE.md` | 1246 | 1246 | 2026-07-31 | GOTCHA | a document number sequence is GAPLESS, so `nextval()` is FORBIDDEN. Recorded beside | `carried` | transaction that persists the document. Accepted cost: issues for one |
| 239 | `CLAUDE.md` | 1257 | 1257 | 2026-07-31 | GOTCHA | tenancy is AMBIENT CONTEXT, not a field, and the reductio is how you tell.** Round 13 found | `carried` | because it also stops the cross-tenant total, PDF and export |
| 240 | `CLAUDE.md` | 1272 | 1272 | 2026-07-31 | GOTCHA | a test cannot kill a mutant in an expression it never REACHES, and a SELECT-list expression is | `process-rule` |  |
| 241 | `CLAUDE.md` | 1284 | 1284 | 2026-07-31 | GOTCHA | a control may not derive its own expected value from the input it is validating.** | `process-rule` |  |
| 242 | `CLAUDE.md` | 1294 | 1294 | 2026-07-31 | GOTCHA | the PER-LINE VAT ALLOCATION RULE is unfixable-later, exactly like the gapless sequence and | `carried` | construction the sum of the per-line rounded figures, so allocating there moves tax onto |
| 243 | `CLAUDE.md` | 1306 | 1306 | 2026-07-31 | GOTCHA | the Symfony ecosystem is the only vocabulary; never transliterate a Laravel/Eloquent | `carried` | that behaviour is expressed in Laravel idiom, so every time a behaviour is understood |
| 244 | `CLAUDE.md` | 1315 | 1315 | 2026-07-31 | GOTCHA | a gate that walks the filesystem reads whatever OTHER checkouts happen to be inside the | `process-rule` |  |
| 245 | `CLAUDE.md` | 1324 | 1324 | 2026-08-01 | GOTCHA | MIGRATIONS GET THEIR OWN CONNECTION, and `.env` claimed so for a commit while nothing implemented | `carried` | ALTER TABLE … DISABLE ROW LEVEL SECURITY |
| 246 | `CLAUDE.md` | 1342 | 1342 | 2026-08-01 | GOTCHA | `test \| tail && git commit` COMMITS ON RED. A pipeline's exit status is the LAST command's.** | `process-rule` |  |
| 247 | `CLAUDE.md` | 1357 | 1357 | 2026-08-02 | GOTCHA | `vendor/autoload_runtime.php` was there the whole time, and a comment asserting otherwise made | `carried` | vendor/autoload_runtime.php |
| 248 | `CLAUDE.md` | 1373 | 1373 | 2026-08-02 | GOTCHA | compose ran two commands the application had no configuration for, and the whole stack was | `carried` | THIRD-PARTY-NOTICES.md |
| 249 | `CLAUDE.md` | 1386 | 1386 | 2026-08-02 | GOTCHA | Valkey was configured as a queue and justified as if it were durable, and the justification | `carried` | up, and transactional with the document that dispatches the message) |
| 250 | `CLAUDE.md` | 1397 | 1397 | 2026-08-02 | GOTCHA | the meta-gate suite was RED at the commit that added a gate, and only a later run noticed.** | `process-rule` |  |
| 251 | `CLAUDE.md` | 1406 | 1406 | 2026-08-02 | GOTCHA | every questionable thing found in a Symfony-conventions sweep was a real defect, not a naming | `process-rule` |  |
| 252 | `CLAUDE.md` | 1417 | 1417 | 2026-08-05 | GOTCHA | dev and prod are now different ARTEFACTS, and the thing that forced it was Xdebug.** A debugger in a | `carried` | compromised process can write, and Xdebug can be told to connect OUT), so the |
| 253 | `CLAUDE.md` | 1427 | 1427 | 2026-08-05 | GOTCHA | a bind mount does not translate ownership, so a dev container must run as the HOST's uid.** The dev | `carried` | ("the container would run whatever PHP resolved on the host") is about the INSTALL, |
| 254 | `CLAUDE.md` | 1436 | 1436 | 2026-08-05 | GOTCHA | `NET_BIND_SERVICE` is load-bearing for a reason its name does not suggest, and dropping it stops the | `carried` |  The Dockerfile applies `setcap CAP_NET_BIND_SERVICE=+eip` to `frankenphp`, and the `e` (effect |
| 255 | `CLAUDE.md` | 1445 | 1445 | 2026-08-05 | GOTCHA | the FUNCTIONAL SUITE was not hermetic and passed only by accident of the shell.** | `process-rule` |  |
| 256 | `CLAUDE.md` | 1455 | 1455 | 2026-08-05 | GOTCHA | widening a gate's coverage found a stray file nothing else could see.** `spdx-headers.sh` had no `ini` | `process-rule` |  |
| 257 | `CLAUDE.md` | 1463 | 1463 | 2026-08-05 | GOTCHA | one suffix was answering three different questions, and the bare name meant something different in | `carried` | muscle memory types the short name, so the short name must be the harmless |
| 258 | `CLAUDE.md` | 1480 | 1480 | 2026-08-05 | GOTCHA | writing one recipe per environment is how the two drift; `DCX` writes it once.** The nine production | `carried` | writing one recipe per environment |
| 259 | `CLAUDE.md` | 1487 | 1487 | 2026-08-05 | GOTCHA | A UUIDv7 IDENTIFIER IS AN ORDERING ARTEFACT, NEVER A SECRET** (developer ruling). Recorded | `carried` | security property assumed in the wrong place is unfixable once a public surface depends |
| 260 | `CLAUDE.md` | 1560 | 1560 | 2026-08-05 | GOTCHA | the HTML documentation page took THREE silent failures to get right, every one a 200 with the correct | `carried` | ordering stops mattering. Resource endpoints |
| 261 | `CLAUDE.md` | 1579 | 1579 | 2026-08-05 | GOTCHA | a READ-ONLY reviewer broke `composer gate` without touching a tracked file, by regenerating an | `process-rule` |  |
| 262 | `CLAUDE.md` | 1595 | 1595 | 2026-08-05 | GOTCHA | the flag that was supposed to harden the PDF renderer disabled it, and its own healthcheck could not | `carried` | liveness endpoint answers "is the process alive", never "does the thing it exists for |
| 263 | `CLAUDE.md` | 1611 | 1611 | 2026-08-06 | GOTCHA | the gates split on ONE INVISIBLE FLAG, and a file Claude has just CREATED is untracked, so four of | `process-rule` |  |
| 264 | `CLAUDE.md` | 1648 | 1648 | 2026-08-06 | GOTCHA | A RENDERED VALUE CAN DETERMINE ITS OWN FORMATTER, which turned a persisted-configuration problem into | `carried` | no later setting can restate a legal document a client |
| 265 | `CLAUDE.md` | 1671 | 1671 | 2026-08-06 | GOTCHA | ONE OF THE THREE REMEDIES A PLAN OFFERED DID NOT EXIST, and finding that out took five minutes of | `process-rule` |  |
| 266 | `CLAUDE.md` | 1699 | 1699 | 2026-08-06 | GOTCHA | A RULING CAN BE UNBUILDABLE, and the gate is what tells you which of two rulings wins.** | `process-rule` |  |
| 267 | `CLAUDE.md` | 1723 | 1723 | 2026-08-06 | GOTCHA | AN IN-PROCESS CACHE AMORTISES ACROSS NOTHING IN PHP, and the sentence explaining why the design | `process-rule` |  |
| 268 | `CLAUDE.md` | 1744 | 1744 | 2026-08-07 | GOTCHA | A LOCK CAN BE CORRECT AND UNPROVABLE, and the remedy was to change the CODE rather than build a | `carried` | completeness-reviewer |
| 269 | `CLAUDE.md` | 1786 | 1786 | 2026-08-07 | GOTCHA | A DEFENSIVE STATEMENT IS UNOBSERVABLE ON A DEFAULT CLUSTER, so the fixture has to leave the default | `process-rule` |  |
| 270 | `CLAUDE.md` | 1816 | 1816 | 2026-08-07 | GOTCHA | THE `e2e` SUITE FOUND A REAL DEFECT ON ITS FIRST RUN, and it was one no other layer could see: two | `carried` | response that depends on a neighbouring line regresses when somebody |
| 271 | `CLAUDE.md` | 1854 | 1854 | 2026-08-07 | GOTCHA | THE PRIMARY TENANCY CONTROL HAD NO CALL SITE FOR THREE COMMITS, and the two fixtures that exercise | `carried` | POST /api/invoices |
| 272 | `CLAUDE.md` | 1907 | 1907 | 2026-08-07 | GOTCHA | A DOCUMENT NUMBER IS WRITE-ONCE, AND THE LOCK IS THE SECOND LINE RATHER THAN THE FIRST.** Recorded | `carried` | concurrent issues of one draft each act on a stale |
| 273 | `CLAUDE.md` | 1960 | 1960 | 2026-08-21 | GOTCHA | THIS MACHINE HAS NO POSTGRESQL SERVER, and the `pg_ctlcluster` recipe written all over this file | `carried` | pg_ctlcluster 16 main stop && pg_ctlcluster 18 main start |
| 274 | `CLAUDE.md` | 1985 | 1985 | 2026-08-21 | GOTCHA | GOAL 7 DIAGNOSED BY ASSUMPTION FOR TWENTY-SIX ROUNDS, and the first table whose whole key is the | `carried` | tenant-owned table had a surrogate key beside the tenant column. |
| 275 | `CLAUDE.md` | 2014 | 2014 | 2026-08-23 | GOTCHA | `git restore` IS THE WRONG WAY TO UNDO A MUTANT, AND IT SILENTLY REVERTS THE FIX WITH IT.** The | `process-rule` |  |
| 276 | `CLAUDE.md` | 2039 | 2039 | 2026-08-23 | GOTCHA | A DENIED COMPOUND COMMAND LEAVES ITS HEREDOC UNWRITTEN, AND `git commit -F` DOES NOT FAIL ON A | `process-rule` |  |
