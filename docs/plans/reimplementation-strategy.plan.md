# Reimplementation strategy Plan

How twes-in relates to Invoice Ninja: what may be reused, what must be rebuilt, what the licences
permit, and what scope is actually achievable. **Nothing here has been implemented.** This document
exists so the decision is made once, on evidence, and recorded.

Research basis: read-only study clones of `invoiceninja/invoiceninja`, `invoiceninja/ui`,
`invoiceninja/admin-portal` and `invoiceninja/dockerfiles` at `/tmp/xxx/**` (shallow, single-commit,
fetched 2026-07-29). Every measured figure below was produced by running a command against those
clones; figures are labelled accordingly.

---

## Decisions Log

- [2026-07-29 09:00] AGREED: the four upstream repos are **studied, never forked into this tree**. Clones live at `/tmp/xxx/**` and `.gitignore` blocks `/reference/`, `/upstream/`, `/vendor-reference/`.
- [2026-07-29 09:30] FOUND: the four repos carry **three different licences**, not one. `invoiceninja` (API) and `ui` (React) are **Elastic License 2.0**; `admin-portal` (Flutter) is the **Attribution Assurance License**; `dockerfiles` is **GPL-2.0**. [Verified: read all four LICENSE files directly.] Any plan that treats "Invoice Ninja's licence" as a single thing is wrong.
- [2026-07-29 09:30] FOUND: ELv2 permits derivative works, modification and distribution. Its three limitations are (1) no providing the software to third parties **as a hosted or managed service** offering a substantial set of its features, (2) no circumventing licence-key functionality, (3) no removing licensing/copyright notices. It is source-available, **not** open source, and not OSI-approved.
- [2026-07-29 09:35] FOUND: **2379 of 2441** PHP files in the API carry an explicit ELv2 copyright header, and **1540 of 1641** `.ts`/`.tsx` files in the web UI carry the equivalent. The reference is by URL (`@license https://www.elastic.co/licensing/elastic-license`), not by the literal string "Elastic License" — **text-matching licence scanners will miss it.** Do not rely on automated scanning.
- [2026-07-29 09:40] AGREED: **clean-room reimplementation, not a port.** Translating PHP/Laravel into PHP/Symfony is a derivative work — a translation is not an independent creation. Build from the OpenAPI contract, the schema shape, and the public standards; never from upstream source. Recorded as an invariant in `CLAUDE.md` § "Licensing invariants".
- [2026-07-29 09:40] NOTED (the useful paradox): the ~4% of the React app that is *technically* portable (interfaces, enums, constants) is precisely the part that carries per-file ELv2 headers, while the 96% that is React view code is the part we have no reason to copy. **The clean-room path is simultaneously the legally cleaner and the technically better one.** That alignment is why this decision is easy.
- [2026-07-29 09:45] FOUND: upstream gates its own branding removal behind a paid white-label plan — `Account::FEATURE_WHITE_LABEL` / `FEATURE_REMOVE_CREATED_BY` → `isPaid()` controls whether the vendor logo appears on generated PDFs and emails. [Verified: read `app/Models/Account.php:246-307`, `app/Utils/Helpers.php:32-42`.] **That is the licence-key functionality ELv2 clause 2 protects.** A fork that strips it is a clear breach; a clean-room build never has it. This is the single sharpest argument against forking.
- [2026-07-29 09:50] FOUND: the Flutter app is under the **Attribution Assurance License**, which requires — on **every launch** — a prominent display (splash screen or banner) of the author's name (*Hillel Coren*), professional identification (*Invoice Ninja*) and URL. [Verified: read `/tmp/xxx/in-flutter/LICENSE.txt`.] "Keep the Flutter app" therefore means "keep Invoice Ninja's name in front of our users, on every launch, forever." **This is a product decision, not a legal footnote, and it is OPEN — see Question 3.**
- [2026-07-29 09:55] FOUND: `dockerfiles` is GPL-2.0 — stronger copyleft than ELv2. Copying a Dockerfile or Helm template makes that artifact GPL-2.0 and obliges source disclosure for the derivative. Deployment *topology* is an idea and free; the files are not. AGREED: write our own (~200 lines), read theirs as reference.
- [2026-07-29 10:00] FOUND: the upstream repo is **not complete**. `modules_statuses.json` declares `{"Admin": true, "Accounting": false}`, `composer.json` has a merge-plugin pulling `Modules/*/composer.json` and a VCS entry for `invoiceninja/admin-api`, while `.gitignore` contains `Modules/`. **The `Admin` module — enabled by default — lives outside the public checkout.** A fork would be missing it, with no way to scope what it contains. Another argument against forking.
- [2026-07-29 10:15] NOTED: upstream scale, measured. ~344k LOC of hand-written backend PHP (excluding 188k LOC of generated data tables), 71 live DB tables, ~508 API endpoints, 91 entities, ~21 production payment gateways, 8 e-invoicing standards, 36 tax-jurisdiction rule classes, 45 locales, 167k LOC of tests, accumulated 2014→2026. A faithful full-scope rebuild is **25–40 person-years** — not a project, a product line.
- [2026-07-29 10:20] AGREED: therefore **scope is the whole decision**, not stack choice. A narrow MVP (see § Recommended scope) is ~18–30 person-months and achievable; "recreate Invoice Ninja" is not.
- [2026-07-29 10:25] AGREED: Symfony and Angular are both **sound choices, and neither is the risk.** The risk is scope, the API contract, and the two irreducible domain problems (money/tax arithmetic, e-invoicing). Recorded so the stack is not re-litigated.
- [2026-07-29 10:30] FOUND: the genuinely hard domain knowledge is in **framework-agnostic PHP packages that Symfony consumes unchanged** — `horstoeko/zugferd` (Factur-X/ZUGFeRD), `josemmo/facturae-php`, `invoiceninja/ubl_invoice`, `setasign/fpdi`, snappdf/Gotenberg, and every payment-gateway SDK. Using the same libraries upstream uses is **not** copying upstream. This materially lowers the cost of a rebuild versus a port.
- [2026-07-29 10:35] FOUND: the React app has **zero client-side validation** — no yup, no zod, nothing. Every rule lives in Laravel's 408 FormRequest classes and surfaces as a 422 `ValidationBag`. **There is no validation ruleset to port**; it must be re-derived. Budget this as backend work, not frontend.
- [2026-07-29 10:40] FOUND: the 45-locale / 6,179-key i18n corpus is plain JSON and **framework-agnostic** — but it carries no per-file header and is upstream's copyrighted content nonetheless. Treat translations as **content to be re-created or sourced**, not lifted. Do not assume "it's just JSON" resolves the licence question.
- [2026-07-29 10:50] FOUND (decisive for the Flutter decision): the Flutter client uses `built_value` + `StandardJsonPlugin`, which is **strictly typed at deserialization** — a wrong type or missing non-nullable field throws inside an isolate rather than degrading. It requires `X-App-Version` on every response, and `X-Minimum-Client-Version` **force-unwrapped** (its absence is a null-check crash, not an error message); unix-second integers for timestamps but `YYYY-MM-DD` strings for business dates; `double` money (a JSON string `"100.00"` throws — and Doctrine serializes `DECIMAL` to string by default); hashid string IDs; independent archive *and* delete axes; nested Fractal `?include=activities.history`; `_method=put` multipart uploads; `per_page=999999`; ~97 irregularly-named bulk-action verbs; and a 13-key `/static` payload. [Verified: read `lib/data/web_client.dart`, `lib/constants.dart`, `lib/data/models/entities.dart`, `lib/data/models/static/static_data_model.dart`.]
- [2026-07-29 10:55] NOTED: "keep the Flutter app unchanged" therefore means **the greenfield Symfony API must become a bug-for-bug reimplementation of Invoice Ninja's v5 API** — none of the constraints above is how anyone would design an API in 2026. The recommended alternative is to fork the Flutter client's transport layer (`web_client.dart` + models, ~500 LOC of Dart) and let the backend be designed properly. **OPEN — see Question 2.**
- [2026-07-29 11:00] NOTED: `pages/documents` in the React app (13,523 LOC, 133 referencing files) depends on `@docuninja/builder2.0`, a **pre-built React component package** (e-signature/blueprint builder). There is no source to translate and no Angular equivalent. It is out of scope, not effort. **OPEN — see Question 4.**
- [2026-07-29 11:05] NOTED: PDF preview is nearly free on the client (`InvoiceViewer.tsx` is 138 LOC — it POSTs to `/api/v1/live_preview` and puts the returned bytes in an iframe) but that implies a real backend feature: rendering a PDF for an **unsaved** entity.
- [2026-07-29 11:10] AGREED: three foundational choices are made **now**, on day zero, because all three are unfixable later and all three are things upstream got wrong. (1) Money is integer minor units or a decimal type, never float. (2) Tenancy scoping is a **default-on** Doctrine filter, never an opt-in helper. (3) Permissions are Symfony Voters over a real `role_permissions` table, never substring matching on a string column. Recorded in `CLAUDE.md` § Gotchas.
- [2026-07-29 11:15] AGREED: this plan file is the record of truth; it is committed. Four questions remain OPEN for the developer and are listed at the end. No work beyond the Claude bundle proceeds until Questions 1 and 2 are ruled.

---

## Pinned stack — verified 2026-07-29, not recalled

Every figure below was fetched from the authoritative source on 2026-07-29. Re-verify before bumping;
do not trust this table once it is more than a few weeks old.

| Component | Pinned | Latest available | Why this one |
|---|---|---|---|
| PHP | **8.5.8** | 8.5.8 (2026-07-02) | [Verified: `php.net/releases`.] Latest stable, and above Symfony 8.1's floor. |
| Symfony | **8.1** (8.1.1) | 8.1.1 stable; 8.2.0 is `next`, unreleased | [Verified: `symfony.com/releases.json` → `symfony_versions.stable = 8.1.1`, `next = 8.2.0`, `lts = 7.4.14`.] **Not "8.2"** — that branch is maintained-in-development, not released. Requires PHP ≥ 8.4.1 [Verified: packagist `symfony/framework-bundle` v8.1.1 `require.php`]. |
| Angular | **22.0.8** | 22.0.8 (`next` is 22.1.0-rc.0) | [Verified: npm `@angular/core` dist-tags.] |
| Node | **24.18.0 LTS "Krypton"** | 26.5.0, but **Current, not LTS** | [Verified: `nodejs.org/dist/index.json` → v26.5.0 `lts: false`; v24.18.0 `lts: "Krypton"`. Angular 22 engines are `^22.22.3 \|\| ^24.15.0 \|\| >=26.0.0`, so both work.] LTS chosen because this system handles money and CI reproducibility matters more than novelty. Node 26 is even-numbered so it enters LTS around 2026-10; bump then, deliberately, not by default. |
| PostgreSQL | **18.4** | 18.4, EOL 2030-11-14 | [Verified: `endoflife.date/api/postgresql.json`.] Longest support runway; `NUMERIC` is the money column type. |

### Decisions Log (stack)

- [2026-07-29 12:10] AGREED: PHP **8.5.8**, Symfony **8.1**, Angular **22.0.8**, PostgreSQL **18.4**. Confirms the developer's proposal in all four cases.
- [2026-07-29 12:10] CORRECTION (developer proposed "Symfony 8.x… maybe 8.2"): the latest *released* Symfony is **8.1.1**. `8.2` appears in `maintained_versions` because it is the in-development branch; `symfony_versions.next = 8.2.0` confirms it is unreleased. Pinning 8.2 would mean tracking an unreleased branch on a project whose whole point is being stricter than upstream — which pinned two dependencies to `dev-main`/`dev-master` on its critical path and is one of the mistakes we are explicitly not repeating.
- [2026-07-29 12:10] CORRECTION (developer proposed "node 26 latest"): 26.5.0 *is* the latest and Angular 22 does support it, but it is **Current, not LTS** — v24.18.0 "Krypton" is the LTS. Pinned **24 LTS**, with a dated note to reconsider when 26 enters LTS (~2026-10). "Latest" and "what a billing system should run" are different questions.
- [2026-07-29 12:10] AGREED: exact versions are pinned in `.nvmrc`, `composer.json` `config.platform.php`, and the Docker base images — not floated with `^`. A reproducible build is a precondition for trusting a money calculation.

## What the licences actually permit

The four repos are **not** under one licence. This is the most consequential finding in the whole
study, and it is routinely got wrong.

| Repo | Licence | Fork & modify? | Deploy internally? | Offer as a service to third parties? | Obligation you cannot drop |
|---|---|---|---|---|---|
| `invoiceninja` (Laravel API) | **Elastic License 2.0** | Yes | Yes | **No** | Keep notices; never circumvent the licence key |
| `ui` (React admin) | **Elastic License 2.0** | Yes | Yes | **No** | Same — 1540/1641 files carry a header |
| `admin-portal` (Flutter) | **Attribution Assurance License** | Yes | Yes | Yes | **Attribution splash on every launch** |
| `dockerfiles` | **GPL-2.0** | Yes | Yes | Yes | Copyleft: derivative files stay GPL-2.0, source disclosed |

### What is clearly permitted

- **Reading all four repos to learn how an invoicing system works.** All are public.
- **Forking and modifying for your own use**, including deploying it for your own invoicing.
- **Reimplementing the functionality from scratch.** Functionality, interfaces, data formats and
  programming techniques are not protected by copyright — expression is. Reimplementing an API
  contract is well-established ground.
- **Using the same third-party libraries upstream uses.** They have their own licences and no
  relationship to ELv2.

### What is clearly not permitted

- **Offering a fork (or any derivative) to third parties as a hosted or managed service** that
  provides a substantial set of its features. This is ELv2's central limitation and it does not go
  away by modifying the code.
- **Removing, disabling or circumventing the licence-key functionality.** Concretely: upstream's
  white-label gate controls whether its logo appears on generated PDFs and emails, and removing that
  gate is exactly what the clause forbids. [Verified: `app/Models/Account.php:246-307`.]
- **Stripping the per-file copyright headers** from anything carried over — which is why carrying
  files over is unattractive even for the small portable fraction.
- **Shipping the Flutter app without its launch-time attribution** to Hillel Coren / Invoice Ninja.

### The part that decides the project

**A translation is a derivative work.** Reading `InvoiceSum.php` and writing the equivalent Symfony
service line-by-line produces a derivative of ELv2 code, and inherits ELv2 — including the
no-hosted-service limitation — even though not one character was copy-pasted. Rewriting in a
different framework does not launder it.

The converse is what makes this project viable: an implementation built from **the OpenAPI spec, the
schema shape, the public standards (EN 16931, UBL, CII, Factur-X, Peppol BIS), and observable
behaviour** is an independent work and carries no ELv2 obligation. That is the discipline
`CLAUDE.md` § "Licensing invariants" enforces, and it needs to be visible in how the work is done —
not just asserted afterwards.

### The honest caveat

None of the above is legal advice, and one question genuinely needs a lawyer rather than an
engineer: **whether the intended deployment is "internal use" or "providing a service to third
parties."** ELv2 was drafted precisely to make that boundary bite, and the answer turns on facts
about who the users are and how they are billed. If the answer is "third parties," then **forking is
foreclosed and clean-room is the only path** — which is another reason the recommendation below does
not depend on that answer.

---

## Should you do what you are proposing? A direct answer

**Recommendation: yes to a clean-room Symfony + Angular build — but at roughly a tenth of the scope
you are implicitly describing, and with the Flutter decision reopened.**

The stack choices are fine and are not where this succeeds or fails. Symfony is at least as good a
fit as Laravel for this domain (Doctrine's data-mapper model is genuinely better for money and
multi-tenancy than Active Record), and Angular is a reasonable target for an admin UI of this shape —
the React app's six near-duplicate `common/hooks.tsx` files (5,591 LOC of columns/actions/bulk-actions
per entity) collapse into one generic entity-config service, which is a real simplification, not just
a port.

**Three things to push back on:**

1. **"Recreate them" is the problem, not "Symfony" or "Angular."** Upstream is ~344k LOC of backend
   PHP plus 202k of React, built over twelve years. The long tail — 21 payment gateways, 8
   e-invoicing standards, 268 company settings resolving through a 4-level cascade, a 4-engine PDF
   pipeline with two template languages, dual exclusive/inclusive tax implementations, 117 activity
   types, 65 webhook events — is where the years went, and it is invisible from the outside. Matching
   it is 25–40 person-years. Pick a tenth of it deliberately, and write down what you are *not*
   building; otherwise scope arrives by accident.

2. **Keeping the Flutter app is the highest-risk decision in the plan, and it is the one that looks
   safest.** It reads as "free client, one less thing to build." What it actually does is take your
   greenfield API and pin it to Invoice Ninja's 2018-era contract: unix-second integers, `YYYY-MM-DD`
   date strings, `double` money that throws on Doctrine's default `DECIMAL`-to-string serialization,
   hashid IDs, two mandatory version headers whose absence *crashes* the client, `per_page=999999`,
   `_method=put` multipart, ~97 irregular bulk verbs. `built_value` fails hard, not gracefully, and
   every near-miss surfaces as an opaque isolate crash. Forking the client's transport layer
   (~500 LOC of Dart in `web_client.dart` + the models) is almost certainly cheaper than contorting
   the backend around it — and it also settles the attribution question on your terms rather than
   inheriting it.

3. **"Adapt only, without recreating" is a real option and it deserves a fair hearing.** If the goal
   is *having working invoicing soon*, forking and adapting is dramatically faster and is legally fine
   for internal use. What you accept: Laravel, ELv2 (so no third-party-service future, ever), two
   dependencies pinned to unstable branches on the critical path (`invoiceninja/einvoice: dev-main`,
   `beganovich/snappdf: dev-master`), a missing `Admin` module you cannot inspect, and four
   foundational flaws you cannot fix without rewriting the foundation anyway — float money,
   opt-in tenancy scoping, substring-matched permissions, Active Record coupling. Choose this if the
   goal is *use*; choose the rebuild if the goal is *owning a product*. Both are defensible; doing
   the rebuild while secretly wanting the fork's timeline is not.

**One thing worth asking out loud:** if the actual driver is French e-invoicing compliance rather
than owning an invoicing product, building either is the expensive way to get there. Upstream's
`app/Services/EDocument/Standards/France/` — e-reporting periods, B2BI payloads, Chorus Pro/PISTE — is
worth reading as a *specification of the obligation*, and the obligation itself is usually met through
a PDP/OD rather than in-house. That is a scoping question only you can answer, and it changes
everything downstream, which is why it is Question 1.

---

## Recommended scope for a first release

Deliberately narrow. Everything omitted is omitted **on purpose** and listed, because silent scope is
how the 25-year estimate arrives.

**In:** Client · Invoice · Quote · Credit · Payment · RecurringInvoice · Product · TaxRate (France
only) · two payment gateways (Stripe + SEPA direct debit) · one PDF template · Factur-X + Peppol BIS
for France · a minimal client portal (view + pay) · single-tenant-per-deployment or a default-on
Doctrine tenancy filter · Voter-based permissions · French + English.

**Out, and recorded as such:** the other ~19 payment gateways · 7 of the 8 e-invoicing standards · 35
of 36 tax jurisdictions · bank feeds and transaction matching · QuickBooks sync · the subscription /
payment-link product · the visual design/template editor · e-signature (DocuNinja) · Elasticsearch ·
the report builder · task/project/expense management · purchase orders · vendors · 43 of 45 locales ·
database sharding.

Realistic estimate for the *in* list: **18–30 person-months.** The two irreducible chunks are money
and tax arithmetic (small in LOC, large in edge cases, and where correctness is legally binding) and
e-invoicing (large in LOC, but heavily assisted by framework-agnostic libraries).

One genuinely free asset worth taking: the React repo's `tests/unit/` (8 files, 1,931 LOC) tests
exactly the invoice-sum and rounding helpers. Those are *test cases* — expected inputs and outputs,
i.e. behaviour, not expression — and reading them as a specification for your own tests gives you a
verified oracle for the calculation logic. Write your own tests from the behaviour they describe.

---

## Four open questions for the developer

These are ruled by you, not by me, and Questions 1 and 2 block application work.

**Question 1 — what is this for?** Internal invoicing for your own entities, a product you intend to
offer to others, or a compliance vehicle for French e-invoicing? The answer decides whether forking
is even permitted (ELv2's hosted-service clause), and whether building at all is the right move.
*My lean: state the intended deployment model explicitly before any code, because it is the one input
that can invalidate the plan outright.*

**Question 2 — the Flutter app: keep unchanged, fork the transport layer, or drop?** *My
recommendation is fork the transport layer* (~500 LOC of Dart) so the API can be designed properly,
because pinning a greenfield backend to a 2018 contract costs far more than 500 lines of Dart. Keeping
it unchanged is viable but means the API is no longer yours to design. Dropping it means no mobile
client until you build one.

**Question 3 — does the Attribution Assurance License's launch-time splash survive review?** If any
Flutter code is reused, *"Hillel Coren / Invoice Ninja / invoiceninja.com"* must be prominently
displayed on **every launch**, forever. That is the licence's price and it is not negotiable while the
code is reused. If that is unacceptable, the Flutter app must be written from scratch — which changes
the answer to Question 2.

**Question 4 — is e-signature in scope?** If yes, Angular is the wrong target for that one module: it
depends on a pre-built React package with no source and no Angular equivalent, so it would mean a
React island inside an Angular app. Better to know now than to discover it late.

---

## Formal Plan

| Phase | Deliverable | Status |
|---|---|---|
| 0 | Claude bundle + this strategy document | built |
| 1 | Questions 1 and 2 ruled by the developer | **awaiting developer** |
| 2 | API contract decided and written down (auth, envelope, IDs, dates, money, pagination, errors) | blocked on Q1+Q2 |
| 3 | Domain skeleton: money type, tenancy filter, Voter permissions, first migrations | blocked on Q2 |
| 4 | Invoice/Quote/Credit/Payment + the calculation kernel, TDD from the first commit | pending |
| 5 | PDF rendering + one template + `live_preview` for unsaved entities | pending |
| 6 | Factur-X + Peppol BIS FR | pending |
| 7 | Angular admin over the contract from phase 2 | pending |
| 8 | Flutter per the Question 2 ruling | pending |
| 9 | Docker Compose written from scratch; CI per tier | pending |

### Rejected, with reasons

- **Forking the API or the React UI into this tree** — inherits ELv2 including the no-hosted-service
  limitation, forecloses any third-party-service future, and cannot be undone once history contains
  it. Also inherits float money, opt-in tenancy scoping, substring permissions, two unstable
  dependency pins, and a missing `Admin` module.
- **Porting the React app component-by-component to Angular** — ~96% of 202k LOC is React view code
  with no mechanical translation, and the ~4% that *is* portable is exactly the part carrying ELv2
  headers.
- **Copying `dockerfiles`** — GPL-2.0 copyleft on the copied files, for ~200 lines we can write. The
  service topology is free to reuse as a pattern; the Chrome/Saxon/CJK-font layers are worth reading
  closely.
- **Lifting the 45-locale i18n corpus** — framework-agnostic in format, still upstream's copyrighted
  content. "It's just JSON" is not a licence.
- **Matching upstream scope** — 25–40 person-years. Not rejected as undesirable; rejected as
  arithmetic.

### Known limits carried into the result

- The study clones are **shallow (1 commit)**, so no trend or history analysis was possible.
- Upstream's `Admin` module is outside the public checkout; its contents are **unknown and unscopeable**.
- The OpenAPI spec documents ~247 operations against ~508 actual endpoints (**~49%**), so it is a
  usable contract for core entity CRUD but not for bulk actions, the portal, or the long tail of
  action endpoints.
- ELv2's internal-use-versus-hosted-service boundary is a **legal** question, unresolved here, and
  flagged as Question 1 rather than assumed away.
- Effort figures are `[Inferred]` from measured LOC and subsystem counts, not from an executed plan.
  Treat them as order-of-magnitude, and note that the largest single risk they carry is the long tail,
  which is exactly the part that is hardest to see in a LOC count.
