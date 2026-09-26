# twes-in — SPEC

> The single source of truth for WHAT twes-in is, what has been ruled, what exists, and what is
> next. `CLAUDE.md` carries HOW work is delivered. On any conflict about the product, this file
> wins. Amend in place: a superseded sentence is edited, never contradicted further down.

## 0. How to read this file

- § 1–4 describe the product and its architecture. § 5 is the goal ladder with the definition
  of done. § 6 is the environment. § 7 is the Decisions Log, the only ledger of rulings.
  § 8 is the machine-maintained status block.
- Every goal ends with a working, tested, CI-green version. Nothing is "done" otherwise.
- A ruling lands in § 7 in the same change that applies it, dated, one sentence.

## 1. What twes-in is

An invoicing and billing SaaS for small companies, **Tunisia first, France second**, built and
sold by Takieddine MESSAOUDI, hosted by him, with a plan per company. A **Symfony REST API**
and an **Angular admin web app** over **PostgreSQL**. Multi-company from the first goal: a
person can belong to several companies and switches between them.

**Who it is for** (2026-09-16, widened 2026-09-20): businesses with a place — shops, cafés and
restaurants, workshops, small warehouses. A service firm is served too, but the brand does not
address it first. Tunisia is the launch market, not the limit: **supporting another country is
data** — a fiscal preset, its sourced rules and its translations — never new code, and the first
two customers are a hardware shop and a machining workshop, which the product serves without
being built for them alone. What the product does for them, today and planned, sorts into five
jobs, and the brand carries what they share, never the list itself:

| Job | In the POC | Planned (§ 2) |
|---|---|---|
| Get paid | invoices, payments, credit notes, PDF | quotes, sending by email, money owed at a glance, Factur-X / El Fatoora, foreign currency |
| Know where things are | products, stock locations from site to bin, stock movements | drawn site and store maps |
| Run the place | delivery notes, vendors, expenses | café and restaurant module (tables, orders, waiters) |
| Stay safe and in control | roles, second factor, passkeys, audit log, several companies | editable roles, plans, per-company branding |
| Work anywhere | web, dark mode, French and English | Arabic and right-to-left, mobile client |

It is a **clean-room reimplementation inspired by Invoice Ninja**, never a fork or a port; the
licensing invariants in `CLAUDE.md` are the legal boundary. twes-in itself is
**AGPL-3.0-or-later plus a commercial licence**, copyright wholly the author.

All branding is ours and configurable per deployment (product name, logo, mail identity,
hostname are platform settings).

## 2. Scope

### In the POC (G0–G10)

Auth (hardened sessions, MFA, invitations), companies with fiscal presets, settings, users and
roles, customers and contacts, products and categories, delivery notes, invoices with manual
payments and credit notes, vendors, expenses with attachments, inventory, PDF, transactional email (invitations, signup, approval), audit
log, and subscriptions: a trial and paid periods per company, cash payments the company declares and the operator
confirms, and an unpaid company left read-only or locked as the operator chose (§ 7 2026-09-17, which re-rules the
2026-09-09 placement of licensing after G10).

### The first working version, in this order (ruled 2026-09-20, § 7)

*The version a paying business in our target can legally run on: a quincaillerie on the shop profile and a
tourneur on the workshop profile, both served at once in thin slices. Ordered so each customer can START
early rather than waiting for the whole. About 104 points of new work over 88 points of already-open rows.*

**Ground, because it gets more expensive every week.** Finish what is in flight (rows 55, 59) · the design
rows that touch every screen (70–73, 45) · the country pack with its named strategies and conformance test,
absorbing rows 44 and 46 · the catalogue: families and variants, purchase/stock/sales units, the
second-language name · the worker (56) and running totals (57).

**The thinnest path that lets each one start.** *Workshop*: quotes acting as the order, deposits, the job
over the existing invoicing. *Shop*: price lists, the counter sale printing a receipt, purchase orders with
goods receipts. Both usable, neither finished · and with them the **barcode scan and the drawn map**, pulled
forward on 2026-09-20: one scan serving the search bar and a document's lines, and both map views.

> **The map's place, settled 2026-09-20 09:45.** This paragraph and *"What completes them"* below used to
> disagree: the map's own ruling (2026-09-19 23:40) names rows 74, 57 and 63 as preconditions, while row 74
> sat after it. Resolved: the **move half of row 74 travels with the map**, not after it, because a map
> showing that stock is wrong on a rack, in a product whose only movements are `receive` and `count`, offers
> no honest way to correct it. The map is also drawn on the canvas for approval first (the rule of 19/09),
> and the brand mark (99) is taken early and cheaply because nothing in the product is ours until it is.

**What completes them.** The three-way match's approval step · statement of account and credit limit ·
recurring · the rest of stock moves and losses (74, its move half having shipped with the map) and
valuation, with the product's home location (101) · the report catalogue and the daily digest · the
declarations area with its calendar · the accountant's exports, role and closed periods · e-invoicing
readiness · the two profiles and module dependencies · the two bets: WhatsApp delivery, Arabic and
right-to-left with bilingual documents · retention (58).

### After the first working version, in this order unless re-ruled

The review panel's fixes (§ 8 rows 17-23) · the invoice, payment and credit-note screens G7 still owes · sending a document to its customer by email, with the payment reminders that wait on it · a home page answering money owed and overdue, with the AGREED list upgrades (status tabs with counts, row actions, column totals, command palette, peek, live PDF preview) · per-type notification preferences (in-app, email, off) · plans and licensing module (plan gates modules) · self-service signup already
built at G1d but off by default · editable role matrix · Postgres RLS as
hardening · e-invoicing TRANSMISSION: the connectors behind the sending port (El Fatoora through TTN — dated work if a customer is on the régime réel, § 7 2026-09-20 — then Factur-X through a French platform) · foreign-currency documents (§ 7 2026-09-17: reference currency, per-document currency and rate, payments with exchange gain or loss, then the guided reference-currency change), carrying the per-customer default currency · workflow options per module ·
the **customer portal** as a customer-account feature, serving a quincaillerie's trade client and a café's business
account alike (§ 7 2026-09-20 19:20-19:35), with row 51's legal pages as its precondition ·
mobile client · fiscal presets editable in the app (row 52), which is how a country is added without a release · composite products (unit conversion first, then made-to-order recipes and menus or kits, nested, with food cost) · the café and restaurant work, designed in full on 2026-09-20 (§ 7) and cut into the modules `Venue`, `Register`, `Menu`, `Service`, `Guest` and `Ratings`, after a legal check of cash-register rules — in Tunisia a homologated register is required for consumption on the premises.

### Out, with no date

Payment gateways · multi-country VAT beyond the presets that exist · the phorj
side-track · dispensing medicines and anything touching reimbursement (CNAM *tiers payant*,
SESAM-Vitale) · drug serialization · medical practice billing · payroll · factory planning and
maintenance management · a general time-tracking product (hours on a job are in, § 7 2026-09-20)
· a double-entry ledger and the French FEC.

Three left this list on 2026-09-20 (§ 7): **purchase orders**, because a shop that never records
what it ordered from its suppliers cannot keep its stock true and both first customers restock
constantly; **recurring invoices**, folded into the document chain as a schedule that
generates drafts; and the **customer portal**, once it was seen to be a customer-account feature
rather than a shop one — the same thing serves a quincaillerie's trade client and a café's business
account, and it is the one ordering channel that needs no fraud control, because the people who use
it are people the business approved.

## 3. Architecture

### Stack [RULED 2026-09-09]

| Layer | Choice |
|---|---|
| API | PHP 8.5, Symfony 8.1, API Platform 4, Doctrine ORM + Migrations, PostgreSQL 18 |
| Web | Angular 22, standalone, signals, zoneless; Angular Material + CDK with Tailwind for layout; ngx-translate (fr, en) |
| Contract | OpenAPI generated by API Platform; TypeScript client generated from it, gitignored |
| Auth | Symfony Security, server-side sessions stored in PostgreSQL; TOTP + WebAuthn |
| PDF | Gotenberg container, Twig HTML in, PDF stored at issue |
| Mail | Symfony Mailer; Mailpit in development |
| Files | Flysystem on a local volume (`FILES_STORAGE=local`) or an S3-compatible bucket (`FILES_STORAGE=s3`, `league/flysystem-async-aws-s3`) |
| Quality | PHPStan max, php-cs-fixer, ESLint, Prettier, PHPUnit, Vitest, Playwright, licence gate, CI on every push |

Bootstrapped officially from scratch (`symfony/skeleton` + Flex recipes; `ng new` + official
schematics), never from a starter. Versions checked at bootstrap and pinned: lock files for packages,
exact tags for images (`compose.yaml`, `infra/`), Node 26 (`web/.nvmrc`).

Dependency policy boundary: the packages in the two lock files are what we compile into what we
distribute, and the licence gate checks every one of them. Base images and service containers
(PostgreSQL, nginx, Gotenberg, Mailpit, FrankenPHP) are aggregation, not dependencies, and are outside
the nine-identifier rule. SPDX identifiers are enforced on PHP by php-cs-fixer's header rule and on
TypeScript and shell by `scripts/gates/spdx-headers.sh`.

### Architecture style [RULED 2026-09-09]

Hexagonal, domain-driven, test-first. Official Symfony, Doctrine, API Platform and Angular
recommendations apply everywhere the spec does not rule otherwise; each ruled deviation is named here.

**Bounded contexts.** One directory per context under `api/src/`: `Identity` (users, sessions,
login), `Tenancy` (companies, memberships, roles), `Audit` now; every later module is a context of
its own (customers, products, delivery notes, invoices, vendors, expenses, inventory), which is what
the Modules ruling's "module directory" is. `api/src/Shared/` holds what several contexts need
(the `CurrentCompany` port and its session adapter now, money at G3) and nothing that belongs to one;
the clock port is PSR-20 (`Psr\Clock\ClockInterface`), which Symfony's clock implements. Contexts may
depend on one another's `Domain` and `Application` (Tenancy's `Membership` points at Identity's `User`);
a `Domain` or `Application` file never reaches any `Infrastructure`, another context's included, and the
architecture test enforces exactly that. `Infrastructure` is unconstrained: Tenancy's `PermissionVoter`
reads Identity's `SecurityUser`.

**Three layers per context**, dependencies pointing inwards only:

| Layer | Holds | May import |
|---|---|---|
| `Domain/` | entities and aggregates, value objects, domain events, repository interfaces, domain services, exceptions; every business rule | PHP, `Shared/…/Domain`, `Symfony\Component\Uid` (identifiers), `Doctrine\ORM\Mapping` and `Doctrine\DBAL\Types\Types` on entities (mapping by attributes, the driver Doctrine recommends; no XML), `Doctrine\Common\Collections` for a one-to-many an aggregate owns (2026-09-14) |
| `Application/` | use cases (one class per command or query, invoked by a handler method), their plain input and output types, the ports the use cases need (clock, password hasher, audit trail, current company, event dispatch) | `Domain`, `Shared`, `Psr\Clock`, `Symfony\Component\Uid`, PHP |
| `Infrastructure/` | adapters: Doctrine repositories, Symfony security (user provider, authenticators, voters, listeners), API Platform resources with providers and processors, console commands, session handler, mailers | anything |

An architecture test (`api/tests/Architecture/`) fails the suite when a `Domain/` or `Application/`
file imports `Symfony\`, `ApiPlatform\` or `Doctrine\` beyond the carve-outs above, or any `Infrastructure`.
A Symfony `UserInterface` implementation, an `#[ApiResource]` class, a console command are therefore
Infrastructure: the security user is a wrapper around the domain `User`, loaded by a custom user
provider through the repository port; the `Me` resource is built from the use case's plain output. Configuration (`api/config/`) wires each port to its adapter.

**Domain conventions.** Identity is created in the constructor (UUID v7 through `symfony/uid`), so an
aggregate is valid before it is persisted. Value objects where an invariant exists (email, permission,
money, tax rate); they are stored through custom DBAL types or embeddables, Doctrine's documented ways.
Money and quantity are not value objects yet: amounts and quantities are numeric strings each entity
checks, until the composite products goal builds `Quantity` and `Money` with unit conversion (§ 7, 2026-09-16).
Entities are not `final` (Doctrine proxies). Domain events are plain objects recorded by the aggregate
and dispatched in-process after the use case commits; no message bus, no event sourcing, no read models.
Thin contexts (vendors, units) may have a use case that only calls the repository; the layers stay, the
ceremony does not. Every timestamp is UTC (`date.timezone=UTC` in the image and the test bootstrap;
`datetime_immutable` columns).

**Tests.** `tests/Unit` for Domain and Application without the kernel (milliseconds, the bulk of the
suite); `tests/Integration` for adapters against the real PostgreSQL (repositories, the session
handler); `tests/Functional` for HTTP through the kernel; `tests/Architecture` for the layer rules;
Playwright through the real stack. Failing test first, always; a sabotage check per goal.

**Security, as the framework recommends.** CSRF through Symfony's stateless mechanism
(`framework.csrf_protection` with `stateless_token_ids: [api]` and `check_header` set to
`SameOriginCsrfTokenManager::CHECK_ONLY_HEADER`, a `!php/const` in YAML since the option is the
manager's integer): the SPA sends a `csrf-token` header with a random value of at least 24 characters
on every request, an Angular interceptor does it; a listener before the firewall asks the framework's
`SameOriginCsrfTokenManager` on every unsafe `/api` request, which also enforces the origin
(`Sec-Fetch-Site`, `Origin`, `Referer`) and remembers in the session which proof a client gave, so a
proof that disappears later is refused. Error codes: `csrf_token_missing`, `csrf_token_invalid`. The
security user is a snapshot of the domain `User` (`SecurityUser`, password hash replaced by a checksum
when serialised, as the documentation recommends), loaded by a `UserProvider` that also implements
`PasswordUpgraderInterface`. Login is `json_login`; the account lockout, the session company and the
audit rows are use cases (`RecordSuccessfulLogin`, `RecordFailedLogin`, `RecordLogout`,
`ChooseWorkingCompany`) called from Symfony's success handler and its authentication event listeners,
which are adapters. Migrations run in the API image's entrypoint after the database answers, exactly
as the official Symfony Docker entrypoint does (`--all-or-nothing`); `composer test` migrates the test
database before PHPUnit, so CI needs no separate step. Deviations kept
by ruling: `argon2id` with explicit parameters (docs default: `auto`), JSON-only formats (docs default
adds JSON-LD), per-context directories (docs: the default layout), and the `/api` requests declared
XMLHttpRequest by a listener so the firewall saves no login target path into a session for anonymous
hits (no documented switch exists).

**Web.** One directory per feature (`auth`, `hello`, `health`, later `customers`…), files named by
role as the Angular style guide asks (hyphenated, matching the class: `login-page.ts`, `auth-facade.ts`,
`auth-api.ts`, `auth-types.ts`, `auth-guard.ts`, `csrf-interceptor.ts`), no subdirectory per kind of
code. Components depend on the feature's facade (signals) and never on `HttpClient`; the facade depends
on the API adapter; the adapter is the only user of the generated types and maps them to the feature's
own types. Components are `OnPush`. Deviation kept by ruling: ngx-translate instead of `@angular/localize`
(runtime language switching).

### Tenancy [RULED 2026-09-09]

- `company` is the tenant. Every business table carries `company_id`; a Doctrine filter
  scopes every query on a company-owned entity to the company a request acts for (§ 7, 2026-09-16: no API
  Platform extension, no resource goes through API Platform's Doctrine layer); one test asserts every entity class
  carries the column or names why not.
- `membership(user_id, company_id, role_id)` attaches people to companies. The current company
  lives in the session; a switcher sits in the top bar.
- Platform-operator scope (outside memberships) creates companies, invites owners, activates
  pending companies, edits platform settings. It reaches no company's own data: an operator reaches
  a company only through a membership, like anyone else.
- No Postgres RLS in the POC. It is a later hardening goal and lands additively.

### Authorization [RULED 2026-09-09]

Every endpoint and action checks a permission string (`invoice.issue`, `product.write`, …)
through a voter. Roles are named permission sets; `owner`, `admin`, `member` are built in
(owner: billing and company deletion; admin: settings and users; member: documents). Custom
roles and the matrix UI come later; the permission strings exist from G1.

**Amended 2026-09-20** (§ 7, 11:30, and the research in `docs/research/authorization.md`). The
matrix UI is row 104 and is no longer "later": a module declares its own permission strings so the
catalogue is collected rather than hand-written, and a company creates its own roles. Three
additions are confirmed by market and standards research and are **ruled but unbuilt**: a membership
may name the **establishments** it applies to, with an explicit `all_establishments` flag because
"every site" and "every site was deleted" are otherwise the same stored state; permissions may be
paired **`_own` / `_any`**, with `_any` satisfying `_own` **in the voter** rather than by listing
both in every role; and a module may declare an act **authorisable**, where a second person's own
credential — never a shared passcode, which destroys attribution — is recorded beside the actor.
Accepted limit: one role per membership cannot express *waiter at site A, manager at site B*, which
Toast and Revel both support by scoping the role per location; the establishment scope above does
not fix it, because it scopes a member to sites rather than a role to a site.

### Auth [RULED 2026-09-09]

Session cookie (HttpOnly, Secure, SameSite=Strict) on the same origin behind nginx; JSON login;
argon2id with tuned parameters; session id rotated at login; absolute and idle timeouts; strict
CSP; CSRF token; throttling per IP and per account; lockout; breached-password check; auth audit
log; admin-forced logout. G1c adds TOTP and WebAuthn passkeys with a per-company
`security.mfa_required` switch, and every platform operator carries a second factor whatever their
companies say (§ 7, 2026-09-15); the passkey relying party comes from `APP_WEBAUTHN_RP_ID` and
`APP_WEBAUTHN_ORIGINS`, never from the request's host.

Onboarding: invitation at G1b (operator creates a company, invites the owner by email; owner sets
their password from the mailed link, which is what G1b ships — MFA enrolment on first login is G1c,
with the rest of TOTP and the passkeys). G1d opens public signup behind platform setting
`signup.enabled` (default off) with verification, rate limits and enumeration-safe responses.
`company.status` is `pending | active | suspended`; `signup.approval_required` defaults to on.
Invitation, verification and reset links are opened from a mail client, so under
SameSite=Strict they arrive without a session cookie: those flows are designed logged-out.

### Subscriptions [RULED 2026-09-17]

`Licensing` holds one `subscription` per company: its billing period, price, trial end and paid-through date, and,
where they differ from the platform's, its grace days and its unpaid mode. The standing — trial, paid, grace or
unpaid, and the access that follows: full, read-only or locked — is computed from those dates on every request, never
written by a scheduled job. A company without a subscription is not managed and keeps full access. Both access checks,
`CompanyGuard` and `PermissionVoter`, judge the permission a request asks for against that access, never the role's
grants, so an owner's `*` passes nothing an unpaid subscription refuses; read-only allows `*.read` plus the way out
(`subscription.read`, `subscription.pay`) and locked allows only the way out. A member refused that way is told which
of the two it is with a 403; a stranger still gets the same 404. Only operators set the terms, from
`/api/platform/companies/{companyId}/subscription`, and the platform defaults are operator-only platform settings —
declared in a business chain, a company's own admin could edit them.

Payments are declared, not taken: many companies pay in cash. A company posts what it paid — amount, method, day,
reference — to `/api/companies/{companyId}/subscription/payments`, one declaration waiting at a time (the use case
checks, and a partial unique index holds it against a race). The declaration keeps the company open for the hold
days, its own `holdDays` or the platform's `licensing.hold_days`, which gives the `held` stage; a confirmation
carries the covered time forward by the periods the operator names, from where it ends or from today, whichever is
later, and a rejection ends the hold at once. Operators are told in the app and by mail as soon as it is declared,
owners as soon as it is decided, and every declaration is kept and audited whatever its answer.

Because a locked company is closed out of the shell, the one page the API still allows it — its own subscription —
is served OUTSIDE the shell at `/subscription`, with `lockedSubscriptionGuard` admitting exactly a company its
subscription locked, and the awaiting page links to it. That route is the only UI path to `Access::ALWAYS`: a
company that could not say it paid would have no way back, so do not fold it into `companyClosed`'s redirect.

### Modules [RULED 2026-09-09]

Everything after the core is a module a company can switch on or off. A module is a backend
directory `src/Module/<Name>/` (entities, resources, handlers) plus one manifest (key, label
key, dependencies, settings definitions, nav entries, permissions) plus a lazy-loaded Angular
feature. `module_state(company_id, key, enabled_at)`. Disabled: nav hidden, resources answer
404, data kept, dependencies enforced on toggle. The registry is born at G5, with the second
module; G2a defines the manifest's navigation shape with hardcoded core entries, which the registry then
supplies without a change to the shell. Plans gate modules once the licensing module exists.

Core (always on): auth, memberships, roles, companies and fiscal presets, settings, files,
audit log, mail. Modules: customers, products, delivery notes, invoices (payments, credit
notes), vendors, expenses, inventory.

### Settings [RULED 2026-09-09, amended 2026-09-13]

One generic engine with any number of **levels** (the two scopes of 2026-09-09 became levels).
A setting is declared in code: key, type, default, constraints, label key, module, and the levels
allowed to override it. Values are stored as `setting(level, level_id, key, value jsonb)`. A read
names its context and the engine walks that context's chain, most specific level first, down to the
declared default. A test refuses a business value read in code that is not registered, so nothing is
hardcoded where a setting belongs. The POC fills three chains:

| Chain | Levels, most general first | Example keys |
|---|---|---|
| business defaults, parties | platform → company → customer group → customer → document | payment terms, document language, printed notes |
| business defaults, articles | platform → company → product category → product → document line | unit, default taxes, stock tracking |
| presentation | platform → company → role → user | table columns, saved views, density, dark mode, accent colour |

**Platform** settings belong to the operator (`signup.enabled`, `signup.approval_required`, default
plan, product name, mail identity). Tax arithmetic and legal mentions are not settings a company can
edit: they change only as operator-owned preset data (§ Fiscal presets). Screens are metadata-driven:
lists, filters and forms are rendered from descriptors, so their columns, filters, saved views and
custom fields are configuration. One endpoint returns definitions plus resolved values; one generic
page renders by type (bool, int, decimal, text, enum, money, colour, file). G2b ships the presentation
chain through a web `SettingsFacade` port with a browser-storage adapter; G3b swaps in the API adapter,
the business chains and the settings page. A later level is registered, never rewritten.

### Fiscal presets [RULED 2026-09-09, rules to be sourced at G3]

One data file per country, schema-validated, in the repo: currency and minor unit, identifier
definitions (key, label, regex, who must carry it), tax components, mandatory mentions as
translation keys, numbering defaults, document languages, rounding rules. Tax components come
from a closed set the calculator implements once:

| Kind | Example | Behaviour |
|---|---|---|
| percentage on line net | VAT, FODEC | flag `enters_vat_base` (FODEC true) |
| fixed per document | timbre fiscal | added after VAT |
| withholding on total | retenue à la source | shown on the invoice, reduces amount due, threshold |

A line carries a SET of tax components, not one rate: a Tunisian line carries FODEC and VAT
together, and stamp and withholding attach to the document. So `invoice_line_tax(line_id,
tax_component_id, base, rate_snapshot, amount)` and `invoice_tax(invoice_id, tax_component_id,
base, amount)` are collections; `product_tax` and `delivery_note_line_tax` follow the same
shape. The exact columns are confirmed at G3 after the research, not before.

A company copies its preset at creation into its own tax rates and settings, then tweaks
freely. Adding a country is a file plus translations; a new tax kind is code. The sourced
research round at the start of G3 writes `docs/fiscal/TN.md` and `docs/fiscal/FR.md` and the
tax-kind set is ruled before any tax code.

### Money

`NUMERIC(14,3)` for amounts, `(14,4)` unit prices, `(14,3)` quantities, `(6,3)` rates; the
scale applied comes from the currency (TND 3, EUR 2). Company currency is fixed per company.
`docs/spec/pricing-vectors.json` is the calculator's fixture set and gains TND cases at G3.
Issued documents are immutable; corrections are credit notes; numbering is gapless and
assigned at issue.

## 4. Data model

Common columns: `id` (uuid v7), `company_id` (business tables), `created_at`, `updated_at`. There is
no common `created_by`: the tables that record who acted carry their own column — `invoice.issued_by`,
`payment.recorded_by`, `file.uploaded_by` — and `audit_log` holds the rest.

| Entity | Fields |
|---|---|
| platform_setting | folded into `setting` at the platform level (2026-09-13) |
| user | email (unique), password_hash, display_name, locale, is_active, is_platform_operator, last_login_at, failed_login_count, locked_until, password_changed_at, mfa (totp_secret, passkeys) |
| passkey | user_id, credential_id (unique), record (the WebAuthn credential record, with its signature counter), name, created_at, last_used_at |
| company | name, legal_name, legal_form, identifiers jsonb (per preset), address, email, phone, website, country_code, fiscal_preset, currency, locale, timezone, iban, bic, vat_regime, invoice_footer_text, late_penalty_text, status. Payment terms are not a column: they are the `document.payment_terms_days` setting (2026-09-13), which a customer group, a customer or a document overrides. `logo_file_id` and `plan` are planned and not built yet (2026-09-13, 2026-09-15) |
| signup | email, locale, token_hash (SHA-256, unique), created_at, expires_at, completed_at (nullable); one open link per address |
| membership | user_id, company_id, role_id |
| role | company_id (null for built-in), name, permissions jsonb |
| invitation | company_id, email, role_id, token_hash, expires_at, accepted_at |
| tax_component | code, name, kind (percentage_line, fixed_document, withholding_total), rate or amount, enters_vat_base, threshold, is_default, is_active, exemption_mention, sort_order |
| unit | code, label, decimals |
| numbering_series | establishment_id, document_type, format, next_number, reset_period, last_reset_year, is_default |
| setting | level, level_id (the subject: none, a company, a company and role, a user), company_id (levels inside a company), key, value jsonb |
| module_state | company_id, key, enabled_at |
| establishment | code (the establishment part of the tax identifier), name, address, phone, email, is_default |
| customer_group | name, description; its settings resolve through the parties chain |
| customer_tax_regime | code (standard, exempt, suspended, export), label key, which tax components apply, mandatory mention; operator-owned preset data |
| stock_location | establishment_id, parent_id, kind (site, building, floor, zone, rack, bin), code, name, is_default |
| customer | customer_number, kind, customer_group_id, tax_regime_id, name, legal_name, identifiers jsonb, email, phone, website, billing_address, shipping_address, default_tax_component_ids, default_discount_rate, notes, is_active. Payment terms and document language are not columns: they are the parties chain's settings at the customer level (2026-09-14), as the company's are |
| contact | customer_id, first_name, last_name, email, phone, role, is_primary |
| product | reference, name, description, kind (goods, service), unit_id, unit_price_net, cost_price, category_id, barcode, is_active; `product_tax` = default tax components |
| product_category | name, parent_id |
| delivery_note | establishment_id, number, status (draft, validated, delivered, invoiced, cancelled), customer_id, customer_snapshot, issue_date, delivery_date, delivery_address, customer_reference, remarks_printed, notes_internal, invoiced_by_invoice_id |
| delivery_note_line | position, product_id, description, quantity, unit_id, unit_price_net; `delivery_note_line_tax` collection |
| invoice_tax | invoice_id, tax_component_id (document-level: stamp, withholding), base, amount |
| invoice | establishment_id, document_type (invoice or credit_note), corrects_invoice_id (a credit note's invoice, null otherwise), number, status (draft, issued, partially_paid, paid; cancelled while draft only), customer_id, customer_snapshot, issue_date, supply_date, due_date, payment_terms_days, currency, language, customer_reference, discount_amount, subtotal_net, total_net, tax_breakdown jsonb, total_tax, fixed_taxes, total_gross, withholding_amount, amount_paid, amount_due, notes_printed, terms_printed, footer_snapshot, mentions_snapshot, issued_at, issued_by, pdf_file_id, operation_category, vat_on_debits |
| invoice_line | position, product_id, description, quantity, unit_id, unit_price_net, discount_rate, line_net, line_tax, line_gross, source_delivery_note_line_id; `invoice_line_tax` collection (component, base, rate snapshot, amount) |
| payment | invoice_id, date, amount, method (transfer, cash, check, card, other), reference, notes, recorded_by, created_at |
| vendor | vendor_number, name, legal_name, identifiers jsonb, email, phone, website, address, iban, bic, payment_terms_days, default_expense_category_id, notes, is_active |
| expense | reference, expense_date, vendor_id, category_id, description, amount_net, tax_component_id, tax_rate (snapshot), tax_amount, amount_gross, currency, payment_method, payment_date (`paidOn` in the API), status (draft, recorded, paid), notes; the due date is derived from the vendor's terms, never stored |
| expense_category | name, parent_id, is_active |
| file, attachment | storage_key, original_name, mime, size, sha256, uploaded_by; attachment: entity_type, entity_id, file_id |
| stock_movement | product_id, location_id, kind (in, out, adjustment), quantity, source_type, source_id, at |
| audit_log | entity_type, entity_id, action, actor_user_id, changes jsonb, at, ip |

## 5. Goals and the definition of done

Every goal is done only when all six hold:

1. Backend: entity, migration, API resource; a functional test per endpoint (happy path,
   validation, unauthenticated, wrong company); unit tests for arithmetic and state transitions.
2. Frontend: pages plus component tests for forms; the generated client compiles.
3. One Playwright scenario through the real UI against the real API and database.
4. CI green on push: lint, static analysis, unit, functional, E2E, licence gate.
5. Settings and module registered; fr and en keys present, parity checked.
6. One paragraph here in § 8's row: what exists, its settings, its endpoints.

| # | Goal | Born here |
|---|---|---|
| G0 | Orphan root, official bootstrap of API and web, compose, CI from scratch, licence gate, one Playwright smoke | the pipeline |
| G1a | Hardened sessions, JSON login, permission voters, auth audit log, a seeded operator and company (console command), hello page | the done template |
| G1b | Companies, memberships, company switcher, invitations, mail | platform scope |
| G1c | TOTP + passkeys, `security.mfa_required` | |
| G1d | Public signup behind `signup.enabled`, approval flow | |
| G2a | Shell (sidebar from the nav manifest, switcher, user menu, language), design tokens and theme (Inter, Material Symbols, accent from a hex), Centrifugo notifications and the notification centre, nonce-based CSP | design system |
| G2b | Generic metadata-driven list and form, presentation settings (columns, saved views, density, dark mode), the design checkpoint screens on fixture data under a dev-only `/design` route | the checkpoint |
| G3a | Fiscal research TN + FR (marked unvalidated), presets, tax components, customer tax regimes, units | calculator |
| G3b | Settings engine chains through the API, settings page, establishments, numbering series, company profile | settings page |
| G4 | Customers + contacts + customer groups | |
| G5 | Products + categories | module registry |
| G6 | Delivery notes + PDF, `delivery_note.validated` event | Gotenberg template |
| G7 | Invoices, DN conversion, manual payments, credit notes, gapless numbering | |
| G8 | Vendors | |
| G9 | Expenses + attachments | Flysystem |
| G10 | Inventory module consuming delivery-note events, stock kept per product and location | |

## 6. Repository layout and environment

```
api/                Symfony application (src/<Context>/{Domain,Application,Infrastructure}/, config/fiscal/<CC>.yaml,
                    tests/{Unit,Integration,Functional,Architecture}/)
web/                Angular application (src/app/<feature>/, e2e/ Playwright)
infra/              Dockerfiles, nginx config
docs/               SPEC.md, fiscal/<CC>.md, spec/pricing-vectors.json
scripts/gates/      dependency-licences.php, spdx-headers.sh, tests/ (each gate's own test)
scripts/notices/    generate-third-party-notices.php; scripts/lib/ shared with the gate
.github/workflows/  ci.yml: licences, api, web, e2e
.claude/            settings.json (one php -l / bash -n hook), agents/ (three reviewers, kept for the POC-end panel)
compose.yaml  .env  Makefile  LICENSE  LICENSING.md  THIRD-PARTY-NOTICES.md  CLAUDE.md  README.md
```

The orphan bootstrap commit carried from the old tree exactly: `LICENSE`, `LICENSING.md`,
`docs/spec/pricing-vectors.json`, `.claude/agents/` (charters trimmed when the panel runs). The
licence gate, the notices generator and `.claude/settings.json` were rewritten; the old
1 851-line gate's four lists and their maximums are what survived of it.

`compose.yaml`: `postgres`, `api` (FrankenPHP, classic mode), `web` (nginx serving the
Angular bundle and proxying `/api` and `/connection/websocket` on the same origin), `centrifugo`
(no published port at all: browsers reach it through nginx, the api publishes to it over the compose
network), `gotenberg`, `mailpit`. No Redis. Versions are not repeated here: `make versions` prints every pin
from its file, and `docs/UPDATE.md` says where each is copied and how to bump it (§ 7, 2026-09-19). Bringing the
stack up, the users and a clean start: `docs/START.md`.
Host ports from `.env`: web 8090 and api 8091 on every interface, since they are what a developer
browses; everything only this machine talks to is bound to `127.0.0.1` rather than published to the
network (§ 8 row 22) — mailpit UI 8092 / SMTP 8093, gotenberg 8094, postgres 5433. The API's
functional tests run from the host against that PostgreSQL (`twes_test`, created by
`infra/postgres/init.sql`); `make up`, `make gate`, `make e2e` are what CI runs.

## 7. Decisions Log

- [2026-09-09] AGREED: reset by orphan root; the developer runs the force push.
- [2026-09-09] AGREED: Symfony 8.1 + API Platform 4 on PHP 8.5; Angular 22 + PrimeNG + Tailwind (PrimeNG superseded by Angular Material the same day, see below); official bootstrap, no starter reuse; CI workflows written from scratch.
- [2026-09-09] AGREED: multi-company from G1 through memberships with roles and a switcher; RLS later.
- [2026-09-09] AGREED: SaaS hosted by the developer, plan per company gating modules; licensing module after G10.
- [2026-09-09] AGREED: hardened server-side sessions at G1; TOTP + passkeys at G1b; invitation at G1; signup behind a platform setting at G1c; `signup.approval_required` default on.
- [2026-09-09] AGREED: permission strings from G1; built-in owner/admin/member; editable matrix later.
- [2026-09-09] AGREED: process is LIGHT (see CLAUDE.md); one three-lens panel when the POC works.
- [2026-09-09] AGREED: in the POC: manual payments, credit notes, email, audit log, inventory (G10). Out: mobile, phorj side-track, foreign-currency documents, gateways, portal, recurring, purchase orders.
- [2026-09-09] AGREED: Gotenberg for PDF, stored at issue.
- [2026-09-09] AGREED: Tunisia first, France second; presets as data, closed tax-kind set in code; money NUMERIC(14,3) with scale per currency; sourced fiscal research at the start of G3.
- [2026-09-09] AGREED: UI fr + en, documents fr, Arabic later; company currency fixed.
- [2026-09-09] AGREED: quotes after G10. Repository stays `twes-in`.
- [2026-09-09] AGREED: two settings scopes, platform and company.
- [2026-09-09] AGREED: G1 is split — G1a = hardened sessions + seeded operator and company (console command) + hello page + the done template; G1b = companies, memberships, switcher, invitations, mail. Both drafts approved as amended; G0 starts.
- [2026-09-09] AGREED: PrimeNG is refused — every published version now carries the PrimeUI License (eligibility-gated commercial licence with a bundled licence-key verifier), outside the nine identifiers; Angular Material + CDK replaces it, Tailwind stays for layout.
- [2026-09-09] AGREED: Transloco is replaced by @ngx-translate/core, because Transloco reaches argparse (Python-2.0) in the runtime tree; the nine identifiers stay as they are.
- [2026-09-09] AGREED: Node 26 is the web toolchain (nvm 26.7.0 on the machine, node:26 images, CI setup-node 26); Angular 22 declares >=26.0.0.
- [2026-09-09] AGREED: auth events (login success and failure, lockout, logout, forced logout) are rows of the single `audit_log` table (entity_type `user`, action `auth.*`, attempted email and reason in `changes`, actor null on failure); no separate auth table.
- [2026-09-09] AGREED: the TypeScript client is types only, generated by `openapi-typescript` (MIT, LICENSE read) into a gitignored `api.d.ts`; Angular services are hand-written over HttpClient. `ng-openapi-gen` is refused: it depends on argparse (Python-2.0). CI: the api job exports `openapi.json` as an artifact the web job consumes.
- [2026-09-09] AGREED: `cookie_secure: true` everywhere; Chromium accepts and returns a Secure cookie over plain http on 127.0.0.1 and localhost (Playwright probe), so the http dev stack and the e2e job need no TLS.
- [2026-09-09] AGREED: `Python-2.0` is permitted for DEV-ONLY TOOLING (fifth identifier beside MPL-2.0): every OpenAPI-to-TypeScript generator reaches argparse through js-yaml, it is build-time code that never ships, and it is not copyleft. Supersedes the same-day ruling on openapi-typescript, which only accepts TypeScript 5: the generator is `@hey-api/openapi-ts` (MIT, LICENSE read, accepts TypeScript 6), types only. To be applied to CLAUDE.md invariant 3, LICENSING.md, the gate and its test in one change.
- [2026-09-09] NOTED: `web/package.json` carries an npm `overrides` entry pinning `js-yaml` to 4.3.2 (MIT). `@hey-api/json-schema-ref-parser` pins 4.2.0 exactly, which `npm audit` flags for three quadratic-CPU advisories on hostile YAML (GHSA-52cp-r559-cp3m, GHSA-5p4m-2wfm-xmqj, GHSA-2883-xcg3-v3hh), all fixed at 4.3.2. Exposure was nil (dev-only tooling parsing our own JSON) and CI installs with `--no-audit`; the override keeps the tree at zero advisories anyway. Drop it once the ref-parser moves past 4.3.2.
- [2026-09-09] NOTED: CSRF for the whole API is a stateless double-submit cookie (`XSRF-TOKEN`, readable, Secure, SameSite=Strict, echoed by Angular's HttpClient in `X-XSRF-TOKEN`) plus an Origin / Sec-Fetch-Site check, applied before the firewall so the login itself is covered; `/api/health` and safe methods are exempt. Chosen over Symfony's session-bound token because it starts no session for anonymous requests and needs no endpoint to fetch a token from.
- [2026-09-09] NOTED: the account lockout counts wrong passwords only. A refusal because the account is already locked, disabled or throttled leaves the counter alone; counting it would let anyone keep an account locked by retrying. Throttling (10 per account and address, 50 per address, 15 minutes) is Symfony's login limiter over the app cache; the lockout (5 failures, 15 minutes) is a column and survives a restart.
- [2026-09-09] NOTED: the seeded operator is `operator@twes.local` with the development password `twes-operator-dev`, passed explicitly by `make seed` and the CI e2e job; `app:seed` has no built-in password and refuses to create the operator without one.
- [2026-09-09] AGREED: the API is hexagonal with a pure domain: no Symfony, Doctrine or API Platform import in a `Domain/` or `Application/` directory, enforced by an architecture test in PHPUnit; Doctrine mapping is XML under `Infrastructure/`.
- [2026-09-09] AGREED: bounded contexts. The core is cut into `Identity` (users, sessions, login), `Tenancy` (companies, memberships, roles) and `Audit` now; every later module (customers, products, invoices, …) is its own context with the three layers inside, which is what the Modules ruling's "module directory" becomes.
- [2026-09-09] AGREED: the Angular side follows the same discipline per feature: domain types and state, application facades, infrastructure HTTP adapters; components depend on facades, never on HttpClient; the generated API types are the infrastructure's contract.
- [2026-09-09] AGREED: no XML mapping. Doctrine mapping is by PHP attributes, the driver Doctrine and Symfony recommend; `Doctrine\ORM\Mapping` (and `Doctrine\DBAL\Types\Types`) is the one framework import a `Domain/` entity may carry, and the architecture test carves out exactly that. Everything else follows the official Symfony, Doctrine, API Platform and Angular recommendations; where the spec has ruled otherwise, the ruling is named in § 3.
- [2026-09-09] NOTED: applying the ruling above to G1a replaced the homegrown CSRF (double-submit `XSRF-TOKEN` cookie, the NOTED entry earlier today) by Symfony's stateless mechanism, header only (§ 3 Architecture style); `csrf_origin_mismatch` no longer exists as an error code. Migrations moved from `make up` and the CI e2e job into the API image's entrypoint; `composer test` migrates the test database. Every timestamp is UTC (`date.timezone` in the image and in PHPUnit). The Symfony user is a snapshot (`SecurityUser`), the domain `User` implements no framework interface, and `Email` is the first value object (custom DBAL type `email`).
- [2026-09-09] AGREED: the breached-password check is Symfony's `NotCompromisedPassword` constraint (the k-anonymity range query against Have I Been Pwned), with `skipOnError: true`: when the service is unreachable the password is accepted and the skip is written to `audit_log`, because an outage there must never stop onboarding. `framework.validation.not_compromised_password.enabled` is false under `when@test`, so no test reaches the network. The constraint lives on the input DTO in `Infrastructure`, never in `Application`.
- [2026-09-09] AGREED (superseded 2026-09-15, see the POC review's rulings: an existing account now accepts like any address): inviting an address that already has a user creates the membership directly, with no acceptance step; the company appears in that person's switcher at next login and the email they receive is informational. The invitation flow that sets a password exists for addresses with no user. Rationale: they already proved that address, and a second credential-setting path into an existing account is an attack surface, not a courtesy.
- [2026-09-09] AGREED: a company an operator creates starts `pending` and becomes `active` when its first owner accepts the invitation. `suspended` stays an operator action and the approval semantics of `signup.approval_required` stay at G1d.
- [2026-09-09] AGREED: notifications are real time, and the transport is Centrifugo, its server Apache-2.0 and its browser client `centrifuge-js` MIT, both already permitted; the OSS image was checked and carries no licence-key gate. Mercure was refused although it is the transport Symfony and API Platform officially recommend, and although its hub ships inside our FrankenPHP binary already: that hub is AGPL-3.0, and a customer self-hosting under the commercial licence would receive an AGPL service. The decision is licensing, not technical, and it is the one place where a licensing invariant outranks the official-recommendation directive.
- [2026-09-09] AGREED: G1b ships the `Notifications` port with the membership event flowing through it, and no wire. The Centrifugo adapter, the token endpoint the API signs, the Angular client and the notification centre are built together at G2, where the shell that displays them is designed.
- [2026-09-09] NOTED: `api_platform.error_formats` must be narrowed whenever `formats` is. The default still lists `jsonld` first, so a browser sending `Accept: */*` negotiated a format this project never enabled and every API Platform refusal answered `500 Serialization for the format "jsonld" is not supported` instead of its own status. Found by the first G1b end-to-end scenario, not by the functional tests, which send an explicit JSON `Accept`; `MembersTest::testARefusalIsStillJsonForABrowserThatAcceptsAnything` now pins it.
- [2026-09-09] NOTED: a company an operator opens has no owner, so `ChooseWorkingCompany` leaves a user with several memberships no working company at all, by the G1a ruling that the switcher decides. The switcher therefore renders whenever there is more than one company, labelled "choose a company" while none is chosen; a user with several companies would otherwise be stranded with no way to pick one.
- [2026-09-10] NOTED: an API Platform resource whose read and write shapes differ needs explicit serialization groups. Without them a write operation answers with only the properties it *accepts*, so `POST /api/companies/{id}/members` returned neither the identifier, the display name nor whether the address had been invited rather than added, while the same resource served through `GET` returned all of them. The metadata was right the whole time: `api:json-schema:generate` listed every property. Each resource now names a read group and a write group, and the operations declare `normalizationContext` and `denormalizationContext`.
- [2026-09-10] NOTED: the breached-password check is a port with three answers rather than Symfony's `NotCompromisedPassword` constraint, which has two. The ruling of 2026-09-09 requires an unreachable service to accept the password AND write an audit row, and a constraint that silently skips cannot report which of those happened. The adapter performs the same k-anonymity range query the constraint does, in the way Have I Been Pwned documents: the password is SHA-1'd locally, only the first five hex characters leave the machine, padding is requested, and a padding entry (count zero) is not a match.
- [2026-09-10] AGREED: amends the ruling of 2026-09-09 on the breached-password check, whose last sentence put the constraint on the input DTO in `Infrastructure` and never in `Application`. The check is a port called from `AcceptInvitation`: the same ruling requires an unreachable service to be audited as a skip, a constraint reports only pass or fail, so the two halves of that ruling cannot both live on the DTO. The NOTED entry above records why; this entry is what the next session should read as the standing decision.
- [2026-09-10] AGREED: a timestamp a person reads is rendered in the company's timezone, never the stored UTC one. `company.timezone` already existed for this; the invitation mail is the first thing to use it, and every later mail, PDF and export follows the same rule.
- [2026-09-10] AGREED: MFA recovery is ten single-use codes, generated at enrolment, shown once and hashed at rest the way passwords are; each is usable once, the set is regenerable with a current factor, and spending one writes `auth.recovery_code_used` to `audit_log`. An operator reset was refused: it would make the platform operator a standing bypass of every user's second factor, and a customer with no operator relationship would still be stuck.
- [2026-09-10] AGREED: `security.mfa_required` is a boolean column on `company` at G1c, not the first row of a settings table. Settings are G3, where the real requirements live (fiscal presets, numbering); designing that model under pressure from a single boolean would be backwards. Moving the column into the settings table at G3 is a data migration of one field.
- [2026-09-10] AGREED: the TOTP secret is encrypted at rest with `sodium_crypto_secretbox`, keyed from a dedicated `APP_MFA_KEY` and deliberately not from `APP_SECRET`, which rotates for unrelated reasons and would silently invalidate every enrolled authenticator. A TOTP secret is a bearer credential that keeps minting valid codes long after a database dump, which is what makes it unlike a password hash.
- [2026-09-10] AGREED: a second factor is verified before any session exists. Password-only login answers `200 {mfaRequired: true}` and leaves a pending marker in the session; `POST /api/auth/mfa/verify` is what logs the user in and rotates the id. The rule the design has to satisfy is that a password-only session is refused by `GET /api/auth/me` with no change to `access_control` or the voters: it fails closed by construction, not by a check someone can forget to add.
- [2026-09-10] AGREED: `security.mfa_required` is evaluated against the user's memberships, not against the session's working company. A member of one company that requires MFA and one that does not must enrol either way, or the company switcher becomes the bypass. A platform-wide rule for operators belongs with G1d's platform settings.
- [2026-09-13] AGREED: passkeys stay in G1c, beside TOTP, as the spec's G1c row already says; G1c is done only with the web pages, passkeys and a Playwright scenario. The WebAuthn libraries are chosen when G1c resumes, and their LICENSE files are read before either is added.
- [2026-09-13] AGREED: G2's typography is Inter and its icon set is Material Symbols, both self-hosted, never loaded from Google. Their LICENSE files (expected OFL-1.1 and Apache-2.0) are read when they are vendored. Inter carries no Arabic glyphs, so the post-POC Arabic UI adds an Arabic face as a fallback.
- [2026-09-13] AGREED: G3's fiscal research is written by Claude into `docs/fiscal/TN.md` and `docs/fiscal/FR.md` from official texts, with every rule cited to its source, and the developer or an accountant validates both before any preset is coded.
- [2026-09-13] AGREED: amends the entry above so the POC run does not stop at G3: the presets are coded from the cited research straight away, every rule is marked `unvalidated` in `docs/fiscal/<CC>.md` and in `api/config/fiscal/<CC>.yaml`, and the developer validates after the run; a correction is a data edit plus its pricing vector.
- [2026-09-13] AGREED: the goal of the current run is a minimal usable version covering the whole POC scope (§ 2), run without stopping except for a question; a decision Claude takes alone is written here as `DECIDED (revisit)`, distinct from `AGREED`, so it can be reviewed later.
- [2026-09-13] AGREED: the run's order is G2 shell → G3 fiscal, settings, numbering → G4 customers → G5 products → G6 delivery notes + PDF → G7 invoices, payments, credit notes → G10 inventory → G8 vendors → G9 expenses → G1c MFA pages + passkeys → G1d signup. Invoices come before stock because G10 consumes `delivery_note.validated`; MFA is off by default, so its pages can wait. No compromise: every ruling above stays valid, and a ruling that proves wrong or costly is raised with a recommendation, never dropped silently.
- [2026-09-13] AGREED: one design checkpoint after G2. The shell, the customer list and form, and a static invoice-editor screen are published as screenshots in one artifact, and the run stops once for approval; every later screen reuses the approved patterns. Quality bar on every screen: design tokens (spacing, type scale, colours), one list pattern (filters, table, pagination, empty state), one form pattern (sections, inline validation), keyboard reachability, an axe check in Playwright, and desktop and phone screenshots reviewed before a goal closes.
- [2026-09-13] AGREED: the visual direction is a calm SaaS dashboard on restyled Angular Material: light neutral ground, one accent colour taken from branding, compact tables, spacious forms, dark mode through tokens. Customisability is a first-class requirement: every feature is designed by answering "how is this configurable?", and the scope of that model is the next entry.
- [2026-09-13] AGREED: customisation is metadata-driven: lists, filters and forms are rendered from metadata, not hand-coded; theme tokens and logo, every table's columns (visibility, order, width, sort), filters and saved views, density and dark mode, translation overrides, custom fields on customers, products and documents, PDF template and colours, numbering formats and module switches are configurable. Tax arithmetic and legal mentions are not company-editable; they change only as operator-owned preset data. Amends the 2026-09-09 ruling of two settings scopes: a user scope is added, and the developer wants many more levels (client, product, document, group), with anything that would double the POC moved to the next milestone, but never hardcoded in a way that makes adding it later a rewrite. The level model is the next entry.
- [2026-09-13] AGREED: settings are resolved by one generic engine with any number of levels. Each setting is registered with its type, its validation and the levels allowed to override it, and a test refuses a business value read in code that is not registered. The POC fills two chains for business defaults, platform → company → customer group → customer → document and platform → company → product category → product → document line, and one for presentation, platform → company → role → user. Customer groups and product categories are real entities with their own screens. A later level is registered, never rewritten.
- [2026-09-13] DECIDED (revisit): numbering is a `numbering_series` entity (document type, format, next number, reset period) rather than a setting value, because gapless numbering needs a row locked in the issuing transaction; a company may hold several series per document type, and the settings engine picks the default one.
- [2026-09-13] AGREED: three more concepts enter the data model now, each with one default instance so the POC screens stay minimal and going multi later is data: establishments (a company starts with one; documents and numbering series reference the issuing establishment, whose code is part of the Tunisian tax identifier), stock locations (stock is kept per product and location, one default location per establishment) and customer tax regimes (standard, exempt, suspended, export; operator-owned preset data sourced in the G3 research). Price lists are deferred to the next milestone: they are additive, a lookup before the product price, so adding them later is not a rewrite.
- [2026-09-13] DECIDED (revisit): G2 is split into G2a (shell, tokens, theme, notifications, nonce CSP) and G2b (generic list and form, presentation settings, checkpoint), G3 into G3a (fiscal) and G3b (settings engine chains, establishments, numbering), and G4 and G5 grow from M to L with customer groups and categories; the status denominator goes from 75 to 101 points, so progress reads 23 % (24 of 101), not 32 %. The Centrifugo notifications of the 2026-09-09 ruling and the nonce-based CSP owed by the known issue both stay in G2a as ruled.
- [2026-09-13] DECIDED (revisit): screens are described by TypeScript descriptors in `web/src/app/shared/`: a list descriptor (columns with key, label key, type, sortable, filterable, width, visible by default; filters; default sort) and a form descriptor (sections, fields, validators), with one merge point where server-provided custom fields are appended from G4 on.
- [2026-09-13] DECIDED (revisit): presentation preferences go through a `SettingsFacade` port from G2b, first with a browser-storage adapter, replaced by the API adapter of the presentation chain at G3b; the nav manifest type is defined at G2a with hardcoded core entries and supplied by the G5 module registry. Both swaps are additive.
- [2026-09-13] DECIDED (revisit): the accent colour is any hex, turned into Material tonal palettes at runtime with `@material/material-color-utilities` (Apache-2.0), rather than a fixed set of Sass palettes, because a fixed set is not "100 % customisable".
- [2026-09-13] DECIDED (revisit): the design checkpoint's customer list and form and the static invoice editor are built on fixture data under a dev-only `/design` route, never on a throwaway API; the members page is the first real consumer of the list pattern, and G4 and G7 replace the fixtures with their facades.
- [2026-09-13] DECIDED (revisit): the accessibility check is `@axe-core/playwright` in the e2e suite. It is MPL-2.0, which the licence gate already permits for dev-only tooling as a list (`--dump-rules`: `dev_only_tooling` = MPL-2.0, Python-2.0), so no licensing decision is involved; it never ships.
- [2026-09-13] DECIDED (revisit): Inter is vendored, not installed. `@fontsource-variable/inter` was refused by the gate as an OFL-1.1 RUNTIME package, which is exactly the rule: OFL-1.1 is permitted for vendored font FILES only. Its latin and latin-ext variable woff2 files ship unmodified under `web/public/fonts/inter/` with their LICENSE, and the file-level check the gate had promised for the first font landed with it: a font file under `web/public` or `web/src` without a LICENSE beside it, or with a LICENSE that is not OFL-1.1, fails the gate, and the notices list every vendored font.
- [2026-09-13] DECIDED (revisit): `@material/material-color-utilities` is pinned to exactly 0.3.0. Version 0.4.0 ships ESM with eleven extensionless relative imports (`from '../dynamiccolor/dynamic_scheme'`), which Node's ESM resolver refuses, so Vitest cannot load it; 0.3.0 has none and carries the same tonal-spot scheme and role getters. Unpin once a release fixes the imports, rather than teaching the test runner to inline a broken package.
- [2026-09-13] DECIDED (revisit): the Centrifugo connection token is an HS256 JWT signed in `Shared/Infrastructure/Realtime` without a JWT library, its signature pinned by a test to the published vector of RFC 7515 Appendix A.1. It carries `sub` (the user), `exp` and a `channels` claim, so Centrifugo subscribes the connection server-side to `user:<uuid>` and, when a company is being worked in, `company:<uuid>`; the browser never chooses a channel, and switching company reconnects with a new token. The two channel shapes are the ones the G1b use cases already publish.
- [2026-09-13] DECIDED (revisit): the notification centre is persisted. Publishing writes one `inbox_item` row per recipient (a `company:` channel fans out to that company's members) and then pushes over Centrifugo; the row is the truth and the push is best-effort, so an unreachable Centrifugo is logged and never fails the use case that published. Centrifugo is reached by the browser only through nginx on the same origin (`/connection/websocket`), and its HTTP API only from the api container.
- [2026-09-13] AGREED: every dependency on every tier (Composer, npm, Docker base and service images, CI actions, Playwright) is upgraded to its latest version, majors included, after the notification centre lands: one gated commit per tier, and a major that breaks beyond a quick fix or changes a licence stops and is reported.
- [2026-09-13] DECIDED (revisit): three newer web majors are held back, each blocked by something outside this repo. TypeScript 7.0.2 and Vitest 5.0.0: Angular 22.1 declares `typescript >=6.0 <6.1` (compiler-cli) and `vitest ^4.0.8` (build) as peers, so both wait for an Angular release that widens them. `@material/material-color-utilities` 0.4.0: its published build still ships ten extensionless relative imports (`from './dynamic_color'`) that fail to resolve under Vitest, re-checked against the 0.4.0 tarball on 2026-09-13, so it stays pinned at 0.3.0 until a release fixes them. Everything else on every tier is at its latest release.
- [2026-09-13] DECIDED (revisit): presentation settings go through one port, `SettingsFacade` (`web/src/app/shared/settings/`), with typed definitions in a registry: a key missing from `settings-registry.ts` can be neither read nor written, and every stored value passes its parser, so stale or hand-edited storage falls back to the declared default. Until G3b the adapter is `BrowserStorageSettings`, one `localStorage` key per user and setting (`twes.settings.<user>.<key>`), which also remembers each write for the page so a browser that refuses storage still works; G3b swaps in the API adapter (platform → company → role → user) behind the same port. `ThemeFacade` reads and writes accent, scheme and density through it, so they now survive a reload.
- [2026-09-13] DECIDED (revisit): every list screen is `DataList` over a `ListDescriptor` (`web/src/app/shared/list/`): the screen declares columns, `withCustomColumns` is the one point configured columns join them, and pure functions (`resolveColumns`, `filterRows`, `sortRows`, `paginate`) decide what shows. Per person and per list, the hidden columns, order, widths and sort persist under `presentation.list.<listId>`. A column may be declared not hideable or hidden by default. Reordering and resizing each have a non-drag alternative (up/down buttons, arrow keys on the resize handle), because WCAG 2.5.7 requires one. The column chooser is an inline panel rather than a `mat-menu`, because `mat-menu` closes on Tab and would lock keyboard users out of its checkboxes. The members page is its first consumer and keeps its test ids.
- [2026-09-13] DECIDED (revisit): every form is `DescriptorForm` over a `FormDescriptor` (`web/src/app/shared/form/`): sections of typed fields whose declared validators (required, email, length, range, whole-value pattern, one-of-options) are the whole of the client-side check, built by the pure `buildFormGroup`, with one translated message per field from `fieldError` shown once the field is left. Submitting an invalid form shows every error and focuses the first invalid field; a valid one emits its values. `withCustomFields` is the one point configured fields join a section. The API validates again; these checks only put the message next to the field.
- [2026-09-13] DECIDED (revisit): the design checkpoint's fixture screens (a customers list, a customer form and a static invoice editor) live under `/design`, reachable only in a development build: the route's `canMatch` is `isDevMode()` and its nav entry is `devOnly`, so a production bundle's router cannot match it. Their amounts are preformatted fixture strings; nothing there computes money. The screenshots are taken by `web/playwright.design.config.ts` against `ng serve`, with every `/api` call answered by fixtures, because the compose image is a production build. That run is not part of CI, and its axe and overflow checks are soft so every screen is still captured. The executed browser proof of the list pattern is the members page in the real stack (`web/e2e/data-list.spec.ts`).
- [2026-09-13] DECIDED (revisit): every list column occupies a real width, whether a person's choice, the descriptor's, or a 160 px fallback clamped to 48 to 960 px. The resize handle therefore always states `aria-valuenow` with its bounds, which axe requires of a focusable separator, and the table's minimum width is the sum of its columns, so on a phone the list scrolls inside its own container and the page never scrolls sideways. Material's paginator labels come from `list.paginator` through `TranslatedPaginatorIntl` and follow a language switch. The design checkpoint's first screenshot run caught all three defects (the axe violation, a clipped column at 390 px, English labels on a French screen); none of the unit tests had.
- [2026-09-13] AGREED: the G2b design checkpoint is approved as published (https://claude.ai/code/artifact/038d341d-f817-49b8-be97-d0069a8f7e27, commits 2d810ff and 0481364): the shared list chrome (filter, inline column chooser, sortable headers, pages, empty state), the shared form (titled groups on a two-column grid, required stars and `facultatif` hints, messages under the field), the invoice layout (actions in the header, lines at full width, totals below) and comfortable density with the `#1f6feb` accent as defaults. G3 onwards builds on them.
- [2026-09-13] DECIDED (revisit): G2b's saved views and density close the goal as written. A list descriptor may declare faceted `filters` (one option per filter, beside the text filter; an unknown filter or a retired option is ignored, so a view outlives its configuration). A saved view is a named snapshot of the text filter, the chosen options and the column layout with its sort, kept per user and per list under `presentation.list.<id>.views`; saving under a name already used (ignoring case and accents) replaces that view in place, a stored entry that no longer parses is dropped alone, and the view matching what the screen shows is marked pressed. The text filter and the options themselves are not remembered between visits, only through a view. Density is a toggle in the account menu next to the scheme, stored as `presentation.density`. The views live in the browser until the G3b API adapter, like every presentation setting.
- [2026-09-13] AGREED: the Tunisian VAT withholding of Code TVA art. 19 bis (the State, local authorities and public bodies withhold 25 % of the VAT on purchases of 1000 TND or more) is deferred: its base is the VAT amount, which none of the three tax kinds expresses, and it applies only to sales to public buyers. The three kinds stay closed for the POC; `docs/fiscal/TN.md` records the rule under its known gaps, and the revisit is a fourth kind when public-sector invoicing is in scope.
- [2026-09-13] AGREED: the Tunisian preset ships the invoice stamp as a flat 1.000 TND fixed per-document charge (CDET art. 117, as amended by LF 2023). The brackets LF 2026 art. 20 sets for large retail outlets (1.5 TND for invoices of 50 to 100 TND, 2 TND above) are a known issue, not modelled; the amount stays editable per company.
- [2026-09-13] AGREED: tax-inclusive (TTC) entry is refused on a line carrying more than one percentage tax or a tax that enters the VAT base (Tunisian FODEC with VAT): no source says how that compound extraction is rounded. Tax-exclusive entry works for every combination, a pricing vector pins the refusal, and the revisit is a sourced rounding rule.
- [2026-09-13] AGREED: creating a company in a country with no fiscal preset answers 422 ("no fiscal preset for this country"): a company cannot invoice without its taxes, and a new country is a preset file plus translations.
- [2026-09-13] DECIDED (revisit): the tax-kind set is closed for the POC as § 3's three kinds, and each component also has a `family`: `vat` and `levy` are `percentage_line`, `stamp` is `fixed_document`, `withholding` is `withholding_total`, and only a levy may enter the VAT base. Regimes exclude families, never codes, so an export regime removes a company's VAT rates whatever the company named them. Mapping: Tunisian VAT 19/13/7 % and French VAT 20/10/5.5/2.1 % are `vat`; FODEC 1 % is a `levy` entering the VAT base; the Tunisian stamp is `stamp`; the 1 % retenue à la source is `withholding` (docs/fiscal/TN.md, docs/fiscal/FR.md).
- [2026-09-13] DECIDED (revisit): a preset is `api/config/fiscal/<CC>.yaml`, validated when read by a Symfony Config tree plus the rules spanning several values (`App\Fiscal\Infrastructure\Preset`), each refusal naming the file, the path and the rule. Component and unit names are written in every product language and copied into the company's own rows, in its locale, at creation. Regime labels, identifier labels and printed mentions are translation keys under `fiscal.` in `api/translations/fiscal.{fr,en}.yaml`, because the API renders documents; a test holds both files to the keys the presets use. Identifiers carry a pattern only; checksums are G4 validators. `symfony/config` becomes a direct dependency (it was already locked through the framework).
- [2026-09-13] DECIDED (revisit): the configurable rounding point of the pricing vectors becomes a preset rounding rule. Both presets say `half_up`, `per_rate_group` (EN 16931 BR-CO-17) and `exclusive`; the vectors keep declaring `vat_rounding_point` and `tax_basis` per case, because the calculator implements both. The rounding rules and numbering defaults reach a company through the settings engine at G3b; G3a copies at creation only what has a table: the tax components and the units.
- [2026-09-13] DECIDED (revisit): the calculator (`api/src/Fiscal/Domain/Calculation`) is plain domain code on PHP's `BcMath\Number` rather than a decimal library: arbitrary precision and half-away-from-zero rounding are built in, and no dependency is added. The bcmath extension is therefore required by `composer.json`, installed in the API image and enabled in CI. Intermediates carry 24 decimals, and each figure is rounded once, at the currency's scale.
- [2026-09-13] DECIDED (revisit): tax components compose on a line in a fixed order. A levy entering the VAT base is computed first, on the line's discounted net, and rounded at the document's rounding point; per rate group, its line shares are allocated by largest remainder, never recomputed. Every other percentage tax is then computed on the line's net plus that line's levy share. The document discount is allocated across the groups of lines carrying the same set of taxes, then across each group's lines, by the same rule. A withholding applies when the total excluding fixed charges reaches its threshold (inclusive, in absolute value); it is computed on that total and reduces only the amount due. `docs/spec/pricing-vectors.json` (version 2) pins this with twelve new cases, authored with an independent Python decimal oracle; they also cover the two documents the earlier fixture owed.
- [2026-09-13] DECIDED (revisit): a company's fiscal setup is three tables. `tax_component` and `unit` belong to the company and are copied from its preset when it is created, named in the company's language; `customer_tax_regime` belongs to the operator, is keyed by preset and code, is written by the seed from the preset files, and keeps a regime a preset stops listing, so no customer loses the regime it was given. `company.fiscal_preset` records the preset, its country's; the migration fills it for existing companies, and the next seed copies their taxes and units, because a migration does not read the preset files. Creating a company copies its preset in the same use case, and the seed does the same for every company, idempotently: a company that already has taxes, or units, keeps them untouched.
- [2026-09-13] DECIDED (revisit): a tax component's code and family are fixed once it exists, because documents will refer to them; its name, rate, amount, threshold, VAT-base flag, default and active flags, exemption mention and order are revised with a PUT, and a tax is deactivated, never deleted. The fiscal rules live in the entity and answer 422 naming the field: which of rate, amount and threshold a family needs, only a levy entering the VAT base, a rate of at most 100, and an amount or threshold no finer than the company's currency. A currency's decimals come from ISO 4217 through symfony/intl's CLDR data, and an unknown code is refused rather than given CLDR's default of two. A duplicate code in one company answers 409.
- [2026-09-13] DECIDED (revisit): two permissions, `fiscal.read` and `fiscal.write`, guard `/api/companies/{companyId}/tax-components`, `/units` and `/customer-tax-regimes` through CompanyGuard, so another company's rows answer 404. The built-in admin role gains both and the member role gains `fiscal.read`, because a member drafting a document must see the taxes it offers; the owner's wildcard covers both. Customer regime labels come from the API translated into the reader's language, the user's locale, else the company's.
- [2026-09-13] DECIDED (revisit): the form descriptor gains a `checkbox` field, where `required` means it must be ticked, and a `visibleWhen` condition (`{ field, oneOf }`): a field shows only while another field of the form holds one of the listed values. A field that does not apply is disabled, so it neither validates nor reaches the submitted values, and a condition naming a field the form does not declare throws when the form is built. It is how a tax form offers a rate, an amount, a threshold or the VAT-base flag according to the family chosen, without a hand-written form.
- [2026-09-13] DECIDED (revisit): a company's fiscal setup has two screens in the admin section, shown to `fiscal.read`: `/fiscal/taxes` (the taxes, a form to add or revise one, and the customer regimes with the families each does not charge) and `/fiscal/units`. Adding and editing are offered to `fiscal.write` only, a courtesy the API enforces. Adding a tax chooses its family, and the value fields follow it; revising one never offers its code or family, only that family's value fields, and sends back the fixed code and family whatever the form holds. A value that does not apply goes to the API as null, decimals travel as strings, and after a save the list is re-read from the server rather than patched. A refusal (code taken, invalid, not found, network) is shown translated and keeps the form open. The regime list is read-only: regimes are the operator's.
- [2026-09-13] DECIDED (revisit): the settings engine stores every value in one `setting` table, keyed by level, `level_id` and key. `level_id` addresses the subject: nothing for the platform, the company, the company and the role (the built-in roles are shared by every company), or the user; `company_id` is kept for every level inside a company, so its settings go with it. § 4's `platform_setting` is folded into it at the platform level. Settings are declared in code as `SettingDefinition`s (a key or a key pattern such as `presentation.list.<id>`, type, default, chain, the levels allowed to hold a value, label key, module, choices and bounds), collected by `SettingCatalog` from every `DeclaresSettings` service; a definition cannot be declared with a default it would refuse. A read walks the chain from its most specific level, and skips a stored value whose level no longer allows the setting or whose value it no longer accepts, so an older row never breaks a read. Code reads a business value through `ReadSetting`, and `tests/Architecture/RegisteredSettingsTest` refuses a key literal read there that no module declares.
- [2026-09-13] AGREED: a person's own presentation choices (scheme, density, accent, list layouts, saved views) follow the person into every company they work in; company and role defaults sit beneath them.
- [2026-09-13] DECIDED (revisit): one endpoint reads a chain, `GET /api/companies/{companyId}/settings?chain=` (every chain without the parameter, 400 for an unknown one), answering each setting with the value in force, its source level, what each level holds, the levels allowed and the levels the caller may write. `PUT .../settings/{key}` stores `{level, value, roleId?}` and answers the setting; `DELETE .../settings/{key}?level=&roleId=` forgets the value at that level (204). Reading, and a person's own user-level choice, need `company.read`; a company or role default needs `company.settings`, and a role of another company answers 404 like the company itself. An unknown key answers 404, a refused level or value 422. Company and role defaults are audited as `setting.changed` and `setting.reset`; a person's own choices are not. The platform level is not writable until the operator's screen arrives with signup (G1d).
- [2026-09-13] DECIDED (revisit): the web's `ApiSettings` replaces `BrowserStorageSettings` behind `SettingsFacade`. It reads the presentation chain once per person and working company, stores a choice at the user level and shows it at once, falls back to the levels above on reset without reading the chain again, and keeps a refused write for the page. Signed out, or signed in without a working company, the browser adapter still answers. Choices kept in browsers during G2b are not carried over: only development data existed. Because the choices now live in the shared database, the Playwright scenarios that change one start by forgetting the operator's own (`web/e2e/presentation.ts`), and density is checked in a second browser context, which shares no storage with the first.
- [2026-09-13] DECIDED (revisit): the first business defaults are declared in `BusinessDefaultSettings`, at the company level and every level below it in their chain. Parties: `document.payment_terms_days` (whole days, 30, 0 to 365), `document.language` (`fr` or `en`, `fr`) and `document.printed_notes` (text of at most 2000 characters, empty). Articles: `article.default_unit` (a UN/ECE Recommendation 20 code, `C62`) and `article.stock_tracking` (off). They are what G4 to G7 read through `ReadSetting`. The levels below the company are declared now but written only by their own entities' screens, once customer groups, customers, categories, products and documents exist.
- [2026-09-13] DECIDED (revisit): a text setting may declare a whole-value `pattern` (a PHP regular expression). The engine refuses a value that does not match with 422, and the endpoint publishes the pattern, which the web strips of its delimiters and anchors for the form's validator. The levels a caller may write are only those the endpoint can address, the user, the company and a role; a level below the company is allowed by a definition without being offered as writable.
- [2026-09-13] DECIDED (revisit): one generic `/settings` page in the admin section, shown to `company.settings`, sets the company's defaults. It is rendered from the definitions the API returns, one section per chain and one field per setting the caller may write at the company level: a whole number is a number field with its bounds, a choice a select, a text with a pattern or of at most 200 characters a text field (longer ones a full-width text area), a flag a checkbox and a colour a colour picker, a new `colour` form field kind; JSON values, such as list layouts, are a person's own and are not shown. Each field starts at the company's value, else the platform's, else the default, never at a person's own choice. Saving writes only the settings that changed, one PUT each at the company level, then reads the chains again; the values the company holds are listed below the form, each with a reset. Role defaults have no screen yet: the endpoint accepts them, and the page gains a level selector when roles become editable.
- [2026-09-13] DECIDED (revisit): the company profile is columns on `company` (§ 4): legal name and form, `identifiers` as JSONB keyed by the preset's identifier keys, a postal address as four columns (`address_line1`, `address_line2`, `postal_code`, `city`; the country is the company's own), email, phone, website, IBAN and BIC, `vat_regime` (default `standard`) and the invoice footer and late-penalty texts. § 4's `default_payment_terms_days` is not a column: it is the `document.payment_terms_days` setting, so a customer group, a customer or a document can override it. `logo_file_id` waits for file storage (G9, with the PDF template). A shared address value object waits for customers (G4), which need two addresses.
- [2026-09-13] DECIDED (revisit): `GET /api/companies/{companyId}/profile` (`company.read`) answers the profile together with what the company's preset asks for: its identifiers (key, label translated into the reader's language, whole-value pattern, whether a company must carry it) and its company VAT regimes, so the form is rendered from them; `PUT` (`company.settings`) revises it. The values are stored without stray spaces, an empty value is no value, and IBAN and BIC are kept in capitals without spaces (Symfony's `Iban` and case-insensitive `Bic` constraints check them). The preset's rules answer 422 naming the field: an identifier it does not know, one without its shape, one it requires of a company left out, or a VAT regime it does not offer companies (Tunisia: `standard`; France: `standard` or `franchise`). A revision is audited as `company.profile_revised` with the names of the fields it changed, never their values, so banking details stay out of the audit trail; an unchanged profile records nothing.
- [2026-09-13] DECIDED (revisit): `/company/profile` in the admin section, shown to `company.settings`, edits the profile on the shared form in six groups (identity with the VAT regime, the preset's identifiers, address, contact, banking, the texts printed on invoices). The identifier fields and regime options come from the API, so another country's preset needs no change to the page; an identifier section is left out when a preset has none. The client checks the preset's patterns and the limits the API checks again, and an emptied field is sent as no value.

- [2026-09-13] DECIDED (revisit): establishments and numbering series are two tables (§ 4). An `establishment` belongs to a company, has a code unique in it, a name, a postal address, phone and email, and exactly one establishment per company is its default (a partial unique index). A `numbering_series` belongs to an establishment and a document type, holds its format, the number it resumes at and its reset period (`yearly`, `monthly`, `never`), with one default series per establishment and type; it is a row, not a setting, because gapless allocation will lock it in the issuing transaction (G6, which also keeps the reset bookkeeping columns). A preset gains an `establishment` node, `default_code` and `code_pattern`: Tunisia `000` and three digits (the establishment part closing a matricule fiscal), France `00001` and five digits (the NIC closing a SIRET; `00001` is a placeholder the company corrects). Creating a company, and the seed for existing ones, adds a default establishment coded that way and named after the company, and one default series per document type the preset numbers, on it. A second series for one type and establishment waits for G7, which picks a series per document.
- [2026-09-13] DECIDED (revisit): a numbering format is literal text (letters, digits, spaces, `_ . / -`) around `{YYYY}`, `{YY}`, `{MM}`, `{EST}` and exactly one sequence, `{SEQ}` or `{SEQ:n}` padded with zeros to n digits (1 to 12) and never cut, in at most 64 characters; the preset reader refuses a preset format that breaks these rules. `{EST}` writes the issuing establishment's code, so two establishments can number apart. The presets' formats do not use it, and a second establishment copies the default one's formats as they are: keeping issued numbers unique within a company is the G6 allocation's rule, not the format's.
- [2026-09-13] DECIDED (revisit): `GET` and `POST /api/companies/{companyId}/establishments` and `PUT .../establishments/{establishmentId}` (read `company.read`, write `company.settings`, another company's establishment 404). Every row carries the preset's `codePattern`. A code without the preset's shape answers 422, a code another establishment has 409; the code stays revisable until documents carry it (G6). The default is handed over by setting `isDefault` on another establishment, and clearing it on the default answers 422, so a company always has one. A new establishment starts with a default series per document type, copied from the default establishment's (else the preset's) format and reset, at one. There is no deletion: documents will name their establishment. `GET /api/companies/{companyId}/numbering-series` answers every series with its establishment code and `preview`, the number a document issued today in the company's time zone would get; `PUT .../numbering-series/{seriesId}` revises the format, the number it resumes at (one or above, freely until G6, so a company moving from another tool continues where it stopped) and the reset period. Audited as `establishment.created`, `establishment.revised` (the names of the fields changed) and `numbering_series.revised` (the new values).
- [2026-09-13] DECIDED (revisit): two admin screens shown to `company.settings`: `/company/establishments` (the list, a form to add or revise one, its code checked against the preset's pattern once the API has given it) and `/company/numbering` (every series with its document type and reset translated and its next number, and a form that shows the next number while the format is typed, computed in the browser by the same rules and on the browser's date; the API's preview is the reference once saved). The Playwright scenario renumbers the seeded company's delivery notes and puts them back; it adds no establishment, because an establishment cannot be removed.
- [2026-09-13] DECIDED (revisit): the preset's rounding rules (`half_up`, `per_rate_group`, `exclusive` in both presets) stay in the preset instead of becoming settings, correcting the G3a entry that sent them through the settings engine at G3b: no shipped country lets a company choose, and a company-level override would let it print totals its tax authority does not accept. G7 reads them from the company's preset when it prices a document; a setting is declared only when a preset offers a choice.
- [2026-09-14] DECIDED (revisit): the entity mapping must describe exactly the schema the migrations build. `tests/Integration/Shared/SchemaInSyncTest` fails when Doctrine's schema tool would change anything but its own migrations table, which is how G3b's drift was found (two foreign-key indexes the mapping did not name, the `company.identifiers` default, and partial index predicates written `(is_default)` where PostgreSQL reads back `is_default`). A partial index's `where` option is written the way PostgreSQL returns it.
- [2026-09-14] DECIDED (revisit): an identifier's checksum is preset data, not code keyed by its name. A preset identifier may name a `check` from a closed set the product implements (`App\Fiscal\Domain\IdentifierCheck`): `luhn` (the French SIREN), `siret` (Luhn over the fourteen digits, except La Poste's establishments, SIREN 356000000, whose digits add up to a multiple of five) and `fr_vat_key` (the two-digit key of an FR VAT number, (12 + 3 × (SIREN mod 97)) mod 97; a key of letters, issued without a SIREN, is not checked). The Tunisian matricule fiscal names no check: its check letter is not sourced (docs/fiscal/TN.md § 8). `IdentifierRules` applies a preset's identifiers (known keys, shape, check, required for the holder) to a company profile and to a customer alike, so the profile PUT now refuses a French SIREN whose check digit is wrong.
- [2026-09-14] DECIDED (revisit): a module's backend is a context one level down, `api/src/Module/<Name>/{Domain,Application,Infrastructure}`, held to the same layer rules (`LayerDependenciesTest` reads `src/Module/*` as contexts). Customers are the first module; the registry, `module_state` and switching a module off arrive with G5, so its navigation entries stay hardcoded until then.
- [2026-09-14] DECIDED (revisit): a customer (§ 4) has a number the company types, unique in the company (409 when taken): numbering is a locked row for documents (G6), and nothing about customers needs it gapless. Its kind is `company` or `individual`; its tax regime is one its company's preset offers customers, and a regime the preset stops listing stays with the customers who have it; its group, when it has one, is the company's. A business customer billed in the company's own country (or with no country given) carries the registration numbers the preset requires of a `business_customer`; a customer billed abroad carries none of them, and foreign registration numbers wait for e-invoicing. Default taxes are active taxes of the company that its regime charges; the default discount is a percentage from 0 to 100 with three decimals; notes are internal. The billing and shipping addresses are the shared `PostalAddress` value object, embedded twice (`billing_*`, `shipping_*`); an address without a country is in the company's country and an empty shipping address means the billing one. A customer is deactivated, never deleted. § 4's `payment_terms_days` and `document_language` are not columns: they are the parties chain's settings at the customer level, like the company's.
- [2026-09-14] DECIDED (revisit): a customer's contacts have a first or a last name, an email, a phone and a role (what they do there); the first contact is the primary one, marking another primary hands it over, and removing the primary contact makes the oldest remaining one primary. At most one primary is held by a partial unique index, and the contact row keeps its company like every business table. A customer group has a name unique in its company and a description; a group still holding customers answers 409 to a deletion, and each group row says how many customers it holds.
- [2026-09-14] DECIDED (revisit): the customers module's endpoints are `GET`/`POST /api/companies/{companyId}/customer-groups`, `PUT`/`DELETE .../customer-groups/{groupId}`, `GET`/`POST .../customers`, `GET`/`PUT .../customers/{customerId}`, `GET`/`POST .../customers/{customerId}/contacts`, `PUT`/`DELETE .../contacts/{contactId}`, and `GET .../customer-options`, which gives the customer form the preset's identifiers (label in the reader's language, shape, whether a domestic business must carry it), the regimes and the company's active taxes, so managing customers needs `customer.read` and `customer.write` only, never `fiscal.read` or `company.read` (the built-in admin and member roles already hold both). Another company's rows, and a contact reached through another customer, answer 404. Every change is audited (`customer.created`, `customer.revised`, `customer_group.created`/`revised`/`deleted`, `contact.created`/`revised`/`deleted`) with the names of the fields a revision changed and never their values, because a customer or a contact may be a private person.
- [2026-09-14] DECIDED (revisit): the customers screens sit in the main section for `customer.read`: `/customers` (number, name, kind, group, city, email hidden by default, status; filters by kind and status), `/customers/new` and `/customers/{customerId}` (one form built from `customer-options`: identity, the preset's registration numbers shown for a business only and never required client-side because only the API knows whether the customer is billed at home, contact details, billing and shipping addresses, regime, default discount and one box per active tax, notes; a new customer starts as a business in the company's country under the preset's first regime), with the customer's contacts listed under the form once it exists, and `/customers/groups`. Someone with `customer.read` alone sees the form read-only and no buttons: `DescriptorForm` takes a `readOnly` input that keeps every field disabled, because its visibility rule re-enables whatever applies and a disabled form group alone did not hold. The contacts are read again after every change, since making one primary steps another down. The G2b design checkpoint's fixture customer list and form are removed now that the real screens exist; its invoice screen stays until G7.
- [2026-09-14] DECIDED (revisit): the parties chain's customer-group and customer levels are written through the one settings endpoint, naming the subject: `GET .../settings?chain=parties&customerId=` (or `customerGroupId=`, never both: 400) reads the chain as that customer sees it, its group included, and needs `customer.read`; `PUT` with `customerId` or `customerGroupId` in the body and `DELETE` with them as query parameters change the `customer` or `customer_group` level and need `customer.write`; a level named without its subject answers 422, and another company's customer or group 404. `writableLevels` offers the level of the subject read for, and only that one. A value's `level_id` is the company and the subject (`<companyId>/<groupId>`), so it can never reach another company. The settings engine stays independent of the module: it asks a `PartySubjects` port whether a group is the company's and which group a customer is in, and the customers module answers. Deleting a customer group forgets every value stored for it (`ForgetSettings`); customers are never deleted. The web shows a customer's defaults under its form and a group's while it is edited, each field starting at what the level above says.
- [2026-09-14] DECIDED (revisit): custom fields are a `CustomFields` context shared by every kind of record: `custom_field_definition(company_id, entity, field_key, label, type, required, choices jsonb, sort_order, is_active)` (migration `Version20260914120000`), a key declared once per kind of record in a company. The kinds are an enum (`customer` only; products and documents add theirs with their goals). Types are text (at most 2000 characters), number, date (`YYYY-MM-DD`), yes-or-no and choice (1 to 50 choices, each at most 80 characters). A field's key and type never change and a field is retired, never deleted. Values live on the record as one JSONB object keyed by field key (`customer.custom_fields`) and are checked on every write by `CustomFieldValues`: an undeclared key, an empty required field or a value of the wrong type is refused as `customFields.<key>` (422), a retired field's stored value is carried over and whatever is sent for it ignored, and a value resent exactly as stored is kept even after its choice was withdrawn, so an old customer still saves. Revisions audit `customFields.<key>` names, never values. Endpoints: `GET /api/companies/{companyId}/custom-fields?entity=` for every member (screens render the fields), `POST` and `PUT .../custom-fields/{fieldId}` with `company.settings`; a taken key answers 409. Web: `shared/custom-fields/` turns definitions into form fields, values and list columns; the customer form joins them in a `custom` section through `withCustomFields` and the list adds hidden columns through `withCustomColumns`; `/company/custom-fields` declares and retires them. A required yes-or-no field is not a ticked-box requirement in the form: unticked is an answer.
- [2026-09-14] DECIDED (revisit): the module registry is a `ModuleRegistry` context. A module is declared once, in code, by a `DeclaresModule` service inside its own `src/Module/<Name>/`; its manifest holds its key (lowercase letters, digits and underscores), the translation key of its name, the modules it needs and the permissions its resources check. The catalogue refuses at boot a key declared twice and a dependency on the module itself, on an undeclared module or in a cycle. § 3's "settings definitions" are not repeated in the manifest: each setting is still declared through `DeclaresSettings` and names its module. Navigation entries live in the web feature beside its routes (`customers/customers-nav.ts`), because the SPA compiles its routes and a copy in the API could only drift; the shell shows a module's entries while `Me.modules` names it.
- [2026-09-14] DECIDED (revisit): `module_state(company_id, module_key, enabled_at)` (migration `Version20260914140000`) holds a row only once a company has switched a module: without a row the module is on, and a row whose `enabled_at` is null is off. Until the licensing module gates modules by plan, every company, existing or new, has every module, and a module added later starts on everywhere without a data migration. A module goes on only while every module it needs is on and stays on while an enabled module needs it (409 naming the modules, both ways), so what is on always has what it needs. No real module needs another before G6, so the refusals are tested on a module only the test environment declares (`fixture_ledger`, needing customers).
- [2026-09-14] DECIDED (revisit): a switched-off module answers 404 through one request listener, `DisabledModuleGuard`. A resource belongs to the module declared in the same `App\Module\<Name>\` namespace, so a resource added to a module is covered without being listed, and a test fails when a directory under `src/Module/` declares no module. The 404 is the one another company's rows get, and to a member the two are indistinguishable; an anonymous caller still gets 401, so a company's modules say nothing without a session. The customers module also stops answering the settings engine about its groups and customers, so their parties-chain settings answer 404 as well; the company's custom fields for customers stay manageable, being company setup. Data is kept. Endpoints: `GET /api/companies/{companyId}/modules` for `company.read` (key, label key, dependencies, permissions, enabled) and `PUT .../modules/{moduleKey}` with `{"enabled": bool}` for `company.settings`, audited as `module.enabled` / `module.disabled` with the key; `Me` gains `modules`, the working company's enabled keys. Web: `/company/modules` in the admin section lists the modules with a switch each, a switched-off module's routes send the user home, and the signed-in state is read again after a switch so the navigation follows.
- [2026-09-14] DECIDED (revisit): products are the second module (`src/Module/Products`, key `products`, needing no other module, permissions `product.read` and `product.write`). `product_category(company_id, name, parent_id)` and `product(company_id, reference, name, description, kind, unit_id, unit_price_net, cost_price, category_id, barcode, default_tax_component_ids jsonb, custom_fields jsonb, is_active)` (migration `Version20260914160000`). A category's name is unique in its company, not under its parent, so a path never needs disambiguating; a category never sits under itself or one of its own subcategories (422 `parentId`), and it is deleted only once it holds neither subcategories nor products (409 naming both counts), which also forgets its settings.
- [2026-09-14] DECIDED (revisit): a product's reference has the customer number's shape and is unique in its company (409); its kind is `goods` or `service`; its unit price and cost price are NUMERIC(14,4) kept as strings with four decimals, from 0 to 9999999999.9999, never negative (a discount or a credit is a document's business, not a product's); the screens show a price at the currency's scale and finer only when the price itself is. A barcode is printable ASCII without spaces, up to 64 characters, and not unique (one EAN can be sold under two references). Products are deactivated, never deleted, because documents will point at them.
- [2026-09-14] DECIDED (revisit): a product's unit is one of its company's units, and a retired unit is refused unless the product already has it; its default taxes are its company's tax components of a line kind (`vat`, `levy`), a retired one kept only if already there, never a stamp or a withholding, which a document charges once (422 naming `defaultTaxComponentIds`). `GET .../product-options` gives the form the company's currency and scale, its active units and its active line taxes. Endpoints: `GET`/`POST .../product-categories`, `PUT`/`DELETE .../product-categories/{categoryId}`, `GET`/`POST .../products`, `GET`/`PUT .../products/{productId}`.
- [2026-09-14] DECIDED (revisit): custom fields gain the `product` kind of record; a field's kind of record never changes once declared (422 `entity`), like its key and its type. The web custom fields screen switches between customers and products; the products list offers a hidden column per active product field and the product form a section for them.
- [2026-09-14] DECIDED (revisit): the products screens sit in the main section for `product.read`: `/products` (reference, name, kind, category path, unit code, price at the currency scale, status; filters by kind and status), `/products/new` and `/products/{productId}` (identity, unit and prices, one box per default line tax, description, custom fields; a new product starts as goods in `C62`), and `/products/categories` (the tree as paths with its counts; a category is never offered under itself or its own subcategories). The articles settings chain's category and product levels, and their defaults panel, arrive in the next G5 slice.
- [2026-09-14] DECIDED (revisit): the articles chain's product-category and product levels are written through the one settings endpoint, naming the subject like the parties chain: `GET .../settings?chain=articles&productId=` (or `productCategoryId=`) reads the chain as that product sees it, its category included, and needs `product.read`; `PUT` with `productId` or `productCategoryId` in the body and `DELETE` with them in the query change it and need `product.write`. A request names one subject at most, whatever its chain (`customerId` with `productId` is a 400 too). The settings engine asks the products module through an `ArticleSubjects` port; switched off, the module has no subjects, and their settings answer 404 and are kept. A product inherits from the category it is filed in, not from that category's parents: the chain has one category level, so a subcategory states its own defaults. A category's settings are forgotten with the category; a product is never deleted, so its settings never are.
- [2026-09-14] DECIDED (revisit): the web articles defaults panel is a sibling of the parties one (`products/article-defaults`, `products/article-settings-facade`), shown on a product once it exists and on a category being edited, rather than one generic panel for both chains: the two differ only in chain, level and wording today, and a shared panel is extracted when the documents' own defaults make a third. A new product starts in the unit `article.default_unit` resolves to for its company, or in the first unit the company offers when that one is not; nothing in the web names `C62`.
- [2026-09-14] AGREED: a Domain entity may import `Doctrine\Common\Collections` for a one-to-many it owns (a delivery note's lines, a line's taxes), Doctrine's documented mapping, rather than a repository loading and writing children by hand or lines kept as JSON; § 3's Domain carve-out and the architecture test say so.
- [2026-09-14] DECIDED (revisit): delivery notes are the third module (`src/Module/DeliveryNotes`, key `delivery_notes`) and the first real one needing others, customers and products, so neither is switched off while delivery notes are on (the `fixture_ledger` test module stays). Permissions `delivery_note.read`, `delivery_note.write` and `delivery_note.validate`: admin has all three, member reads and drafts but does not validate, the way a member does not issue invoices. Tables `delivery_note`, `delivery_note_line` and `delivery_note_line_tax` (migration `Version20260914180000`); a number is unique in its company and null while the note is a draft.
- [2026-09-14] DECIDED (revisit): a draft names an active customer of its company and one of its establishments (none named: the default). A line names an active product or none, says what it delivers (1 to 5000 characters), a quantity above zero with at most three decimals and never finer than its unit counts, an active unit, a net unit price from 0 with four decimals, and active line taxes (VAT, levy) its customer's regime charges, each once. A line naming a product and leaving out its description, unit, price or taxes takes the product's name, unit, price and default taxes, less those the regime does not charge or the company retired; a line without a product states them all (422 `lines[i].<field>`). What a draft already names stays valid once retired. Each line tax keeps the code, rate and VAT-base flag its component had when the line was written. A draft may have no line yet.
- [2026-09-14] DECIDED (revisit): a delivery note's figures are never stored. `subtotalNet`, `taxes` (code, rate, base, amount), `totalTax`, `total` and each line's `net` are the shared calculator's on every read: prices net of tax whatever the preset's entry basis (the column is `unit_price_net`), VAT rounded where the company's preset rounds it, at its currency's scale, no discount and no document tax; a write whose lines the calculator refuses answers 422 `lines`. Endpoints behind `delivery_note.read` / `delivery_note.write`: `GET`/`POST /api/companies/{companyId}/delivery-notes`, `GET`/`PUT .../delivery-notes/{deliveryNoteId}` (409 once the note is no longer a draft) and `GET .../delivery-note-options` (currency and scale, establishments, active customers with the tax families their regime leaves out, active products with what a line starts from, active units, active line taxes with their rates). Lines travel as JSON objects checked by constraints and described by an explicit schema, not a typed list of objects, which the serializer could only build with `phpstan/phpdoc-parser` added. Audited as `delivery_note.created` and `delivery_note.revised` (field names).
- [2026-09-14] DECIDED (revisit): domain events are plain objects implementing `Shared\Domain\DomainEvent`, recorded by their aggregate (`releaseEvents()`) and published by the use case through the `Shared\Application\DomainEvents` port once its transaction has committed, never inside it. The adapter dispatches each object on Symfony's event dispatcher under its class name, so a module reacts with `#[AsEventListener(event: DeliveryNoteValidated::class)]`, and every listener has run before the request ends. The first two are `DeliveryNoteValidated` (`delivery_note.validated`: the note, company and establishment ids, the number, the issue day, and each line's product id or none, quantity and unit id) and `DeliveryNoteCancelled` (`delivery_note.cancelled`, published only when a validated note is cancelled: a cancelled draft announced nothing). A delivery announces nothing: the goods left with validation, and G10 adds an event if its stock movements need one.
- [2026-09-14] DECIDED (revisit): `POST /api/companies/{companyId}/delivery-notes/{deliveryNoteId}/validate` (`delivery_note.validate`) takes a draft (409 otherwise) with at least one line (422 `lines`). In one transaction it takes the next number of the establishment's default `delivery_note` series with the company's day as issue date (no such series, or a day before the month of the series' last number, answers 409), refuses a number another note of the company already carries (409 telling to put `{EST}` in the series' format), takes each line tax's code, rate and VAT-base flag again as they stand that day (a draft's rates are the day its lines were written; a validated note's are its issue day's), keeps what its customer is called that day (number, kind, name, legal name, identifiers, billing address, regime code and mention key, as `customer_snapshot` jsonb, migration `Version20260914200000`) and, when the note names no delivery address, fills in the customer's shipping address, else its billing one. A validation refused anywhere rolls its number back. Audited as `delivery_note.validated` with the number.
- [2026-09-14] DECIDED (revisit): `POST .../deliver` (`delivery_note.write`: whoever drafts a note hands the goods over) marks a validated note (409 otherwise) delivered on `deliveredOn`, a day from its issue day to the company's today (422 `deliveredOn`), today when left out; that day becomes its delivery date, audited as `delivery_note.delivered` with it. `POST .../cancel` (`delivery_note.validate`: whoever may number a note may withdraw one) cancels a draft or a validated note (409 once delivered or cancelled); a validated note keeps its number, which is never given again. Audited as `delivery_note.cancelled`. The three answer the note, which carries `customerSnapshot` (null until validation). There is no deletion and no return to draft: a mistake in a validated note is cancelled and drafted again.
- [2026-09-14] AGREED: the delivery note PDF is stored at issue from G6, as the 2026-09-09 ruling says, rather than when G9 arrives: the `file` table and a file storage port on Flysystem with a local volume come forward from G9. A validated note's PDF is rendered once after its transaction commits and stored; when Gotenberg could not render it then, its first download renders and stores it. A draft renders on request with a draft watermark and is never stored. G7's invoices reuse the same storage.
- [2026-09-14] DECIDED (revisit): `GET /api/companies/{companyId}/delivery-notes/{deliveryNoteId}/pdf` (`delivery_note.read`) answers `application/pdf` inline, named after the note's number (`/` becomes `-`) or `delivery-note-{id}.pdf` for a draft. A draft is rendered on every request across a `BROUILLON`/`DRAFT` watermark and a cancelled note across `ANNULÉ`/`CANCELLED`; neither is stored. A validated or delivered note answers its stored bytes, rendering and storing them first when validation could not; 503 when the renderer cannot answer. It is a plain Symfony controller, since the answer is bytes rather than JSON: the module guard now also owns a plain controller by its class's namespace, and a decorator documents the path in the OpenAPI document.
- [2026-09-14] DECIDED (revisit): what a printed delivery note shows comes from settings read for its customer: `document.language` (fr or en), `document.printed_notes`, and a new `delivery_note.show_prices` (bool, default true, parties chain, settable at company, customer group, customer and document levels; label `settings.delivery_note.show_prices`). Without prices the page keeps descriptions, quantities and units, and drops unit prices, taxes and totals. A stored PDF keeps what the settings said the day it was issued.
- [2026-09-14] DECIDED (revisit): the layout is one Twig template (`api/templates/pdf/delivery_note.html.twig`) worded from a `pdf` translation domain in fr and en, with a parity test over every API translation pair. It shows the establishment's address (else the company profile's), the company's identifiers, the customer as the note's snapshot names it, the delivery address, dates as d/m/Y, amounts through a `decimal` filter that formats the decimal string itself (at least the currency's scale, never through a float), the customer's tax mention (a mention still waiting for a parameter is left out rather than printed half-written), the printed remarks and notes, and a signature box for the recipient. No script. Gotenberg renders it on A4 with 0.4-inch margins; tests use a fake renderer that returns the page behind a PDF header.
- [2026-09-14] DECIDED (revisit): a stored file is a `file` row (company, `storage_key` `companies/{companyId}/{fileId}` unique, original name without a slash, mime, size, sha256, uploader, creation time) whose bytes are written before the row is saved, never overwrite an existing key, and are checked against size and sha256 when read (a mismatch is an error, never served). The bytes sit on a local volume through Flysystem (`FILES_DIRECTORY`, compose volume `api-files`); G9 adds uploads, their limits and an S3-compatible adapter.
- [2026-09-14] DECIDED (revisit): the web delivery notes screens sit in the main section for `delivery_note.read` (module `delivery_notes`). `/delivery-notes` lists the number (a draft says so), status, customer (the name a numbered note was issued to, today's name for a draft), issue and delivery dates and the total at the currency scale, filtered by status. `/delivery-notes/new` and `/delivery-notes/{id}` show the header as a descriptor form (customer, establishment, the default one for a new note, customer's reference, delivery date, delivery address, printed remarks, internal notes) and the lines in their own editor: a product fills a line's description, unit, price at the currency scale and default taxes less the families the customer's regime refuses; every value stays editable; a quantity finer than its unit counts is refused before sending. Figures are the API's, shown as last saved. "Validate and number" saves what is on screen and then validates it, so a note is never numbered with content other than the one shown; it needs `delivery_note.write` and `delivery_note.validate`. A numbered note is read-only and offers "Mark delivered" on a day (the company's today when left empty) and a cancellation confirmed in a second click (`delivery_note.validate`). Every saved note links its PDF, a draft's as a watermarked preview.
- [2026-09-14] AGREED: an issued credit note reduces the amount due of the invoice it corrects, as a payment does, and never takes it below zero: a credit note's amount due is at most what its invoice still has due when the credit note is issued, and an invoice brought to zero reads `paid`. Refunding an invoice already paid is outside the POC.
- [2026-09-14] AGREED: a credit note starts from its invoice's lines and document taxes, copied, and is then an editable draft: lines are lowered, removed or added (a price correction is a free line), and its figures come from the shared calculator with `credit` set.
- [2026-09-14] DECIDED (revisit): invoices are the fourth module (`src/Module/Invoices`, key `invoices`), needing customers and products but not delivery notes: a company invoices without delivery notes. Credit notes and payments belong to it. Permissions: `invoice.read`, `invoice.write` (drafts of either kind) and `invoice.issue` (numbering either kind), already seeded, plus a new `payment.write` (recording and deleting a payment), which admin has and member does not. Existing databases converge on it at the next seed, as every built-in role does.
- [2026-09-14] DECIDED (revisit), amending § 4: an invoice and a credit note are one `invoice` table with `document_type` (`invoice` or `credit_note`) and `corrects_invoice_id` (a credit note's invoice, null for an invoice), sharing `invoice_line`, `invoice_line_tax` and `invoice_tax`, rather than a `credit_note` table mirroring every column; each type keeps its own numbering series (`invoice`, `credit_note`), and a number is unique per company and type. `discount_rate` on the document is dropped: a document discount is an amount (the calculator's), a line discount a rate.
- [2026-09-14] DECIDED (revisit): a draft invoice names an active customer and an establishment (none: the default), optional supply date, `paymentTermsDays` (0 to 365; null: the customer's `document.payment_terms_days` at issue), customer reference, printed notes, internal notes, a document discount amount at the currency's scale (0 up to the lines' net), document taxes and lines. A line is a delivery note line plus `discountRate` (0 to 100, three decimals; null: none, the screen prefills the customer's default). Document taxes are fixed charges and withholdings, each once; left out (null), they are the company's active default document taxes (TIMBRE) with the customer's own default document taxes (RS1 for a withholding payer), less the families the customer's regime refuses. Prices are net of tax, VAT is rounded where the preset rounds it, as for delivery notes.
- [2026-09-14] DECIDED (revisit): a draft's figures are the calculator's on every read; issuing freezes them. At issue each line's `line_net`, `line_tax` and `line_gross` and the document's `subtotal_net`, `discount_amount`, `total_net`, `tax_breakdown` (code, rate, base, amount), `total_tax`, `fixed_taxes` (code, amount), `total_gross`, `withholding_amount` and `amount_due` are written, and an issued document is answered from those columns, never recomputed: a tax, a rounding rule or a price changed later changes no issued document. `amount_paid` and `amount_credited` start at zero; an invoice's amount due is `total_gross − withholding_amount − amount_paid − amount_credited`.
- [2026-09-14] DECIDED (revisit): `POST .../invoices/{invoiceId}/issue` (`invoice.issue`) numbers a draft with lines from its establishment's default series of its type, in one transaction, with the company's day as issue date (the 409s of delivery notes apply: no series, a day before its last month, a number already given). It snapshots the customer (`CustomerSnapshot` moves to the Customers domain), the language (`document.language` for the customer), the footer (`invoice_footer_text`), the mentions (the regime's mention, the company VAT regime's mention, the preset's `mentions.invoice`, with the company's late penalty text when it has one), and the due date (issue day plus the terms). It records `InvoiceIssued` (with the delivery note lines its lines came from) and stores the PDF after commit as delivery notes do. A draft is cancelled (`invoice.write`); an issued document never is. Statuses: `draft`, `issued`, `partially_paid`, `paid`, `cancelled`; a credit note is `draft`, `issued` or `cancelled`.
- [2026-09-14] DECIDED (revisit): `POST .../invoices/from-delivery-notes` (`invoice.write`, and 404 while the `delivery_notes` module is off) drafts an invoice from one or more validated or delivered notes of one customer, copying their lines with `source_delivery_note_line_id` and their taxes; a note already on another invoice that is not cancelled answers 409. The notes turn `invoiced`, with `invoiced_by_invoice_id`, when that invoice is issued (a delivery notes listener on `InvoiceIssued`), so a cancelled draft strands none; an invoiced note is neither delivered nor cancelled.
- [2026-09-14] DECIDED (revisit): a payment (§ 4 `payment`, plus `recorded_by` and `created_at`) is recorded on an issued invoice (409 on a draft, a cancelled invoice or a credit note) by `POST .../invoices/{invoiceId}/payments` with a day from the issue day to the company's today, an amount above zero and at most the amount due (422 `amount`: an overpayment is refused), a method (`transfer`, `cash`, `check`, `card`, `other`), a reference and notes; `DELETE .../payments/{paymentId}` removes one. Both are audited and rederive the status: nothing paid or credited `issued`, zero due `paid`, otherwise `partially_paid`.
- [2026-09-14] DECIDED (revisit): `POST .../invoices/{invoiceId}/credit-notes` (`invoice.write`) drafts a credit note for an issued invoice (409 otherwise) from its lines and document taxes; issuing it (`invoice.issue`) refuses (422 `amountDue`) an amount due above what its invoice still has due, then adds its amount due to the invoice's `amount_credited` and rederives the invoice's status in the same transaction.
- [2026-09-14] DECIDED (revisit): the numbering e2e stops renumbering the invoice series, which G7 numbers: it changes the format only and expects the next number the series already had, which a numbered series still accepts.
- [2026-09-14] AGREED: the design is raised by a deep restyle that keeps Angular Material and CDK underneath (accessibility, i18n and the tests stay valid) and replaces their look; the approved G2b patterns are its starting point, not its limit. Evidence: `var/claude/design-audit/` (every screen at 1440 and 390 px) and `var/claude/design-research-2026-09-14.md` (106 sourced ideas).
- [2026-09-14] AGREED: the design serves the owner-manager first: a small-business owner who invoices, chases payments and checks cash on a laptop and sometimes a phone. Home answers money owed, overdue and what to do next; comfortable density by default; lists become cards on a phone.
- [2026-09-14] AGREED: new product ideas are pitched now, challenged and sorted into design system (with the restyle, before the invoice screens), after the POC, or parked; the run's order G7 → G10 → G8 → G9 does not move for them.
- [2026-09-14] AGREED: G10 ships `stock_location` as a tree (a parent and a kind: site, building, floor, zone, rack, bin) so a drawn map of a site can come after the POC as a purely additive module over it, without migrating stock rows. Visual location maps exist elsewhere (CyberStockroom, SKU Savvy, Mappedin); the map is not a differentiator on its own.
- [2026-09-14] AGREED: a café and restaurant capability is its own bounded context switched on in the module registry, never parameters that branch the existing workflows; it is recorded after the POC, behind quotes and e-invoicing, and no code starts before a legal check of cash-register rules (France NF525, Tunisia). The market is crowded (Odoo POS Restaurant, Lightspeed, Toast; in Tunisia eFactureTN, TNPOS, Innova Soft, Hesabi).
- [2026-09-14] AGREED: a shared venue-layout context is the seam for anything drawn: a company's sites, their floors, shapes and placeable markers. Consumers bind markers to their own records (inventory to stock locations, a future restaurant module to tables with a capacity), so a company can draw a storage site and a dining room side by side and neither domain owns the drawing. Nothing is built until a consumer is scheduled.
- [2026-09-14] AGREED: the restyle follows "quiet ledger": a calm, number-led visual language (warm neutrals, tabular numerals, colour for status and the one primary action), with cockpit affordances inside lists and the shell (status tabs with counts, row actions, column totals, command palette, peek) and a live PDF preview beside the document editor. The shell itself (settings access, a collapsible sidebar, the notification centre) is brainstormed before it is built.
- [2026-09-14] AGREED: a business profile (retail, food service, or both) is an onboarding preset only, like the fiscal preset: chosen when a company is created and editable later, it writes module switches, navigation order, defaults and home widgets once. No code reads the profile; code only asks whether a module is switched on.
- [2026-09-14] AGREED: company settings move behind a gear in the top bar into a settings area with its own grouped sub-navigation (company: profile, establishments, numbering; fiscal: taxes, units; team: members; customisation: custom fields, modules, appearance), shown to `company.settings`. Customer groups and product categories become tabs of Customers and Products, so the sidebar keeps the daily entries only. A person's own choices (language, scheme, density) stay under the avatar as their preferences.
- [2026-09-14] AGREED: the sidebar has three states: expanded, an icon rail with tooltips, and a drawer on a phone. The desktop choice is a person's presentation setting and is toggled by a button and the `[` key; a screen such as the document editor may ask for the rail.
- [2026-09-14] AGREED: notifications open in a panel from the right, grouped by day, each entry with an icon, a relative time and a link to its record, an unread marker, all/unread tabs and mark-all-read. The feed is built around money and document events (invoice paid, overdue, payment recorded, delivery note delivered) rather than team events, and each person chooses per notification type in-app, email or off through the settings engine.
- [2026-09-14] AGREED: the accent a company picks shows as picked: the runtime theme builds its system colours with a scheme that keeps the accent's chroma (`SchemeFidelity`) instead of `SchemeTonalSpot`, which rendered `#1f6feb` as a muted slate.
- [2026-09-14] AGREED: the design canvas (static mockups: shell expanded and as a rail, notification panel, invoice list, invoice editor with its PDF preview, customer page, home, phone) comes first. The G7 backend, then the formatting and colour basics (fr-TN amounts and dates, the accent, status badges), then the shell rework follow without waiting for its review. The invoice screens wait for the developer's review of the canvas.
- [2026-09-14] DECIDED (revisit): issuing an invoice (migration `Version20260915100000`) stores the frozen document discount in `document_discount`, since `discount_amount` already holds the amount a draft states; the mentions as `mentions_snapshot` jsonb holding the translation keys (customer regime, company VAT regime, the preset's `mentions.invoice`, each once) and the company's late penalty text verbatim, rendered in the snapshotted `language`; the footer as `footer_snapshot`. Every invoice answer adds `dueDate`, `language`, `customerSnapshot`, `mentions` (keys), `latePenaltyText`, `footer`, `amountPaid` and `amountCredited`; an issued document's figures are read from its columns and written at the currency's scale. `paymentTermsDays` left out on a draft is written back with the customer's `document.payment_terms_days` at issue, so the due day and the terms agree. `InvoiceIssued` carries the invoice, company, establishment, type, number, issue day and an empty list of source delivery note lines until the conversion endpoint exists.
- [2026-09-14] DECIDED (revisit): an invoice's or credit note's PDF (`GET .../invoices/{invoiceId}/pdf`, `invoice.read`, migration `Version20260915110000` adding `pdf_file_id`) is one Twig layout, `api/templates/pdf/invoice.html.twig`, worded from the `pdf` domain: the company and the customer as the delivery note prints them, the title (invoice or credit note, with the invoice it corrects), issue day, due day, supply day, customer reference and terms, lines with unit price, discount rate, taxes and net, then subtotal, document discount and net when there is one, each tax over its base, total tax, each fixed charge, total, each withholding over its base and the amount due; then the mentions (a mention still waiting for a parameter is left out), the late penalty text, the printed notes (the document's and `document.printed_notes`) and the footer. It is stored after the issue commits by a listener on `InvoiceIssued`, and served as stored; an issued document prints the language, mentions and texts issuing kept. A draft, and a cancelled draft, render on request under a watermark with the mentions and texts they would be issued with today, never stored.
- [2026-09-15] DECIDED (revisit): payments landed before the delivery note conversion, because they stay inside the invoices module and carry its money state that credit notes then share. `POST .../invoices/{invoiceId}/payments` answers the payment (201: id, date, amount, method, reference, notes, recordedBy, createdAt) and `DELETE .../payments/{paymentId}` answers 204; the invoice lists its `payments` by day and answers the amounts and status they leave, so a client reads the invoice again. Both lock the invoice's row (`SELECT … FOR UPDATE`) in the transaction that writes, so two payments recorded at once never both fit what was due. The audit rows are on the invoice (`payment.recorded`, `payment.deleted`) with the payment's id, day, amount and method. Every stored amount keeps three decimals (the column's); the currency's scale refuses a finer amount and shapes what is read. An issued credit note is not yet refused a payment by type: no credit note can be issued until its endpoint lands, which adds that 409 with its test.
- [2026-09-15] DECIDED (revisit): `POST .../invoices/{invoiceId}/credit-notes` (201, the draft) copies the invoice's establishment, customer, header, lines and document taxes; a draft, a cancelled draft or a credit note answers 409, and so does a payment on a credit note. The draft is revised like an invoice, except that its customer stays the invoice's (422 `customerId`). A credit note charges every tax its invoice charged as the invoice charged it (code, rate, VAT base behaviour, amount, threshold), when drafted, revised and issued, so a tax changed since the invoice never makes the correction disagree with it; a tax the invoice did not carry is taken as it stands, as on any document. Issuing locks the corrected invoice with the series, refuses (422 `amountDue`) a credit note that comes to more than the invoice still has due, adds what it comes to to the invoice's `amount_credited` and audits `invoice.credited` on the invoice (credit note id, number, amount). Credits and payments settle an invoice together; deleting a payment keeps what was credited. A stamp duty copied onto a credit note is charged back unless the draft removes it: whether a Tunisian credit note carries the stamp is not yet sourced in docs/fiscal/TN.md.
- [2026-09-15] DECIDED (revisit): `POST .../invoices/from-delivery-notes` (`invoice.write`, 201, the draft) takes `deliveryNoteIds` and belongs to the delivery notes module, the one place both sides meet: an invoice knows a delivery note only by the id of the line it invoices (`invoice_line.source_delivery_note_line_id`) and a note its invoice only by id (`delivery_note.invoiced_by_invoice_id`), neither a foreign key, so the dependency points one way; it answers 404 while either module is off. The notes' rows are held (`SELECT … FOR UPDATE`, in id order) while the draft is written, so two drafts never share a note. 422 on `deliveryNoteIds`: none, one named twice, one that is not the company's, notes of two customers, or of two establishments (the invoice is numbered in one establishment's series); 409: a note that is not validated or delivered, or already on an invoice that is not cancelled. The draft copies the notes' lines in the order they were numbered, with their taxes and no discount; its supply date is the last delivery date the notes name, its customer reference the one they share (none when they differ), its document taxes the defaults, and it is audited `invoice.created` with the notes' ids. A revision keeps or drops where a line came from but never adds a delivery note line (422 `lines[n].sourceDeliveryNoteLineId`), a document invoices a delivery note line once, and a credit note copies none. Once the invoice is issued its notes turn `invoiced` (listener `MarkInvoicedDeliveryNotes` after the commit, audited `delivery_note.invoiced` with the invoice's id and number); a note that can no longer be marked is logged and left. A note on an invoice that is not cancelled is not cancelled (409), so issuing meets a cancelled note only when one was cancelled while its draft was being written, which the cancellation does not wait for. The web delivery notes screens name the new status.
- [2026-09-15] DECIDED (revisit): figures are written in the interface language for the company's country (`fr-TN`, else the language alone): amounts grouped and punctuated by the locale at the currency's scale ("2 975,000"), their digits never passing through a float; calendar days as dd/mm/yyyy, not "15 sept. 2026"; moments with a 24-hour time in every language, since ICU writes fr-TN times as "10:00 AM". Inputs, request bodies and list sorting keep the API's own strings ("2975.000"); formatting happens only where a screen shows a figure (the `amount`, `day` and `moment` pipes). The fiscal settings pages still show rates and stamp amounts raw, until the invoice screens. Statuses show as badges in five tones computed as the design canvas computed them (`--twes-status-<tone>-{bg,fg,dot}`): the accent-following tone is named `accent`, not the canvas's `blue`, because it is red under a red accent; a delivery note's draft is neutral, validated the accent, delivered amber (it waits for its invoice), invoiced green and cancelled red; an active customer, product or custom field is green and an inactive or retired one neutral. Material's neutrals stay tinted by the accent; the warm neutrals (hue 75) proposed by the quiet-ledger canvas were never reviewed, that direction having been withdrawn on 2026-09-16, and the page itself is archived in `docs/history/2026-09-artifacts.md`. *(Amended 2026-09-20: "the canvas" named the withdrawn page, which has since been deleted.)*
- [2026-09-15] DECIDED (revisit): the settings area keeps every address (`/members`, `/company/profile`, `/fiscal/taxes`…): it is a layout route with no path of its own, so bookmarks and links survive, and its grouped navigation (company: profile, establishments, numbering, defaults; fiscal: taxes, units; team: members; customisation: custom fields, modules) sits beside each page. The gear shows to whoever may open at least one settings page and opens the first of them, not to `company.settings` alone, because members need only `user.read` and would otherwise lose their way to the member list. The page at `/settings` holds the company's defaults, so it is named "Valeurs par défaut" in the company group; the canvas's "appearance" page, a company's accent and look, is not built. The company switcher stays in the top bar (§ 3); the design checkpoint's development-only screens stay last in the sidebar. Whether the gear lights up on a settings page is not built: the area has no address prefix to match.
- [2026-09-15] DECIDED (revisit): the notification centre is a Material dialog pinned to the right edge, full height and 26rem wide (the whole width on a phone), opened by the bell, which keeps the realtime connection; Escape, the focus trap and the focus going back to the bell come with the dialog. Entries are grouped by the day they fall on in the company's time zone (Aujourd'hui, Hier, then the date), each with its type's icon, its words, how long ago (the exact moment as a tooltip) and an unread dot named for screen readers. All and unread are two tabs over one list. An entry links to its record when its type has one in this company and the person holds the permission that opens it; following the link reads the entry and closes the panel. Today only `invitation.accepted` links (to the members), since `membership.added` concerns another company. The money and document event types and the per-type in-app/email/off preferences are backend work, not built with the panel; each new type adds one row to `notificationRecord` in `web/src/app/notifications/notifications-types.ts`.
- [2026-09-15] DECIDED (revisit): G10 is the `inventory` module (`src/Module/Inventory`), depending on products only; it is on by default like every module, and its permissions are `stock.read` (members and admins) and `stock.write` (admins). Stock is never stored: what is on hand of a product at a location is the sum of its `stock_movement` rows (signed DECIMAL(14,3), kind in, out or adjustment, a source type and id), so it may fall below zero and nothing reconciles. Only goods whose `article.stock_tracking` resolves to true take movements. A person records a receipt (a positive quantity) or a count, which stores the difference from what is on hand even when it is nil, so the count itself is on record; a quantity finer than the product's unit is refused (422). Transfers between locations are deferred. Movements write no `audit_log` row: they are the record.
- [2026-09-15] DECIDED (revisit): `stock_location` is the tree ruled on 2026-09-14. Each establishment has exactly one default location (a partial unique index), a site carrying the establishment's code and name, created the first time anything needs it, including the list read. It sits at the top and is never deleted; a location given no parent sits under it, a parent must be of the same establishment and no cycle is allowed; a code is unique in its establishment (409); a location is deleted only once it holds no location and no movement (409). Endpoints: `stock-locations` (list, create, revise, delete), `stock-levels`, `stock-movements` (latest 200 of the company, or one product's with `productId`; a POST with `operation` receive or count) and `stock-options` (active products whose stock is kept, the establishments).
- [2026-09-15] DECIDED (revisit): a delivery note moves stock through its domain events, never through a call from the delivery notes module. `delivery_note.validated`, while the company has inventory on, takes each tracked product out of its establishment's default location, one movement per product summing its lines; a line counted in another unit than its product's, or with no quantity, moves nothing and is logged as a warning, since no unit converts into another. Notes validated before inventory was switched on are not backfilled. `delivery_note.cancelled` returns exactly the movements its validation wrote, whatever the tracking or the module say since. Both are idempotent, by an `ofSource` check and a unique index on source, product, location and kind, so a replayed event moves nothing.
- [2026-09-15] DECIDED (revisit): G8 is the `vendors` module (`src/Module/Vendors`), depending on no other module and on by default; its permissions are `vendor.read` (members and admins) and `vendor.write` (admins). A vendor has a number the company types, unique in it (409), a name and a legal name, contact details, one postal address (in the company's country when none is given), an IBAN and a BIC kept uppercase without spaces, payment terms from 0 to 365 days and notes; it is deactivated, never deleted, and a revision is audited with the names of the fields it changed, never their values. Its registration numbers are those its company's preset knows, each checked for shape and check digits, and none is required: a supplier's number is recorded from its invoice, never demanded before buying from it.
- [2026-09-15] DECIDED (revisit): § 4's `vendor.default_expense_category_id` arrives with G9, which creates `expense_category`; G8's table has no such column. Nothing reads `payment_terms_days` until expenses do.
- [2026-09-15] DECIDED (revisit): G9 is the `expenses` module (`src/Module/Expenses`), depending on vendors and on by default; its permissions are `expense.read` (members and admins) and `expense.write` (admins). An expense is a draft until it is recorded, which needs a category, and a recorded expense is paid once, on or after its own day and no later than today, by one of the invoice payment methods; only a draft is revised or deleted. The tax is one active percentage rate on the net, its rate kept on the expense, the amount rounded to the currency's scale; a net finer than that scale is refused. A vendor, category or tax deactivated after an expense named it stays on that expense. The due date is the vendor's payment terms counted from the expense's day and is not stored. Every change is audited (`expense.created`, `revised` with the changed field names, `recorded`, `paid`, `deleted`, `attachment_added`, `attachment_removed`).
- [2026-09-15] DECIDED (revisit): `expense_category` is a tree per company: a name unique in the company (409), a parent of the same company, no cycle, deactivated and never deleted. A vendor's `default_expense_category_id` fills an expense's empty category when that vendor is chosen, and never replaces a category already chosen.
- [2026-09-15] DECIDED (revisit): an attachment is an `attachment` row (company, `entity_type`, `entity_id`, `file_id`) over a stored `file`. A record holds at most 10; a file is 1 byte to 10 MB and a PDF, PNG, JPEG or WebP image, its type read from its bytes, never from its name or header (422). nginx and PHP accept a little more (12 MB) so the API answers the size itself; above that nginx answers 413. A receipt may be attached to an expense in any status, a late receipt being common; only a draft loses one, and deleting a draft removes its attachments. Files are sent as a multipart part named `file` and read back through plain controllers, still behind the module guard.
- [2026-09-15] DECIDED (revisit): S3-compatible storage is `FILES_STORAGE=s3` with `FILES_S3_BUCKET`, `FILES_S3_REGION`, `FILES_S3_ACCESS_KEY`, `FILES_S3_SECRET_KEY`, and optionally `FILES_S3_ENDPOINT` (empty for AWS) and `FILES_S3_PATH_STYLE`. The adapter is `league/flysystem-async-aws-s3` (MIT, with `async-aws/s3` and `async-aws/core`, MIT, on the Symfony HTTP client already in use) rather than `league/flysystem-aws-s3-v3`, which brings the whole AWS SDK. A missing setting refuses to build the filesystem, so the client never looks for credentials elsewhere. Local storage stays the default, and compose has no S3 service.
- [2026-09-15] RAISED: `company.logo_file_id` (§ 4; 2026-09-13) did not arrive with G9, whose scope was expenses. Recommendation: store the logo as a `file` with an `attachment` of `entity_type` `company`, with the PDF template that prints it, as a small step after G1d.
- [2026-09-15] DECIDED (revisit): G1c's libraries are `spomky-labs/otphp` for TOTP, `lean-qr` for the enrolment QR code and `web-auth/webauthn-lib` 5.3.8 for passkeys, each LICENSE read and recorded through `make notices`. The WebAuthn library is pinned at `^5.3.8`: 5.3.9 did not resolve against the rest of the lock file when the dependency was added.
- [2026-09-15] DECIDED (revisit): recovery codes are stored as SHA-256, not argon2id as the 2026-09-10 entry's "the way passwords are" suggested: each is a long random code, not a chosen secret, so a slow hash protects nothing a fast one does not, and finding the code offered stays one indexed lookup.
- [2026-09-15] DECIDED (revisit): a passkey is a full second factor beside TOTP. The relying party asks for no attestation, requires user verification, accepts ES256 and RS256 and prefers a discoverable credential; its id and origins are configuration (`APP_WEBAUTHN_RP_ID`, `APP_WEBAUTHN_ORIGINS`), never the request's host. A ceremony's options live in the session under one purpose (registration, login, recovery) and are spent by the first attempt, a failed one included. The first factor of either kind issues the recovery codes; removing the last factor is refused only where a company requires one.
- [2026-09-15] DECIDED (revisit): the recovery codes are replaced against a current authenticator code or against a passkey, so an account whose factors are passkeys is not left without the ruling's "regenerable with a current factor"; both paths spend the account's second-factor budget of five attempts.
- [2026-09-15] DECIDED (revisit): the passkey Playwright scenario drives Chromium's virtual authenticator and addresses the stack as `localhost`, because Chromium refuses an IP address as a relying party id.
- [2026-09-15] DECIDED (revisit): G1d enforces `company.status`, which until now was recorded and never read. A company that is not active, pending an operator's approval or suspended by one, is closed to its members in both places access is decided, `CompanyGuard` and `PermissionVoter`, and answers them the same 404 as a stranger; operators still reach it, which is how they approve it. The session still describes the company, with its status, so the application can say why.
- [2026-09-15] DECIDED (revisit): signup is two steps and email first. `POST /api/signup` takes an address and always answers the same 202; the link it mails is single use, expires, and is stored as a SHA-256 like an invitation's. The far end of the link takes the name, the password (breach-checked), the company name, its country (one with a fiscal preset) and its timezone, then makes the account, the company and the owner membership. An address that already has an account is mailed a note saying so instead, so the answer never tells anyone whether an address is registered. Finishing starts no session: the person signs in, as after an invitation.
- [2026-09-15] DECIDED (revisit): with `signup.approval_required` on, a signed-up company stays `pending` and its owner, once signed in, sees that it waits for approval. An operator approves it (`active`, and the owner is mailed) or rejects it, which suspends it: the ruled statuses have no rejected state, and a suspended company keeps its name taken, which stops the same signup being retried under it.
- [2026-09-15] RAISED: the ruling of 2026-09-13 opens the platform level of the settings chains with the operator's screen at G1d. G1d's operator screen carries the platform's own settings, `signup.enabled` and `signup.approval_required`, in a platform chain of one level; no declared setting of the three business and presentation chains allows the platform level yet. Recommendation: open those chains' platform level when a platform default is first wanted (default plan, product name, mail identity), which is a declaration change per setting and needs no new screen.
- [2026-09-15] DECIDED (revisit): operators decide on companies at `GET /api/platform/companies` (`?status=pending|active|suspended`, 400 on any other; each with its owners' addresses), `POST /api/platform/companies/{companyId}/approve` and `.../reject`, all behind `platform.company.approve`. Approving an active company, or rejecting a suspended one, changes nothing, mails nobody and audits nothing; otherwise `company.approved` or `company.rejected` is audited against the company with its previous status, and approving mails every owner in their own language a link to sign in. Rejecting mails nobody, and rejecting an active company suspends it, which is how an operator suspends one. The web page is `/platform`, reached from an operator's home page rather than the sidebar, whose entries are filtered by company permissions an operator's scope sits outside; it carries the two signup switches and the waiting companies.
- [2026-09-15] AGREED: the one three-lens review panel ran when the POC worked, before Quotes, against `db8aa5f` (domain correctness, tenancy and security, completeness; reports in `var/claude/poc-review/`, gitignored). `CLAUDE.md`'s timing wins over the place § 2 gave the panel, which is amended.
- [2026-09-15] AGREED: adding an address that already has an account invites it like any other address: a mail and a notification with a link to accept. `POST /api/companies/{companyId}/members` always answers `invited` and names no user; the member list shows an open invitation without a name; nobody is a member of the company, nor held by its MFA requirement, until they accept.
- [2026-09-15] AGREED: operators hold what § 3 Tenancy names and nothing more: the platform endpoints, creating companies, inviting owners and deciding on companies. A company's business data, settings and members answer an operator as they answer a stranger (404). An operator account must carry a second factor.
- [2026-09-15] AGREED: the tenancy layer § 3 rules is built: a Doctrine SQL filter scoping every company-owned entity to the session's company (off where no company applies: signed out, platform endpoints, console and listeners that name their company), an API Platform extension, and an architecture test that every company-owned entity carries `company_id`. The explicit company checks in repositories stay as the first layer.
- [2026-09-15] AGREED: a credit note of an invoice that carried a withholding withholds at the invoice's rate whatever its own total, so the credit notes of an invoice add up to it. `docs/fiscal/TN.md` records the rule as one to confirm against its source.
- [2026-09-15] AGREED: G7 is reopened: its API is done, its screens are not. The invoice, payment and credit-note screens, their Playwright scenario and WCAG checks, and the French `operation_category` and `vat_on_debits` columns are built after the review panel's fixes and before Quotes.
- [2026-09-15] AGREED: the POC's "email" is transactional mail (invitations, signup, approval). Sending an invoice, credit note or delivery note to its customer is a goal of its own, after the invoice screens.
- [2026-09-15] AGREED: the AGREED home page answering money owed and overdue, the list upgrades and per-type notification preferences were not built with row 16; they are named goals after the POC (§ 2), the home page and lists after the invoice screens, the preferences with document email.
- [2026-09-15] DECIDED (revisit): a numbering series that starts again prints the period it starts again in: a yearly series carries `{YYYY}` or `{YY}`, a monthly one `{MM}` with `{YYYY}` or `{YY}`, and creating or revising one without is refused on `format` (422). Otherwise the first number of a new period is one the series already gave, and every document of that period is refused as taken (POC review D4). A series already stored is not rewritten; it is refused at its next revision.
- [2026-09-15] DECIDED (revisit): documents are read with `FOR UPDATE` inside the transaction that changes them, and their status is checked before a number is allocated, so a double submit changes a document once and takes no number (POC review D1). The money and state rules of invoices, credit notes, payments, delivery notes and expenses are also PostgreSQL CHECK constraints (`Version20260915200000`, POC review D11).
- [2026-09-15] AGREED: only a platform operator ends an account's sessions or deactivates and reactivates it, audited, from a users section on `/platform`. A company owner or admin removes someone from the company instead: removal ends that company's access at once, its realtime channel included, and never touches the person's other companies.
- [2026-09-15] DECIDED (revisit): who grants and removes which role in a company follows the roles' order, owner above admin above member (POC review S2). An owner grants and removes any role, never the last owner; an admin grants member or admin and removes members only; nobody else manages members. A refusal is 403.
- [2026-09-15] DECIDED (revisit): an existing account accepts an invitation from its mailed link like any address, with nothing to fill in: the link proves the address, and the password is never asked or touched. `GET /api/invitations/{token}` says whether the address has an account, so the page asks for a name and a password only to create one. Inviting an address that has an account also tells it in the application, with an `invitation.received` notification naming the company and the role and carrying no link: notifications are stored and shown to whoever holds the session, and the token lives in the mail alone. Inviting someone already in the company is refused (409) before anything is sent. An open invitation cannot be withdrawn yet; inviting the address again replaces it.
- [2026-09-15] DECIDED (revisit): operators manage accounts at `GET /api/platform/accounts?q=` (part of an address or a name, any case, in address order, 50 at most), `POST /api/platform/accounts/{userId}/end-sessions`, `.../deactivate` and `.../reactivate`, all behind `platform.account.manage`; the page is the Accounts section of `/platform`. Ending the sessions rotates the account's security stamp, so each of its sessions ends on its next request; deactivating rotates it too and refuses the operator's own account (409); asking for the state an account is already in changes and audits nothing. `account.sessions_ended`, `account.deactivated` and `account.reactivated` are audited against the user with the operator as actor and no company. A new password also rotates the stamp, so every session opened with the old one ends (POC review C3).
- [2026-09-15] DECIDED (revisit): an operator opens a company and invites its owners from the Companies section of `/platform` (POC review C7). `POST /api/companies` opens it pending, as before; `POST /api/platform/companies/{companyId}/owners` (`{email}`, behind `platform.company.create`) sends the owner's invitation, the one any member gets, audited `invitation.sent` against the company with the operator as actor: 201 `{email, role: owner, status: invited}`, 409 for an address already in the company, 404 for an unknown company. The operator is never a member of it; accepting opens the company. The section lists every company with its status and owners, each with its own owner field, so a company whose owners are gone gets a new one the same way. The form offers the countries with a fiscal preset (TN, FR) and opens a company with that country's currency, French and the country's time zone.
- [2026-09-15] DECIDED (revisit): an operator carries a second factor the way a member of a company that requires one does (POC review S3): `MfaRequirement` holds for every platform operator, so an operator without one is sent to enrol and every other API path answers them 403 `mfa_enrolment_required`. `app:seed --operator-totp-secret=<base32>` enrols the seeded operator's authenticator when they have none, confirmed on a step already past so a code for the moment the seed ends is unspent; without it the operator enrols their own at first sign-in. `make seed` and the CI e2e job pass a fixed development secret. Every code check counts against `mfa_verify` (five per account in five minutes, successes included), so the Playwright run signs the operator in with a code once, in a `setup` project that saves the session (`web/e2e/operator.setup.ts`, `web/e2e/session.ts`), and the scenarios reuse it; only the sign-in scenario and the company switcher open sessions of their own. Functional tests enrol every operator `createUser` makes, and `login()` answers the code step for them.
- [2026-09-15] DECIDED (revisit): `CompanyGuard` no longer lets a platform operator through (POC review S3), so a company's endpoints answer an operator by membership alone, 404 as for a stranger otherwise, pending or not. This amends the 2026-09-15 G1d entry's "operators still reach it, which is how they approve it": deciding runs through `/api/platform/companies`, opening a company through `POST /api/companies` and its owners through `/api/platform/companies/{companyId}/owners`, none of which passes the guard. `PermissionVoter` already scoped `platform.*` to operators and everything else to memberships. The seeded operator keeps reaching Demo as its owner.
- [2026-09-16] DECIDED: the company filter (review S5, C2) amends § 3 Tenancy and the 2026-09-15 agreement above. `CompanyFilter` (`orm.filters.company`, off by default) adds `company_id = :company` to every query on an entity implementing `App\Shared\Domain\CompanyOwned`, `find()` included. `CompanyGuard::companyForActing` turns it on with the company it has just resolved, after its own lookups, and `CompanyFilterLifter` turns it off at `kernel.terminate`: signed-out requests, the platform endpoints, `/api/auth/*` and the console never turn it on. It follows the company in the path rather than the session's: `CompanyGuard` is already the one place a company is resolved, and the SPA builds every company path from the session's company (`company-api.ts` and its sibling `*-api.ts` files), so the two never differ for it. No API Platform query extension is built: every resource is a plain object with its own provider and processor, so API Platform's Doctrine providers, the only place such an extension runs, never run; `tests/Architecture/ApiResourceStateTest` keeps it so (no resource is an entity, no operation names a Doctrine provider, processor or `stateOptions`). Every entity carries `company_id` except company, user, signup, passkey, recovery code and customer tax regime (`tests/Architecture/CompanyColumnTest`, read from the Doctrine mapping); invoice lines and their taxes, invoice taxes, payments, delivery note lines and their taxes gained it in `Version20260915210000`, filled from their document. Every entity with a non-null `company_id` is `CompanyOwned` except membership, read across an account's companies; role, setting, audit log and inbox item hold a nullable one for the rows no company owns and are not scoped. The explicit company conditions in repositories stay the first layer.
- [2026-09-16] DECIDED: the unauthenticated sweep (review C12) is `tests/Functional/UnauthenticatedSweepTest`. It walks the router rather than the OpenAPI document, so the plain controllers (platform, auth, second factor, PDFs, attachments) count as much as API Platform operations, and sends every `/api` route and method with no session but with the CSRF header, which `CsrfRequestListener` checks before the firewall: each answers 401 but the paths `security.yaml` opens to everyone, which must not answer 5xx. The test writes those public paths out and checks they are exactly the firewall's `PUBLIC_ACCESS` rules, so opening a path to everyone is also a change to the test.
- [2026-09-16] DECIDED: the 2026-09-15 withholding agreement above is `TaxInput::withholdingAsCharged` (review D2). `InvoiceTotals` reads the withholdings the corrected invoice's issued figures name: a credit note carries each of them at the rate its own tax states and no threshold, and leaves out a withholding its invoice did not charge, whatever the credit note's own lines come to. An invoice is unchanged. The credit notes of an invoice therefore add up to it as far as the currency rounds, the remainder being a known issue; the stamp every partial credit note copies (review D3) stays unscheduled, so a second credit note leaves it out by hand. `docs/fiscal/TN.md` § 5 records the rule as unvalidated.
- [2026-09-16] DECIDED: a wrong second factor counts towards the account lockout (review S6). The flag on `FailedLoginAttempt` is `wrongCredential`, because a wrong password and a wrong code are both guesses at one login: five of either lock the account for `app.auth.lock_duration`, and a login that succeeds clears the count as it always did. `SecondFactorLockout` refuses a locked account at `/api/auth/mfa/verify` and `/api/auth/mfa/recovery-codes` with 401 `account_locked`, tested BEFORE the `mfa_verify` limiter consumes: `UserChecker` never runs on the first, where by design no session exists yet, and at both the two budgets are five, so a lock tested second would answer `too_many_attempts` on the very request that ought to name the lock. Regenerating recovery codes takes the same lock although its session is signed in, the guesser not necessarily being its owner. A limiter paces guessing and never stops it: six digits with a step of leeway either way is three codes in a million, which the limiter's own budget reaches inside a year.
- [2026-09-16] DECIDED: the development stack publishes its backing services to this machine alone (review S11). `compose.yaml` binds postgres 5433, gotenberg 8094 and mailpit 8092 and 8093 to `127.0.0.1`; web 8090 and api 8091 stay on every interface, being what a browser and Playwright open. Nothing else had to change: every consumer already addressed them at `127.0.0.1` (`api/.env`, `web/e2e/mailpit.ts`), and CI's postgres is a service container on the runner rather than this file. `tests/Architecture/ComposePortsTest` reads the compose file rather than a list of services, so one added later is covered without being named, and it refuses to pass on a file it has stopped reading.
- [2026-09-16] DECIDED: production refuses to start on the committed development keys (review S10). `api/.env` carries working values rather than placeholders, and `compose.yaml` defaults the containers to the same literals: that is what lets a clone run with one command, and exactly why they are public. `App\Shared\Infrastructure\DevelopmentKeys`, called from `Kernel::boot()` before the container is built, throws naming the one variable at fault when `APP_ENV` is `prod` and `APP_MFA_KEY`, `REALTIME_TOKEN_KEY` or `REALTIME_API_KEY` still holds the committed literal, or when any of those or `APP_SECRET` is empty — `api/.env` ships `APP_SECRET=`. It is a refusal rather than a warning because nothing would go wrong visibly: the instance would simply run, signing and encrypting with keys the world already holds. Development and the test suite are meant to run on those values, so the check is scoped to production, and `DevelopmentKeysTest` pins both directions — a production kernel boots on keys of its own, and the test environment still boots on the committed ones. The keys are not rotated: the seeded operator's authenticator is encrypted under `APP_MFA_KEY`.
- [2026-09-16] DECIDED: two consequences of the second-factor lock above are scheduled rather than carried with it (§ 8 rows 26 and 27). `MfaController` counts every `SecondFactorRefused` as a wrong guess, and `VerifySecondFactor` raises that same exception for a secret the current `APP_MFA_KEY` cannot read: rotating that key would therefore lock every enrolled account five attempts in, out of the password step as well, for a refusal no code could have answered — row 26 narrows it. A replayed code keeps counting: it is a guess at a code someone else held. And the lock now answers every wrong code before the limiter, both budgets being five, so no run of wrong codes reaches `mfa_verify` at either of its two sites in that controller: with both `consume()` calls removed the suite stays green (16 tests, 176 assertions, measured), which is row 27 — only a run of CORRECT codes can reach that limiter, so only such a run can cover it.

 - [2026-09-16] DECIDED: the screens several goals added are walked against WCAG in both colour schemes (review C8), and the contrast defect that walk appeared to find was REFUTED by its own sabotage. `accessibility.spec.ts` carries a table of the eleven screens no axe assertion had ever reached — each was visited by its feature's scenario only AFTER that scenario's last assertion, or by one making none — and asserts the route, a visible heading and the scheme on every screen, softly, so one bad screen cannot hide the eight behind it. `/invitations/:token` and `/products/:id` are asserted in `company.spec.ts` and `products.spec.ts` instead, where the token and the saved id already exist. One real defect: the notification centre's close button had NO accessible name, because `MatDialogClose` host-binds `attr.aria-label` from its own `aria-label` input and so overwrote the template's `[attr.aria-label]` with null — measured as absent on the live element, not merely empty; it takes the input now, and it is the only site in `web/src` where that directive and an attribute binding met. The tab-label contrast the walk reported was NOT one: pointing `--mat-tab-*-label-text-color` at the system roles measured WORSE than Material's own dark values (inactive 10.92 against 14.42), so the change was withdrawn rather than kept. Both the wandering screen and the phantom violation were one cause — eighteen axe analyses overrunning the 30s default, tearing the page down mid-analysis — which is why the test now sets its own timeout and forgets the scheme INLINE after its last assertion, never in an `afterEach`: that would also run after the signed-out tests, where `/api/auth/me` answers with no `company` key at all and the guard against a null company is passed `undefined` instead.

- [2026-09-16] DECIDED: what day it is in a time zone is answered in one place, `web/src/app/shared/i18n/format.ts` (§ 8 row 24). The expense screen proposed the BROWSER's day for an expense's date and its payment, which the API refuses with 422 to anyone whose own day has turned ahead of the company's — Paris after midnight, a company in `Africa/Tunis` — while the notification centre grouped by the company's day correctly, because it carried its own `dayKey`. That function MOVES rather than being copied: `notifications` imports it now, its tests move with it, and `todayIn` is built on it, which is why the row's Files cell names `web/src/app/notifications/**` as well as the two globs it was filed with. Duplicating it would have left two implementations of one rule, and two implementations of that rule is precisely the drift that produced this defect. `todayIn` answers the viewer's own day while the company has not been read yet, and for a zone that is no zone at all, where `Intl` throws a `RangeError` — the case `formatLocale` already answers the same way. The known issue and the CLAUDE.md lesson that both told a reader to wait out a red `expenses.spec.ts` between 00:00 and 01:00 Paris are deleted with the defect they described: kept, they would have explained away a real regression.

- [2026-09-16] DECIDED: the credit notes of a withheld invoice add up to it exactly, the one completing its base absorbing what the others rounded (§ 8 row 25). It supersedes the review D2 ruling above, whose `withholdingAsCharged` and whose "as far as the currency rounds" remainder it replaces. Each credit note worked its withholding out on its own total and rounded it there, so two halves of an invoice withheld 6.010 twice against the 12.019 the invoice withheld itself, and that thousandth stayed due however many were issued. Which note is "the last" cannot be known in hindsight, because issued figures are never recomputed (§ 7, 2026-09-14): it is decided at THIS note's issue, from frozen figures alone — the invoice's withholdings carry their base, so a note completing that base takes what is left of the invoice's amount rather than a rounded share of its own, while one leaving part of the base uncredited rounds as before. The remainder is clamped at nothing, because a credit note may carry lines its invoice never had: past a base already taken in full, a correction rounds its own share as any document does. `TaxInput::withholdingAsCharged` is REPLACED by `withholdingCompleting`, which carries that remainder, rather than joined by it, having had one caller. An invoice keeps the credit notes correcting it on both sides instead of leaving the inverse side to Doctrine to fill on a load, because what a correction withholds is decided from its siblings and an invoice never loaded has none of them — the foreign key and `idx_invoice_corrects` already exist, so nothing is migrated. The unit test that pinned the old arithmetic as correct is replaced, not joined: two tests asserting 0.001 and 0.000 of one case are a contradiction rather than coverage. `InvoicesTest` moves to a 505 line so the HTTP path exercises the absorption instead of a residue-free pair whose halves happen to round to nothing. The known issue describing the thousandth is deleted with the defect it described; the stamp defect beside it (D3, a partial credit note copying its invoice's whole fixed charges) is untouched and stays open.

- [2026-09-16] AGREED: the "quiet ledger" direction (2026-09-14) is withdrawn. After testing the running app the developer judged it dated rather than calm, and asked for a look that is simple and easy to use yet clean, modern, vivid and durable, where every section, format and animation earns its place. Before any restyle is coded, three contrasting directions are drawn on one design canvas over the same screens (home, invoice editor with its PDF, invoice list) with their tokens and motion, and discussed; the invoice screens keep waiting for that choice. The canvas: https://claude.ai/artifact/5N1tDVLyZXCBoGKJCU6wQd (A Prisme, colour-led; B Relief, depth and motion; C Registre, type and density). Work with no visual surface (§ 8 rows 26, 27 and 29) continues alongside.
- [2026-09-16] AGREED: of the three directions the developer rejected C (Registre) and asked for one final design between A (Prisme, status colour carries meaning) and B (Relief, layered depth with the PDF as the hero object), researched against existing invoicing tools, and shown complete before any restyle: vivid, easy to see and use, informative, extensible as modules are added, and responsive down to a phone. Anything added to that brief must not weaken one of those requirements. The final design is on page 1 of the same canvas, https://claude.ai/artifact/5N1tDVLyZXCBoGKJCU6wQd (desktop home, invoice list with detail panel, editor with live preview, delivery notes with the command palette, four phone screens, components, rules and the tool comparison; the three directions moved to its Explorations page); the restyle waits for the developer's verdict on it.
- [2026-09-16] AGREED: the developer approved the final design as drawn (canvas page "Design final", https://claude.ai/artifact/5N1tDVLyZXCBoGKJCU6wQd). The restyle starts with the shared tokens (fixed status tones, the company accent for action, selection and focus with its ink chosen for contrast, layered surfaces, the motion scale) and the shell (bottom bar below 600 px, icon rail to 1199 px, labelled rail above, Ctrl K palette modules register commands in), then the invoice screens, while functional rows keep moving. A second design round may follow once the developer has tested and validated every screen.
- [2026-09-16] DECIDED: the approved design's first part is built (§ 8 row 30), and three of its rulings are product rules. (1) A status takes one of six fixed tones named for a meaning — neutral, info, warning, success, danger, purple — drawn at fixed OKLCH lightness (`statusTokens(scheme)` in `web/src/app/shared/theme/accent-theme.ts`) and never the company accent, which marks actions, selection and focus; this supersedes the 2026-09-13/14 quiet-ledger status tones, where `accent` was itself a tone and `green`/`amber`/`red` named colours. (2) The shell is decided by window class: below 600 px a bottom bar with the first four destinations and a Plus button opening the drawer, from 600 to 1199 px a rail of named icons, from 1200 px the labelled rail; `presentation.sidebar` and the `[` key remain as the person's override at 1200 px and above only. (3) A module extends the Ctrl K palette the way it extends the navigation: it declares `*_COMMANDS` beside its `*_NAV`, gated through the same `Gated` shape (permission, module, development build), and every visible navigation entry is a "go to" command without any module declaring it.
- [2026-09-16] AGREED: every page takes the new design, not only the shell and invoices. Sign-in (login, second factor, awaiting approval, signup, finish signup, accept invitation) and settings have no approved mockup, so one mockup round covers those two families in light and dark, desktop and phone, with variants where the layout is a real choice; lists, record forms and home are built from the approved mockups without another round. Order: the sign-in and settings mockups are published first, and the shared lists and forms are restyled while the developer reviews them; then sign-in, settings and home on the chosen variants, then one visual review of the whole, then invoices (row 10).
- [2026-09-16] DECIDED: the mockups for row 32 are published as the page "Connexion et réglages" of the approved canvas (https://claude.ai/artifact/5N1tDVLyZXCBoGKJCU6wQd, version 3; the approved "Design final" artboards are unchanged). Sign-in: variant A, a centred panel on the tinted backdrop (recommended), and B, the form beside a brand side; both carry the installation's brand and the default accent, since no company is known before sign-in, and the G0 health line becomes "Service opérationnel". The second-factor step names why it appears and shows the passkey and recovery code as choices, a proposed copy change. Settings on a desktop keep today's two panes restyled; on a phone, A is the section list then one section with a back arrow (recommended), B is today's chip row over the form. Row 33 owns the shared list and form components, so the settings pages built on them (members, taxes, units, establishments, custom fields) change with it; row 35 owns the settings frame and the settings pages that are not lists.
- [2026-09-16] AGREED: sign-in follows variant A (centred panel) with a product scene behind it: real twes-in cards (an invoice, a payment received, a stock level, a delivery) drawn from the app's own components, following light and dark and the accent, no image files; an installation may upload its own background, light and dark. The phone settings take variant A: the list of sections, then one section full screen with a back arrow. The colour scheme gains Automatique, which follows the device and is the default, beside Clair and Sombre; every sign-in page carries a Mode clair / Mode sombre switch the browser remembers, and the person's own setting wins after sign-in. Branding is an installation brand kit, set by the platform operator on one page: product name (it may change), tagline in each language, logo for light and dark, app icon, default accent and sign-in background, read everywhere the name appears today (page title, sign-in, mails, PDF footer, authenticator and passkey labels); "Facturation pour petites entreprises" is dropped, as the product serves any business. The font is not configurable. A company's own brand (logo, accent, PDF colours) applies inside its space. Per-company sign-in, signup and invitation pages are wanted and scheduled after the POC, since they need a per-company address; the brand kit is resolved through a port so that level is added, never rewritten, within the hexagonal layering of § 3. Of the mockup extras: the second-factor step says why it appears and shows the passkey and recovery code as choices; settings pages get an unsaved-changes bar with Ctrl S and a warning before leaving with unsaved edits; an invoice preview from the company profile comes with the invoice screens (row 10); no settings filter, Ctrl K already reaches every section.
- [2026-09-16] AGREED: the developer validated the sign-in family and settings mockups of the page "Connexion et réglages" (second-factor step, signup, awaiting approval, authenticator activation, settings two panes with the unsaved-changes bar, phone settings variant A). Not validated, reopened for a direction round: the sign-in scene's content, which read as one coffee shop and must be generic or follow a business profile; the logo, judged generic and unclear, with a rename to stay painless and the logo replaceable, possibly later sold as an option to a business wanting its own; and the tagline, none of the four kept.
- [2026-09-16] AGREED: brand direction. twes-in should read as trust, clarity and power. The logo is a wordmark now, set from the product name, with a letter tile generated from the name for the app icon and favicon, so a rename updates both with no redraw; a drawn symbol waits until the name is final. A rename is data in the brand kit, never code; what a rename cannot spare is the web address (passkeys are bound to it) and the label existing authenticator apps show until re-added. The tagline takes the angle of the result and of simplicity. The sign-in scene must not read as one trade: generic by default, with business-profile scenes as a later brand-kit option. Per-company branding (own address, logo, colours, e-mails, no "powered by" footer) is an entitlement the operator sets per plan, paid or free, built with row 38.
- [2026-09-16] RAISED: the developer may later ship the app wrapped by FrankenPHP "so the source is not accessible". Licensing, a one-way door (CLAUDE.md): the public edition is AGPL-3.0-or-later, whose users are owed its source, including over a network (§ 13); as sole copyright holder the developer can distribute a closed build only under the commercial licence, and PHP files embedded in a FrankenPHP binary remain extractable, so it is packaging, not protection. To be decided with the developer before any such build; nothing is built on it.
- [2026-09-16] AGREED: brand round closed for now, open to revisit at the developer's request. The sign-in scene is the generic one (cards from mixed businesses, no trade named). The logo is the Angle wordmark: the product name in Manrope 800, lowercase, the hyphen muted, the dot of the last "i" replaced by a rising accent arrow; what a name without an "i" carries is settled when row 34 builds the wordmark (the mockup drew none), and the icon and favicon are the name's first letter with the arrow on an accent tile, all computed from the name so a rename redraws nothing. The shipped tagline sits between the two the developer kept: "Facturez sans paperasse. Soyez payé." / "Invoice without the paperwork. Get paid.", a brand-kit value like the rest. Sign-in is built against a brand port whose first adapter serves these defaults, so row 36 swaps the adapter for the platform settings without touching a page.
- [2026-09-16] DECIDED: row 33's rulings. Material's own fields, buttons, tables and cards are restyled through its override mixins in `styles.scss` (40 px outlined fields, 36 px buttons, 10 px corners, flat cards), so every page follows without template churn. A descriptor form puts each label above its field, tied to the control, with "optional" inside the label, and each section takes an optional explanation shown beside its title. A list's filters are pill choices with counts taken over what the text filter and the other filters leave, never the whole list, so a number never promises rows the click cannot deliver; the counts are exact only because a list holds all its rows in the page, and a server-paged list must drop or re-source them. Left out of the approved list on purpose: the row checkboxes (no bulk action exists), the sort drop-down (the sortable headers already do it, accessibly) and shortcut hints for shortcuts that do not exist.
- [2026-09-16] AGREED: the tagline is "Tout en lieu sûr. Tout tracé. Rien ne se perd." / "All kept safe. All on record. Nothing lost.", replacing "Facturez sans paperasse. Soyez payé." from the brand round, which the developer rejected on seeing it: the documents are printed, so "without paperwork" promises something false. It says what the product assures a business: everything in one place and safe, every change traced, anything recoverable. Still a brand-kit default, configurable like the name.
- [2026-09-16] DECIDED: row 34's rulings. Every page shown before the shell (sign-in and its code step, second-factor setup, awaiting approval, signup, finishing signup, an invitation) wears one layout, `auth/signed-out-layout`: the wordmark and tagline over one card, and a scene of five business documents behind it from 1024 px, hidden from assistive technology and drawn from translations. The name and tagline come through the `Brand` port (`shared/brand/brand.ts`), whose default adapter holds the chosen values; row 36 replaces the adapter. The wordmark is computed from the name: lowercase, Manrope 800 (vendored under `public/fonts/manrope`, OFL-1.1), joiners muted, the dot of the last "i" replaced by the accent arrow, and a name without an "i" carries the arrow over its last letter. The text is bound, never interpolated, because an interpolation's surrounding whitespace draws as a gap inside the name, and the formatter reintroduced it once. The code step says why it is asked for and that a recovery code works in the same field; the passkey is offered as another way. Left for later on purpose: the Auto / Clair / Sombre switch (row 37), a show-password toggle, and the name in the signed-in shell (row 36).
- [2026-09-16] AGREED: the sign-in scene follows the modules a deployment has switched on: each module contributes its own card, so a store map or a table order appears the day that module exists and nothing is redrawn; a sign-in page never shows a feature the product does not have. Built with the brand kit (row 36). The shipped wordmark and tagline stay until the rebrand below.
- [2026-09-16] AGREED: the brand speaks to businesses with a place (§ 1, "Who it is for"). A full rebrand — name, then symbol, then tagline, in that order, starting from the five jobs of § 1 and the one promise they share — runs once the POC is usable: after the invoice screens (row 10) and the settings and home restyle (row 35), before any public launch. The name twes-in is open in that round and kept only if it fits: it is an acronym of its founders' names that the developer judges does not ring well. A rename stays brand-kit data; what it cannot spare is the web address passkeys are bound to. Row 39 is deferred into that round.
- [2026-09-16] AGREED: composite products come after the POC, immediately before the café and restaurant module (§ 2). Its goal starts with unit conversion: a unit gains a dimension and a factor to its base, added as columns without migrating stock rows; until then the POC keeps "no unit converts into another". The first cut is made-to-order recipes and menus or kits, nested, with a dish's cost computed from its components' cost prices; prepared batches with their own stock and per-sale options (size, extras) come later, added on top. Shape: a product's components (component, quantity, unit) belong to the `Product` aggregate, which refuses a product containing itself at any depth; Products expands a sold quantity into what it consumes, and Inventory reads that through a port it owns rather than Products' domain. Only delivery notes and the café module's orders take stock out; an invoice never does, so an invoiced delivery note cannot count twice. The café module (tables, orders, waiters, on-site or to go, deliveries) is ruled in its own design conversation before it is planned.
- [2026-09-16] AGREED: the architecture audit of `7a84e6b` (`var/claude/architecture-audit-2026-09-16.md`, static, no finding reproduced) is triaged. Before the invoice screens (row 10): stock integrity (row 40) and one commit per command with its audit row (row 41), then web boundary lint rules (row 42). A delivery note's stock stays an in-process event after the commit, made fail-safe: the handler catches, logs and notifies whoever holds `stock.write`, and a repeatable reconcile command replays a validated note that moved nothing; no outbox. `Money` and `Quantity` are built with the composite products goal, and § 3 says so meanwhile. The medium and minor findings (spec rules the code no longer matches, the clock bypass in Identity, the Identity and Tenancy cross-calls, delivery notes depending on invoices undeclared, settings levels hardcoded in core, naming, infra duplication, `Invoice.php` size) are row 43, after the POC. Developer's standing request: every goal's completion note states the § 3 layering check.
- [2026-09-16] DECIDED (revisit): the invoice screens (§ 8 row 10) follow the approved canvas where the API already answers and depart from it where it does not, rather than drawing what nothing behind them does. Kept: the list with status tabs and their counts, where *En retard* is an issued or partly paid invoice whose due day is before the company's today (derived on screen, not an API status, and counted apart from *Émises*); the amount still due beside the total; the editor's header, lines with product, quantity, unit, price, discount and taxes, notes, the totals as the API works them out, and the issue action; an issued invoice's amount due, payments (record, delete) and credit note. Departures: the detail is a page (`/invoices/{id}`), not a side panel beside the list (the panel and peek are list upgrades after the POC, § 2); the button is *Émettre*, not *Émettre et envoyer*, and *Relancer*, *Envoyée à* and *Envoyée par e-mail* are not shown until sending a document is built (§ 7, 2026-09-15); *Consultée par le client* waits for a portal; *Activité* waits for an audit read endpoint; the PDF preview is the PDF of the draft as last saved, opened on demand, not redrawn while typing, and saving is explicit, not automatic; converting delivery notes starts from one note's page (*Facturer*), choosing several at once is a list upgrade. The canvas's static `/design` invoice editor is retired with this row, as the 2026-09-13 ruling foresaw.
- [2026-09-16] DECIDED (revisit): the French `operation_category` and `vat_on_debits` fields leave row 10 for a row of their own (§ 8 row 44), after the settings and home restyle (row 35) and the colour scheme (row 37), instead of before the invoice screens as the 2026-09-15 ruling placed them. The screens were what stood between the developer and a usable POC, and the company it is tested in is Tunisian, which prints neither field. The fields raise two questions that row answers before building: whether VAT on debits is an option of the company (beside its VAT regime) or of an establishment, and whether a document's category is chosen, derived at issue from its lines' products (goods, services), or both, since a line without a product has no kind. Row 10's walk also found that the modules scenario switched invoices off and never back on, and that the accessibility walk's URL check could match before a module guard redirected; both are fixed.
- [2026-09-16] DECIDED (revisit): the home page (§ 8 row 35, part 1) takes its figures from a new API read model, `GET /companies/{companyId}/invoice-summary` (`Module/Invoices/Application/SummarizeInvoices`, read with `invoice.read`, 404 while the module is off), never from sums in the browser: every amount stays the API's own decimal string. Worked out on the company's day: to collect is what issued and partly paid invoices still have due, a credit note counting only through the invoice it corrects; late means due before today, so due today is not late; the aging buckets are 1–15, 16–30, 31–45 and over 45 days; to chase are the late invoices, latest first, then those due within 7 days, four shown; collected is the payments by the month of their day, six months; VAT is the VAT-family taxes of documents issued this month, credit notes netting out, labelled "TVA facturée" because it is invoiced VAT, not cashed VAT. Every invoice is read to sum them, which a company with years of invoices will outgrow (a repository projection). The web side is a home manifest (`shell/home-manifest.ts`): a module declares its panel beside its navigation (`INVOICES_HOME`), gated the same way and loaded lazily, so `hello` imports no feature. Departures from the canvas: no *Relancer* action, no sent or viewed state and no activity feed, since nothing behind them exists; no *Premiers pas* checklist (its logo and colour step waits for the brand kit, row 36); no *Personnaliser*; no "12 % vs août" comparison; the invoice count per bucket is not drawn. Open for the developer: whether VAT should be shown on the basis the company declares on (invoiced or cashed), the 7-day window for invoices to chase, and where the activity feed would read from (the audit log is the candidate).
- [2026-09-16] DECIDED (revisit): the settings frame (§ 8 row 35, part 2) follows the canvas "Réglages" and phone variant A of row 32. The settings navigation carries a "Paramètres" title and a "Filtrer les réglages" field that matches every word against a setting's name and its group's, ignoring case and accents, and hides empty groups. Below the large window class the list and a page take turns: `/company`, a new child of the settings layout, shows the list alone, the gear opens it on a phone, and each page offers "Tous les paramètres" back to it; on a wider window `/company` asks to pick a setting beside the list, and the gear still opens the first page. The chip row that stood for the list on a phone (variant B) is gone. Departures from the canvas, left to the pages that are forms: no sticky save bar with a count of unsaved changes, no Ctrl S, no "Aperçu d'une facture" beside the company profile; the section descriptions already come from the shared form (row 33).
- [2026-09-16] AGREED: the developer's review after row 35, ruled through two rounds of questions. (1) The scheme switch Automatique · Clair · Sombre is on every screen, signed in and signed out: an icon button showing the current mode that opens a three-choice menu with a check; signed out the browser remembers it, signed in the presentation chain does. (2) The language leaves the account menu for the top bar as a menu naming each language in itself with its code ("FR · Français"), never a flag, because a flag is a country and Arabic has no single one. Language and scheme fold into the account menu below the medium window class, and on a phone settings live there too. (3) The bell shows the unread count (9+ above nine) from the inbox's existing `unread`, with a label saying it. (4) The top search is wider and centred from the large class, an icon opening Ctrl K below. (5) Settings are reached from the top bar and from the sidebar. (6) Every clickable control has an accessible name, and an icon-only one a tooltip drawn from that same name, enforced by a test; nothing is explained only in a tooltip. (7) Each screen declares its actions once; the toolbar, Ctrl K, keyboard shortcuts (AZERTY-safe, ignored while typing, never a key the browser reserves), the tooltip hint and a "?" sheet read that declaration. The design is re-measured in both schemes, the dark one never having been checked by eye. Build order: row 37 widened, row 45, row 46, rows 38 and 36, row 47, then row 44.
- [2026-09-16] AGREED: a tax's family (its fiscal nature: VAT, levy, stamp, withholding, which regimes exclude and documents snapshot) and its kind (the arithmetic) are decoupled: the preset states both and validation refuses an incompatible pair, instead of `TaxFamily::kind()` fixing one kind per family. The family `stamp` is not renamed; "Droit de timbre" is preset data, and the form names the arithmetic generically. A minimum or maximum amount on a percentage is added the day a sourced tax needs it, with its `docs/fiscal/` entry and calculator vectors, never speculatively (§ 8 row 46).
- [2026-09-16] AGREED: a company's own address (subdomain, later a custom domain) resolves the company before sign-in through a public endpoint keyed on the host that answers only what a sign-in page may show: name, logo, colours, languages and the sign-in texts. Anyone may sign in there; a member lands in that company and the switcher stays. Without a resolved host, platform defaults apply. Row 38 is brought forward and built with the brand kit (row 36).
- [2026-09-16] AGREED: texts are changed through sparse overrides over the shipped catalogues, never full copies: platform, then company, per language; no role or user level. The editor searches a text, shows the shipped one, stores an override and resets it, and marks an override whose shipped text changed since. An override keeps exactly its original's placeholders (refused otherwise), is plain text, and never touches the `fiscal` legal mentions. Screen texts first, then email and PDF texts; new languages are added at platform level. The web loads the shipped file then the overrides, replaces (never merges) its catalogue on a change so a reset shows the original again, and a realtime message reloads every open screen of the company (§ 8 row 47). Angular's compile-time i18n cannot do this; ngx-translate stays.
- [2026-09-16] DECIDED (revisit): row 37 as built. The scheme is `presentation.scheme` with `auto` added and made the default in the API declaration and the web registry, stored rows of `light` and `dark` staying valid; `ThemeFacade` keeps `preference` (the choice) apart from `scheme` (what is drawn), following the device's `prefers-color-scheme` through a `DEVICE_SCHEME_QUERY` token while the choice is Automatique. The interface language becomes `presentation.language` (fr, en; French by default), so the browser remembers it before sign-in and the presentation chain after, and `LanguageFacade` applies whatever that setting answers; before this row the choice was lost on reload. The account's own `User.locale`, which the API uses for mails and option labels, is not moved by it: the two can disagree until they are reconciled in row 43 (§ 8 Known issues). The controls are `shared/theme/scheme-menu` and `shared/i18n/language-menu`, in the top bar from the medium window class and on every signed-out page, folded into the account menu on a phone with the settings link; each language is named in itself with a `lang` attribute. Every icon-only control is named through the `appLabel` directive (`shared/a11y/label`), which sets the accessible name and a tooltip from one string; `scripts/gates/icon-buttons-named.sh` refuses a Material icon button without it and the e2e walk refuses any wordless control without a tooltip. The bell's badge moved from Material's small size, which draws a bare dot and hides its content, to medium, showing 9+ above nine. The search is a field from 1200 px and an icon below, centred on the bar from 1440 px; narrower, the controls beside it need more than half the bar and it gives way rather than lying under them (33 px off centre at 1280, 73 at 1200), and the account shows its name only from 1600 px so the controls fit. The sidebar ends with one Paramètres entry.
- [2026-09-16] AGREED: the developer asks for one feedback system: a lean loader while data loads, a clear state when the API cannot be reached and while it is retried, a message when a request takes unusually long, and toasts for success and failure with clear wording, so the application is never silent about what it is doing (§ 8 row 48, built right after row 37).
- [2026-09-17] DECIDED (revisit): row 48's foundation, in two commits (this one, then the sweep of every screen's inline "saved" line onto toasts). Every API request passes `activityInterceptor` (`shared/feedback/`), which reports to `RequestActivity`: a thin bar at the top of the window after 300 ms (never for a quicker answer, so nothing flickers), "taking longer than usual" after 8 s, and, when no answer arrives at all (status 0) or a gateway answers for the API (502, 503, 504), a notice "Le service est momentanément injoignable. Nouvelle tentative dans N s." with a Réessayer button, checking `/api/health` after 2, 4, 8, 16 then every 30 s; the first answer from the API clears it and says "Connexion rétablie". The browser going offline shows its own notice first. A 401 outside `/api/auth/` means the session ended while the page was open: the shell signs the person out and opens `/login?expired=1`, which says why. A request the person did not wait for carries the `SILENT` context (the notifications poll and realtime token, a presentation preference written as they click): it never shows the bar, but its failure still counts. `SettingsApi.change`/`reset` take an explicit `quiet` flag rather than silencing every settings write, so a form's save still shows. Outcomes go through the `Feedback` port (`MaterialFeedback`): success is announced politely and leaves after 4 s, failure is announced at once and stays until closed. A refused field or a domain rule stays beside its form, because a toast goes away and an error must not. Two structural moves: the health client moved from its own feature folder to `shared/health`, since the shell's feedback now depends on it and `shared` may import no feature; and ESLint's HTTP allowance widened from `*-api.ts` to `*-interceptor.ts` and `app.config.ts`.
- [2026-09-17] DECIDED (revisit): row 48's sweep. Every outcome a screen used to keep as a line under its title (twenty "saved" lines, and the member invited, the stock movement recorded, the payment recorded and the company invited) is now a success toast with the same translation key, so a line no longer lingers after the next edit; creating a customer, vendor, product, expense, delivery note or invoice, and deleting an expense, now say so too, the toast surviving the navigation that follows. A state change (issue, validate, cancel, remove) still speaks through the page it changes; its toast comes with row 45, where each action is declared once. The shell also forgets, when it opens, a session end that arrived after its last redirect, so several refused requests cannot send a fresh sign-in straight back. Refusals stay beside their form. `scripts/gates/outcomes-as-toasts.sh` refuses any `role="status"` element whose test id is not one of the page states it lists (the session that ended, the sign-up request sent, the slow request, the empty palette search). Axe now reads a page through one `e2e/axe.ts` helper that waits for a toast Material has just opened to reach its live region: Material draws the toast's content under `aria-hidden` for 150 ms, close button included, and a scan in that moment reported `aria-hidden-focus` on a state nobody can reach.
- [2026-09-17] AGREED: the developer's rulings on live data, confirmations, account security and legal pages, through three rounds of questions. (1) Everything any member of a company does reaches every open tab of every member live: the API publishes, after the transaction commits, only what changed (kind, id, who) to the company's channel (or the user's, for what is theirs), and a browser reloads it through the API under its own permissions, so it never receives data it could not read. (2) A record another person changes while I edit it merges per field: fields I have not touched take the new value with a brief highlight, a field we both changed shows "Leur version" with Prendre la leur / Garder la mienne, invoice and delivery note lines count as one field shown side by side, and a banner names who and when; a save still wins last (a version check comes later). (3) Another person's change announces itself only where I am: a toast on the record or settings page (a banner while I edit), a brief highlight and a "N nouveaux" chip on a list, silence elsewhere; my own outcomes keep their toasts. (4) Actions are classed once (row 45): a dialog stating the consequence for what is irreversible, fiscal or reaches other people (issue, cancel, a payment deleted, validate, deliver, record in books, numbering, the two-factor requirement, a module off, a member removed or a role changed, a passkey or the last factor removed, recovery codes regenerated, a company approved or rejected, an account deactivated, every device signed out); immediate with an Annuler toast backed by a server-side bin for a reversible deletion; nothing for create and edit; and a guard before leaving unsaved changes. (5) Requiring two-factor opens a dialog naming who is affected with a deadline of 0, 7 (default), 14 or 30 days; until then those members see a dated banner, after it enrolment is forced; the owner must hold a factor first; operators stay immediate. A factor cannot be set "automatically" for someone, since it is their device. (6) Account security (row 50): change password and forgot password, connected devices with sign-out of one or all others, offered after a password or factor change; session lengths set by the platform as defaults and ceilings, a company may only shorten them; deactivating an account always ends its sessions, never optionally; a member removed loses the company in their open tabs at once. (7) Legal (row 51): mentions légales, CGU accepted at signup with version and date, privacy policy (the ten-year invoice retention, Tunisia's INPDP declaration), a data processing agreement with its sub-processors, the AGPL § 13 source link, the open-source notices, a cookies and storage page; later the CGV with paid plans, security.txt, a status page. Pages are operator-edited, per language, versioned, shipped with a draft marked for a lawyer's review, never presented as legal advice. (8) No cookie banner: the CNIL's guidelines (deliberation 2020-091, art. 82) exempt authentication and interface-choice storage, which is all twes-in stores; a CI gate fails on any cookie, storage key or third-party script not on the page's list, and the consent banner (refuse as prominent as accept) arrives with the first non-exempt tracker. Build order: row 49 now, then 45 (widened), 46, 38 and 36, 47, 50, 51, 44; the milestone ends after 44 with the parked questions.
- [2026-09-17] AGREED: the developer's second round of rulings the same day. (1) Every tool is used as its official documentation recommends (Symfony, PHP, Monolog, API Platform, Doctrine, Angular, TypeScript and the rest); a departure is a recorded decision, and a gap found is a fix. (2) Logs, aligned with Symfony's logging guide and Monolog's own advice: production containers write JSON to STDERR through `fingers_crossed`, with the channel in each line, and Docker's log driver rotates every service (10 MB × 5); self-hosting without containers writes one file per channel (app, request, security, doctrine, realtime, mail, deprecation, php) rotated by a shipped `logrotate` configuration, because Monolog calls its rotating handler a workaround; development writes one file per channel through `rotating_file` (14 days) and tests keep errors only (row 54, after row 49). (3) Fiscal presets become editable in the app (row 52, after row 46): the operator edits each country preset in Plateforme, versioned with a source note, applied to new companies and offered to existing ones; an owner or admin with `fiscal.write` edits the company's copy (taxes, units, numbering, establishments, its own mentions, regime labels, optional identifiers); law-bound values (rounding, VAT point and basis, mandatory mentions and identifiers, VAT regimes, the establishment code format, document languages) sit in an owner-only "Zone sensible" that names each rule and its risk, asks for the company's name typed, is audited, shows "Préréglage modifié" with "Revenir au préréglage", and applies to future documents only, each document keeping the rules it was issued under. Seven hard limits stay closed there: the currency, its decimals and the country once any document exists, issued and validated documents, the number counter going back or renumbering, deleting a tax or unit a document uses (archive instead), and the audit log. The YAML files remain the shipped defaults. (4) A sole owner already does every job in one session: the owner role holds `*`. Custom roles come as row 53, after row 50: the owner builds roles by ticking permissions from templates (Comptable, Commercial, Magasinier, Lecture seule), one role per member, the owner keeping `*` and the last owner never demoted. (5) Workflows become options per module, never a designer (an invoice approved by another person before issue, stock moved at validation or delivery, expense approval above an amount, quote expiry), with legal steps not switchable; the next milestone. (6) Foreign currencies are the first item of the next milestone: the company keeps one reference currency, each document carries its own (EN 16931 BT-5) and at issue records the rate, its date and source (BCT, ECB, or manual with a reason) and its VAT total in the reference currency (BT-6, BT-111); a payment in another currency keeps what was received and the day's rate, settles the invoice at the converted amount, and records the exchange gain or loss. Once that exists, the reference currency changes through a guided change with an effective date (the next financial year), a conversion preview, typed confirmation and an audit, cancellable until then, and decimals follow ISO 4217; a change of country is "Créer une société à partir de celle-ci". Whether a Tunisian company may invoice a Tunisian customer in a foreign currency is unverified and waits for an accountant; until then the TN preset allows foreign-currency documents for foreign customers only. Build order: 49, 54, 45, 46, 52, 38 and 36, 47, 50, 53, 51, 44.
- [2026-09-17] AGREED: the developer's rulings after the scale and operations review (`var/claude/scale-audit-2026-09-17.md`, 36 findings: every business list returned whole and paged in the browser, the home figures reading every invoice, stored files never deleted, logs never rotated, forms downloading whole tables, stock levels re-summed from all movements, no audit index or retention, no backup, realtime publishing inside the request, no worker, scheduler or error reporting). Each choice was compared against its alternatives and the tools' own guidance. (1) Pagination by list type (row 55): business lists (invoices, customers, products, vendors, expenses, delivery notes, stock) page with a total through API Platform's paginator, 25 by default and 100 at most, switched to partial pagination per list should one company pass about 100 000 rows, since the count is the documented cost; histories (audit, stock movements, notifications) use cursor pagination, whose cost does not grow with depth and which stays stable as rows arrive; bounded reference lists (taxes, units, establishments, numbering, modules, custom fields) come whole, capped at 500; page, sort and filters live in the URL. Offset was kept for business lists because accountants jump to a page and read a total, and one company's rows sit on its own index. (2) Filters are API Platform `QueryParameter`s, as its documentation recommends over `ApiFilter`; a `q` search runs on PostgreSQL `pg_trgm` and `unaccent` with GIN indexes over names, numbers, addresses and tax identifiers, from three characters (an exact number sooner), because what is searched here is fragments of short codes, which full-text search does not match; full-text search comes later for long text, and no search engine is run. (3) Customers, products and vendors are picked in forms through server-side autocomplete (a 300 ms pause, 20 results, the recently used first); taxes and units stay preloaded. (4) Screen texts stay one compressed file per language (62.8 KB, 14.9 KB gzipped on 2026-09-17), named with a content hash for long caching, with a CI budget of 60 KB gzipped per language beyond which they split by module; Angular's built-in i18n was refused because it builds one application per language and cannot switch language or apply overrides at runtime. Row 47 widened: the browser merges one ETag-cached request holding only the overrides (platform, then company, one per existing key and at most 2 000 characters each, so bounded and never paged) over the base file and fetches it again on a live `translation` change; the Textes editor is a server-paged, searched list over a base catalogue CI exports into the API image, which the API also uses to refuse an unknown key or a missing placeholder; API texts (mails, PDFs) follow as its second step, fiscal mentions staying behind row 52's danger zone. (5) Also in this milestone: a worker (Symfony Messenger and Scheduler in their own container) for realtime, mail and PDF work off the request and the scheduled purges, with an audit index by company (row 56); running totals for the home figures and stock levels, kept in the transaction of each change (row 57); a file lifecycle that deletes a detached draft file after 30 days and keeps issued PDFs and receipts of booked expenses for the ten-year retention, a nightly PostgreSQL backup kept 14 days with a restore tested in CI, capped container logs and restart policies (row 58). Build order: 49, 54, 55, 57, 56, 58, 45, 46, 52, 38 and 36, 47, 50, 53, 51, 44.
- [2026-09-17] AGREED: lists carry their page and total as Hydra (row 55). Plain JSON has no place for them in API Platform: its pagination metadata exists only in the hypermedia formats (JSON-LD/Hydra, HAL, JSON:API), and it sends no total header. JSON-LD is therefore enabled on collection operations only, giving `totalItems`, `view` links and cursor links for histories, with the OpenAPI types generated as documented; single records stay plain JSON. This narrows the JSON-only departure of § 3, whose reason ("no consumer needs JSON-LD") paging removed. A custom envelope over `AbstractCollectionNormalizer` and total headers were compared and refused: the first is off the documented path and hand-describes OpenAPI, the second is no API Platform mechanism.
- [2026-09-17] NOTED: how row 55 pages a list, customers first. JSON-LD is registered in `api_platform.formats` and a paged collection names it as its only `outputFormats`; `Shared/Infrastructure/ApiPlatform/JsonLdOnlyWhereNamed`, a resource metadata factory decorator (API Platform's documented extension point), takes it off every other operation. The plain `defaults.output_formats` cannot do it: API Platform merges an array default into an operation's own list instead of replacing it, so the list answered plain JSON first. Every Hydra answer links `/api/docs.jsonld`, which `docs_formats` now serves; API Platform serves it in production too, since JSON-LD makes it mandatory. A repository answers `Shared/Domain/Page` for a `PageRequest`, the provider wraps it in API Platform's `TraversablePaginator` with `Pagination`'s page and limit (25 by default, 100 at most, client-sized), and the filters and sorts are `QueryParameter`s the operation validates. Words are searched through `search_text()`, an IMMUTABLE SQL function over `unaccent` with its dictionary named, and a `pg_trgm` GIN index on the same expression; Doctrine does not see an expression index, so the mapping does not declare it and `CustomerSearchIndexTest` proves the query uses it. DataList takes a `total` for a list the API pages and emits `queryChange`; its filter chips then show no counts, since a count over one page would be wrong. Such a list keeps its state in the address (`q`, one parameter per filter, `sort` as `name` or `-name`, `page` from 1, `size`), replacing it on each change; a sort the address names wins over the one a person saved until they pick another. Customers are found by number, name, legal name, email, billing address and the values of their registration numbers (`JSON_VALUES`); under three characters only by their exact number (`3c7dc05` and the next commit). Every paged list's provider goes through `Shared/Infrastructure/ApiPlatform/Paging` and orders through `Shared/Infrastructure/Doctrine/ListOrder`, which puts a row with an empty sorted column last in both directions (DQL has no NULLS LAST); `SearchIndexesTest` names each list's search condition and its index. Vendors page, search and sort the same way.
- [2026-09-17] AGREED: the developer's rulings on import, export, product identity, similar products and scanning, through three rounds of questions. (1) Import and export are urgent: existing businesses keep their data in Excel or CSV files. Everything is in scope: customers, products, vendors, opening stock, past invoices and delivery notes, and an export of every list. Import comes first, because onboarding an existing business matters most: customers and products first, then vendors and opening stock (row 59); then the export of every list with its filters (row 60). Past invoices and delivery notes come in last as a read-only archive keeping their original numbers, never entering the gapless series (row 64). (2) Formats: CSV and Excel .xlsx first, read and written on the server, with a template per type; JSON later on the same pipeline. Clean implementations, not a column-guessing importer. (3) A product's reference is optional. Left empty, it takes a reference generated from a numbering series the owner can reformat. A product without one given is still found by name or barcode. A business that wants re-imports to recognise its products writes its own reference in the file. Import matches a row only on a reference or barcode written in it, never on a name, and its preview warns about rows that have neither but share a name with an existing product. (4) A barcode stays optional, because many products have none. When set it is unique within the company, so a scan finds exactly one product. An EAN-13, EAN-8 or UPC check digit is verified when the code has that shape; any other code is kept as typed. There is no separate QR-code field. (5) Similar products are declared by the business, never inferred, as substitution groups: a product joins a group once, and every member replaces the others. They are shown on the product page and on a delivery note or invoice line whose product lacks stock, with each substitute's stock and a one-click swap (row 62). Tags may come later, separately, for filtering. (6) A scanner module (row 63): a scan button opens the device camera to fill the barcode field of a product being created or revised, then the person continues with the other fields; the same button finds a product from a list's search and adds a line to a delivery note or invoice. A laptop can use a phone as its camera: the laptop shows a QR code carrying a single-use token that lives 10 minutes and allows only one thing, sending codes to that laptop tab for that user and company, until the laptop closes the pairing; each code reaches the tab through the realtime channel it already has. The browser's BarcodeDetector is used where it exists, with a permissive decoding library elsewhere once its licence file is read; iPhone Safari support and the camera's HTTPS requirement are to research first. USB and Bluetooth scanners already work as keyboards. Open, for the next session: the developer wants the generated reference shown while the product form is filled, so it can be kept or changed. Claude's proposal to confirm: prefill it from a per-category prefix plus the series' next number (BOI-0042), previewed without consuming the number, which is taken at save (the next free one if another save took it meanwhile, with the toast naming it). Not derived from the name, which changes and carries accents.
- [2026-09-17] AGREED: the developer's rulings on subscriptions, trials, cash payments and support access, which bring licensing into the POC ahead of the 2026-09-09 "after G10" placement, right after the products list (row 55) lands. (1) A `Licensing` context keeps a subscription per company separate from `company.status`: trial, then paid periods, then warnings, grace, and an unpaid state decided in `CompanyGuard` and `PermissionVoter` from dates, never by a scheduled job. (2) The billing period is configurable per company (a month, six months, three years or any length), and every new period needs a payment the client declares and the operator confirms; in-app payment is out for now. (3) What an unpaid company gets when grace ends is a mode the operator chooses, per company with a platform default, and the default is read-only: view, print and export, no writes; the operator's suspend stays available at any time. (4) A declared payment the operator has not decided holds the lock off for a configurable time, 7 days by default; one declaration is open at a time and rejecting ends the hold. The operator is told in the app and by mail, and a mailed link only opens the platform page: confirming is a signed-in action. (5) Trials, their length and every licensing setting are the operator's alone. (6) Support reaches a company's data only through access its owner grants, time-boxed, read-only by default, visible while active and audited in the company's log; operators keep no standing access, as ruled 2026-09-15. (7) The POC covers the hosted SaaS only; a signed licence file and phone-home for clients' own servers wait for the first such client, under the commercial licence.
- [2026-09-17] AGREED: twes-in deploys in two modes, chosen at deploy time, and in neither does the client control the server, which is the only way the source code and environment files stay out of their reach: the hosted SaaS, and a dedicated instance the developer administers on a server the client owns or pays for (full-disk encryption when it is a machine on their premises). A client running it on a server they control waits for the signed licence of the previous entry and is protected by the commercial licence contract; no PHP encoder is used, because ionCube's loader is proprietary, trails PHP releases and is decodable. The browser bundle is minified without source maps and carries no secret; the licence signing key is never on any server.
- [2026-09-18] AGREED: spreadsheets are read and written through one port, `Shared/Application/Spreadsheet`, on one library: **openspout**, chosen by the developer over PhpSpreadsheet and over adding `league/csv` beside it. All three are genuinely MIT — each project's real LICENSE file was read at the commit Composer installs, not its metadata string, and none carries a rider — so this was not a licensing decision but a dependency-surface one. openspout covers .xlsx and CSV in both directions and brings **no other PHP package** with it (measured: `composer require` reported one install and nothing else), against five transitive packages for PhpSpreadsheet, each of which would need its own licence read and a notices entry. It also streams, so what a 50 000-row import costs in memory does not depend on the file's length. Two consequences are accepted with it. Its PHP constraint is tight (`~8.4 || ~8.5`), so a future PHP 8.6 bump waits on an openspout release. And it cannot make a downloadable template safe: a cell format styles only the cell it is on, and openspout's `<col>` element carries width, hidden, collapsed and outline level but no `style` — read out of the installed writer — so Excel still turns a reference typed as `0012` into `12` in the empty cells a person fills in. The protection therefore lives in the import preview, which warns on a reference or barcode column whose values look numeric, and not in the file. Every cell crosses the port as text in both directions: an importer parses a price from a string whether it was typed into a CSV or stored as a number by Excel, no amount passes through a float this code did not choose, and a reference written back as a number would come back shortened. A float is converted by `json_encode` rather than cast, because PHP's `precision` of 14 turns 123456789012345.67 into `1.2345678901235E+14`; a row keeps its own line number, empty rows included, so a rejected row is named by the line its author is looking at; and a CSV is sniffed rather than assumed, because asked for one French Excel writes semicolons in Windows-1252 with no byte-order mark, which read as UTF-8 with commas makes every line a single unreadable cell.
- [2026-09-19] AGREED: the developer's rulings on operating documentation. (1) Two files under `docs/`, split by when they are read: `docs/START.md` (bring-up, sign-in, every way to create a user, what the seed holds, a clean start, the checks, a second stack beside the first) and `docs/UPDATE.md` (every version pin: where it is written, which files copy it, how to bump it, what proves the bump); `README.md` and § 6 point at them, and § 6 no longer repeats version numbers, which had drifted (Gotenberg 8.36 there, 8.37.0 pinned). (2) No document holds version numbers: `make versions` (`scripts/versions.sh`) prints them from the files. (3) Copies of a pin are checked, not documented only: `scripts/gates/version-pins.sh`, with its own test, in `make gate-licences` and CI's licences job, refuses a disagreement or a missing copy among the postgres image (compose and CI), `serverVersion` (the image's major), PHP (image tag, CI, composer.json floor), the PHP extension list (image and CI), the Node major (`.nvmrc`, the web image, `engines`), the Symfony minor (`extra.symfony.require` and every `symfony/*` pinned X.Y.*) and the Angular major. (4) `make reset` is the clean start: it asks, then drops both volumes, the host caches and the saved e2e session, then `make up`; `CONFIRM=yes` skips the question. (5) There are no demo fixtures; the seed is documented as all the data a new stack holds, and fixtures (DoctrineFixturesBundle with Foundry, as Symfony recommends) are the goal after import and export.
- [2026-09-19] AGREED: the Symfony web profiler is installed for development only: `symfony/profiler-pack`, unpacked by Flex to `symfony/web-profiler-bundle` (MIT) with Symfony's recipe, collecting in dev, registered with collection off in test, absent from prod. Per request it shows what no log or `debug:*` command does: which voter decided, the listeners in order with their time, every query and their count, the timeline, serializer, validator and HTTP client calls. It is at `http://localhost:8091/_profiler`, and every answer's `X-Debug-Token-Link` header leads to its own profile. One departure from DoctrineBundle's recipe: `profiling_collect_backtrace` is `false`, not `%kernel.debug%`, because a backtrace kept per query took a 1000-row import past the 128 MB memory limit; the profiler still lists every query and its time.
- [2026-09-19] DECIDED (revisit): how an import runs (row 59, second slice). One file is one unit of work: it is stored only if no row is rejected, and a preview is the same run rolled back, so the preview says exactly what the import will do. The API keeps nothing between the two: the import is the same file sent again, checked against the data as it is then. Whoever may write the subject (`customer.write`) may import it; there is no separate import permission, and a switched-off module's subject cannot be imported. The header is matched on the column keys only, whatever their case and surrounding spaces; a column nobody declares, a column twice or a required column missing refuses the file whole, naming the columns (`unknown_columns`, `duplicate_columns`, `missing_columns`), as does an unreadable file (`unreadable`) or one over the row cap (`too_many_rows`, with the `limit`). A row goes through the same use case as the form, so it meets every rule a person does; a refusal names the line (the file's own, empty lines counted) and the column. Create mode refuses a number a customer already has; create-and-update mode updates it, and a blank cell or a column the file lacks keeps what the customer holds. A customer group or a tax code the company does not have rejects the row: nothing is created implicitly. Two rows of one file with the same number reject the second, naming the first. A custom field's column is `custom.<key>`; a number field is read as a number and a decimal comma as a point. 200 answers a preview or a stored import; 422 a file refused whole or an import stopped by rejected rows, in which case nothing was stored.
- [2026-09-19] AGREED: the developer's rulings on how big an import may be. (1) An import runs inside the request until row 56's worker exists, so a file must finish well within PHP's 30 seconds (`max_execution_time`, which nothing here changes): the cap is 2000 rows, header excluded (`app.import.max_rows`). Measured on release PHP in the dev stack: 2500 rows 10.4 s at a load average of 6, and 2000 rows 18 to 22 s at a load of 12, dev writing every query to its log. (2) A file over the cap is refused whole, at once, naming the limit, and the screen says to split it; nothing splits it automatically. Splitting in the browser or committing in parts would each lose all-or-nothing (a bad row in the third part leaves the first two stored), and a download of the file in parts would be work the worker makes unnecessary. (3) Heavy work belongs in the worker: when row 56 lands, import and export run on it, still one transaction per file, and the cap rises to what the 12 MB upload holds (48 000 twelve-column CSV rows measured 10.5 MB). (4) Written rows leave Doctrine's unit of work, as Doctrine's batch-processing guidance says: the import detaches each row's customer, and the audit trail detaches every audit row after its flush, since nothing reads one back in the request. Every flush walks every managed entity, so a thousand rows had run past 30 s. (5) The import action switches the profiler off for its request with `Profiler::disable()`, as Symfony documents: a profiled 2000-row import ran out of memory saving its profile after the answer had been sent.
- [2026-09-19] AGREED: a rejected row's reason reaches the person in their language through a code, not a sentence. Every refusal an import reports carries a stable `code` and its `params` (`unknown_group` with the name the file gave) beside the API's English `message`; the web translates `import.rejections.<code>` in fr and en, overridable in Textes like any screen text, and shows the English message only for a code it does not know, so a new rule is readable before it is translated. The domain's refusals (`InvalidCustomer` and the like) gain the code, which the customer form can use later. The API's own strings stay mails, fiscal labels and PDFs.
- [2026-09-19] AGREED: the developer's rulings on improving the design, after an audit found every screen on the tokens (no literal colour outside `shared/theme` but the QR code's and the default accent's, no inline style, no raw Tailwind colour, statuses through `StatusBadge`) but nothing enforcing it and nobody having looked at the screens together since the restyle (the design screenshot suite dates from 2026-09-13). (1) Look first, in parallel with functional work: a gallery of every current screen, light and dark, desktop and phone, published beside the approved design, on which the developer marks what bothers them; a second design round follows only if those marks call for it, costed then. (2) A gate keeps the tokens enforced: no literal colour outside `shared/theme` and no inline style. (3) Future screens are mocked one batch ahead of their build, only where the layout is a real choice: the first batch is import (customers and products), export on a list, the product reference preview, the substitutes panel and the scanner with its phone pairing, light and dark, desktop and phone, on the same canvas as the gallery. Screens built from the generic list and form are not mocked. Import's API half continues meanwhile; its screen waits for the mock.
- [2026-09-19] AGREED: the demo fixtures (row 69) are pulled forward, before import's API half: the screen gallery needs data to be worth reviewing, and the dev database was found recreated empty at 12:50 that day by a `docker compose` run from the project directory that neither the developer nor any Claude session on the machine issued (the container's compose labels say where it came from; Docker's event log had nothing left). A reproducible dataset loaded by one command makes such a wipe cheap for demo data; row 58's nightly backup is what covers data entered by hand.
- [2026-09-19] AGREED: amends this day's operating-documentation ruling (5): the demo fixtures use DoctrineFixturesBundle alone, without Foundry, and every fixture writes through the application's own use cases (`ManageCustomers`, `ManageInvoices`, `ManagePayments`, `ManageDeliveryNotes` and the rest), never by persisting entities. Those use cases are where an invoice is checked to total (`InvoiceTotals`), line taxes follow the customer's regime, a deactivated customer is refused, every change is audited and a delivery note moves stock; Foundry's factories persist entities directly and would re-implement or silently skip each of those. The dataset is fixed, the same at every load, so gallery screenshots compare run to run; list volume comes from a loop with deterministic names, not Faker. Loading it is also a smoke test: a rule that stops accepting realistic data fails `make fixtures`.
- [2026-09-19 18:20] DECIDED (revisit): the demo dataset (row 69) fixes everything but its dates, which run relative to the day it is loaded (five months of activity ending a few days before), so overdue, not-yet-due and this-month figures stay true whenever it is loaded; a company that already exists is skipped, not refused, so `make fixtures` reruns like `make seed`. Loading clears the entity manager after each row and each dated step, Doctrine's batch-processing practice: holding every written entity made each flush compare all of them, and one load took over four minutes in the test environment instead of 25 seconds.
- [2026-09-19 19:10] AGREED: an invoice with nothing left due reads "Soldée" ("Settled"), not "Payée", whether payments, credit notes or both brought it there: the fixtures showed a fully credited invoice reading "Payée" although its customer paid nothing. The stored status stays `paid`; only its wording changes, and the invoice page keeps showing payments and credits apart.
- [2026-09-19 19:55] DECIDED (revisit): import's API half (row 59). Every refusal a row can meet carries a `reason` code and its `params`: the importer's own (`value_required`, `already_exists`, `not_one_of`, `unknown_group`, `unknown_tax_code`, `not_a_number`, `not_yes_or_no`, `duplicate_in_file`), the customer rules' (`unknown_tax_regime`, `unknown_group_id`, `unknown_tax`, `regime_excludes_tax`, `invalid_customer_number`, `invalid_length`, `invalid_rate`), a custom field's (`unknown_custom_field`, `invalid_text`, `not_a_date`, `not_a_boolean`, and the shared ones) and a preset identifier's (`identifier_required`, `identifier_shape`, `identifier_checksum`, `unknown_identifier`, now an `IdentifierRefusal` value object). The code is a required constructor argument, so no refusal ships without one; the PHP property is `reason` because `\Exception::$code` is an int, and the JSON key stays `code`. The column guide is an API Platform resource on the import's own path, `GET /api/companies/{companyId}/imports/{subject}`, with the import's permission and 404s: the identity column, the row cap and each column's key, whether it is required, its example and note key, and exactly one of a `headingKey` the screen translates or a `label` already in the person's words (a preset's identifier translated by the API, which owns the fiscal catalogue; a custom field as the company named it). The import and template routes are documented by `ImportOpenApi`, so the screen is typed. The customer form's 422 still reads field and message; the code reaches it when the form wants it.
- [2026-09-19 21:49] AGREED: vendor and company-profile refusals keep reading field and message, without the import's `reason` code, until vendor import is written. No screen reads the code outside an import report, and the required `reason` argument forces it onto `InvalidVendor` the moment a vendor importer reads one.
- [2026-09-19 21:55] AGREED: a decimal field shows its value with the decimal separator the rest of the screen uses (the interface language for the company's country, "890,000" in French; no digit grouping inside a field, so "1,234" can never read as a thousand) and accepts a decimal comma or a point, as the import does; the API still reads and writes a point. This reverses "inputs keep the API's format" (`FormatFacade`), which refused "890,5" from a French person. No number-format setting for now: the format follows language and country, and a setting can come later without undoing this.
- [2026-09-19 21:55] AGREED: a document page names nothing it has not loaded: an existing invoice is not titled "Nouvelle facture" while it loads, and the gallery captures a page once it has finished loading.
- [2026-09-19 23:16] AGREED: a list row gives access to its record without scrolling (design review finding 1, measured: row actions sat past the table's right edge in every list, even at 1440 px). The whole row opens the record through a real link on its number or name, so a new tab, a copied link and a screen reader all work; selecting text and the row's own controls (a switch, a selection checkbox) never open it, and a click never selects. "Ouvrir" goes. A column pinned to the table's right edge shows the list's one or two most frequent actions as visible buttons (an invoice: its PDF, and recording a payment while something is due); only rare or destructive ones sit in a "⋮" menu. On a phone each row is a card, the whole card the link. Each list declares its actions in row 45.
- [2026-09-19 23:17] AGREED: a document page keeps its next step in view (design review finding 3, measured: "Facturer ce bon" and "Enregistrer le paiement" sat below 2000 px of form). An action bar beside the title stays visible while the page scrolls: the next step of the document's state is its one primary button (Émettre, Facturer ce bon, Enregistrer un paiement), frequent actions are visible (the PDF, Dupliquer), rare or destructive ones sit in a "⋮" menu (Annuler). A locked document (issued, delivered, cancelled) shows as a read view, its parties, lines as a table and totals, with empty fields left out, never as a disabled form; only a draft is a form. Recording a payment opens a dialog rather than a form at the foot of the page. Actions are declared per screen in row 45.
- [2026-09-19 23:18] AGREED: a record page saves from the same bar beside its title (design review finding 4, measured: every long form's save sat below the first screen, the customer page at 3165 px with two of them). "Enregistrer" is its primary button, active once something changed, beside "Annuler les modifications" and a count of unsaved changes; leaving with changes asks first (row 45). A long record splits into tabs, one save each: Fiche and Valeurs par défaut now, Documents and Historique when they exist.
- [2026-09-19 23:19] AGREED: on a phone the header reads company, search, notifications, account (design review finding 5, measured: at 390 px the account button was cut off on every screen). The working company's name takes the wordmark's place, truncated when long, and switches company when tapped, so the company a person acts for stays in view; the wordmark stays on wider windows and the signed-out pages.
- [2026-09-19 23:20] AGREED: the header search starts right after the menu button and grows to meet the header's right-hand controls, capped near 800 px (design review finding 6, measured: 496 px centred in a 1184 px header at 1440, empty on both sides); the cap only binds from about 1920 px, where an uncapped field would be a stripe. Its placeholder names what it finds ("Rechercher un client, une facture, un produit…").
- [2026-09-19 23:21] AGREED: the settings area is a navigation rail and a docked list (design review finding 7, measured: the settings menu floated as a card beside a full app menu, about 650 px of a 1440 px window, and cut the tables beside it). While in settings the app menu shrinks to its 80 px rail and the settings menu docks against it as a full-height column on the same surface, no card and no gap; the rest of the app stays one tap away. Material 3's rail with a list.
- [2026-09-19 23:22] AGREED: settings are reached from the sidebar's "Paramètres" only; the top bar's gear goes (design review finding 8: two controls opened the same area). Settings are a place the rail shows you are in (finding 7), and the top bar keeps only what is used from anywhere: search, notifications, the account. This reverses the 2026-09-14 placement behind a gear; on a phone the entry stays under "Plus". The gear's visibility rule carries over to the sidebar entry: shown to whoever may open at least one settings page.
- [2026-09-19 23:25] AGREED: stock gains moves and reasoned losses, lifting the 2026-09-15 deferral of transfers for moves inside an establishment. A move is one operation that takes a whole or partial quantity out of one location and into another, its two movements linked; a write-off takes a quantity out with a required reason (lost, broken, expired, stolen, internal use, sample), an optional note and photo, by `stock.write` only, and shows in reports so it never stands in for a count; "quarantine" becomes a location kind for goods awaiting a decision. Transfers between establishments, with an in-transit state, come later, after research on the transport document. The VAT effect of a loss (a deducted VAT to repay, by country and reason) is checked and sourced in `docs/fiscal/` before the reasons ship. Precondition of the drawn map: a map is only as true as the moves recorded.
- [2026-09-19 23:26] AGREED: stock transforms goods through one generic operation: it consumes some lines and produces others in one transaction, optionally from a product's components, which covers assembling a kit, taking a product apart, repacking (a carton into twelve units) and processing with waste. It is built inside the composite-products goal (2026-09-16), right after its unit conversion; what it produces costs what it consumed.
- [2026-09-19 23:40] AGREED: the drawn stock map (after the POC, on the shared venue-layout context of 2026-09-14) ships with two views from the start over one set of data: a 2D view, an SVG top plan per floor and a front view of each rack, drawn in-house, and a 3D view in Three.js (MIT, recorded in the notices when added). Every location carries its plan rectangle and its height and level from day one. Editing happens in 2D only (grid-snapped rectangles over an optional floor-plan image); 3D is for viewing, loaded only when opened, and each person's choice of view is remembered. Every feature is complete and accessible in 2D, 3D adding and never replacing: search by name, reference or barcode (or a scan, row 63) opens the right floor and highlights every location holding the product with its quantity, and a delivery note highlights its lines' locations. It needs stock moves (row 74), running totals (row 57) and the scanner (row 63) first.
- [2026-09-19 23:48] AGREED: the stock roadmap beyond moves and losses. Scheduled: returns (a customer return puts goods back, each to a chosen location or quarantine, linked to its credit note; a return to a vendor takes them out), batches with expiry (lot and expiry per movement, the earliest to expire picked first, alerts before expiry; designed so a serial number is a batch of one, unique within its product), reorder alerts (a minimum per product and location raising a notification) with printable QR labels for locations and products, and stock valuation (a receipt records its unit cost; one method, weighted average unless the sourced fiscal research in `docs/fiscal/` says otherwise; losses and transformations valued with it; a stock value report). Kept with a trigger, not scheduled: reservations (available against on hand) arrive with sales or café orders, and serial numbers once a customer segment needs warranty tracking.
- [2026-09-20 00:35] AGREED: the first working version serves two trades end to end, and a country is data (business review finding 1). Tunisia is the launch market and the first two customers are a **quincaillerie** (hardware shop) and a **tourneur** (turning and machining workshop), without the product being built for those two alone. The shop's half: a counter sale producing a receipt, barcodes, goods sold by the metre or the kilo, product variants, trade customers on account with their own prices, and restocking from suppliers — which **reverses "purchase orders — out, with no date"** (§ 2). The workshop's half: quotes, deposits, and a job that consumes material and produces a finished piece, on the generic transformation of 2026-09-19 23:26. The two halves are built **at once, in thin slices**, so neither customer waits for the other to be finished. Supporting a further country means a fiscal preset in `api/config/fiscal/<CC>.yaml`, its sourced rules in `docs/fiscal/<CC>.md` and its translations — never new code; this raises the 2026-09-09 preset design to an invariant a test enforces, and makes row 52 (presets editable in the app) the way a country is added without a release. This widens the 2026-09-16 "who it is for" from shops, cafés and workshops to any business with a place, in any country the presets describe.
- [2026-09-20 00:35] AGREED: the first working version is **ready for electronic invoicing and connected to nothing** (business review finding 1, second half). Tunisia has required VAT-registered businesses to issue through TTN's El Fatoora in TEIF format since 1 January 2026, with a per-document fine for a paper invoice — the figures and the article are to be read in the JORT and written into `docs/fiscal/TN.md` before any code depends on them; enforcement is reported as not yet applied, which is a reprieve and not an exemption. So v1 stores every field TEIF and Factur-X need, keeps an issued document immutable (already true: gapless numbering, credit notes rather than edits), leaves room on the printed document for a verification QR, and exposes one **port for sending a document to a platform with no adapter behind it**. A connector — TTN, a French *plateforme agréée*, or another country's — is then a module written when a customer needs it, against that port, and carries the company's own certificate and operator contract, never ours. Two facts to settle before the port's shape is fixed: whether the first customers are VAT-registered or under *forfait*, and whether a counter sale to a walk-in customer needs an electronic invoice at all or only a receipt.
- [2026-09-20 00:45] AGREED: the first working version carries the whole chain around the invoice (business review finding 2), which today starts only at the delivery note. **A quote** (already planned) is sent, accepted or refused, and **an accepted quote is the commitment itself** — there is no separate sales-order type: a direct order is a quote created and accepted in one gesture, and the quote then tracks what is delivered and what is still owed, so partial delivery and the remainder have one home. **A deposit is an invoice**, with its own number and its VAT, and the final invoice subtracts it; the subtraction is computed by the engine, never typed. **A statement of account** lists a customer's documents and payments over a period and prints, and **a credit limit** per customer or group warns when a new delivery would take them past what they already owe. **Recurring invoices leave "out, with no date"** (§ 2): a schedule generates drafts an issue confirms. **Payment reminders** are not in this ruling — they need sending by email, and arrive with it.
- [2026-09-20 00:52] AGREED: buying is three linked documents with a **full three-way match** (business review finding 3, chosen over the lighter warning-only shape). A **purchase order** is sent to a vendor with expected dates; **goods receipts** are recorded against it, partial allowed, each one moving stock into a location and recording the unit cost the valuation of 2026-09-19 23:48 needs; a **supplier bill** carries lines, and order, receipt and bill are matched automatically within a tolerance the settings engine holds, anything outside it waiting on an approval before the bill is payable. Expenses keep their present shape for what is not goods. A supplier bill with lines is also where a received electronic invoice lands when a connector exists (2026-09-20 00:35).
- [2026-09-20 01:02] AGREED: **a variant is a product, gathered in a family** (business review finding 4, re-asked). Every sellable thing keeps one identity — its own reference, barcode, stock, price and lines — and a **family** groups the ones that differ only by an axis (diameter, finish, size), carrying the axis labels and letting a screen show them side by side; no second identity is generated, so stock, movements, lines, imports and the drawn map keep the single key they already use. A **unit conversion** sits on the product (bought by the roll, held and sold by the metre; bought by the sack, sold by the kilo), which is also how a workshop buys bar by the metre and consumes it by weight. **Price lists** apply per customer or group, with quantity breaks and validity dates, and decide a line's unit price before any discount. Three needs stay distinct and must not be merged: a **variant** is a thing in stock; an **option or supplement** ("extra shot", "no onions") is a choice made on a line, changing price and what the recipe consumes, held in stock never — it is ruled here as its own line-level mechanism arriving with the recipes and the café module, reusing a family's axis labels; a **composite** is what a sellable thing is made of (2026-09-16). A menu item is therefore an ordinary product with a recipe, priced on its own, which is what a café needs since a large cup is not a proportional small one.
- [2026-09-20 01:10] AGREED: the first working version has a **counter sale**, the first thing in this product that is not a back-office screen (business review finding 5). One full screen driven by a scanner and the keyboard, no mouse needed: a barcode scanner types, so it needs no driver. The **same sale prints a receipt or an invoice** — a walk-in takes a ticket, a business gives its tax identifier and takes an invoice — on the numbering series of its establishment. Payment is **cash with change, card, on account, or two means on one sale**. A **shift** opens with a float and closes with a counted drawer and a **Z report** by payment means and VAT rate, without which an owner cannot tell a mistake from a theft. A **return at the counter** writes a credit note and puts the goods back. **Selling offline is deliberately not in this version**: it brings local numbering, conflict resolution and stock reconciliation, and it is the longest single piece of work in this review — so every sale is built **idempotent and queued in shape**, and offline is added later without a rewrite. Tunisia's certified-register rules (NACEF) appear to cover consumption on the premises and not a hardware shop; that is to be sourced into `docs/fiscal/TN.md` before a till is sold to a café.
- [2026-09-20 01:18] AGREED: made-to-order work is **a job**, from the accepted quote to the invoice (business review finding 6). A job is created from an accepted quote (2026-09-20 00:45), consumes material through the generic transformation (2026-09-19 23:26), records **hours at a cost rate**, produces its output — a catalogue product or a one-off described on the job — and closes into a delivery note and an invoice. Its screen shows the quoted price against material plus time, so the next quote is priced from evidence. Two boundaries: **hours on a job are not a timesheet product** — a general time tool with weekly grids, approvals and billable rates is a different product and stays out; and **scrap is consumed by the job**, never written off with a stock reason code (2026-09-19 23:25), whose reasons are for goods sitting on a shelf.
- [2026-09-20 01:26] AGREED: "a country is data" (2026-09-20 00:35) is made precise as **a pack that may name strategies** (business review finding 7). A country pack declares, as data, its taxes and levies, the sentences a document must print in each situation (exempt, reverse charge, franchise, withholding), its tax identifiers, its numbering constraints, its electronic formats, its rounding and amount-in-words rule, and its archive duration; its rules are sourced beside it in `docs/fiscal/<CC>.md`. Where something is genuinely algorithmic — a check digit, a signature format — the pack **names a strategy from a small code registry** rather than trying to express it in configuration, which would only be a worse programming language. Adding a country that needs no new algorithm is then pure data; one that does costs a single registered strategy. A **conformance test loads every pack in the directory** and proves each produces a valid invoice with its mentions, its identifiers checked and its totals rounded, so a pack that quietly needs code fails the build. Built while the first version is built, with Tunisia and France as its first two proofs; row 52 (presets editable in the app) later makes adding a country possible without a release.
- [2026-09-20 01:33] AGREED: the first working version answers **the accountant** (business review finding 8), who is the person most able to reject the tool. Three pieces: **exports** — a sales journal (invoices and credit notes with VAT by rate), a purchases journal, a payments journal and a VAT summary for a period, as CSV and xlsx, the formats accounting software imports; a **read-only accountant** invited to the company, seeing documents and exports and nothing else, one row in the role matrix; and **closing a period**, which refuses any new or back-dated document before its date — an invariant, not a feature, and far cheaper now than retrofitted over a year of data. Deliberately out: a **double-entry ledger and the French FEC**, which are produced by whoever keeps the books; twes-in does not, and neither first customer keeps their own.
- [2026-09-20 01:42] AGREED: the first working version ships **two business profiles and declares its walls** (business review finding 9). A profile is data — modules switched on, defaults set (does this trade hold stock, sell at a counter, which units), a starter catalogue seeded — never code, and never a cage: the **shop** profile (quincaillerie: counter sale, stock and locations, unit conversion, price lists and trade accounts, purchasing; jobs off) and the **workshop** profile (tourneur: quotes and jobs, transformation, stock for material; counter sale and price lists off) answer the first ten minutes of onboarding, after which every module stays switchable, since a hardware shop is asked for written quotes and a turner sells a part across the bench. Two profiles rather than five, so the mechanism is proved twice by real customers and a third is written without a release. Other trades are served by the same primitives with different defaults — drugstore and superette, bookshop, wholesaler, garage (whose work order is the job of 01:18), general retail, workshops of every kind — while cafés and restaurants need their planned module and construction needs a real vertical (progress billing, retention, reverse charge). **Out, with no date, stated plainly**: dispensing medicines and anything touching reimbursement (CNAM *tiers payant*, SESAM-Vitale), drug serialization, medical practice billing, payroll, factory planning and maintenance management. A parapharmacie's non-drug shelf is an ordinary shop and is welcome; the pharmacy counter is not.
- [2026-09-20 02:05] AGREED: in the café and restaurant module, **orders have two ways in and route themselves** (café review finding 1, on the developer's QR design). There is one order model. The **waiter's own screen is the primary way in** — a phone or tablet at the table — because a customer without data, an older phone or the wish to speak to someone must still be served, and because a site whose connection drops must keep taking orders. **The QR on the table adds to it**, never replaces it. Each line **routes by its product to a preparation point** (bar, kitchen, or the counter in a small place), each station showing its own queue on its own screen: the waiter is notified to **serve**, not to carry the message to the chef, which is where orders are lost in a rush. A QR order is **accepted by the waiter before a station sees it**, which is also the abuse control on an unauthenticated surface.
- [2026-09-20 02:12] AGREED: a table holds a **service, and one tab per guest** (café review finding 2). A **table service** opens on the first order and closes when everything is paid; inside it each **guest has a tab**, and a guest is a **device session** carrying a typed name as a label on the cup — or "Invité 2" for whoever does not want to type — never a name used as an identity, since two guests share a first name and a name typed for a kitchen ticket is also a place to type an insult. Because the session belongs to the device, a second scan **resumes that person** and shows his running tab and his statuses; it is the previous person's basket that must not appear, not the customer's own history. Settling is a choice at the end: **one tab, several tabs, or the whole table**, and the waiter can move a tab to another table, join two tables, or move a single line between tabs when one guest offers another a round. A guest session is personal data held without an account: it is short-lived and purged, with the retention written in the module's settings.
- [2026-09-20 02:20] AGREED: at a shift handover **the tables stay open and the waiter's purse is counted** (café review finding 3, correcting the developer's first rule). **Each order keeps the waiter who took it**, so a report by waiter or by shift is honest and a customer ordering again after the handover is automatically the new waiter's — the developer's rule, kept. What a handover settles is **the cash the waiter holds**, counted and handed to the cashier, not the room's tables: asking a table to pay mid-meal because a service ended is the complaint that reaches the owner. The day then closes on **the counter till's shift close** (2026-09-20 01:10), extended with what the developer described and two things his description needed to make the count balance: the drawer counted **by note and coin** with a chosen **float carried to tomorrow**, **tips** recorded (cash left on a table inflates the drawer and is not revenue), and **cash taken out or put in** during the day (a delivery paid from the drawer, a drop to the safe). One mechanism serves the café and the hardware shop.
- [2026-09-20 02:30] AGREED: **a menu is a presentation over the products**, not a second catalogue (café review finding 4). It holds sections, their order, photos and the hours it is shown (breakfast, happy hour); a *formule* is a composite product (2026-09-16); and prices come from the **price lists** of 2026-09-20 01:02, keyed to the **area and channel** — counter, terrace, take-away, delivery — so a coffee is one price at the bar and another on the terrace without a second catalogue to maintain. A menu object with its own entries is refused: a dish would exist twice, stock and cost on one side, price and description on the other, and they drift within a week. **Consumption mode sits on the line**, chosen by the guest among what the owner allows for that item, and **drives the tax through the country pack** (2026-09-20 01:26): eating in and taking away are taxed differently in France, and consumption on the premises is what pulls a Tunisian place into the certified-register rules — so it is never merely a flag on a receipt. With it: **sold out** in one tap reaching every open phone at once, **allergens** on the product, and **courses** fired by the waiter, without which a kitchen screen receives a whole table's evening in one burst.
- [2026-09-20 02:38] AGREED: a guest may **rate a menu item he actually ordered** (café review finding 5, the developer's idea, gated). The tab proves he was served it, so **only that guest may rate it**, once, within a window after service — a rule no review site can enforce, and the one that shuts out a competitor leaving one-star reviews, a bot, and anyone rating a dish they never ate. The **score publishes immediately** and feeds an average that stays hidden below a handful of ratings, so one bad evening does not brand a dish; a **written comment waits for the owner's approval**, so nothing insulting ever appears under a dish on an unauthenticated page carrying the business's name. The owner sees every rating and comment, published or not — the honest signal of which dish disappoints is his either way — and may hide any of them later. The whole feature is switchable per company, like any module.
- [2026-09-20 02:50] AGREED: the café and restaurant work is **six modules, cut by who else would switch each one on alone** (café review finding 6, on the developer's standing requirement that everything be a module). Not an eighth module called `Restaurant`, which would bundle what other trades want separately. **`Venue`** — areas, spots and the drawn plan, the shared layout of 2026-09-14 that the stock map already stands on: a café's tables and a warehouse's racks are the same object seen twice. **`Register`** — the counter sale, the shift, the drawer counted by note and coin, the Z report (2026-09-20 01:10 and 02:20), which the quincaillerie switches on without ever meeting the café. **`Menu`** — the presentation over products (2026-09-20 02:30), wanted by a bakery with no tables. **`Service`** — table services, tabs, orders, courses, station routing, waiter assignment: the restaurant proper. **`Guest`** — the QR surface, table tokens, guest sessions, live status: its own module because it is **the only place in the app where an unsigned-in person writes**, with its own rate limits and its own switch, so an owner may close public ordering and keep the rest. **`Ratings`** (2026-09-20 02:38), on Guest and Service. This exposes a gap in what exists: **a module manifest declares no dependencies**, so nothing stops `Guest` being on while `Service` is off — a live public page ordering into nothing. Manifests gain declared dependencies and the registry refuses an impossible combination, which also makes a **business profile (2026-09-20 01:42) a list of modules**.
- [2026-09-20 03:00] AGREED: four further pieces ship with the café and restaurant module (café review finding 7, all four chosen). **Table states on the drawn room** — free, occupied, ordered, served, to clean — so a waiter reads the floor at a glance and no one is seated at an unwiped table; nearly free, since `Venue` draws the room and `Service` knows each table's stage. **Take-away with a pickup number** and a "ready" notification on the guest's phone, **paid at the counter on collection**, since payment gateways are out and nothing can be paid online — which is also what prevents food made and never collected. **Station screens rather than kitchen printers**: each preparation point shows its queue on a cheap tablet with what is late highlighted and a ticket marked done — a thermal printer is the most fragile object in a café and paper cannot say a ticket is eight minutes late; it stays **one simple view**, never a configurable kitchen display system. And **reports** by table, waiter, shift, hour and item, which the model just ruled gives almost for free — their catalogue is ruled separately. Deliberately out: **reservations**, a product of its own that neither first customer needs, and **delivery platforms**, an integration and a commission model per platform, a business decision before a technical one.
- [2026-09-20 03:20] AGREED: the first working version carries **two bets, WhatsApp and Arabic** (business review finding 10, re-asked). Compliance is an entry ticket and not a moat — Tunisian rivals are already El Fatoora-native — so the advantage has to come from elsewhere. **Sending a document over WhatsApp**: a signed, expiring link to one invoice, quote or delivery note with a ready-written message, opened from the phone; days of work for the gesture these businesses make every day, needing no business API and no per-conversation cost, and distinct from the customer portal, which at the time stayed out [AMENDED 2026-09-20 19:20: the portal left the Out list that evening, as a customer-account feature; the link remains the cheap answer for one document, and the two do not replace each other] — one document, one link, expiring. **Arabic, right-to-left and bilingual documents**, pulled forward from § 2: the thing a French or European competitor answers slowest, and the easiest argument at home. The two reinforce each other, a document sent over WhatsApp printed in the language its reader reads. Refused for now: the **Tunisia–France corridor** (an invoice becoming a bill in the other company — elegant, narrow, and it needs the foreign-currency work first) and **mobile money with offline mobile** (a reference field without a gateway; and offline mobile is the largest single item in this review).
- [2026-09-20 03:20] AGREED: language and currency are configurable **per person, per customer and per document** (developer's addition to finding 10). Three things that "the app's language" hides are kept apart: the **interface language belongs to the person**, not the company, since two employees of one café may not read the same language; the **document language belongs to the customer**, with an override on each document, so an Arabic interface has nothing to do with sending a French invoice to a French client — which binds the country pack (2026-09-20 01:26): **its legal mentions must exist in every language a document can print in**, or an Arabic invoice loses its exemption sentence; and the **language of our own data** — product names, descriptions, terms — is deliberately *not* made fully translatable, because that touches search, the till, imports, exports and price lists: a product carries **one second-language name**, printed when the document is in that language, which is most of the value for a fraction of the work. Currency: per-document currency, rate and exchange gain or loss as already planned, plus a **default currency per customer** and a **counter that accepts a foreign note** at a manual rate with change given in local money. Invariant: **stock valuation and every report stay in the company's reference currency**.
- [2026-09-20 03:40] AGREED: **a module declares its reports**, as it already declares its manifest, its settings and its imports (reports review, part one). `DeclaresReport` gives a key, a permission (the subject's own, never a separate reporting permission), its module, its parameters and its columns; one engine collects them into a single catalogue and gives every report the same period, the same comparison, the same filters, the same export and the same permission check — so a switched-off module's reports disappear with it and a new module brings its own without touching a reporting screen. The home page's hand-written `SummarizeInvoices` is the shape this replaces: fine for one screen, and at twenty it is twenty bespoke classes sharing nothing. Four properties are not negotiable. **Drill-down everywhere**: every figure opens the documents behind it, because a number that cannot be verified stops being trusted at the first surprise — and it is how a wrong total is diagnosed. **Comparison is a parameter**, not a separate report: any report reads against the previous period or the same period last year. **Figures are read, never recomputed**: a report sums stored line amounts and re-derives no tax and no rounding, so it can never disagree with the invoice it summarises. **Tax reports are declared by the country pack** (2026-09-20 01:26), not by a reports module, since a VAT recapitulation's shape belongs to its country. A user-built query builder is refused: it is a product of its own, and a query surface over a tenant's database is a performance and privacy risk for a feature small businesses rarely use.
- [2026-09-20 03:55] AGREED: the first working version's **report catalogue**, on the engine of 03:40 (reports review, part two; research in `var/claude/biz/06-reports.md`). Sales: revenue for a period against the one before, **margin by product and by family**, sales by customer including who has gone quiet, discounts granted, credit notes. Money owed: aged balance, statement per customer, exposure against each credit limit. Till: X and Z per shift, takings by means of payment, drawer differences by shift and waiter, tips and cash movements. Stock: value by location and family, **dead stock** untouched for a period, what to reorder, losses by reason, count differences. Buying: purchases by supplier, **a supplier's price evolution per product**, late orders, bills to pay, match exceptions. Workshop: **profit per job**, quoted against material plus hours, and scrap rate. Plus the accountant's three journals (2026-09-20 01:33), which are the same objects exported. The café set — by hour, waiter, table and dish, food cost, voids — comes with its module. A **daily digest** carries the few figures that matter (yesterday's takings, what is overdue, what to reorder, what expires) by email or WhatsApp on the row-56 worker: the reports an owner reads are the ones that arrive. **Margin is the headline, not revenue** — of 289 owner-managers studied, three quarters watched cash but only 56% ever looked at gross profit by product line, and 31% among the less financially confident half [Inferred: Mazzarol, Reboud & Clark 2015, read in full] — so margin is surfaced in the flow, on a product and on a line, not only in a report. Deliberately out: balance sheet, cash-flow statement, forecasting and break-even (fewer than half of owners use the nearest equivalent), and any figure without a denominator — rates, not cumulative totals.
- [2026-09-20 03:55] AGREED: **a legal document is not a report, and an alert is not a report either** (reports review, two amendments). A VAT declaration, a Z, a withholding certificate have a deadline, a fixed layout and a retention period; they leave the Reports menu for a **Declarations area with its own calendar**, which tells a company what is due and when. It carries Tunisia's **monthly form DC** shape — VAT by rate alongside FODEC, stamp duty and withholding, due before the 28th of the following month — the **withholding certificate per beneficiary**, which Tunisia extended to all firms through the TEJ platform in January 2026 with a fine for issuing one outside it, and the **recapitulation of withholding suffered** per customer and period, which lets a company reconcile the certificates it receives and claim its credit — **a direction no product found in the research covers, twes-in included**. Each of these is sourced into `docs/fiscal/<CC>.md` before it ships, since the research reached expert and vendor agreement but not the JORT. And **low stock, a credit limit reached and a count difference become notifications** through the centre and the realtime channel that already exist, not rows in a menu nobody opens. One further constraint recorded, not acted on here: Tunisia's homologated-register obligation has covered **all on-premises consumption since 1 July 2026** — in force now, reported as barely enforced — while **retail shops are not covered** [Inferred: five Tunisian press and vendor sources; JORT not read] — the counter sale serves the hardware shop freely and cannot legally serve a Tunisian café without homologation, which is a constraint on the café module.
- [2026-09-20 04:15] AGREED: the first working version is built **ground first, then the thinnest path that lets each customer start, then what completes them** (business review finding 12; the order now stands in § 2, which this ruling rewrote). Ground gets built first because it grows more expensive every week: the design rows touch every screen and many more screens are coming, the country pack is what every document's tax and mentions hang on, and the catalogue is what everything downstream prices from. Then each trade gets only enough to *start* — the workshop its quotes, deposits and job over the invoicing it already has, the shop its price lists, a counter sale printing a receipt, and purchase orders with receipts — so both are usable early and neither waits for the other to be finished. Completion follows. The sum was stated before the ruling rather than after: about **104 points of new work over 88 points of already-open rows, some 38 steps**, against 233 points delivered to date.
- [2026-09-20 04:15] AGREED: **the barcode scan and the drawn map come forward into the first working version** (developer's amendment to finding 12), out of rows 63 and the 2026-09-19 23:40 map ruling. One scan serves three places: read into the **search bar** it finds the product; read into an **invoice, delivery note or purchase order's lines** it **adds the line** when exactly one product matches, and offers the choice when several do. The map arrives with it, both views as ruled, because a scan that highlights where a product physically sits is the same gesture — which makes `Venue` part of this version rather than after it.
- [2026-09-20 04:15] AGREED: **a company declares its own tax regime, and the app follows it** (developer's answer on whether the first customers are VAT-registered). The regime belongs to the company's fiscal preset, so *régime réel* brings the electronic-invoicing obligation, the mentions and the withholding behaviour while *forfait* or franchise brings none of them, and one body of code serves both. Nothing in this version depends on knowing the two first customers' regime; what it changes is only **when the TTN connector is scheduled** — dated work with 1 January 2027 on it if either is on the régime réel, and a module on request otherwise. Recorded as an open question, not a blocker.
- [2026-09-20 05:10] AGREED: **an imported row is found again by one or several columns**, and **opening stock is a count, not a receipt**. The engine's `identityColumn` became `identityColumns()`: a customer is still found by its number, but a quantity of stock is found by its product AND the place it sits in, which one column cannot say, so only the same PAIR twice in a file is a duplicate while one product at two locations is two rows. A row writes through `KeepStock::count`, the use case the stock-count screen uses, so the same file imported twice leaves the same stock rather than twice as much — an opening balance stays safe to re-run after a correction. A location is named by its CODE, which is unique per establishment and not per company, so a code two establishments share is **refused** (`ambiguous_location`) rather than guessed at: putting goods in the wrong building is worse than asking. Landed in 85c2f67 and 7e634eb.
- [2026-09-20 09:30] AGREED: **the e-invoicing question closes as "build every field, schedule no connector"**, which is the developer's own reading of their answer — *"i don't know yet ! and i'm not supposed to know ! we should be able to handle all cases"*. Row 93's fields (everything TEIF and Factur-X need, and room for a verification id) are built with the country pack, so a company of **any** regime is served without anyone knowing which regime the first customers hold. The TTN connector gets a row marked **unestimated, blocked on TTN's specification and a test account** rather than a date, because an estimate without their interface, signing and certificate requirements would be a made-up number. What is publicly documented about TTN and El Fatoora is to be researched and written into `docs/fiscal/TN.md` with its sources, so the unknown becomes partly known before documents are chased. This **closes** the "régime réel" question in § 8, which is struck from Needs input.
- [2026-09-20 09:30] AGREED: **the super user the developer asked for already exists; it is documented, not built.** `PlatformOwnerInvitationResource` lets an operator holding `platform.company.create` open a company *and invite its first owner*, and that owner accepting activates the company — so one login starts everything: sign in as the operator, open a company, invite its owner, read the mail in Mailpit, accept, sign in as that owner, and that owner invites everyone else. The operator deliberately still cannot invite an admin or a member directly, nor read a company's data without being a member, and that boundary **stands**: it is also the path a real self-hosted customer takes, so testing it tests the real thing rather than a testing convenience. The walkthrough opens with this chain.
- [2026-09-20 09:30] AGREED: **an installation starts empty, and demo data is asked for.** `make up` gives one super user and no companies, so the developer — and any first customer — sees the real first-run path: empty states, creating the company, the first setup. `make fixtures` keeps the two demo companies for the cases only volume can test (paging, lists, the home figures, both fiscal worlds), and gains **one account per built-in role in each of them** (owner, admin, member, in Carthage Conseil and Atelier Mercier), written through the invitation use cases like every other member. Until now those three accounts existed only as hand-made rows in one local database, which the Test Access page documented as though they were reproducible; building them into the fixtures is what makes that page true.
- [2026-09-20 09:30] AGREED: **the brand mark is chosen now and shipped ahead of row 36.** The tab carries Angular's shield and the sidebar a stock Material glyph, so nothing in the product is ours — against invariant 5. The four treatments already drawn on the Look canvas (Registre, Socle, Angle, Cadre, each computed from the name) are put to the developer at the sizes that decide it — a 16 px favicon, the sidebar, the app icon — and the chosen one ships as the favicon, the sidebar mark and the app icon in one small slice. It settles the mark only: the tagline is its own decision, and row 36 still has to make all of it **configurable** behind a brand port rather than shipped as a default. The faces are OFL and vendored, which the licence policy permits.
- [2026-09-20 09:45] AGREED: **the map and the 3D view are built after Ground, not before it** (developer's choice against seeing them sooner). § 2 already places them in tier two, and line 747's reason holds: the design rows touch every screen, so a venue screen built now is built on patterns about to change and gets reworked. The order is therefore rows 70–73 and 45, then the **move** half of row 74 — because a map that shows stock is wrong on a rack, in a product that has only `receive` and `count`, offers no honest way to correct it, and *"a map is only as true as the moves recorded"* — then the map drawn on the canvas for approval under the rule of 19/09, then 2D and 3D together, 3D being the smaller half of the two.
- [2026-09-20 09:45] AGREED: **a map is one drawing per floor, and a floor belongs to an establishment.** A company has establishments already; each gains one or more levels, and each level is one drawing. It matches a shop with a sales floor and a stockroom, or a workshop over two storeys, and it is what gives the 3D view something to stack. **What is drawn is a palette of typed objects on a grid** — round table, rectangular table, rack, shelf, counter, door, wall — moved, resized and rotated, never free-hand except for walls and areas. The **kind is the point**: it is what lets a rack be tied to a stock location, a table to an order and a counter to a till, which is what makes a map do something rather than decorate. It is stored as rows, never as a picture.
- [2026-09-20 09:45] AGREED: **drawing a rack creates its locations.** Drawing a rack and saying "3 levels, 4 columns" creates the child locations beneath it, named from the company's numbering and tied to the drawn cells, so the drawing is the tool that builds the location tree rather than a second chore after it, and the map and the tree cannot disagree because one act made both. Erasing a shape never removes a location that holds stock.
- [2026-09-20 09:45] AGREED: **where a product IS stays derived from the movements; it is never a field, and never pinned on the map.** Three reasons, each fatal on its own: a product is in several places at once (the shop, the stockroom, the van) and one field can name one; a field is a hand-kept copy of what the movements already know, so it is wrong the moment goods move; and it breaks the day a second establishment opens, since the product is company-wide and its position is per site. Precision comes from the **location tree**, which already runs `site › building › floor › zone › rack › bin`: a rack with three levels is a rack location with three child bins, and stock held at the bin reads as *10 vis · A-12 · niveau 1* as ordinary stock data. A drawn object is tied to a location, and the map then **derives** what each holds — searching a product lights the racks holding it, clicking a rack lists its contents — which stays true for three thousand products with nobody maintaining anything.
- [2026-09-20 09:45] AGREED: **a product gains a home location — where it BELONGS, not where it is.** One nullable location per product per establishment, which is a different question from the stock and so does not duplicate it: a home changes when someone decides it does, not when goods move. It earns its place by answering what stock cannot — receiving **proposes** the right bin instead of offering a long list, the map can show a **slot standing empty** (reorder this) and **goods sitting away from home** (a put-away mistake, visible on the drawing). Three doors write that one column: a **picker** on the product screen, which needs no map and so works from the first day; a **"these products live here"** panel on the map from the location's side, which is how a shop is really set up — standing at the rack, saying what goes on it; and a **`home_location` column in the product import**, because assigning homes is naturally a bulk job. The picker and the import ship with the field; the map door ships with the map.

- [2026-09-20 11:30] AGREED: **the fiscal journal is designed now, and it chains and signs what already exists.** The research that confirmed the three authorization additions also found what none of them addressed: the law is barely about *who may act* and almost entirely about *what the record looks like*. A void must be a new, dated, signed counter-entry with the original untouched (France BOFiP § 90, Germany DSFinV-K 4.2.3, Portugal art. 3 d), and `audit_log` is append-only but **not chained, not signed, and not the sales journal** — so recording who authorised a void there, while the void mutated a line, would satisfy our own rule and breach the law. The door turned out to be far smaller than that framing suggested, and the reason is worth keeping: **documents here are already frozen.** `Invoice::assertDraft('changes')` guards every edit and `creditNoteFor` refuses anything but an issued invoice, so "the original is untouched and the void is a counter-entry" *already holds* for invoices and credit notes. What is missing is the record *over* them. A `FiscalJournal` context therefore gets append-only entries, each carrying the previous entry's hash and a per-company sequence number, signed; originals never mutated; a **`training` flag on every entry**, which France § 150 and Germany § 4.2.6 both require and which we have nothing for today; and reprints marked as duplicates. Invoices and credit notes journal from day one. **The till writes to the same journal when the register module arrives** rather than getting its own — the contract is jurisdiction-neutral on purpose — but its Z-closure is *not* an ordinary entry and must be split from a waiter's purse count, because a Z is a fiscal closure that may never be reopened while a purse count is a commercial act. What this design rests on, stated plainly: the FR, DE and PT texts the research actually read. **Tunisia's adapter waits for JORT n° 125, which nobody has read** — all of the research's § 3.2 is press, and `docs/fiscal/TN.md` currently says nothing about cash registers at all. One correction to anything written earlier: France's editor attestation was **restored on 21 February 2026** by LF 2026 art. 125, so any source saying "certification only from September 2026" is stale.
- [2026-09-20 11:30] AGREED: **the signing key is per company, encrypted at rest, and a KMS is a later adapter.** This is the one-way door in the row above — not the chain, which can be rebuilt, but the key, because entries already signed can never be re-signed elsewhere. A key is generated per company at provisioning and encrypted under a master key taken from the environment. France's self-attestation regime explicitly permits software keys, which is where the product goes first; **Germany's TSE and Portugal's registered RSA arrive as adapters behind the same port**, so a country that demands hardware costs an adapter and not a migration. Rejected: a KMS/HSM port from day one, which is the stronger posture and what a large customer eventually asks for, but would make the **self-hosted** install require a KMS it does not have today, for a demand nothing is making yet; and a single platform-wide key, which is simplest to operate and wrong on two counts — one company's dispute would put every other company's chain in scope, and a self-hosted install cannot share a key the SaaS also holds.
- [2026-09-20 11:30] AGREED: **a module declares its own permissions, and a company edits roles in a matrix screen.** The developer asked how a coffeeshop's cashier, waiter and customer are expressed when only three roles exist. They are expressed by custom roles — and the domain is already ready for them: `Role.company` is nullable and `redefinePermissions()` exists, needing no migration. What is missing is everything around it. **There is no permission catalogue at all** — `Permission` holds only a wildcard and a platform prefix, and the strings live as scattered literals — and `redefinePermissions()` has exactly **one caller, the platform seed**, so roles today are seeded and frozen. So: each module declares its permission strings with their labels beside its existing `DeclaresModule` declaration, the way it already declares its settings, nav entry and home panel, and the catalogue is **collected rather than hand-written** so it cannot drift from the code — a permission added in a module and forgotten in a catalogue file would be invisible on the screen and silently ungrantable, which is the exact drift class this project keeps meeting. The screen lists the company's roles, creates a custom one, and ticks what it may do in a matrix grouped by module; the members page stops offering three hardcoded names and offers the company's own. `RoleBounds` already ranks owner above admin above member and refuses granting above the actor's own rank, and the screen inherits that rather than restating it. Recorded as an accepted limit, not a gap to close now: **row 53's one-role-per-membership cannot express "waiter at site A, manager at site B"** — Toast and Revel both scope the role per location — and the establishment scope ruled separately does not fix it, because it scopes a member to sites, not a role to a site.
- [2026-09-20 11:30] AGREED: **the order is the journal's design, then the roles screen, then Ground, then the map and 3D, then the barcode**, and the developer tests **once** at the end rather than now (their choice against testing the stock slice today). The journal row above is **design recorded, not built** — nothing is implemented for it in this pass — and the roles screen is the first thing actually built, moved ahead of the ground rows because the coffeeshop case is the thing the product cannot express today.
- [2026-09-20 13:10] AGREED: **the fork walkthrough is archived without its passwords**, and, separately, **an installation generates its operator password instead of publishing one.** Salvaging `docs/history/2026-09-twes-fork-walkthrough.md` would have written six live development passwords and the developer's own address into `master`'s history, where removing them needs a rewrite; the table keeps *which* accounts existed and what each could do — the part worth salvaging — and drops the credentials. That raised the real question behind it. Today `app:seed` **refuses to run without `--operator-password`**, so no installation can inherit a default by accident, and the known values (`twes-operator-dev`, the TOTP secret `JBSWY…`) live only in `Makefile:13` and `.github/workflows/ci.yml:122` — the command line, never the application. Two things are still missing and are now a row. **`--generate-operator-password`** mints a strong password and a fresh TOTP secret, prints both **once**, and stores only the hash and the encrypted secret. And seeding **refuses the published development password and the published TOTP secret when the environment is production**, which is the half that actually protects an operator: copy-pasting the command out of `docs/START.md` into a real install must fail loudly rather than quietly ship a password anyone can read on GitHub. The fixed literals **stay** for the local stack and CI, deliberately: Playwright and the functional tests must sign in, so determinism there is the feature, and a failing test has to be reproducible from one command line. Rejected: generating everywhere and writing the values to a gitignored file, which is the purest answer but makes CI depend on a file and costs that reproducibility.

- [2026-09-20 14:20] AGREED: **a role somebody holds is refused, not reassigned, and a custom role ranks below `member`.** Both were asked at the row 104 gate and both keep what the code already does, which is why they are recorded rather than built. `membership.role_id` is `NOT NULL` with no `ON DELETE`, so deleting a held role was going to be a foreign-key error however it was dressed; the refusal now names up to five holders and asks for them to be moved, because the alternative — quietly moving them to another role — is a demotion, or, if the custom role was wider than `member`, a promotion, and neither is something anyone would notice until it bit. On rank: `RoleBounds::rank()` already answers `0` for any name that is not owner, admin or member, so a custom role's holder grants nobody anything even holding `user.write`, and every admin may remove them. That is fail-closed and it is kept deliberately — **it also means a coffeeshop "manager" cannot hire a "waiter"**, which is the developer's own case, and the right moment to answer it is after the matrix has been used, not while guessing at it. Narrowing a permission model later is much harder than widening one.
- [2026-09-20 14:20] AGREED: **permission strings are the API's, the words a person reads are the SPA's, and a gate ties them together.** Every other string a person reads in the product lives in `web/public/i18n/`, and a permission label is no different; the API's own translations stay what the API itself emits (mail, fiscal, PDF). The risk that creates is real and is closed rather than argued away: a permission added to a module manifest is one line, and nothing would have warned that the roles screen then draws a raw `stock.write` where a sentence belongs. `scripts/gates/permission-labels.sh` discovers the strings from the `*Permission` classes, checks each has a non-blank label in both languages, skips the platform family a company never grants, and carries a floor so a discovery pattern that stops matching reds instead of comparing two empty sets.

- [2026-09-20 15:30] AGREED: **every write to a role is audited, and that is also how the screen hears it.** Found at
  row 104's end-of-goal review: `ManageRoles` recorded nothing, which was two defects wearing one cause. A role is the
  object that decides who may do what, so it is the last one that should change unrecorded — and `DoctrineAuditTrail`
  stages each entry as a live change whose kind is the entity type, so with no entry the `reloadOn(['role', …])` the
  roles page already carried was permanently dead code with nothing to report it. The row carries the name and the
  permissions, not just the verb, because "revised" alone cannot answer the question an audit is read to answer; the
  delete is recorded before the row goes, since afterwards that content exists nowhere. Each write runs in a
  transaction, as the sibling use cases do.
- [2026-09-20 15:30] AGREED: **a gate checks the branch as well as the leaves.** `permission-labels.sh` passed green
  while `modules.invoices` and `modules.expenses` — the headings over the two biggest groups in the matrix — were raw
  dotted keys, because the gate enumerated the permission labels and not the group headings above them. It now checks
  both. The follow-up is the sharper lesson: pointed at the `KnownPermissions` PORT, whose file exists and declares no
  groups, the discovery silently lost three headings and still read as a pass — the floor caught it only because it
  sat above the number the module half alone produces. A discovery input that names a file must red when the file
  yields nothing, and a floor set to exactly what currently passes is not a floor.

- [2026-09-20 15:50] AGREED: **straight through the agreed order, no mid-way checkpoint** — row 104's remainder, then
  70, 71, 72, 73, 45, then the map and 3D view (row 83), and the developer tests ONCE at the end. Asked because the
  remaining scope measured ~14-18 hours across several sessions and row 45 declares the actions rows 71 and 72 render,
  so reordering it first would have saved building the toolbars twice; the developer chose the agreed order anyway.
  Recorded so the cost is a known one rather than a surprise: a design disagreement in row 70 is found after
  everything has been built on top of it.

- [2026-09-20 16:10] AGREED: **a role's name, not a fixed three, is what the members page offers and the API takes.**
  Row 104's remainder. `MemberResource` carried `Assert\Choice` on owner/admin/member, which could never be right:
  which names exist is a property of the COMPANY being acted for, and a class-level attribute has no request in
  sight. The check moved to `InviteToCompany`, which resolves the name against that company through the new
  `RoleRepository::ofNameForCompany` — so a role belonging to ANOTHER company is as unknown here as an invented
  name, and answers the same 422 with a message that can say which company it looked in. `MemberRole` in the SPA
  stopped being a union of three literals for the same reason: a union there is a second, quietly wrong answer to a
  question only the API can settle.
- [2026-09-20 16:10] AGREED: **an open invitation counts as holding its role.** `AcceptInvitation` resolved the role
  the same built-in-only way, and an invitation names its role by NAME with no foreign key — so deleting a role
  somebody had been invited at raised nothing at the database, and the invitation simply stopped being acceptable,
  which the person discovered by clicking the link. The delete refusal now counts invited addresses beside the
  members and names them the same way, and accepting an invitation whose role is gone is refused rather than
  resolved to some other role, because joining at a role nobody chose is a silent grant.

- [2026-09-20 16:50] AGREED: **row 70 built as ruled, with two details the code forced.** The four findings landed as
  written: the search starts after the menu button and grows to meet the right-hand controls, capped at 800 px, its
  placeholder naming what it finds; a phone shows the working company where the wordmark was, truncated, switching
  company when tapped; settings fold the app menu to its 80 px rail with the settings list docked against it; and
  the top bar's gear is gone. Two things the design did not say and the code decided. **`main` gives the settings
  area its whole width**: the list cannot dock against the rail from inside a container with `mx-auto max-w-6xl`
  and a gutter, so the shell drops both on a settings address and the area supplies the page's gutter itself.
  **The account menu's settings entry went with the gear**, which finding 8 implies rather than states: on a phone
  the drawer IS "Plus", so the sidebar entry is already the one way in and the account menu's was the second
  control finding 8 exists to remove. `isSettingsUrl` answers which addresses are inside the area, and is
  deliberately not a prefix test — `/settings-of-mine` is not `/settings` and `/companies` is not `/company`.

- [2026-09-20 17:40] AGREED: **row 71 built as ruled, with one departure the markup forced.** Every list declares
  its actions once as `RowAction`; the trailing column shows the frequent ones as icon buttons, folds the rare and
  the destructive behind "⋮", and is sticky against the table's right edge. The eleven settings tables now declare
  theirs the same way, so `appDataListRowActions` — the projected template each of them used — is **deleted**: one
  declaration, no second way to put a control in a row. The departure: the ruling says *"on a phone each row is a
  card, the whole card the link"*, and the card's **heading** carries the link instead. A card holds the row's own
  buttons, and an `<a>` wrapping a `<button>` is neither valid nor operable — the same reason the table row is a
  link on its name rather than on the row. Below 600 px the table is replaced by cards, one per row, keeping the
  row's test id, its link and its controls unchanged so a phone is not a second vocabulary; the boundary is the
  shell's own `WINDOW_CLASS`, injected, so the two cannot disagree about where a phone ends. Three details the code
  decided: an action that navigates carries `linkQuery`, since the address is what says whose movements these are;
  `labelParams` lets an icon button name its row ("Supprimer Zone 1"), because a screen reader reads a column of
  identical "Supprimer" otherwise, and the four keys the old visible labels used are gone with them; and the
  trailing column's width is paid for only where actions are declared, which is what a list with none no longer
  pays. A row's id is a uuid, so an e2e finds an action INSIDE its row (`web/e2e/rows.ts`) rather than by an
  address it would have to guess.

- [2026-09-20 19:10] AGREED: **row 72 built as ruled, and "Dupliquer" had to be built to be offered.** A document
  declares its actions once as `DocumentAction`; the bar beside its title is sticky, shows the state's next step as
  its one filled button — Émettre a draft, Livrer a validated note, Facturer a delivered one, Enregistrer un
  paiement an open invoice — keeps the frequent ones beside it and folds the rest into "⋮". Nothing destructive is
  ever a visible button, whatever its frequency, and cancelling now ASKS in a dialog rather than turning one button
  into two: the action lives in a menu, and a menu entry that becomes two entries is a place to misclick. The
  ruling names "Dupliquer" among the frequent actions and nothing of the sort existed, so it is built here:
  `Invoice::duplicateOf` copies the parties, the lines, the document taxes and the typing into a new draft and
  copies none of what the original EARNED — its number, its state, its payments, its corrections. Its supply date
  is dropped too, which the ruling did not say and the domain decided: a supply date is a fiscal claim about a
  particular day, and copied onto a document made weeks later it is silently wrong. A credit note refuses to be
  duplicated: it belongs to the invoice it corrects, and a copy would correct that invoice a second time. A locked
  document (issued, delivered, cancelled) is read through `RecordView` — parties, lines as the table they already
  were, totals — with every field the document does not fill in left out, and a section left out with its fields;
  a disabled form says "you may not change this" where the truth is "this no longer changes". Only a draft is a
  form. Recording a payment and choosing a delivery day are both asked in dialogs, which is also what takes the
  date field out of the delivery note's bar.

- [2026-09-20 18:20] AGREED: **row 73's record half built as ruled, with one departure and one honest definition of
  "changed".** Every record page — client, produit, fournisseur, dépense, profil de l'entreprise — saves from
  `RecordBar` in the same sticky bar as its title: "Enregistrer" is the primary button and is INERT until something
  changed, beside "Annuler les modifications" and an announced count of what is unsaved. What counts as a change is
  measured against **what the API last answered**, not against Angular's `dirty` and not against a control's
  `defaultValue`: `dirty` says a field was touched, so typing a character and deleting it again would have left the
  page claiming an unsaved change, and `defaultValue` is `null` on every control that is not `nonNullable`, which
  would have called each optional field changed from the moment the page opened. "Annuler les modifications" puts
  the form back to that same answer. The departure: the ruling's tab list names "Fiche and Valeurs par défaut now,
  Documents and Historique when they exist", and the customer page has a **third tab, Contacts** — its contacts
  section is a list with its own editor and would otherwise be the very thing the ruling removed, a second section
  below the first screen. The rest of the ruling's clause, **leaving with changes asks first**, is row 45's and is
  not built here. Row 73's second half, the balance pass (design review finding 10, dense vs empty per screen),
  stays open: it is measured on the gallery, which the rows above have changed.

- [2026-09-20 18:55] AGREED: **the balance pass is two measured corrections, not a redesign** (design review finding
  10, re-measured on the gallery after rows 70-73 changed the layouts: every screen at 1440 × 900, reporting the
  content column's width, the page's height and what each table's columns ask for against the room they are given).
  **A page whose content is a list is no longer capped**: ten settings and reference pages carried `max-w-4xl` or
  `max-w-5xl` while their area offered more, which is half of what "half empty yet its table cut" described.
  **A column declares the width it HOLDS**: every column took the 160 px default whatever it carried, so six of them
  plus the row actions asked for 1056 px where the settings pane offers 968 — the numbering, custom-fields and tax
  tables were each cut by 90 to 162 px, with "Prochain numéro" showing as `AV-202`. They now ask 966 and fit.
  **A form page's cap goes from 896 px to 1152**: measured, its fields were 250 px wide and already overflowing
  their own box by 7 px, inside a content area of 1152. What the pass did NOT do is shorten the long forms: the
  customer record is 2479 px because it has some fifty fields, and the answer to that is the tabs of 23:18 and the
  per-screen actions of row 45, not a layout trick. The gallery itself was stale and is fixed in the same change —
  it opened a record through the "Ouvrir" link row 71 deleted, so every record page would have been reported
  missed; it now follows the row's own link, and all 41 screens were reached.

- [2026-09-20 19:10] AGREED: **"validation before the kitchen starts" is three mechanisms, and only one of them is
  a gate** (developer's question, researched in `var/claude/biz/order-acceptance-hospitality.md`). The developer's
  reason was that only the barista or the chef knows whether a dish still exists, which is true and is not an
  argument for a gate: per-order validation asks the kitchen **two hundred times a day to tell you three facts**,
  it is slowest exactly at the rush when the pending queue is longest, it answers after the guest has committed and
  the waiter has left the table, and after the two-hundredth "yes" the system has learned nothing. The three things
  are separated instead. **Does it exist** — availability as state: sold out in one tap reaching every phone and
  every open QR menu (02:30's ruling, now load-bearing), a morning count that reaches zero by itself, and — the
  part that makes the list maintain itself — **a station's refusal of a line as `rupture` marks the item out
  automatically**, so the knowledge is captured from the one act the kitchen will certainly perform. **Has the
  kitchen seen it** — the ticket's own state, with an alarm on a ticket nobody has touched after N minutes; the
  waiter's certainty comes from the ticket shouting, never from the order waiting. **Is it real** — acceptance,
  and 02:05's rule stands unchanged: only an order from a channel with no member of staff present (QR, à emporter,
  phone, portal) waits; a waiter's own order is created accepted, because the waiter at the table IS the
  confirmation. No researched system gates a staff-taken order and neither do we.

- [2026-09-20 19:12] AGREED: **one state vocabulary for the café table, the counter and the portal, carried on the
  LINE.** `Commandé → En préparation → Prêt → Servi`, with `Prêt à retirer` / `Retiré` substituting for the last
  two wherever the goods are collected rather than served. Three states appear only where they are true:
  `En attente de validation` (never on a waiter's own order), `Refusé` (someone said no) and `Annulé` (it was
  called off), which stay distinct because they are different events. The state belongs to the line and not to the
  order, since a coffee is ready while the dish is still cooking and one state on the order could only lie about
  one of them; **the order's state is derived** — "3 sur 5 servis" — plus its own `Réglée`. `En préparation` is
  entered when the station opens the ticket, which collapses "seen" and "started" into one honest state rather
  than asking a cook for two taps; a general "en attente" was refused because a guest cannot read what it waits
  for.

- [2026-09-20 19:15] AGREED: **the service floor's three controls, and who holds them.** *Marking an item
  `en rupture`* is a permission in the catalogue row 104 already collects, **granted by default to every role that
  takes or prepares an order** and removable by the owner from any of them — whoever finds the empty tray must be
  able to say so without finding a manager, and the audit log records who said it. *A locked tab* — no new lines
  on a table being settled — is set **automatically when settlement begins** and by hand by anyone serving, and
  **never expires on a timer**: a tab that quietly reopens mid-bill is the very bug the lock prevents. It releases
  when the bill is cancelled or when a human lifts it, and the floor shows who locked it and why; a guest whose QR
  is still open is told the table is being settled rather than failing silently. **Both directions carry a
  permission** — locking and lifting — both granted by default, either removable by the owner (developer's ruling,
  against a first proposal that only lifting should carry the right). *A suspended table* accepts nothing from its
  QR — it is being cleaned, or someone is sending nonsense from the pavement — **timed by default (30 minutes) so
  no table is lost for an evening**, with an explicit "until I lift it" for a table out of service. It joins the
  table states of 03:00 (free, occupied, ordered, served, to clean) rather than being a new concept.

- [2026-09-20 19:20] AGREED: **the customer portal is re-opened, and it is a customer-account feature, not a shop
  feature.** It leaves § 2's *Out, with no date* list, where it had sat since the first spec commit (G0,
  2026-09-09) **with no reason ever recorded** — the only recorded reasoning is 03:20 today, which is about
  keeping it out, not about putting it there. The four reasons that actually stood were: the signed expiring link
  answers the document half for one point where a portal costs eight; the security model has **no third kind of
  principal**; an outside person signing in is a data subject, which pulls row 51 (legal pages, RGPD) from "before
  launch" to "precondition"; and the evidence says the ordering half asks a customer's staff to change a habit
  that competes with a phone call that works. What changes the balance is the developer's own observation that the
  same feature serves a **café giving a business account to a nearby company** — so the portal is designed
  catalogue-shaped from the first line, never quincaillerie-shaped. It is scheduled **after the first working
  version** and is not given § 8 rows yet, deliberately: § 2 orders it in prose with the café work, and the table
  is the working queue.

- [2026-09-20 19:22] AGREED: **a portal principal is a contact, never a `Membership`.** Route (i) — "a customer is
  a user with a customer role" — is refused explicitly so nobody re-proposes it as a small change: permissions are
  decided company-wide and not row-wide, `CompanyFilter` scopes to the company and not to the row's owner, and a
  `company:` realtime channel would push every invoice, customer and role change in the company to that customer's
  browser **by design**. Route (ii) is the ruling: a separate firewall, a separate session, a separate voter, and
  its own resources — never the staff ones, which carry `notesInternal`. The one thing to pin with a sabotage
  before anything ships: `CompanyFilter` is **off** until `CompanyGuard` switches it on, and a portal path does
  not pass through that guard, so **a portal request that reaches a repository without both its company and its
  customer scope must FAIL**, not quietly return rows. Two switches, **both off by default** — the module per
  company, then access per contact — which the registry cannot express today (`ModuleStates`: an absent row means
  enabled), so a `defaultEnabled` on the manifest is part of the work.

- [2026-09-20 19:25] AGREED: **portal access is granted, never claimed, and the two keys are asymmetric.** There is
  no sign-up page: the merchant opens a customer's record, picks a contact and invites him; that first person is
  the customer's *responsable du compte*. **He may propose colleagues**, who land `en attente d'approbation` and
  can do nothing until the merchant approves — so the set of people inside the merchant's data is always a list
  the merchant approved. **Removal never waits for anyone**: either side may cut anyone, including the merchant
  cutting the responsable, which is what makes a departed employee a one-tap problem rather than a phone call. The
  merchant always sees the roster — who can sign in, who invited them, when they last did, revoke beside each —
  and an approval nobody answers lapses. Two portal roles only: **may order**, or **documents only** (the
  accountant who reads invoices and the balance and may never commit his company).

- [2026-09-20 19:30] AGREED: **a customer orders from a basket, not from the invoice editor.** The invoice editor
  asks questions only the merchant may answer — which tax, what discount, which series — and a customer answers
  none of them. The first version has **two ways in**: search his catalogue, and **reorder from his history**,
  which is where the value is and which works on the day the portal is switched on because his past orders and
  delivery notes already exist. A **barcode scan** arrives with the barcode row and the basket takes it without
  redesign; a **saved usual list** waits, because it is "history, pinned" and a customer cannot know his usual
  list before he has ordered. Baskets are drafts, **several of them, each named** ("Chantier Menzah 6"), which is
  free because a basket is an unsubmitted quote. A line carries the product, the quantity in **his** unit, **his
  price from his price list** — not the shelf price, since prices that disagree with the counter are the first
  reason a trade portal dies — and availability as a **band** (`en stock / dernières pièces / sur commande`):
  **nothing is reserved**, the merchant's acceptance is what commits the goods, and no reservation machinery is
  introduced. Submitting asks for his own reference or PO number (**offered, never blocking**), where and when he
  wants it, and a comment. A portal order **is a customer-created quote** — 2026-09-20's rule that an accepted
  quote is the commitment stands, there is no portal-order type — so it enters `En attente de validation` and runs
  the same states; **accepting part of it is a counter-offer**, so he is told what dropped and what the new total
  is. He never sees internal notes, cost, margin, exact stock, another customer or a document that is not his, and
  he can never set a price, choose a tax or make a document exist. When it is ready he is notified and his screen
  shows a short code; the counter reads the code and the order becomes a delivery note. **Payment stays out of the
  portal** — gateways are out, and an account customer pays on his terms.

- [2026-09-20 19:35] AGREED: **a café is a business whose catalogue is its menu, so the portal serves it unchanged**
  (developer's idea, and it is the one that reframed the feature). An approved company orders coffees or meals, on
  site or to go; the menu is already ruled a presentation over the same products, so nothing new is needed to show
  it. Three differences: **time is the order** ("12 cafés pour 10h30"), so the café's equivalent of reorder-from-
  history is a **standing order** — the same twelve coffees every weekday; the order **routes to the stations**
  exactly like a QR order, because that is what it is, an order from an approved-but-remote channel that waits for
  a human; and **payment leaves the till** — the café chooses **per customer** between on account invoiced monthly
  and paid at collection (developer's ruling, over a recommendation of monthly only). That choice is recorded with
  its cost: the same feature then has **two fiscal paths**, a till receipt and an invoice, and both must be right.
  Whether an account order invoiced monthly sits outside Tunisia's homologated-register obligation is **[Unverified
  — JORT n° 125 is still unread, § 9]** and is not a design assumption. The portal is also the one ordering channel
  that needs no fraud control at all: the prank order, the fake table and the no-show cannot exist for a company
  the business approved and invoices.

- [2026-09-20 19:40] AGREED: **reservations are re-opened as a path, still built last** (developer's ruling,
  amending 03:00's "deliberately out"). What makes a booking system a product of its own is **the stranger, not the
  table**: no-shows, spam, confirmations, reminders and deposits all exist because anyone may book and nobody is
  accountable. Three tiers, in this order. **Tier 2 first — the café books on the guest's behalf**: a waiter
  records the table, the time, a name and a number on the room `Venue` draws. No public surface, no security model,
  and it is most of a small café's real bookings. **Tier 1 with the portal** — an approved account attaches a table
  and a time to its order, accountable by construction. **Tier 3 last** — a stranger books himself, behind a phone
  number confirmed by a WhatsApp or SMS code, one live booking per confirmed number, repeat no-shows blocked by
  number. Note what tier 3 cannot have: **a deposit is impossible while payment gateways are out**, so the phone
  confirmation and the no-show history are the entire control — which is worth knowing before promising it.

- [2026-09-20 19:45] AMENDED, correcting 02:50 in place: **module manifests DO declare dependencies and the
- [2026-09-20 22:10] AGREED: a screen declares what it offers ONCE (row 45), as `ScreenAction` in `web/src/app/shared/actions/`, and the toolbar beside the title, the Ctrl K palette, the keyboard and the "?" sheet all read that one declaration through the root `ScreenActions` registry — a screen cannot offer an action in one of them and lack it in another. Consequences, each pinned by a test: an action's CLASS is derived, not declared twice (`confirm` present means it asks), and the ruling's third class `undo` has no field because no server-side bin exists — a class nothing can produce is a promise, not a type, and the bin is its own row. A shortcut is one character compared against what the keyboard PRODUCED, so one declaration works on AZERTY and QWERTY; Alt is NOT refused, because AltGr is how an AZERTY board types "[" at all, while Ctrl without Alt and Meta belong to the browser. The reserved list refuses Enter/Escape/Tab/space/the arrows/Backspace, Firefox's "/" and "'", and the shell's own "?" and "[" — a key claimed twice on one screen is refused where it is written, on every read rather than once, because a screen recomputes its list. The palette leads with the screen's own actions, leaves out what is refused for now, and is not permission-gated there (the screen already decided). Leaving a page with unsaved changes asks: the count every record page already keeps declares itself through `unsavedChanges`, and `guardUnsaved` puts the guard on every signed-in route as one mapping rather than one route at a time, so a page added later cannot be left unguarded. **Scope, stated plainly: only the invoice and delivery-note pages DECLARE so far** — the four `RecordBar` pages (customer, product, expense, vendor) do not, so on them `s` saves nothing, Ctrl K shows no "Sur cette page" group and "?" says the page offers none; `RowAction` was not migrated onto the shared shape either. Wiring those four and `RowAction` is a follow-up row, as is the server-side bin the `undo` class would need.
- [2026-09-20 22:40] AGREED: what is GLOBAL updates what is on screen, without a refresh of the browser (developer, testing). Three defects of one shape, each fixed at its cause rather than per screen. (1) The menu's fold toggle was drawn inside settings and INERT — the rail was forced for the whole area, so the button and the "[" key wrote a preference nothing read. The settings area now keeps its OWN answer (`presentation.sidebar-settings`, default `rail`), so both menus fold and unfold and each still opens as it does today; folding one does not fold the other. (2) Switching company refreshed the session but left the open screen showing the previous company's rows. The routed outlet is keyed on the company a person acts for, so a switch REBUILDS every signed-in screen; `authGuard` awaits the session before the shell activates, so a cold load still builds each page once. Because a rebuild discards what was typed, `switchTo` asks first through the same unsaved-changes guard as leaving a page. (3) A company-level setting saved on the settings screen — the accent, the density, the language — changed nothing until the browser was refreshed: that screen writes whole rows through `SettingsApi`, and a live change NEVER comes back to the tab that caused it (the `X-Tab` header names it), so only other tabs heard. `SettingsFacade.refresh()` re-reads the chain, and the settings screen calls it after every accepted write. The general rule this leaves: a screen that writes something the rest of the application displays must make it visible in its own tab; `LiveChanges` covers every tab but that one.
- [2026-09-20 23:05] AGREED: a screen that writes something the rest of the application reads calls `AuthFacade.refresh()` itself, and the sweep behind that rule found six screens that did not: changing a ROLE's permissions (your own rights, so the menu and every permission-gated control stayed as they were), adding or removing a MEMBER, accepting an INVITATION (the new company was missing from the switcher), an operator approving or rejecting a company, setting or stopping a SUBSCRIPTION, and confirming a payment declaration. `refresh()`, never `load()`: the four screens that already re-read the session used `load()`, which SIGNS THE PERSON OUT when the API is unreachable — after a write that already succeeded, a moment of network trouble cost them their session. `switchTo` is the one deliberate exception and says so in place: the server has already moved, so keeping the old session would show one company while acting for another. Two notes from the same sweep: `me().company.name`, `currency`, `locale` and `timezone` are never written by any screen (`reviseProfile` sets `legalName`; the rest are constructor-only), so the shell's comment naming the company's name was drift and is corrected; and the customer- and product-level settings writes need no live refresh, because they are not on the chain the shell reads.
- [2026-09-20 23:50] AGREED: going to the record a page just created is NOT leaving unsaved work, and the page says so with `UnsavedChanges.savedAndLeaving()` before it navigates. Row 45's leave guard otherwise asked on all six create screens and HELD the navigation on `/customers/new`; four e2e caught it in CI and no unit test did. The reason is structural and worth keeping: a create page's form holds what was TYPED while the record it now compares against holds what the API ANSWERED, so a normalised value reads as a change on a record that is already saved. Writing the API's answer back into the form (`LiveRecord.savedHere`, which the revise branch does) does NOT settle it — measured, the count stayed at 8 — so the statement is explicit rather than derived. It is consumed by the very next question, which is always the one that navigation asks, so it cannot leave a later genuine leave unguarded. Certified by running the four CI-failing scenarios against a rebuilt web image: `customers`, `custom-fields`, `expenses` and `live` all pass.
  registry DOES refuse an impossible combination.** 02:50 says this is a gap to be filled; it was already built on
  2026-09-14 (`ModuleManifest::$dependencies`, `ModuleCatalog` refusing an undeclared dependency or a cycle at
  container build, `ManageModules` refusing to switch one off while a dependent is on), and 2026-09-14's own ruling
  states it. Row 94's real remaining work is **profiles as data**, not the registry. Recorded because a session
  reading 02:50 would rebuild what exists. Two further findings from the same study, kept here so they are not
  lost: **`Venue` must live at `api/src/Module/Venue/`**, since `ModuleOwnership` maps a class to its module by the
  `App\Module\<Name>\` prefix alone and a context outside `src/Module/` **owns nothing**, so a `Venue` placed at
  `api/src/Venue/` would silently escape the switch that is supposed to govern it; and **`DisabledModuleGuard`
  fires only on a path carrying `{companyId}`**, so a portal or guest API shaped `/api/portal/…` would not be 404'd
  when its module is off — such a path must keep `{companyId}` or the guard must learn a second way to resolve the
  company.

- [2026-09-21 01:45] AGREED: **a stock move is one operation that writes two linked movements** (row 74, first half). `KeepStock::move`
  locks the source stock the way a count does, reads what is on hand AFTER the lock, and saves what left and what arrived in one
  transaction under one move id, staging a single `stock.moved` live change. `StockMovement::move` refuses the same location on
  both sides, a destination in another establishment and a quantity of zero; the application layer refuses more than is at the
  source. The HTTP surface is the existing `POST /stock-movements` with `operation: move` and `toLocationId`, answering the
  movement that LEFT, whose `sourceId` names the pair. The screen offers it as a third button beside receive and count.
  **Certified by execution: the domain, the use case and the HTTP surface** (`InventoryTest`, `KeepStockTest`, `StockMovementTest`,
  two sabotages each red). **NOT certified by execution: no e2e** — a location that has seen a movement is kept (409), so a browser
  scenario moving into the e2e location could not delete it and would leak one location per run into the shared company.

- [2026-09-21 03:20] AGREED: **the drawing is its own context, and the consumer owns the binding** (row 83, API half; the seam
  of 2026-09-14 made real). `Venue` holds `venue_area` — one floor of an establishment, at most one per level, with an optional
  floor image kept beside HOW MANY METRES WIDE that image really is — and `venue_spot`, a rectangle on it in METRES (x, y, width,
  depth, whole-degree rotation, height). Metres and not pixels: a floor plan is rescanned and recropped over a building's life
  while the building does not move, so a drawing stored in the image's own units would shift every rectangle the day somebody
  uploads a better scan. A spot carries no code, name or kind — `stock_location.spot_id` (nullable, `ON DELETE SET NULL`) is the
  binding, and a café table will point at a spot the same way, so neither domain owns the drawing. Four consequences. **(1)** A
  location not drawn is still a location: removing a rectangle undraws it, never deletes it. **(2)** A location is drawn only on
  a floor of its OWN establishment, refused in the entity — otherwise a Sfax rack lands in the Tunis warehouse and no screen can
  show it. **(3)** Every change is audited (`venue_area.*`, `venue_spot.*`), which is also the signal open plans reload on: a use
  case recording nothing would leave every other screen on yesterday's drawing with no error anywhere. **(4)** Dropping a
  rectangle where it already was records nothing, so a drag that went nowhere does not tell every other tab the plan moved.
  **Certified by execution: the domain, the use case, the binding and the migration both ways** (`VenueSpotTest`,
  `ArrangeVenueTest`, `StockLocationTest`, `migrate prev` then `latest` then a clean `schema:update --dump-sql`; three sabotages
  red). **NOT certified by execution: no HTTP surface and no screen yet** — the resources and the 2D/3D views wait on the canvas
  being approved (the rule of 19/09), which is why row 83 stays `doing`.

- [2026-09-21 06:10] AGREED: **the map's HTTP surface belongs to the module, not to the venue** (row 83, second half).
  `Venue` stays a LIBRARY context with no endpoints of its own, the way `Files` is: a permission belongs to a module
  (`InventoryModule::manifest()`), and the venue is not one, so a floor plan of a warehouse is read by whoever may read
  stock and drawn by whoever may arrange it. The endpoints are therefore the inventory's — `/companies/{id}/stock-floors`,
  `.../stock-floors/{id}/drawings` and `/companies/{id}/stock-drawings/{id}`, behind `stock.read` / `stock.write`. A café
  will add its own under its own permission, over the same two tables. `DrawStockMap` is where the rectangle and the
  binding are written as ONE unit of work, and it carries the rules neither half can hold alone. Five of them.
  **(1)** A drawing always names a location: the rectangle knows nothing, so an unbound one would be unlabelled on every
  screen and reachable from none — the reader returns bound rectangles only. **(2)** The location is resolved BEFORE the
  rectangle is placed, so a drawing refused for an unknown location leaves nothing behind. **(3)** A location is in one
  place, so it is drawn in one place: drawing it again, or handing its rectangle to another location, ERASES the one it
  had rather than leaving it unbound. **(4)** Removing a floor unbinds what was drawn on it and then takes the
  rectangles; the locations keep their code, their tree and their stock. **(5)** An unknown `locationId` answers 422
  naming the field, not 404 — it is a field of what was sent, not the thing being addressed — while a caller without
  `stock.write` answers 404 like a stranger, which is `CompanyGuard`'s standing rule, not this surface's.
  One path stays outside the composition and is accepted: deleting a stock location that is drawn leaves its rectangle
  behind, bound to nothing, because the foreign key runs from the location to the rectangle. It is on no screen (the
  reader skips it) and removing its floor sweeps it — a dead row, never a rectangle somebody sees and cannot label.
  Also ruled here: `VenueArea::showPlan` returns whether anything changed, like `rename` and `moveTo`, so re-saving an
  unchanged plan form records nothing and tells no other tab to reload.
  **Certified by execution: the composition and the HTTP surface** (`DrawStockMapTest`, `StockMapTest`, `ArrangeVenueTest`;
  six sabotages red — and a seventh, an inner join turned LEFT in `drawnInCompany`, stayed GREEN and is recorded as an
  EQUIVALENT mutant: the join is not what filters undrawn locations, the reader's own null check is).
  **NOT certified by execution: no screen** — the 2D and 3D views still wait on the canvas being approved (the rule of
  19/09), so row 83 stays `doing`.

- [2026-09-21 07:05] AGREED: **a row's own destructive control asks, through the same rule everything else asks
  through** (row 106, first half). Measured, not suspected: `RowAction` had no `confirm` field at all and
  `DataList.runAction` was `action.run?.(row)`, so **five destructive row actions ran on the click with no question
  anywhere on the page** — removing a member's access, deleting a customer group, a contact, a product category and
  a stock location. `ScreenAction` had carried `confirm` since row 45 and the toolbar, the keyboard and the palette
  all asked; the table was the one surface that did not, and nothing said so because nothing tested it.
  The fix is the shared shape the row asks for, not a second rule beside the table: `runAction` now takes a
  `RunnableAction` — `{ disabled, run, confirm }` — which `ScreenAction` satisfies structurally and a `RowAction`
  bound to its row is built into, so the one rule (refused while disabled, asked before what is irreversible, run
  otherwise) has exactly one implementation. Two consequences. **(1)** `RowAction.confirm` is a FUNCTION of the row,
  because over a list the question has to name what is about to go: "Supprimer 000 › Z1 — Zone froide ?" is
  answerable where eleven identical "Supprimer ?" are not. **(2)** `ActionConfirm` therefore gained `messageParams`,
  which `ConfirmDialog` interpolates — the first thing here that needed a question to carry a value.
  **Certified by execution** (`data-list.spec`, `members-page.spec`, `customer-page.spec`, `customer-groups-page.spec`,
  `product-categories-page.spec`, `stock-locations-page.spec`; web gate 1248 tests; four sabotages red — the run
  going straight through, the question losing its parameters, a dismissal counting as a yes, and one page's `confirm`
  deleted). Worth recording about the fourth: it first read GREEN because the batch's include list left out the spec
  that covers an Escape dismissal — **a sabotage is only evidence against the tests it actually ran**.
  **NOT certified by execution: no e2e** — no browser scenario deletes a row through the "⋮" menu today, so the
  dialog has not been driven in a real browser.

- [2026-09-21 07:50] AGREED: **the bar beside a record's title draws the page's declaration, it does not carry its
  own** (row 106, second half; row 106 closes). `RecordBar` had `busy`, `saveLabel` and `(save)`/`(revert)` outputs,
  so the five record pages — customer, product, expense, vendor AND `company-profile`, which the row's own scope
  line missed — offered a save the KEYBOARD knew nothing about: "s" saved nothing there, Ctrl K showed no
  "Sur cette page" group and "?" said the page offered none, while a save button sat beside the title all along.
  It now takes `actions: readonly ScreenAction[]` and renders **every** one whose `shown` is not false, keyed
  `record-<id>`, running each through the same `runAction` + `ConfirmDialog` as the toolbar. Three consequences.
  **(1)** Nothing is looked up by a hardcoded id: `record-save` and `record-revert` fall out of the ids the page
  declares, so the ~50 test-id references and the nine e2e that click `record-save` are untouched, and a page that
  declares a third thing gets it in the bar as well as in the palette. **(2)** The gate moved OUT of the template:
  the `@if (mayWrite())` around the bar was the same rule as the declaration's `shown`, written twice, and the
  declaration is now the only one — which is also what makes a reader's "s" reach nothing. **(3)** The next step is
  drawn last, where a hand ends up, so `primary` decides position rather than each page's markup.
  No `confirm` on revert: `guardUnsaved` already asks when leaving a page with unsaved work, and the only behaviour
  this changed is that "s", Ctrl K and "?" now work here.
  **Certified by execution** (`record-bar.spec` rewritten against a declaration, `customer-page.spec` asserting
  `ScreenActions.forKey('s')` tracks `changes` and is absent for a reader; web gate 1253 tests; four sabotages red
  — the declaration never reaching `ScreenActions`, the bar ignoring `disabled`, the page claiming another key, and
  nothing drawn as the next step).
  **NOT certified by execution: no e2e presses "s"** — and worth knowing before writing one: `isTypingTarget`
  refuses a shortcut while focus is in an input, which on a record page is nearly always, so "s" saves only after
  the field is left. That is the same on the invoice page and is the ruling's own rule, not a defect here.

- [2026-09-21 09:30] AGREED: **a home is an attribute of the PRODUCT, and it proposes rather than rules** (row 101;
  row 101 closes). `product_home_location` holds one location per product per establishment, unique on the pair, so
  setting a home where the product already had one MOVES it instead of adding a second. The establishment is never
  sent: a location already knows where it is, and two sources for one fact drift. Five consequences.
  **(1) The permissions are `product.read` / `product.write`, not the stock ones.** It sits on the product screen
  and in the product file beside the rest of a product's attributes, and it authorizes nothing — stock may still be
  put anywhere. Keying it on `stock.write` would have meant a product file could set a home only for somebody who
  also arranges the warehouse, which is not who fills that file in. The SCREEN browses locations, so its tab is
  shown with `stock.read` and the module on — nobody points at a shelf they are not allowed to see — and
  `product.write` decides whether that tab is editable: a reader sees where the product lives and changes nothing.
  **(2) A proposal is offered only where it is unambiguous.** `KeepProductHomes::proposed` answers a location only
  where the product has exactly ONE home; a product at home in two establishments comes back without one, because a
  picker knows which product was chosen and not which site the goods are arriving at — a wrong shelf proposed is
  worse than none, since it is accepted without being read.
  **(3) It fills the box and never overwrites a choice.** The stock page proposes into `locationId` only while the
  control is pristine; a move is left out entirely, because its location is where the goods are NOW, which a home
  does not say, and proposing the home as a destination would be refused whenever the goods are already there.
  **(4) Products reaches the warehouse through a PORT it declares**, `Products\Application\ProductHomes`, answered
  by `Inventory\Infrastructure\Products\InventoryProductHomes` — the `ProductStockHistory` shape. The import column
  needed a location looked up by code and a home set, which written directly would have inverted the module
  dependency; what a product file needs OF the inventory is declared in the catalogue and implemented in stock.
  **(5) The `home_location` column exists only for a company that holds stock.** The port's `offered()` reads the
  module switch, so a company without it is not asked for a shelf — and because `RunImport` refuses an unknown
  header outright, a file naming the column is rejected BY NAME rather than having the cell quietly dropped. A
  blank cell keeps whatever home the product has, like every other cell in upsert mode.
  **One defect this found, which would have shipped**: `KeepProductHomes::set` left the home MANAGED while the
  import detached its product, so the next row's flush walked `ProductHomeLocation#product`, found an object
  Doctrine no longer knew and took it for a new entity — every product file with TWO `home_location` cells would
  have answered 500. Caught by writing the three-row test before believing the one-row one; the adapter now drops
  the home from the unit of work, and says why it does so there rather than in the use case the HTTP surface
  shares. The row's home is also RESOLVED before anything is written, where every other cell's refusal is resolved:
  it fired after the product was created, and only the whole import being rolled back kept that from showing.
  **Certified by execution** (44 tests / 2162 assertions over the six import, inventory and home suites, PHPStan
  clean; `ProductHomeTest` 5 cases including "two homes propose neither"; `ProductImportTest` 12, covering the
  column, the blank cell, three rows each getting their home, `unknown_location` / `ambiguous_location`, a rejected
  row leaving no product behind, and the module-off refusal; `product-homes.spec` 7, `product-homes-facade.spec` 5,
  `product-page.spec` 13, `stock-page.spec` 10 — two PRE-EXISTING cases went red on `l1` → `l2` the moment the
  proposal landed, which is what proves it fires).
  **Six sabotages, six red**: two homes proposing one anyway; an ambiguous code silently taking the first location;
  the column offered whatever the module says; the home left managed (the defect above, re-armed); the proposal
  overwriting a location the person chose; and a move proposing the home as its source — that last one caught by a
  test that already existed, so the move guard was pinned before it was written.
  **NOT certified by execution: no e2e sets a home or receives goods into a proposed one**, and nothing proves the
  proposal in a real browser — the pristine/dirty rule is asserted against the form control, not against a click on
  a `mat-select`.

- [2026-09-21 10:40] AGREED: **the drawn map's eight decisions, approved on the canvas** (row 83, web half; the
  canvas is "The Drawn Stock Map", seven artboards, its `Model.dc.html` board holding these verbatim). The rule of
  19/09 is that a design is drawn and approved before it is written, and this is that approval.
  **(1) A floor is the canvas.** Site, building and floor are not drawn: the floor IS the plan, and zones and racks
  are rectangles on it. **(2) A bin is not on the plan** — it is placed in its rack's FRONT view, by column and
  level, and has no x, y on the ground. **(3) Metres, snapped to the quarter-metre**, rotation in 15° steps: a plan
  holds metres and never pixels, because a photo is rescanned and recropped over a building's life while the
  building does not move. **(4) The floor image is a `Files` record** per floor, with its opacity and two
  calibration points that give the scale — calibration rather than a fixed scale, because no two scans agree.
  **(5) Height and level sit on the rectangle**: height feeds the 3D and answers whether a pallet truck clears it,
  level orders the floor. **(6) The 3D only LOOKS** — Three.js, MIT, recorded in `THIRD-PARTY-NOTICES.md` in the
  same commit, loaded only when the view is opened; everything the map does is doable in 2D, so the 3D is never the
  only way to reach anything. **(7) 2D or 3D is a per-person PREFERENCE**, `presentation.stock-map-view`, through
  the settings chain like the folded menu. **(8) The phone reads, it does not draw**: search, highlight and opening
  a rack work on it; editing the plan asks for a wide window.
  Out of this row on purpose: an optimised picking route, a plan spanning several establishments, drawing an
  individual bin on the top-down view, and editing the plan on a phone. Row 74 (stock moves) is delivered and the
  map needs it; rows 57 (running totals) and 63 (scanner) are not, and the map reads without them — it joins them
  later for a live quantity and for scanning.

- [2026-09-21 13:44] AGREED: **a line moves stock the first time a document claims the goods left, and a later
  document naming the same goods moves nothing.** One rule in both directions, per LINE, not per document. Today
  only a validated delivery note moves stock, so an invoice billed over the counter with no note behind it leaves
  the goods on the shelf forever, and a credit note refunds money while nothing in the inventory module references
  credit notes at all — both verified in the code, both live defects rather than missing features. Four consequences.
  **(1)** Issuing an invoice moves stock for the lines no note covers — `InvoiceLine::$sourceDeliveryNoteLineId` is
  null — out of the product's home location falling back to the establishment default, and a credit note reverses
  what its invoice moved. **(2)** `StockMovement` already carries `sourceType` + `sourceId`, so the movement names
  the invoice or the credit note as its cause and the stock screen links straight to it: the auditability wanted
  from "only a delivery note moves stock" comes from ATTRIBUTION, not from forcing every movement through one
  document type. Two constants, not a new column. **(3)** An issued invoice can generate a delivery note ON DEMAND,
  in `draft`, for the case the flow does not cover — the bill was raised first and the goods are going out now, so
  the driver needs something to carry and get signed. It is generated from an ISSUED invoice only, because a draft
  invoice's lines still change and a hand-over document drawn from one goes stale; it lands in draft so a human
  fills in who received the goods, and under the rule above its already-moved lines move nothing. **(4)** A TASK or
  time line needs NO exception: the rule is that a stock-tracked product's line moves stock, and a time line has no
  such product, so it moves nothing by itself; a job that consumed materials carries those as ordinary lines, which
  move like any other. Rejected: the invoice AUTO-creating a delivery note. It fabricates the assertion a note
  exists to make — that goods were handed over, soon with a receiver's name and a delivery instant, which § "never
  invent a receiver" forbids — it does not avoid the uncovered-lines computation, which is the whole of the work and
  already exists, there is no honest status for it to land in, and crediting the invoice would have to unwind a
  numbered document that should never have been written. The same conclusion the developer's own earlier fork
  reached: rejected, then re-adopted opt-in only, with the original reasons standing. An opt-in per-company auto-draft
  remains additive and is not in this ruling.

- [2026-09-21 14:20] AGREED: **a global discount is a ROW of its own, never a reduction of a line's net.** Today
  a line's frozen net is before the document discount while its frozen tax is charged on the base after it, so
  `lineGross = lineNet + lineTax` mixes two levels: two lines of 100.000 at 19 % with a 20.000 document discount
  freeze 117.100 each, summing to 234.200 against a correct document total of 214.200 — overstated by exactly the
  discount — and each line's share of that discount is computed by `DocumentCalculator` and then DROPPED by
  `InvoiceFigures::of`, so it cannot be recovered from an issued row. Anchored on EN 16931 / Peppol BIS 3.0
  [Verified 2026-09-21 against docs.peppol.eu]: **BT-116** (VAT category taxable amount) = Σ **BT-131** (line net
  amounts) + **BT-99** − **BT-92**, and a document-level allowance carries **its own VAT category code (BT-95) and
  rate** — so line nets are never touched and the discount is one entry per category. Six parts. **(1)** A LINE
  discount stays inside the line, shown on its row as `remise`, and is what BT-131 means; the global discount never
  sees it. **(2)** The global discount is allocated across **LINES** in proportion to their nets AFTER their own
  line discounts — one share per line, so a line carrying several tax components is never counted twice — which is
  what `allocateDocumentDiscount` already does. **(3)** Presentation groups the rows by each line's **full SET of
  tax components**, not by individual component: grouping by component double-counts a line carrying VAT plus a levy
  that enters the VAT base, measured at 0.002 off on a three-combination invoice. A group whose share is zero prints
  no row. **(4)** Line tax is the line's part of its group's tax computed on the UNDISCOUNTED base, allocated by
  largest remainder so line taxes sum to it exactly; the true VAT is authoritative and the rows carry the whole
  difference, allocated **ONCE at document level** — nested per-component roundings drift, a single allocation is
  exact by construction. **(5)** Every column reconciles: Σ line HT + Σ row HT = total HT, same for TVA and TTC, and
  the rows sum to the amount typed. Verified numerically over 14 cases (single/two/three rates, exempt-only, line
  discount together with global, an indivisible 7.000 over three lines, overlapping tax groups, nasty remainders, a
  discount equal to the subtotal, no discount) — the model script lives only in the session scratchpad, so the
  fixtures are rebuilt as real tests. **(6)** Frozen AT ISSUE: per line (net, tax on its own base, TTC) and per
  group (the allowance's HT and tax), alongside the totals, so no issued document is re-derived by a later
  calculator. Blast radius: the shared `Fiscal/Domain/Calculation` calculator, so delivery notes and credit notes
  freeze the same way; pre-production rows are recomputed in the migration. **UNCERTIFIED-BY-EXECUTION**: the
  tax-INCLUSIVE entry path and the Tunisian withholding interaction were not exercised numerically and get failing
  tests of their own before the calculator is touched. This makes the partial-credit-note discount defect FIXABLE —
  a credit note can prorate each group's allowance by the credited fraction of that group's base — it does not fix it.

- [2026-09-21 14:35] AGREED: **the rows-per-page chooser is always reachable, and the chosen size is remembered per
  person through the settings chain, one key for every list.** The chooser already EXISTS — `ListDescriptor.pageSizes`,
  `DataList.pageSize`, the `size` URL parameter — and was reported missing by a first research pass and by the
  developer alike, because `data-list.ts`'s `paged` is `total > pageSizes[0]`, so the whole footer renders only once
  a list exceeds its SMALLEST page: a list offering 25/50/100 shows nothing at 25 rows and the chooser can never be
  reached to ask for more. The footer therefore shows whenever a list can be paged at all. The size becomes a
  `presentation.*` key on the settings chain, declared in the registry like `presentation.stock-map-view`, so it
  follows a person across devices rather than living in one URL. Rejected: one key per list — a single habit and a
  single place to change it beat per-screen memory here; the URL parameter stays, so a shared link still carries the
  size it was taken with.

- [2026-09-21 14:55] AGREED: **three order documents, because a bon de commande is issued by whoever BUYS.** A quote
  to a client is a **devis**, our offer, binding our price until a validity date — the biggest hole in the product
  today and ranked first by the documents pass independently. A client committing to buy signs a **commande client**:
  we print the form because we hold the specs and the price, but the signature that makes it an order is HIS, and it
  is the contract rather than an estimate — it carries his own order reference, the agreed delivery date and the
  acompte. A **bon de commande fournisseur** is what WE issue to a vendor, with PARTIAL receipts so a half-delivered
  order stays open. Chain: devis → commande client → bon de livraison → facture for selling; bon de commande →
  receipt → dépense for buying. Four consequences. **(1)** A devis converts WHOLLY OR PARTLY into a commande, and
  more than once — a kitchen quoted together is ordered as units now and a worktop in March. **(2)** Devis and
  commande are therefore SEPARATE records with separate numbering, not one record with two faces: one record cannot
  split, and a devis that never became an order would burn numbers in the order series. Independently required by
  the repo's own sourced rule that invoices run in an uninterrupted series (art. 18-II, `docs/fiscal/TN.md` § 11)
  with one series per document type. **(3)** An **acompte** is money received before any invoice exists, which
  twes-in cannot hold today — payments attach to invoices only. It takes one of two forms and **the fiscal preset
  decides which**, never hardcoded: a `reçu d'acompte` where VAT is not yet due, or a `facture d'acompte` — a real
  numbered invoice carrying VAT on the deposit — where it is. The final invoice deducts whichever was issued, net
  AND tax, or the VAT is charged twice. **(4)** A commande client for the counter case is never raised: a shop
  selling across a counter goes straight to the invoice, and the earlier "no commande client" recommendation was
  reasoning from that shape alone — the car-dealer case (goods ordered, deposit taken, delivered later) is the
  ordinary shape for a kitchen, tiles or a machine to bring in, and is what overturned it.
  **UNCERTIFIED — LEGAL**: this session's web-search budget was spent, and `docs/fiscal/TN.md` carries nothing on
  devis, bon de commande or acompte. Recalled and UNVERIFIED, to be confirmed with citations added to that file
  BEFORE the acompte ships: that the taxable event is delivery for goods and collection for services (so an acompte
  on a service makes VAT due at once), the arrhes/acompte distinction under the Code des obligations et des contrats,
  and any mandatory mentions a signed bon de commande must carry. The design is preset-driven precisely so the
  answer is data rather than code.

- [2026-09-21 15:20] AGREED: **the sidebar is four groups in the order the business runs — Stock · Ventes · Achats ·
  Travail — with lifecycle order inside each.** Signed off against a drawn design, not prose
  (`https://claude.ai/artifact/DyhxgaUad8vDCA97r1DyLH`, four artboards: libellée ≥ 1200 px, rail 600–1199,
  téléphone, feuille Plus). Entries: Accueil, then **Stock** (Produits, Stock — a product exists before there is
  stock of it), **Ventes** (Clients, Devis, Commandes, Bons de livraison, Factures), **Achats** (Fournisseurs, Bons
  de commande, Dépenses), **Travail** (Tâches). Today's `MODULE_NAV` order is Invoices, Customers, Products,
  DeliveryNotes, Inventory, Vendors, Expenses — a flat list in no stated order. Four consequences. **(1)** The
  grouping is what makes the developer's lifecycle order survive the new documents: flat, it would be THIRTEEN
  entries, and the rail collapses to unlabelled icons below 1200 px where thirteen stop being scannable. **(2)**
  `NavSection` gains the four sidebar sections; `SIDEBAR_SECTIONS` is `['main']` today, so the shell renders one
  group and the manifest's section machinery already exists. **(3)** **Récurrentes are a TAB inside Factures and
  Dépenses, never a menu entry** — a recurring invoice is a template that emits invoices, so it belongs beside them,
  and that is what holds the count at eleven. **(4)** On the phone the bottom bar carries FOUR tabs plus Plus, chosen
  by FREQUENCY — Accueil, Stock, Factures, Clients — and the Plus sheet repeats the same four groups. This is the one
  place the lifecycle rule is deliberately broken: eleven entries cannot fit a bottom bar, and standing in the shop
  what matters is what is opened most. The group named Stock containing an entry named Stock is accepted as drawn;
  `Catalogue` was offered and declined.

- [2026-09-21 15:45] AGREED: **who received the goods is recorded on the delivery note, and who received the invoice
  on the invoice, both behind their own setting.** The note already records a delivery DATE (`DeliveryNote::$deliveryDate`,
  set by `DeliveryNoteWorkflow::deliver()`); what is missing is the PERSON, and the printed note carries a dashed
  empty box waiting for one (`api/templates/pdf/delivery_note.html.twig:154`). Four parts. **(1)** The receiver is a
  SEARCHABLE list of the customer's contacts with a free-text fallback for a name that is not one — a driver or a
  storeman at a loading dock usually is not on file, and forcing a contact would mean inventing one; preferring a
  real contact where there is one keeps a repeat receiver consistent and makes "everything this person signed for"
  answerable. **(2)** The stored value is a SNAPSHOT of the name, not only a link: a contact renamed or deleted later
  must not change what a signed document says, which is the pattern the customer snapshot already follows.
  **(3)** A TIME is added beside the existing day, so "delivered" says when. **(4)** Printing is a setting, and so is
  the invoice's — the invoice's receiver is a SEPARATE fact with its own wording (`remis à … le …`), never the same
  field: a delivery note proves GOODS changed hands, an invoice arriving is a DOCUMENT reaching someone, and one
  field for both leaves no way to tell later which a name meant. Both follow `delivery_note.show_prices`'s shape —
  `SettingChain::Parties`, overridable at Company, CustomerGroup, Customer and Document. Tracking which contact an
  invoice was SENT to and when it was opened stays a separate, still-wanted thing, not an alternative to this.
  **AMENDED 16:40**: the Document level named above does not work yet — see the 16:40 ruling. These two settings
  are declared with it, and land only once that level exists.

- [2026-09-21 16:40] AGREED: **the `Document` and `DocumentLine` setting levels are implemented, not withdrawn.**
  Both exist as `SettingLevel` cases and both are unreachable: `SettingAddress` has no factory for them and
  `SettingContext::addressOf()` has no arm, so they fall to `default => null` — skipped on every read and refused
  on every write, silently. FOUR definitions already advertise them, `delivery_note.show_prices` among them, so the
  "four levels" that setting is credited with have always been three, and the two receiver settings agreed at 15:45
  were about to be declared the same way. Implementing them means a `documentId` and `documentLineId` on
  `SettingContext` and the matching addresses, so ONE invoice or ONE delivery note can answer differently from its
  customer's default — this note hides prices, that one prints the receiver box — which is what "per document"
  was always meant to mean. Withdrawing them was offered and declined. This also closes a class of silent refusal:
  a level that cannot be addressed must never be declarable, so the catalogue should refuse a definition naming a
  level no context can reach, rather than accepting it and failing at write time.

- [2026-09-21 15:55] AGREED: **settings are configurable by default, and each one is decided together when it comes
  up** — the developer's posture, taken over the author's proposed earn-its-place gate. The risks were put and
  accepted: combinations that cannot all be tested (ten switches are 1,024 states); branches nobody exercises until
  a customer finally flips one; support questions unanswerable without a customer's whole settings state; and
  Hyrum's Law, a shipped switch being permanent. PERFORMANCE IS NOT among them for catalogue SIZE — `ResolveSettings::stored()`
  fetches every setting on a chain's addresses in ONE repository call and resolves in memory, so 200 keys cost the
  same query shape as 4. Four additions to the engine, which is otherwise kept as it is (typed definitions that
  refuse their own bad default, four ordered chains, per-level write restriction, modules declaring their own keys
  via `DeclaresSettings`, duplicate keys throwing at boot, `ResolvedSetting` already answering where a value comes
  from). **(A) `requires`** — a definition applies only when another setting holds a given value: the screen hides
  the control, the resolver returns the declared default when the requirement is unmet so code never meets an
  impossible state, and the test matrix enumerates only REACHABLE combinations. This is what makes the chosen
  posture survivable. **(B) Lifecycle, `deprecated` + `replacedBy`** — a retired key still resolves so stored data
  keeps working, writes are refused, the screen hides it, and a migration maps old values onto the replacement. This
  is what keeps the posture from being a one-way door, and it is cheap on 4 keys and brutal across 200.
  **(C) The fiscal preset as a SOURCE of defaults**, consulted before the definition's PHP literal, so a country
  changes a default while an untouched company still reads "unset" and reset-to-default lands on the country's
  answer. A source, not a fifth level: a preset stores nothing of its own. **This is a gap the acompte ruling of
  14:55 already depends on** — nothing in `api/src/Fiscal` declares or reads a setting today, so "the fiscal preset
  decides which form" has no mechanism. **(D) Request-scoped memoisation** — measured, not theoretical:
  `PrintDeliveryNote` issues THREE queries for three keys over identical addresses (`:94`, `:95`, `:102`) with four
  keys in the catalogue. Request-scoped only, never process-scoped: a write must be visible at once, and § 7 names
  FrankenPHP worker mode as a later step. Order: A, B and D before any new key; C when the acompte lands.
  Catalogue metadata and screens at 200 keys — groups, ordering, help text, search, starting profiles per trade,
  export and import — are real but change no engine shape and are not in this ruling. One rule that is not code:
  **never let one function answer both "show it?" and "show what?"** — that is precisely the defect that printed a
  labelled empty row for a company with the setting off. Still owed: an enumerated review of every existing key and
  every hardcoded presentation choice, config or hardcoded, as its own pass now that the model is settled.

- [2026-09-21 16:10] AGREED: **the settings list gets its own fold trigger, docked on the list and available below
  1200 px.** The fold itself is NOT missing and was nearly reported as such: `presentation.sidebar-settings` exists,
  defaults to `rail`, is read by `ThemeFacade` (`:64`, `:111`) and `app-shell.ts:190`, and each area remembers its own
  answer so folding the settings list does not overrule the main menu. What is missing is DISCOVERABILITY and reach:
  the only trigger is the shared top-bar menu button, which renders `@if (windowClass() === 'expanded')`
  (`app-shell.html:111`), so a 1100 px laptop can fold nothing and the button never looks like it belongs to the
  settings list. A small fold button goes on the list's own top edge and the fold works at the rail width too; the
  top-bar button and the `[` shortcut keep working, and the remembered per-area state is unchanged.
  **AMENDED 16:35, and the amendment is the substance:** the fold state is NOT remembered.
  `presentation.sidebar-settings` is registered in the WEB registry (`settings-registry.ts:69`) and declared
  NOWHERE in `api/src` — `PresentationSettings` yields `presentation.sidebar` and no twin — so `ChangeSettings`
  throws `UnknownSetting` and `api-settings.ts:102` swallows it under a comment saying the page keeps the choice
  until it is reloaded, which for this one key is what happens every time. The toggle works within a session; the
  preference dies on reload. The fix is one `SettingDefinition` beside `presentation.sidebar`. (The author reported
  this key as dead, then corrected that to "already built"; both were wrong, and only the enumerated review settled
  it — a reminder that a grep over the web alone cannot tell whether a setting PERSISTS.)

- [2026-09-21 16:10] AGREED: **the platform page becomes tabs, with the chosen tab in the URL.** It is one 461-line
  template stacking five sections — signup, companies awaiting approval, companies, payments, accounts. They become
  tabs, and the tab is written to the address so a reload, a bookmark and a link all land where the person was:
  acting on a company and being returned to the first tab is the annoyance tabs usually introduce, and the URL is
  what prevents it. A route per section was offered and declined as changing the area's shape rather than its
  presentation.

- [2026-09-21 16:20] AGREED: **delivery notes may be added to a DRAFT invoice, and an issued one refuses them.**
  Consolidating many notes into a NEW invoice already works — `InvoiceDeliveryNotes::draftInvoice()` takes a list of
  note ids and returns one draft, each invoice line keeping `sourceDeliveryNoteLineId`. What is added is the other
  half: merging notes into an invoice that already exists. A draft has no number and nothing fixed, so the lines
  merge in; an issued invoice is a numbered document in an uninterrupted series (`docs/fiscal/TN.md` § 11) and is
  refused with a reason naming the alternative — consolidate the remaining notes into a new invoice. Offered and
  declined for now: adding to an issued invoice through the correction path, and treating "unpaid" as "still open".
  The developer noted they may revisit this, so the rule lives in ONE place that names its reason rather than being
  spread across the merge path — relaxing it later must be a change to that rule, not a hunt.

- [2026-09-21 16:55] AGREED: **a setting may never be able to take an illegal value — so where the law fixes the
  answer it is CODE, where the law differs by country it is a PRESET default, and only where the law is silent is it
  a COMPANY setting.** This refines the 15:55 posture rather than replacing it: "configurable by default" still
  governs the third tier, which is most decisions. It exists because the developer's two requirements — everything
  configurable, everything compliant — cannot both be unconditional: a switch that can be set to a non-compliant
  value IS the compliance hole. A legal answer carries its citation in `docs/fiscal/<CC>.md`; a country difference
  is addition (C) of the 15:55 ruling, the preset as a source of defaults. Offered and declined: making the tier a
  declared field on every `SettingDefinition` with a gate refusing a mis-tiered one — worth revisiting if the
  catalogue drifts. Applied at once to three lock points, which are NOT the same kind of thing. **A task is an
  internal record of work with no law over it**: locking it once invoiced is a SETTING, defaulting to locked,
  because an edited task silently desynchronises the invoice from the record of work. **An issued invoice is fixed
  and that is not a preference**: invoices carry a number from an uninterrupted series (art. 18-II,
  `docs/fiscal/TN.md` § 11), so no switch may make an issued invoice editable — a correction is a credit note or a
  corrective invoice, which leaves a trail. What IS configurable is the run-up: approval before issue, who may
  issue, whether issuing asks for confirmation. **A delivery note is in between**: its number is allocated at
  `validated`, so its identity is fixed from then on, but it is a commercial and transport document rather than a
  fiscal declaration — the setting there is whether validating also means the goods left, or whether `delivered`
  stays a separate step; not an unlock of a numbered document.
  **UNCERTIFIED — LEGAL**: only the numbering rule is sourced. Everything said here about Tunisian delivery-note
  obligations is structural reasoning, and this session's web-search budget is spent; citations go into
  `docs/fiscal/TN.md` before any of it ships.

- [2026-09-21 16:55] AGREED: **a task's rate carries a UNIT — hour, minute, day or fixed — and "month" is not one
  of them.** The developer asked for hour/minute/day/month. Hour is the default. Minute is a display and entry unit
  storing the hourly equivalent — it is an hourly rate with a one-minute rounding step. Day converts through a
  company setting, billable hours per day, default 8, which is how installation and field work is sold here. Fixed
  prices a task as a lump sum while time is still tracked, so profitability stays visible. **Month is refused as a
  task rate**: a monthly amount is a RETAINER, paid whether or not anyone logged time, so a month unit invites
  logging three hours against a monthly rate and printing a line that is simply wrong. It is routed to a RECURRING
  INVOICE, and the UI says so where someone looks for it; "a monthly retainer including N hours" is a recurring
  invoice plus a task budget, two features rather than one unit. The rate resolves task → project → customer →
  company, first one set wins, with the UNIT inherited alongside the amount so a customer billed by the day stays
  billed by the day. A per-member rate is a real need for a services business and is deferred, as a fifth link
  between task and project.

- [2026-09-21 17:10] AGREED: **bank details print on an invoice whenever the company has them, with a setting to
  suppress.** `Company::$iban` and `$bic` are captured, editable and NEVER rendered — `api/templates/pdf/invoice.html.twig`
  has no block for them — so a company paid by transfer cannot be paid from its own invoice. Printed by default
  because an invoice a customer cannot pay is the failure mode and forgetting to switch it on is the likely mistake;
  a company paid only in cash or by cheque turns it off. `invoice.show_bank_details`, Parties chain, company /
  customer group / customer, default true. A payment-instructions text block beside the numbers was offered and not
  taken in this ruling; it remains additive.
  Context: this came out of the enumerated settings review (`var/claude/settings-review.md`, 2026-09-21) — 18
  declared settings, all read, plus 56 hardcoded candidates: 26 recommended SETTING, 22 HARDCODED, 3 part-and-part,
  3 PRESET, 2 already settings whose default should come from the preset. The remaining candidates are published for
  the developer to read and flag rather than walked one by one (`https://claude.ai/artifact/RryBa2hrQY1youjXXxYZWp`).
  Two findings from it are already ruled above (the dead `presentation.sidebar-settings`, the unreachable document
  levels). One claim was CHECKED AND WITHDRAWN rather than shipped: `decimal(0, locale)` on a rate is not a rounding
  defect — `DecimalExtension::format()` treats the argument as a MINIMUM applied with `str_pad` and never rounds.

- [2026-09-21 17:25] AGREED: **the delivery note's state machine stays fixed, and the step people want to skip is
  collapsed into an ACTION instead — "Valider et livrer", one transaction, both transitions recorded.** Asked
  whether the chain should become configurable, the developer leaned toward letting a draft be delivered directly;
  examining it showed the question was mis-framed. `validated` is not a step that can be bypassed: it is where the
  number is allocated from the series AND where the stock moves. So "draft → delivered" can only mean doing both at
  once, which is a compound action, not a relaxed machine. The two separate actions stay for shops that want them.
  This is the shape to prefer whenever a request looks like a state-machine setting: **an action that performs two
  transitions costs one button; a setting that makes a transition optional costs every downstream rule a branch for
  the state that was skipped, forever.** Accepted cost, unchanged either way: one click both numbers the note and
  moves stock, so a mistake is already numbered — recoverable by cancelling, which correctly leaves a cancelled
  number visible in the series. The counter-sale case that would otherwise justify skipping validation is already
  served by the 13:44 ruling, where an invoice moves stock for the lines no note covers.

- [2026-09-21 17:35] AGREED: **over- and under-payment are handled by ACTIONS that give the difference a home, not
  by parameters that let a number past a guard — plus one tolerance setting.** Underpayment is ALREADY accepted and
  always was: nothing guards it, `amountPaid`/`amountDue` are exactly that, and the chase feature exists to track
  it. What the developer meant is CLOSING a short-paid invoice, which is not "accepting underpayment" but
  **writing off** the difference — a loss being recognised, and in a VAT system the difference lands on a document
  rather than on a status. So: overpaid → an action moves the excess to the customer's CREDIT BALANCE, visible on
  their statement and applied to the next invoice (a gap the parties pass had already flagged independently);
  short-paid → an action closes the invoice and records the difference on a CREDIT NOTE. `Invoice.php:450` keeps
  refusing a payment larger than what is due. The one genuine parameter is a **tolerance**: the shortfall under
  which the system offers to close without asking — a number, per company, that decides when settlement is OFFERED
  and never whether the difference is recorded.
  **The pattern, now twice in a row and worth applying generally: when a guard feels too strict, ask WHERE THE
  DIFFERENCE GOES. If there is no answer, the guard is protecting a missing feature, and a parameter would only
  hide it.** A setting lets a number past; an action gives it somewhere to live.
  **UNCERTIFIED — LEGAL**: that a post-invoice commercial discount must be documented by a facture d'avoir is
  recalled, not sourced; it goes into `docs/fiscal/TN.md` with a citation before the write-off action ships.

- [2026-09-21 17:45] AGREED: **the delivery moment becomes a TIMESTAMP, auto-filled with now, correctable within
  the existing window, shown to the minute.** `DeliveryNote::$deliveryDate` is a `Types::DATE_IMMUTABLE` today and
  becomes a datetime — a migration reading existing rows as midnight, harmless pre-production — stored UTC and
  rendered in the company's time zone, which matters for a timestamp and did not for a date. Marking a note
  delivered stamps the current moment; `DeliveryNoteWorkflow::deliver()` already takes an optional `deliveredOn`
  and falls back to today, so this is a granularity change rather than a new mechanism. **Auto-fill, not auto-lock**:
  a driver doing five deliveries at 09:00, 11:00 and 14:00 records them all at 18:00, and an uneditable stamp would
  put all five at 18:00 — destroying exactly the value that motivated the precision. Correction stays inside the
  guard of `DeliveryNote.php:237,240`: never before the issue day, never in the future. Seconds are STORED and not
  printed: `14:32:07` on a document a human signs claims an accuracy nobody observed. Note the developer's stated
  reason — telling apart several notes to one customer on one day — is already served by the note's NUMBER; what a
  timestamp actually buys is ordering within a day and proof of arrival in a dispute. Same conclusion, sounder
  reason. This is the "time beside the date" half of the 15:45 receiver ruling, now specified.

- [2026-09-21 17:55] AGREED: **stored precision stays fixed — quantity 3 decimals, unit price 4 — and a display
  setting is added for price decimals.** The price scale is deliberately FINER than the currency's (TND
  `minor_unit: 3`), so a unit price can be 0.0125 per screw while the line total rounds to the millime; that
  relationship is right and the money side is already preset-driven. Storage stays one answer for everyone because
  the scale is what makes figures comparable and what the 14:20 discount arithmetic is built on: per-company scales
  would make every allocation ask whose scale it is in, and raising one later silently changes what old rows meant.
  Quantity DISPLAY already varies per unit (`line.unit.decimals`), which is the correct axis — grams and pallets
  genuinely differ — and prices gain the same, as presentation only. Raising the fixed numbers instead was offered
  and not taken.

- [2026-09-21 18:05] AGREED: **the 2,000-row import cap becomes a PLATFORM setting with a declared maximum, and the
  async tier is built with the import as its first consumer.** The author had recommended HARDCODED and reversed it
  on evidence: `RunImport.php:43` wraps the whole import in ONE transaction, so a timeout rolls everything back —
  the cap protects the user's TIME, not their data, which is a much weaker reason to make it immovable. The person
  who knows whether a server can take 5,000 rows is the OPERATOR, not the shop, so it belongs on the Platform chain
  beside the session TTLs, with the definition's own `max:` stopping an absurd value. **It is not a reason to skip
  the real fix.** There is NO async tier at all — no Messenger, no Scheduler, no transport, no worker, nothing in
  `composer.json` or `api/config/packages/` — so a background import is infrastructure, not a feature: Messenger, a
  transport and its migration, a worker container in compose (which must carry the `*logging` anchor the gate
  enforces), retry and failure policy, and somewhere a person sees the result. **At least four agreed or leaning
  features need that same tier**: recurring invoices (today's tab ruling), recurring expenses, reminders, and the
  scheduled "bill all outstanding work" run for tasks. The import is therefore the RIGHT first consumer, not an
  afterthought: user-initiated, bounded, already one transaction, and its failure means nothing happened. Standing
  the tier up with recurring invoices first would mean debugging a new worker while it generates numbered financial
  documents unattended — the wrong place to discover a retry policy is wrong.

- [2026-09-21 18:15] AGREED: **the seven "how many to show" constants stay fixed, and `article.stock_tracking` is
  RESOLVED onto the product row.** Six of them are dropdown lengths — 20 picker matches, 5 role holders, 200
  companies, 50 accounts — where twenty is what fits a picker on a phone and typing another word narrows faster
  than a longer list helps; seven switches would be combination cost spent on nothing. The seventh is different and
  the code already says so honestly (`StockProductPickProvider:32–36`): `SCANNED = 200` exists because
  `article.stock_tracking` is a settings CHAIN walked per product and therefore cannot be a `WHERE` clause, so the
  provider takes a bounded window of goods and applies the setting inside it — the answer is "these, among the first
  few hundred that match", never "these are all there are". Raising 200 only moves the edge. The fix is to maintain
  the resolved value as a COLUMN on the product, updated when the setting changes at any level, which turns the
  chain into a `WHERE` clause and deletes the window. **That shape generalises: any setting we ever want to FILTER
  by needs its resolved value materialised, because a chain cannot be queried.**

- [2026-09-21 18:20] AGREED: **destructive actions keep confirming with no switch, and the unsaved-work guard gains
  the BROWSER level.** The confirmation ruling of 07:05 stands: a confirmation switched off once, long ago, by
  someone since gone is indistinguishable from no confirmation, and what it guards is deletion; a prompt that feels
  excessive is an argument about WHICH actions are destructive, which is tuned in code where it can be reviewed.
  Leaving a page with unsaved work ALREADY confirms — `UnsavedChanges` (row 45) is wired as a `canDeactivate` guard
  onto every route automatically (`unsaved-changes.ts:79`), across eight record pages, and its own docblock carries
  the principle: asking on a page nobody changed is how a person learns to dismiss the question without reading it.
  What is missing is the browser level: no `beforeunload` exists anywhere, so closing the tab or refreshing loses a
  half-typed invoice silently. It is added, **registered only while the existing unsaved count is above zero** — one
  source of truth, and a clean page never prompts, which is the same principle the file already states. Two costs
  accepted: the message is the browser's own and cannot be written by us, and the e2e suite must be taught to expect
  it or a run can hang. A setting to disable it was offered and declined for the same reason as the confirmation.

- [2026-09-21 18:30] AGREED: **issuing an invoice is REFUSED when a legal mention the customer's regime requires
  cannot be filled, naming the missing datum.** Found while confirming a HARDCODED verdict, and it is a live
  compliance defect rather than a preference. `invoice.html.twig:149–155` drops any mention still containing a `%`
  placeholder — correct in itself, since `Exonéré en vertu de %article%` helps nobody — but **nothing checks
  mentions before issue**: `InvoiceWorkflow` does not look at them at all. So the order of events is issue (numbered,
  fixed, declared) → render → silently drop, and the shop believes it sent a compliant invoice. The repo's own
  fiscal file puts the exposure at 250–10,000 TND per invoice for missing mentions (`docs/fiscal/TN.md:113`, marked
  unvalidated there). Tier one under the 16:55 rule — the law fixes it, so it is not a setting — and the check moves
  UPSTREAM to issue, where it can still be acted on. **Refuse rather than warn**: an issued invoice is immutable, so
  a warning clicked through produces precisely the outcome that can only be undone by crediting and reissuing. The
  twig guard stays as the last resort.

- [2026-09-21 18:40] AGREED: **a payment stays something that HAPPENED — the future-date guard stays — and a
  cheque/traite portfolio is built for instruments received.** `Invoice.php:454,457` refuses a payment dated in the
  future, which refuses a post-dated cheque or a traite: dated ahead by design, and ordinary in Tunisian B2B. The
  guard is still right, because recording a traite as a PAYMENT would say you had been paid when you are holding an
  instrument that may bounce — the invoice would read settled, receivables would show nothing owed, and a dishonour
  in November would have no trace to undo. An instrument received is therefore its own record: amount, due date,
  bank, and a state — en portefeuille → remise à l'encaissement → encaissé or impayé — becoming a PAYMENT only when
  it clears. The client-facing pass recommended exactly this independently. **Fourth instance of the same pattern in
  this walkthrough**: the guard was right and was pointing at a missing feature. Over-payment → credit balance;
  short-payment → write-off on a credit note; future delivery date → expected-delivery date; future payment date →
  instruments portfolio. In every case a setting would have let the number past and recorded nothing.

- [2026-09-21 18:50] AGREED, three at once from the hardcoded walkthrough. **(1) Stock stays GOODS-ONLY**
  (`KeepStock.php:36–38`): a service with an on-hand quantity is a number nobody can explain. The case the rule does
  not cover is a **kit** — a boxed or pre-assembled item sold as one line that consumes several stocked components,
  which today is either a good (the box is counted, the components silently do not move) or a service (nothing
  moves). That is an argument for a COMPOSITION — a product declaring what it is made of — recorded as wanted and
  built when it earns its place, never for loosening the guard to approximate it. **(2) `presentation.list.<id>`
  widens from `user` only to Company, Role and User** (`PresentationSettings.php:39`): per-person layouts already
  work, but a shop cannot say "this is how our invoice list looks", so every new employee starts from the
  developer's default. A one-word change to the level list; the chain resolves it like everything else and a person
  still overrides their own. Locking a layout against override was offered and declined. **(3) The seeded
  `BUILT_IN_ROLES` stay seed data** (`SeedPlatform.php:37`) — roles are editable per company afterwards and a
  settings row cannot express a role with its permissions.

- [2026-09-21 18:50] AGREED: **a company picks its TRADE at provisioning, and that seeds a coherent set of
  defaults.** Three separate items today ended in "the right default depends on what kind of business this is":
  `article.stock_tracking` defaults false while a quincaillerie wants it on for everything from day one; payment
  terms default to 30 while the habit differs by country and by trade; and the seeded roles. A trade — quincaillerie,
  atelier, services — is chosen once when the company is created and seeds stock tracking, payment terms, the chase
  window, list layouts, which modules start on and which roles exist. Everything stays editable afterwards: it
  decides where a shop STARTS, not what it may do. **Two axes feed defaults and neither is a company switch: the
  COUNTRY preset says what the law requires, the TRADE says what the business habitually does.** The trade seeds
  roles as well as settings, since a workshop starting with a cashier role is the same wrong-default problem one
  level up.

- [2026-09-21 19:05] AGREED: **passkeys stay a second factor; step-up re-authentication is built; passwordless
  waits.** `company.mfa_required` stays a column and not a setting — `RequireSecondFactor` reads it on every request,
  it owns the audit action `company.mfa_required_changed`, and a security control does not belong on a screen of two
  hundred switches. Its real limitation is that it is all-or-nothing per company: requiring a second factor of the
  owner and the accountant but not of counter staff belongs **on the role**, beside the permissions it already
  carries, and is noted for when roles are next opened. On the login itself, three findings, verified rather than
  recalled: **passkeys already exist** (`api/src/Identity/Application/Mfa/`, `web-auth/webauthn-lib ^5.3.8`,
  `Version20260915170000`), and `PasskeyCeremonies::creationOptions` already requires user verification, so a passkey
  here is possession plus PIN or biometric in one gesture and the phishing-resistant factor is in place; **step-up
  re-authentication does not exist anywhere** (`git grep` over `api/src` and `web/src` finds no step-up, re-auth or
  sudo-mode), and it is what defends the attack this product actually attracts — a stolen session changing the
  company's IBAN so customers pay the attacker, which the ruling of 17:10 to print bank details makes more valuable,
  not less. So a short fixed list of actions re-authenticates at the moment they happen: changing bank details,
  adding or removing a member, changing a role's permissions, exporting customer or document data. The list is code,
  not a setting — a company that could switch it off would switch off the only guard against the attack it exists
  for, and the three-way rule of 16:55 puts it under "law silent, but the answer is not the company's to get wrong".
  **Passwordless is refused for now, and the reason is not difficulty.** `BeginPasskeyAssertion` names
  `allowCredentials` from the account's own passkeys, so the server must already know who is signing in, so the
  password comes first — deliberate, and its docblock says why. Passwordless needs discoverable credentials, which
  the library supports; what it also needs is an answer for device loss, because a discoverable passkey is then the
  only thing between a person and their company's books and the recovery codes stop being a backstop and become the
  front door. It returns as an **opt-in per person** once a second registered device is the norm, never as a default.
  And the instinct it came from is worth recording as refused: **authenticator-only login is weaker, not stronger** —
  a TOTP secret is shared, sits in our database, and a convincing fake page replays a code inside thirty seconds,
  where a password at least survives a breach as a hash.

- [2026-09-21 19:20] AGREED: **the company's logo and a document accent colour become configurable; the font, the
  paper size and the DRAFT watermark stay code.** Verified first: `git grep logo` over `api/src`, `api/templates` and
  `web/src` matches only `login`, so **this product has no company logo at all** and nothing a company prints carries
  its mark. That is a missing feature, not a settings question, and it is the plainest reading of invariant 5 — the
  logo is an upload on the company through the existing `Files` context and `FileStorage`, printed on the invoice and
  the delivery note. A **document accent colour** becomes a company setting: `invoice.html.twig` and
  `delivery_note.html.twig` hardcode `#1f2328` for text, `#57606a` for labels and the watermark's red, and a shop
  putting its name on a document expects its colour with it. The **font stays Inter**: a company choosing an
  arbitrary face means either a face Gotenberg's Chromium does not have and silently falls back from, or a licence
  question per face, and OFL-1.1 vendored fonts are a narrow exception in LICENSING.md rather than an open door — a
  short list we ship and licence is a later feature, not a setting. **A4 stays code, and when it moves it is a
  PRESET, not a company switch**: paper size is decided by where a company is, not by taste, so it belongs beside the
  country under the three-way rule of 16:55. It is currently written twice — `@page { size: A4 }` in both templates
  and `paperWidth`/`paperHeight` in `GotenbergPdfRenderer.php:34-35` — which must be single-sourced before it becomes
  variable, since two values that must agree and cannot check each other is the shape that printed every document
  flush to the paper's edge. The **DRAFT watermark stays code**: a draft that prints looking like an issued invoice
  is how a document is paid twice, which is a safety property and not a preference. A full template editor is refused
  for now on its own grounds — every required legal mention would then have to survive whatever a company does to the
  layout, which fights the 18:30 ruling that issuing is refused when a required mention cannot be filled.

- [2026-09-21 19:55] AGREED: **the drawn map's screen is FORM-FIRST, and one of the eight decisions needs the
  developer** (row 83, web half, slice 1). The approved canvas settles the shape: the Edit board's right panel IS a
  form of x, y, width, depth, height and rotation, and its header carries "3 modifications non enregistrées" beside
  Annuler and Enregistrer. So the rectangle is drawn and moved through a FORM beside the plan, and dragging is an
  accelerator to be added on top of it — never the way in. That is not a concession to accessibility, it is what
  decision 8 requires (the phone reads the map and does not draw it) and what makes every control reachable by
  keyboard by construction. The list of drawn rectangles beside the plan is the same rule: nothing on this screen may
  be reachable only by pointing at the drawing. **Shipped in this slice**: the floors (add, rename, level, remove),
  the SVG plan in metres with each rectangle turned about its own centre and labelled by its location's code, the
  rectangle form with the quarter-metre snap applied to what is TYPED as well as to what will be dragged, erasing,
  and the live reload on `venue_area`, `venue_spot` and `stock_location` — the three audit types the composition
  already records, checked rather than assumed. **Left inside row 83**: dragging, resizing and turning on the canvas;
  the floor image and its two calibration points; the Three.js 3D view; and search-highlight. The per-person
  `presentation.stock-map-view` key of decision 7 is deliberately NOT added yet — a preference between two views
  while only one exists is the same can-never-fire shape as `presentation.sidebar-settings`, which is registered on
  the web and declared nowhere in `api/src`. It ships with the 3D.
  **One API gap closed**: `DrawStockMap` never read the location's kind, so the surface accepted a rectangle for a
  BIN, which decision 2 says is placed in its rack's front view and has no x, y on the ground at all. It is refused
  now, on both verbs, resolved before the rectangle is placed so a refusal leaves the plan as it was.
  **And one decision the developer must settle.** Decision 1 says "site, building and floor are not drawn: the floor
  IS the plan", but an establishment's own DEFAULT location is a `StockLocation` of kind `Site` by construction
  (`StockLocation.php:90`), and the approved Main board DRAWS it — "Z6 · Préparation et expédition", labelled
  *Emplacement par défaut de l'établissement*. The two disagree. Refusing `site` outright would make the approved
  plan impossible to draw, so only `bin` is refused for now and every other kind is accepted. The question to settle:
  is decision 1 about the hierarchy's containers only, leaving the default location drawable as the canvas shows, or
  should site, building and floor be refused and the default location excepted by its flag rather than its kind?

- [2026-09-21 20:40] AGREED: **the quarter-metre grid holds a rectangle's PLACE, never its SIZE** (row 83, a
  refinement of decision 3 — flagged for the developer to confirm or reverse). Decision 3 reads "Metres, snapped to
  the quarter-metre", and the first implementation applied that to everything a person typed. CI caught what that
  means: a rack entered as **3,90 × 0,60 m** was stored as **4 × 0,5 m**. The approved canvas settles it the other
  way — the Edit board draws that same rack as `3,90 × 0,60 m · x 2,50  y 4,00`, a size off the grid at a position
  on it, and its scale bar reads *2 m · aimanté sur 0,25 m*. **Aimanté** is a magnet, which is what a grid is while
  something is DRAGGED, not a rounding applied to a number somebody typed. So: **x and y are taken to the quarter**,
  because where a rack stands is a decision about the plan; **width, depth and height are taken as measured**,
  because how big the rack is, is a fact about the rack and rounding 3,90 up to 4,00 loses a measurement somebody
  went and took; **rotation stays in fifteens**, because a rack stands square to a wall or at an angle off it. The
  magnet returns with dragging, in the next slice, where it belongs. If the developer meant decision 3 to round a
  typed size too, this is the line to reverse — but then the canvas's own rack cannot be drawn as it is drawn.

- [2026-09-21 21:30] AGREED: **the stock map grows well past the eight decisions, and the whole of it is drawn
  before any more of it is built** (row 83). The developer looked at the shipped slice and asked the right question —
  *"no actual touch to draw or predefined shapes?"* — then asked for every screen and every interaction to be mocked
  and validated first. The canvas is **"The Stock Map, End to End"**, eighteen artboards in five rows: reading (plan,
  search, rack façade, movements, picking round), drawing (move and the eight handles, freehand tracing, the shape
  palette, repeat-down-an-aisle, the structure layer, the floor scan and its calibration), volume (two 3D views),
  devices (tablet, phone) and finally the three empty states, the default-size settings and a board naming what each
  gesture writes to which table. Four rulings came out of it.
  **(1) Four capabilities are ADDED to the eight decisions**, all approved: tracing a rectangle by hand on bare floor
  then saying what it is; a palette of ready-made shapes whose sizes are COMPANY SETTINGS, never code constants;
  repeating a rack down an aisle; and a walls-and-doors layer. Decision 8 is also **widened**: it said the phone reads
  and does not draw, and that stands — but the **TABLET DRAWS**, with 44 px circular handles and the palette as a
  drawer, because a ten-inch screen with a stylus is a drawing surface and a five-inch one is not.
  **(2) The structure layer lands AFTER the drawing gestures.** Tracing, the palette and repeating are pure web work
  over the API that already exists, so they ship quickly; structure needs a new table (it is deliberately NOT a
  `stock_location` — a wall that were one would appear in every stock list, every import and every movement's
  location picker, to hold nothing forever), a migration, an audit type and a 3D story. It gets its own slice.
  **(3) Repeating CREATES STOCK LOCATIONS from the plan screen**, N locations and their N rectangles in one action,
  and the panel lists the codes it will create (`R2 … R8`) before it is confirmed. The alternative — the plan may
  only draw what another screen already created — was rejected: it would mean typing forty racks elsewhere first,
  which is the work this feature exists to remove.
  **(4) The drawable-kinds question of the 19:55 entry is SETTLED: only `bin` is refused, every other kind may be
  drawn.** Decision 1 is read as being about the hierarchy's containers, not as a ban: if someone draws a site, they
  meant a real place, and the approved plan's "Z6 · Préparation et expédition" — the establishment's own default
  location, a `Site` by construction — is exactly that. `StockLocationKind::isDrawable()` already implements this;
  the ruling is that it is right, not provisional. The `### Needs input` entry asking the question is therefore
  answered and removed.
  **Two rules hold the whole design together and are the ones to reverse if any of it is wrong.** The magnet touches
  GESTURES ONLY — a dragged position, a dragged rotation and a dragged edge go to the quarter-metre and the
  fifteen-degree step, a TYPED measurement is taken exactly as measured (the 20:40 entry, now general). And a gesture
  writes into the FORM, never to the API — dragging fills x and y and stops there, so nothing reaches the server
  before "Enregistrer", `Échap` and Annuler work everywhere, and a half-arranged plan can never save itself.
- [2026-09-21 22:15] AGREED: **the settings engine gains a fourth company chain, `venue`**, which is where the
  plan palette's ready-made sizes live (`venue.shape.{rack,zone,aisle,dock}.{width,depth}`, `VenuePlanSettings`).
  The approved canvas is explicit that these are the company's settings and not constants of the code — *"un
  entrepôt de palettes et une boutique n'ont pas les mêmes rayonnages"* — and none of the three existing chains
  fits: `parties` is what a document says to its customer, `articles` is what a product starts with, and
  `presentation` is how a person likes their screen, while a rack's real length is a fact about the warehouse.
  Since the company settings page draws ONE SECTION PER CHAIN, putting them in any of the three would have filed
  rack sizes under a heading that is plainly wrong to whoever reads it. The chain stops at the company: a floor
  belongs to one and nothing below a company draws a plan. It is cheap to reverse — the stored `setting` row
  carries the level and the key but NOT the chain, so moving these keys to another chain later is a code change
  with no migration — and it is recorded here rather than asked about because of that.
  The sizes reach the screen through `stock-options`, the context the plan already loads, so the web carries no
  measurement of its own; a company whose settings answer nothing gets no palette rather than an invented one.
  The canvas's three STRUCTURE shapes (a wall, a door, a post) are deliberately absent: they belong to the
  walls-and-doors layer, which ruling (2) above puts after the drawing gestures.

- [2026-09-21 23:20] AGREED: **a repeat's spacing is the FREE FLOOR between two rectangles, not the pitch**, and a
  rectangle is repeated only from a QUARTER TURN (row 83, the canvas's Repeat board; `DrawStockMap::repeat()`).
  Two departures from the approved canvas, both recorded here because the author made them rather than asking.
  (1) The canvas's own arrow measures the PITCH — its 27 px racks step 54 px and the arrow spans one whole step —
  so "espacement 1,20" there means depth plus gap. The free floor was chosen instead because it is what a person
  measures with a tape between two racks, and because it cannot be typed wrongly: zero means back to back, and no
  value makes two copies overlap, whereas a pitch smaller than the rack's own depth silently does. The field says
  so in words on the screen (`repeat_spacing_hint`), so nothing is ambiguous where it is typed.
  (2) Only 0, 90, 180 and 270 degrees may be repeated. The step along the floor's axis is the side the rectangle
  actually COVERS along it, which for any other angle is an irrational projection: the screen computes its dotted
  preview in JavaScript and the API computes the copies in bcmath, and at a right angle those agree exactly while
  at 30 degrees they part company in the third decimal. A preview that does not show what will be created is worse
  than no preview, so the angle is refused by name rather than approximated.
  **What "refusée avant, pas après" can and cannot mean here**: `PlanRect` knows no distance below zero, so a copy
  stepping past the floor's top or left corner is refused before anything is written — but a floor has no right or
  bottom edge in this model (the view's frame is derived from what is drawn, and the only limit is 10 km), so a
  repeat running rightwards or downwards for ever is NOT refused. The canvas's promise is kept on two sides of
  four, and that is what the code does rather than what it claims.
  The copies are created as stock LOCATIONS as well as rectangles, in one transaction, because a rack that is drawn
  and does not exist is a picture rather than a place goods can be put; the count is capped at fifty as a typo
  guard, not as a rule about warehouses; and the panel lists the codes it will create before it creates them.

- [2026-09-22 01:30] AGREED: **the building is its own table, and nothing in it is a place** (row 83, the canvas's
  Structure board; API half). `venue_structure` (migration `Version20260922010000`) holds a `kind` and the same
  `PlanRect` every other rectangle on the plan is measured in, on one `venue_area`, scoped to its company.
  It is NOT a `venue_spot` and NOT a `stock_location`, for the reason the board gives in its own words: a spot
  exists to be BOUND to — a stock location points at one, a dining room's table will point at one the same way —
  and nothing ever binds itself to a wall. Drawn as stock locations, every wall would sit in every stock list,
  every import and every movement's location picker, holding nothing for as long as the company exists.
  **Four kinds, not three** — `wall`, `door`, `post`, `dock`, which are the board's own four tools. Nothing in § 7
  had ruled the count; `VenuePlanSettings`'s docblock said three, which was that file's summary and not a ruling.
  Note the plan calls two different things *quai*: this `dock` is the OPENING a lorry backs to, cut through the
  building's envelope, while `venue.shape.dock.*` is the dock BAY posed in front of it, which is a stock location
  and does hold goods.
  **The kind is revisable, and is part of what counts as a change.** A doorway traced with the wall tool is right
  in every measurement and wrong in exactly one field; a comparison reading the rectangle alone would answer
  "nothing changed" and leave it a wall. Since an audit row is also the live-change signal, that would additionally
  leave every other open plan showing the wall.
  **Eight new settings under `venue.structure.*`, with their own floor.** The board's partition is 6,90 × **0,20**
  m, and `VenuePlanSettings`'s palette floor (0,250 m) refuses a thickness that thin — so the structure keys carry
  `MIN_BUILT` = 0,050 m instead. Two floors rather than one lowered to fit both: a single floor thin enough for a
  wall would let a rack be posed five centimetres deep. A door and a dock are cut INTO a wall and declare no
  thickness of their own, and a post runs floor to ceiling, so three measurements are taken from the wall rather
  than declared again — a second key for a thickness could only ever disagree with the first.
  **The HTTP surface sits in the module** (the ruling of 2026-09-21 06:10) while the use case is the venue's own:
  `GET`/`POST /api/companies/{companyId}/stock-floors/{floorId}/structures`, `PUT`/`DELETE
  .../stock-structures/{structureId}`, read with `stock.read` and built with `stock.write`. The processors call
  `ArrangeVenue` directly rather than passing through `DrawStockMap`, because the inventory has nothing to say
  about a wall and a pass-through that adds nothing is a layer that can only drift.
  Removing a floor now takes its structure as well as its spots. `setting-labels.sh`'s floor rises to 23, which is
  above what the other declarations plus the palette's eight alone produce, so losing either block of eight reds.
  **Deliberately not in this half**: the demo fixtures draw no map at all — three slices of row 83 have shipped
  without them — so no wall was added there either; and the "Fond de plan" layer of the board waits for the floor
  image it would show, rather than shipping a control that can never do anything.
- [2026-09-22 04:30] AGREED: **the structure layer is drawn UNDER the stock, and the board carries a layers panel**
  (row 83, the Structure board; web half). The building's rectangles are emitted before the spots in the same
  `<svg>`, because SVG has no z-index and document order is the whole of the answer: a rack standing against a
  wall is then what both the eye and the pointer find. A test asserts that order through
  `compareDocumentPosition`, and the mutation that moves the block to the end of the drawing reds it.
  **Each layer shows and locks itself** — structure, racks, zones — with the counts derived from what is loaded
  rather than stored, so no number needs maintaining. Locked means the layer keeps `pointer-events: none` while
  staying drawn and reachable by keyboard: it stops being something caught by accident while a rayonnage is being
  moved, and it does not become unreadable to do it. Hidden takes the layer off the plan altogether. Both are
  per-person view state and are deliberately NOT settings: nothing about which layer this person has folded away
  belongs to the company.
  **A tool poses at the company's own measurements, never at a constant.** `structureValues` reads the four shapes
  the API resolves from `venue.structure.*`; a kind the settings say nothing about opens at zero, so a palette
  that could not be read is visibly empty rather than quietly wrong.
  **Naming the stock's own rectangles was the blast radius.** Adding a `<rect>` under the spots broke four specs
  that had been selecting `svg rect` and `svg g` by position — a real consequence, not a test artefact, since the
  first rectangle in the plan is now the building. Both elements carry `stock-drawing-rect-<code>` /
  `stock-drawing-group-<code>`, and nothing on this board is selected by document position again. The same
  sweep was owed to `web/e2e`, where six selectors read `svg[data-testid="stock-map-svg"] rect`: they counted the
  stock and would have counted a wall, so they read the named prefix too. A Playwright scenario poses a door with
  its own tool against the real stack, reads it back off the plan, proves it is on no list of what is drawn and on
  no location list, locks it, hides it and erases it; a mutation that builds every piece as a wall reds it.
- [2026-09-22 04:35] AGREED: **the map is finished before the barcode, and the barcode field before any scanner**
  (developer ruling, asked and answered: *"Option 1 then 2"*, where option 1 was finishing the walls-and-doors
  layer then in flight). The barcode comes before row 63's scanner; where exactly it falls against row 83's four
  REMAINING slices — the floor image and its two calibration points, the Three.js 3D view with
  `presentation.stock-map-view`, search-highlight and the rack front view — was not what the question asked, so it
  is not recorded here as ruled. What is ruled is the pair: the
  product's own `barcode` field on the terms already ruled on 2026-09-17 § 7 (optional, unique per company,
  EAN-13/EAN-8/UPC check digit verified only when the shape matches one, matched on by import), then row 63's
  camera scanner as its own goal. The reason the field goes first is that it is what the scanner would write into:
  a scanner shipped against no field has nowhere to put what it reads, and the field is useful on its own the day
  it lands — typed, imported and searched — while the scanner is not useful without it.

- [2026-09-22 05:05] AGREED: **the barcode field and row 63's scanner are ONE goal**, taken next (developer ruling,
  asked and answered: *"Option 3"*), which settles the boundary the 04:35 entry left open: the remaining four
  slices of row 83's board — the floor image and its two calibration points, the Three.js 3D view with
  `presentation.stock-map-view`, search-highlight and the rack front view — come AFTER it.
  **The field itself has existed since 2026-09-14** (`product.barcode`, migration `Version20260914160000`, shape
  rule in `ProductDetails`, in the search index, in the import, on the form). What row 61 rules and nothing
  implements is the pair the 2026-09-17 entry added: **unique within the company when set**, and an **EAN-13,
  EAN-8 or UPC check digit verified when the code has that shape**.
  **This supersedes the 2026-09-14 line** that a barcode "is not unique (one EAN can be sold under two
  references)". The later ruling is the one that holds: a scan must find exactly one product, which is the whole
  reason the scanner can add a line without asking. A business that genuinely sells one EAN under two references
  expresses that through a family or a substitution group, not by two products answering one scan.
  **One shape is knowingly not detected**: UPC-E is eight digits with its own check rule, and an eight-digit code
  is read here as EAN-8. Tunisia is EAN territory and UPC-E is a North-American retail compression, so a UPC-E
  code fails the EAN-8 check and is refused rather than kept as typed. Named here so it is a decision and not a
  bug report.
  **Row 61's other half stays out of scope**: the reference generated from a numbering series is still marked
  "proposal to confirm" in the 2026-09-17 entry and has not been ruled on.
  **Known and deliberately not fixed in the API half**: `products-api.ts` maps EVERY product 409 to
  `reference_taken`, so a duplicate barcode currently toasts the wrong sentence. The web cannot tell the two
  apart, because a 409 carries prose and no machine-readable code — and matching on an English message is the
  kind of guess that breaks the day a translation lands. Giving the refusal a code is an API-surface decision
  (it changes the error payload and the OpenAPI document), so it is the next slice rather than an improvisation.
  Until it lands, the refusal is correct and its label is not.

- [2026-09-22 06:00] NOTED (not a ruling — the state row 63 is picked up from): the barcode field's own rules are
  built and pushed; **the scanner has not been started, and deliberately so**. The 2026-09-17 entry puts three
  research gates in front of its first line of code, and two of them are one-way doors:
  1. **The decoder's licence.** `BarcodeDetector` exists in Chrome and Android and NOT in Safari, so a library is
     needed for the iPhone — and the popular ones are exactly the trap § "Licensing invariants" names: read the
     real LICENSE file at the commit the lock installs, never the metadata string. `zxing-js/library` reads
     Apache-2.0 and `zbar.wasm` LGPL-3.0; neither has been verified here, and LGPL in a bundled SPA is a
     licensing question, not a build question — which means it STOPS and asks (invariant 6).
  2. **Camera over HTTPS.** `getUserMedia` is a secure context only. The local stack is plain http on :8090, so a
     scanner cannot be tried at all until that is answered — a certificate for development, or the decision that
     it is only ever exercised in e2e against a served origin.
  3. **iPhone Safari.** Whether the pairing half is needed at all depends on it: if Safari serves the camera to
     the PWA acceptably, a phone scans for itself and the QR-pairing is for laptops only.
  The pairing half is already specified in the 2026-09-17 entry (single-use token, 10 minutes, scan-only, one
  laptop tab, over the realtime channel the tab already holds) and needs no further design before the three
  answers above.
  **And the slice that comes before any of it**: `products-api.ts` labelling every product 409 `reference_taken`,
  noted in the 05:05 entry. A scanner that fills the barcode field will hit that refusal constantly, so the wrong
  sentence stops being cosmetic the moment the scanner exists.

- [2026-09-22 06:30] OPEN — developer feedback on the stock map, to go over together before more is built on it.
  Recorded verbatim in substance: *"i could not use it properly, i don't know if it's not complete or i did not
  understand; where is the 3d view; for walls/doors i can't move them"*. Three items, of which two are answered
  and one is not:
  1. **The 3D view is NOT BUILT.** It is one of row 83's four remaining slices, with the floor image and its two
     calibration points, search-highlight and the rack front view. The 2026-09-19 23:40 ruling promised two views
     from the start over one set of data; only the 2D half exists. Nothing in the screen says so, which is its own
     defect: a person looking for a promised view finds no view and no explanation.
  2. **A wall or a door genuinely CANNOT be dragged, and that is a gap, not a misunderstanding** [Verified by
     reading the template: a stock rectangle carries `(pointerdown)="grab(…)"` plus eight `grip` handles, while a
     structure rectangle carries only `(click)="openStructure(…)"`]. A piece of structure can therefore be moved
     only by typing x and y into its form. The gestures shipped for stock in earlier slices were never extended to
     the structure layer, and the board gives no sign of the difference — the piece even shows `cursor-pointer`,
     which promises a direct manipulation that is not there. **This is the first thing to fix.**
  3. **"Could not use it properly" is NOT diagnosed and must not be guessed at.** Two candidates are visible from
     here — the asymmetry in (2) makes half the board feel inert, and nothing anywhere explains the arming model
     (a tool is armed, then the floor becomes a drawing surface for one box) — but which of those, or something
     else entirely, is what the developer met is unknown. It is to be walked through together at the office, on
     the running stack, before anything else is built on this board.
  **Consequence for the order**: this comes before row 63's scanner and before the four remaining map slices. A
  board whose own author could not use it is not a finished board, whatever its tests say — and every one of this
  session's certifications was of behaviour, never of whether the thing is usable, which is exactly the blind spot
  named in § "Certification" as the one a lens cannot see.

- [2026-09-22 09:10] AGREED: **the way in stops being the password — passkeys become a FIRST factor, and the session
  stops expiring under you** (developer ruling, asked and answered). The ask was *"a PIN so I don't write my long
  password each time"*; the audit answered it differently. `web-auth/webauthn-lib`, a `passkey` table, the
  ceremonies and eight endpoints ALREADY EXIST — wired as a second factor only: both public login routes open with
  `$this->pending->waiting()` and 401 `mfa_not_pending`, and that marker is written by `SecondFactorListener` at
  priority **-100**, i.e. after the password is verified. The ceremony also always sends `allowCredentials` and asks
  `RESIDENT_KEY_REQUIREMENT_PREFERRED`, so no discoverable credential exists. Promoting it is a ceremony change
  (resident key required, usernameless assertion) plus listener ordering — not a new subsystem.
  **A server-checked PIN is refused, and the reason is written here so it is not re-proposed.** Everything that
  makes a Windows Hello PIN safe is device-bound: *"the PIN never leaves the device"*, it unlocks a private key in
  the security module, and the hardware rate-limits guessing. Send a 4-6 digit PIN to an API and none of that
  holds: a ~10⁴–10⁶ secret grants what a ~10²⁰ one did, portable to any device and stealable from the database.
  That is a downgrade wearing an upgrade's clothes. A PIN keeps ONE legitimate home here — the counter sale
  (row 82), as a fast user switch on a shared, already-authenticated terminal — and is not built before it.
  **The real cause of the pain was the session, not the password**: `idle_ttl` 1800 s and `absolute_ttl` 43200 s
  with NO remember-device anywhere, so a working day re-authenticates repeatedly. A trusted-device grant lands with
  this. Note it needs the `sessions` table to gain a `user_id` — today the user id lives only inside the
  serialized `sess_data`, so per-user session listing or revocation is a schema change, not a screen.
- [2026-09-22 09:12] AGREED: **every second factor is manageable, and the last one cannot be removed** (developer
  ruling; they were blocked in testing and were right). `User::disableTotp()` exists at `User.php:283` with **zero
  callers** — an enrolled authenticator can never be removed or re-enrolled, while `MfaEnrolmentListener` answers
  403 on every `/api` route until enrolment, so a company with `mfa_required` on has no way back. Passkeys can
  already be added and deleted and recovery codes regenerated; TOTP is the hole. One security screen covers all
  three, under two rules: **never remove the last factor while the company requires MFA**, and removing one asks
  for a fresh factor or the password first.
- [2026-09-22 09:14] AGREED: **password policy — 12 characters, the breach list, no composition rules, and history
  only as a knob** (developer ruling, asked and answered; the ask included symbols/capitals/digits and a minimum of
  10). **Composition rules are refused on the standard's own words** — NIST SP 800-63B §5.1.1.2: *"Verifiers SHOULD
  NOT impose other composition rules (e.g., requiring mixtures of different character types or prohibiting
  consecutively repeated characters) for memorized secrets."* They produce `Passe2026!`, which satisfies every rule
  and sits in every breach corpus; **min-12 plus the breach list is strictly stronger than min-10 plus
  composition**, because it refuses what attackers actually try. **The minimum stays 12, not 10** — NIST's floor is
  8 and this codebase is already stricter; that number is not lowered. The compromised-password scan the developer
  asked for **already exists** (`HibpBreachedPasswordCheck`, k-anonymity, `Add-Padding`, 3 s) and keeps **failing
  open** by explicit choice: a third party being down must not block onboarding, and the skip is audited
  (`breach_check_skipped`). **Password history** is a per-company setting, default OFF, capped at 5 — NIST says
  nothing about it, it only means anything when rotation is on, and each comparison is an argon2id verification at
  64 MiB. Expiry is likewise a knob defaulted to **never** (entry of 09:08 below). Both read the existing
  `password_changed_at`, written since 2026-09-14 and read by nothing.
  **The prerequisite both share: there is no way to change a password in this application** — no change endpoint,
  no forgot/reset; the only three `setPasswordHash()` callers are accepting an invitation, completing signup and
  the CLI seed. That flow is built first, because an expiry policy would otherwise demand something the product
  cannot do, and because a user who suspects compromise currently cannot act — the one case NIST says a verifier
  SHALL force a change for.
- [2026-09-22 09:08] AGREED: **password expiry ships as a per-company knob, defaulted to never** (developer ruling).
  NIST §5.1.1.2: *"Verifiers SHOULD NOT require memorized secrets to be changed arbitrarily (e.g., periodically).
  However, verifiers SHALL force a change if there is evidence of compromise of the authenticator."* The knob
  exists because PCI-DSS, ISO 27001 auditors and enterprise procurement still ask for it and this is a product
  sold to businesses; the default follows the standard. This needs a `security` settings chain, which does not
  exist — the five today are `parties`, `articles`, `presentation`, `venue`, `platform`, and `company.mfa_required`
  is a plain column rather than a setting.
- [2026-09-22 09:20] AGREED: **the scanner starts with the keyboard wedge, not the camera** (developer ruling).
  § 7 of 2026-09-17 says *"USB and Bluetooth scanners already work as keyboards"*; they do at the OS level and
  **do not work in this application**. `pick-field.ts` debounces `typed.valueChanges` by `PICK_PAUSE_MS = 300`,
  declares no `autoActiveFirstOption` (zero hits for it anywhere in `web/src`) and carries no Enter handler, so a
  scanner that emits its code and Enter in tens of milliseconds gets Enter before the request is even issued.
  Nothing anywhere implements the 2026-09-20 04:15 ruling that one scan *"adds the line when exactly one product
  matches, and offers the choice when several do"*. The wedge path — exact-match-wins ranking, a scanner-speed
  burst not debounced, Enter taking a single hit — needs no camera, no decoder licence and no HTTPS, and is what a
  shop counter actually uses. Row 63's three research gates stand unchanged in front of the camera half.
- [2026-09-22 09:24] AGREED: **the map is made usable before it is made bigger** (developer ruling, answering the
  06:30 OPEN item with the mockup in hand). In order: zoom, pan and fit with a scale bar; the **Consulter /
  Aménager** split; one drawing model instead of three; structure gaining the same gestures, list rows and
  keyboard path as stock; the permission hole; confirmations on erase. Then the floor image, then the 3D, then
  search-highlight and the rack façade.
  **What the audit found, all of it uncovered by the test suites.** (a) There is **no zoom, pan or fit at all** — a
  24 m depot in a fixed viewport, no way to approach or step back; the most likely reason the board could not be
  worked with. (b) **Three drawing models coexist** — the form, the palette and an armed `Tracer` tool that sits
  BELOW three other blocks and is the only thing needing arming — and no text on screen ranks or explains them.
  (c) `openStructure` has **no permission guard whatsoever**: `stock.read` alone opens a wall's form with
  Enregistrer and Effacer live, and only the API refuses. (d) A hidden or locked structure layer leaves **no path
  to the piece at all** — no list row, no keyboard route — which makes the comment shipped beside it, claiming a
  locked layer stays keyboard-reachable, **false**. (e) No erase anywhere asks for confirmation. (f) The tablet's
  handles are `frame().width / 110`, a metre value scaled by the floor, never the ruled 44 px target.
  **And the floor image is already built in the API**: `VenueArea` carries `imageFileId`, `imageOpacity` and
  `imageMetresWide`, refused coherently by `showPlan()`, writable through `ArrangeVenue` → `DrawStockMap` →
  `WriteStockFloorProcessor`, exposed on `StockFloorResource` and typed in `StockFloorRow`. Only the UI is absent.
  One mismatch to settle when it is built: decision 4 rules **two calibration points**; the column is a single
  `imageMetresWide`.
- [2026-09-22 09:30] AGREED: **the palette of ready-made shapes becomes DATA, and a shape may be round** (developer
  ruling, asked and answered: more shapes were wanted, *"a round étagère?"* among them). Each palette shape today
  costs two keys in `VenuePlanSettings` — eight shapes, sixteen keys — plus a branch in code, so twenty shapes
  would be forty keys and a release per trade. Instead a **`venue_shape` row per company** carries its name,
  family, default width/depth/height and colour, seeded per trade (quincaillerie, atelier, café) and editable by
  the owner. Same reasoning as the fiscal presets: a trade is data, not a release.
  Shapes named as wanted, none of them the whole list: palettier, rayonnage léger, **cantilever / rack à barres**
  (tubes and profilés — both launch customers hold long goods), gondole, tête de gondole, comptoir/caisse,
  vitrine, emplacement palette au sol, chambre froide, zone de retours, zone de quarantaine, établi, machine,
  armoire à outils, zone de matière première, zone de pièces finies; and for the structure, fenêtre, escalier,
  monte-charge, rideau métallique.
  **`form: rect | round` joins the rectangle**: a round shape draws as an ellipse inscribed in its bounding box and
  extrudes as a cylinder, so the quarter-metre snap, the rotation and every gesture keep working and the migration
  is one column. It is not only for shelving — the Venue context already promises that *"a dining room's table
  will point at one the same way"*, and a café without round tables is not a café. Polygons are deliberately NOT
  taken: arbitrary outlines change hit-testing, the editor and the 3D all at once, and no concrete need names them
  yet.
- [2026-09-22 11:05] AGREED: **a product carries SEVERAL barcodes, in a table of its own**, not the single
  `product.barcode` column shipped at `9dbb8c4`. A row is `(role, code, quantity, supplier?)` where the role is
  `unit | pack | supplier | internal`: a `unit` row holds exactly 1, a `pack` row holds strictly more than 1 (the
  ITF-14 on a box of 100 enters a hundred pieces in one scan), a `supplier` row is the code the supplier prints on
  *their* carton and is tied to that supplier, and an `internal` row is one we generated and printed ourselves.
  **Uniqueness is across the whole table within a company, all roles together** — two rows sharing a code would
  make a scan ambiguous, which is the one thing a scan may never be. The shipped column becomes the first row of
  the table and its check-digit rule (EAN-13, EAN-8, UPC, right-aligned mod 10) and its partial unique index move
  with it unchanged. Taken now, while nothing yet reads the column, because taken later it costs the same
  migration **plus** every scanning screen rewritten. Mocked on the *Un article, plusieurs codes* board.
- [2026-09-22 11:10] AGREED: **lots, serial numbers and expiry ship in the first working version, in full.** A
  product declares its tracking — `none | lot | serial` — and where it is not `none` the stock ceases to be a
  number per location and becomes **a number per lot**: two drums of the same glue are no longer interchangeable
  when one expires next month. Picking is **FEFO** (the lot nearest its expiry leaves first, whatever is most
  reachable), an expired lot is blocked and unblocking it is an explicit, recorded act, and a `serial` line always
  carries quantity 1. The GS1-128 application identifiers are read rather than treated as one opaque code —
  `(01)` the GTIN, `(10)` the lot, `(17)` the use-by date, `(21)` the serial — because without that the whole
  string matches no product and a scan that carries everything fails. Reception, picking, documents, inventory and
  the recall search all change with it; this is the heaviest piece of the model and is taken deliberately, since
  adding it after the stock movements exist means rewriting every movement already recorded.
- [2026-09-22 11:15] AGREED: **a location's capacity is optional and carries the unit that measures it** —
  `pieces | bins | linear metres | m³ | kg`, each rack choosing its own, because a rack whose real limit is
  "24 bins" cannot honestly be written in cubic metres and nobody will convert it. The product carries **optional
  volume and weight**, which is what makes the `m³` and `kg` units resolvable at all: shipping the unit without
  those fields would be a feature that can never fire. The occupancy reading colours only what it can actually
  compute and **names the rest "non mesurable" instead of inventing a percentage** — the defect the mockup was
  built to expose. Four nullable columns, no forced data entry.
- [2026-09-22 11:20] AGREED: **a venue structure carries a name.** Locations already hold both a `code` (the
  store's own numbering, 32 chars, `[A-Za-z0-9._-]`) and a `name`; walls, doors, posts and docks held neither, so
  "the quai 2 door" could not be written down. The plan also gains a **choice of what it labels** — the code, the
  name, or the code with the name in the panel and on hover — because a dense plan cannot carry both, and the
  code-only label shipped today is a choice nobody was offered. **Bulk renumbering** comes with it: a pattern with
  a counter (`A{n}`, `R{nn}`), a start and a step, a full before/after preview, and a refusal naming the code
  already taken rather than a silent partial apply. A code change keeps the stock and the history — it is the same
  location renamed — leaves past documents on the code they carried, and requires the shelf label reprinted.
- [2026-09-22 11:25] AGREED: **the search bar is a workbench, not a link.** A scan opens it from any screen, and
  the found product is then acted on **without leaving the screen behind it**: add to the open document, adjust
  stock with a reason, move between locations, change the price, print labels, show on the plan, movements,
  traceability, reorder from the supplier, its barcodes, edit the sheet. The chosen action **opens inside the
  palette** rather than navigating away, and every one of them has a single-key shortcut, because the hand that
  just used a scanner is not on the mouse.
- [2026-09-22 11:30] AGREED: **build order — the plan first, then the data foundation, then the scanning
  screens.** (1) The plan made usable: zoom, pan, fit-to-screen, movable walls and doors, named structures, the
  label choice — it is what the developer could not use, and putting away, picking and the picking path all rest
  on it; the three keyboard-wedge defects in `pick-field.ts` (the 300 ms debounce, no `autoActiveFirstOption`, no
  Enter handler) are pulled forward into this step because they are twenty lines and depend on no model. (2) The
  **two migrations together** — the barcode table and the lot model — since both touch the same stock movement.
  (3) Every scanning screen on top, written once. The developer's own order put the scanning screens before the
  migrations; moved deliberately, because that order writes search, document lines and reception twice.
- [2026-09-22 11:30] AGREED: the full model for both features is **mocked and validated before any code**, on two
  Design canvases of thirteen interactive boards each: the plan
  (`https://claude.ai/artifact/LCU1XwArUfwwBDDvh4WTsd` — consult, search, occupancy, sites & floors with the
  architect's underlay, arrange, naming, picking path, 3D volume, rack elevation, cycle counting, printing &
  permissions, tablet, phone) and the barcode
  (`https://claude.ai/artifact/N6hRMBa7US16mYVpQkNNDg` — the field and its refusals, the code types, labels for
  products *and locations*, search-as-workbench, document lines, supplier reception, put away, pick, count,
  camera, lots & serials, traceability, scanner settings). Boards are a design record, not a contract: where one
  disagrees with a later ruling, the ruling wins.
- [2026-09-22 12:20] AGREED: the API documentation is reached on the application's own origin at **`/api/docs`**,
  and every redirect nginx issues is **relative**. Two defects, both invisible to a status-code check and both
  reported by the developer on the same page. `/api` answered a 301 to `http://localhost/api/`, dropping the port:
  nginx answers a proxying `location` ending in `/` with its own absolute redirect, built from `server_name` and
  the LISTEN port, which is 80 inside the container — no proxy header can reach it, so `absolute_redirect off`
  is the fix. And the page's stylesheets and scripts live under `/bundles/`, which is not under `/api/`, so they
  fell through to the single-page application's catch-all and came back as `index.html` with `Content-Type:
  text/html`, which `nosniff` refused to run: 200 everywhere, Swagger rendered as unstyled text. `/bundles/` is
  now proxied to the API. `web/e2e/api-docs.spec.ts` pins both, with a size floor on the bundle so serving the
  wrong file under the right content type still reds. Production is unaffected: it sets `enable_docs: false`.
  One inline style Swagger applies at runtime stays blocked by the CSP and the page is whole without it; the
  application's `style-src` is not weakened for a development page.
- [2026-09-22 13:40] AGREED: **the plan writes what its reader chose** — the location's code, its name, or both —
  as the presentation preference `presentation.plan-labels`, `code` by default. A store arrives with its building
  already numbered, already named, or moving from one to the other; that is a property of the reader and not of the
  data. Three rules make it honest. A stock location always has a code and may have no name worth reading, so asking
  for the name falls back to the code — a blank rectangle reads as data loss. A piece of the building has no code
  and never will, so it is labelled by its name under EVERY choice rather than vanishing under "codes". And a label
  is drawn in METRES on a scale plan, so one too long for its own rectangle is CUT with an ellipsis: found by
  looking at the rendered plan, where `LBL77640633 · Rayonnage LBL77640633` ran 6,1 m across a 3,9 m rack and onto
  the floor beside it, where it read as belonging to nothing. Nothing is hidden by the cut — the whole label is the
  rectangle's own `<title>`, on the shape a person points at and not on the text, which takes no pointer and whose
  title would therefore never be shown to anyone.
- [2026-09-22 13:45] AGREED: **a presentation key the SPA writes is a key the API declares**, both ways, checked by
  `scripts/gates/presentation-settings-parity.sh`. The defect is silent on both sides: `ApiSettings.set` posts the
  choice and swallows the answer (`.catch(() => {})`), so for a signed-in person an undeclared key works perfectly
  until the page is reloaded and is then simply gone, while the unit specs use the browser-storage adapter where
  every key persists. `presentation.sidebar-settings` had shipped that way and nobody had noticed; the settings
  area's fold state did not survive a reload. Both it and `presentation.plan-labels` are now declared, labelled in
  both languages, and the gate carries floors so a pattern that stops matching reds — which it did, on its own
  first real run, catching a grep that read one key where seven were declared.
- [2026-09-22 14:05] AGREED: **signing in without a password means a PASSKEY as the first factor, never a password
  field that accepts anything.** The proposal on the table was a per-person checkbox making any password pass, with
  the second factor still asked. It is refused on three grounds. It does not remove the password, it removes the
  CHECK: the field, the form and the code path all remain, so the screen, the audit trail and the code keep saying
  "authenticated by password" about something never verified. Its blast radius is not the login screen — a password
  check also guards re-authentication, a password change and recovery, and the first of those that forgets to read
  the flag is a universal bypass. And TOTP is not a first factor: it is a shared secret, phishable in real time, so
  "any password + TOTP" is strictly weaker than "password + TOTP".
  What ships instead is genuinely STRONGER than today: a discoverable-credential WebAuthn ceremony with
  `userVerification: 'required'`, where the authenticator checks a biometric or a PIN locally and never transmits
  it, origin-bound by the protocol. NIST SP 800-63B calls that a multi-factor cryptographic authenticator — AAL2 in
  one ceremony, AAL3 with a hardware authenticator, where password + TOTP plateaus at AAL2. Passkeys exist here
  today but are strictly a SECOND factor: `BeginPasskeyAssertion` names the account's own credentials and its own
  docblock says it runs after the password step. Off by default, as `presentation`'s sibling in the security chain:
  company → role → user, and a company may pin it so members cannot change it. The password stays, as the recourse
  when no authenticator is at hand and as what re-authorises a sensitive change.
- [2026-09-22 14:05] AGREED: **the account lockout is carried by the PASSWORD factor, not by the account.** Five
  consecutive failures lock for fifteen minutes (`app.auth.lock_after_failures`, `app.auth.lock_duration`), beside
  Symfony's `login_throttling` (10 per account+IP per 15 minutes) and the `mfa_verify` limiter (5 per 5 minutes).
  Those numbers stand — NIST SP 800-63B asks for rate limiting or lockout and warns specifically against a
  PERMANENT one, which is a denial of service wearing a security badge. What changes is what the lock blocks: a
  passkey must pass while the account is locked, because a lock exists to stop GUESSING and a signature cannot be
  guessed. Left on the whole account, anyone who knows an address locks its owner out by failing five passwords —
  including accounts that never sign in with one. Two consequences: the screen says how many minutes remain rather
  than refusing indistinguishably from a wrong password, an owner may lift a lock, and a first-factor ceremony must
  never reveal whether an address exists (`BeginPasskeyAssertion` raises `PasskeyRefused` for an account with no
  passkey today, which its comment justifies only because the password step already proved the account exists).
- [2026-09-22 14:05] AGREED: build order for passwordless sign-in — the ceremony first, the policy after:
  (1) a discoverable-credential assertion that begins with no known user and reveals nothing about an address,
  (2) the listener that accepts that assertion as the whole login, (3) the lock moved onto the password factor,
  (4) the company → role → user setting, (5) the button and its e2e. Each step is certifiable on its own, which a
  single commit over this surface would not be.
- [2026-09-22 20:15] AGREED: **the first working version carries the distinctive features, and the groundwork
  still comes first** (developer ruling, asked and answered). The developer counts as distinctive, all to ship in
  the first working version: the two bets (WhatsApp link, row 95; Arabic, right-to-left and bilingual documents,
  row 96); the map in full with the scanner, the search workbench and lots, serials and expiry (rows 83, 63);
  the café module (QR orders routed to stations, rating what one was served), which moves in from "after the first
  working version"; the customer portal, the daily digest and alerts (rows 90, 97, the portal with row 51); and
  the sign-in work below. **"No compromise" is ruled as: a feature that only adds on top moves up freely; one
  that rests on the data model waits for rows 75, 76, 56 and 57**, so nothing is migrated twice — bilingual
  documents wait for row 76's second-language name, anything carried on a document line (a lot, a frozen cost, a
  purchase unit, a price list) for row 76's units, the digest and alerts for the worker (56), and the counter
  sale and the café module for JORT n° 125, still unread (§ 8 "Needs research"). Order: the map walkthrough owed
  by the 06:30 entry first, then the sign-in work, then the reorder of § 2, ruled with what the walkthrough shows.
- [2026-09-22 20:15] AGREED: **today's sign-in and password rulings are confirmed for the first working version**
  (developer re-asked and confirmed each): a passkey as the first factor (09:10, 14:05); every second factor
  manageable, never the last one while the company requires it (09:12); the lock on the password factor only
  (14:05); sessions that do not expire mid-work, the connected devices, change and forgot password (row 50);
  12 characters plus the breach list, no composition rules (09:14); history as a knob off by default (09:14);
  expiry as a per-company knob defaulted to never (09:08). They are additive to `Identity`, need no groundwork
  row, and are built right after the map walkthrough, in the five steps of the 14:05 entry.
- [2026-09-22 20:30] NOTED (answers the 06:30 OPEN entry): **the map walkthrough, run by Claude in a real browser
  on the running stack at the developer's request** ("i could not even place a wall ! when i select it it
  disappears"). Screenshots in `var/claude/map-walk/`. Blockers: **(A)** pressing a posed, UNSAVED wall, door or
  post erases it — the pending piece is drawn by the saved pieces' own template, so its `pointerdown` runs
  `grabStructure` → `openStructure(PENDING_PIECE)`, which edits the zeroed placeholder as if it were a saved row:
  the form resets to 0, the preview is dropped and "Effacer le rectangle" appears; **(B)** the form holding
  Enregistrer opens under the board, off-screen at 900 px, with nothing on the board saying a save is owed;
  **(C)** a door is posed at the floor's centre, under whatever is there, white on white, and snaps to nothing —
  saved and invisible; **(D)** the Rayonnage and Zone tools draw an EXISTING location only, and the save refuses
  "Ce champ est obligatoire" with no hint of why. In the way: **(E)** the board refits after every save, so
  what was just placed jumps; **(F)** tracing a rectangle selects the page's text; **(G)** a piece keeps its
  handles while another is worked on; **(H)** a bare white board, no floor outline, grid or size; **(I)** a rack
  shows nothing of what it holds, so a product cannot be found from the plan; **(J)** "Dessiné sur cet étage"
  ignores the building and lists an unsaved rack as "—"; **(K)** resizing by the right edge moves the left one a
  quarter metre. Works: moving and resizing saved pieces, posing and saving a rack, tracing, the zoom buttons.
  Not exercised: wheel zoom and pan, Répéter, layer locks, floors, the label modes, the phone.
- [2026-09-22 20:32] AGREED: **the Rayonnage and Zone tools create the location with its drawing** (developer
  ruling, finding D): the form asks a code and a name, or picks an existing location, and one save creates both,
  so a store is drawn without leaving the plan.
- [2026-09-22 20:32] AGREED: **a door and a dock door follow the pointer and snap onto the nearest wall, turned
  with it; a click puts them down; a post goes anywhere** (developer ruling, finding C). Each is drawn so it can be
  seen — an outline, and a door's swing — never a white fill on a white floor.
- [2026-09-22 20:32] AGREED: **findings A–K are one goal, "the map is usable", taken now, before the sign-in
  work** (developer ruling), each with its failing test first and an e2e for A, B, C and D; the developer checks it
  on the running stack before the sign-in work starts.
- [2026-09-22 20:44] AGREED: **while a piece is placed or changed, its form stands where the tools were**
  (developer ruling, finding B, chosen over a card floating on the board and a bar pinned under it). The panel is
  as tall as the board beside it and scrolls inside, Annuler and Enregistrer pinned at its foot, so both are on the
  screen while the piece is dragged; the tools come back on save or cancel, and the board's toolbar says "Pas encore
  enregistré" while a save is owed. Forms in that column stack their section titles above the fields
  (`.twes-form-stacked`), leaving every other form's layout untouched.

- [2026-09-22 22:10] AGREED: a floor has a width × depth in metres of its own, asked when it is added and on every
  edit (developer ruling, findings E and H). The board frames the floor, not whatever is saved on it: an outline and a
  one-metre grid (five metres beyond 60 m) show where the floor ends, a save no longer reframes the board, and a wall
  outside the racks stays on it. The floors drawn before this have no size and keep the old framing until someone
  measures them; the database keeps both sides null or both positive. Finding K (the left edge moving) is retracted:
  measured, it was E's reframing and no defect of its own. And a side shorter than four handle radii carries no middle
  handle (`handlesThatFit`): on a 0,20 m wall the handles covered the whole piece, so it could be resized but no longer
  grabbed or hovered.

- [2026-09-22 22:20] NOTED (not a ruling — where the map walk is picked up from): the walkthrough's findings A, B,
  E, F, G, H and J are built and pushed with this entry; K was retracted. **Parked, in the ruled order, when the
  developer moved to the barcode**: D (the Rayonnage/Zone tool creates its location with the drawing — inline on
  `POST …/drawings`, parent the zone the rack is drawn inside, else the establishment's default; 409 on a taken
  code), C (a door or dock door follows the pointer and snaps to the nearest wall, turned with it, a visible swing
  arc; a post goes anywhere), then a minimal I (the rack's card lists what it holds). The dev database still carries
  walkthrough data in Carthage Conseil RC (a RAYON-A drawing, one wall, its floor measured 24 × 15).

- [2026-09-22 22:26] AGREED: **the barcode table first, the lot model right after**, amending the 11:30 build order
  (developer ruling, chosen so the scan is seen working first). In order: (1) a product carries several barcodes
  (`unit | pack | supplier | internal`, the 11:05 ruling) and a scan finds a product by any of them, a pack row
  entering its quantity; (2) the search workbench, opened by a scan from any screen; (3) scanning into a document's
  lines; then lots, serials and expiry, before reception, picking and counting, which are the screens that need them.
  Safe because nothing built in (1)–(3) records a stock movement per lot, so the lot migration rewrites no data.

- [2026-09-22 22:28] AGREED: **the camera scanner is in this goal**, alongside the table, the workbench and document
  lines (developer ruling, after the three open questions of the 06:00 entry were explained). Its three answers are
  therefore taken now: the decoder library's real LICENSE file is read at the version the lock installs and brought
  to the developer before it is added (an LGPL or any non-permissive result stops the work, invariant 6); the local
  stack gains a development certificate so `getUserMedia` can be tried from a phone; and whether iPhone Safari
  scans for itself decides whether QR pairing is built now. What the camera reads enters the same path a handheld
  scanner's keystrokes do.

- [2026-09-22 22:38] AGREED: **the camera decoder is `barcode-detector` 3.2.2 over `zxing-wasm` 3.1.3, reader build
  only**, and **`Apache-2.0 WITH LLVM-exception` is accepted as Apache-2.0** (developer ruling, licensing invariant
  6). Both packages are MIT as read from their LICENSE files; the reader wasm compiles in zxing-cpp and its glue
  (Apache-2.0), libzueci (BSD-3-Clause), stb_image and Emscripten with musl (MIT), and LLVM's libc++/libc++abi
  (Apache-2.0 WITH LLVM-exception, whose exception only waives attribution for compiled-in portions). Refused: both
  zbar builds (LGPL-2.1), quagga2 (no QR, no DataMatrix); `@zxing/library` passes on licence but is in maintenance
  mode. Conditions: import `barcode-detector/ponyfill` or `zxing-wasm/reader` only, never the bare `zxing-wasm` (its
  default is the full build, which carries the writer, libzint and embedded font data whose provenance was not
  read); self-host the wasm, never the jsDelivr default; and the notices generator lists the wasm's embedded
  components, which no lock file shows. Evidence: `var/claude/decoder-licence.md` (session scratch). With a decoder
  that needs only WebAssembly, iPhone Safari scans for itself, so QR pairing is not built in this goal. Correcting
  the 06:00 note: `localhost` is a secure context, so a laptop webcam works on `http://localhost:8090`; only a phone
  on the LAN needs the development certificate.

- [2026-09-22 23:42] NOTED (decisions taken building the barcode table, each the standard's or the codebase's own
  answer): **(1) a code is matched on its GTIN-14 key.** GS1 compares GTINs right-justified on fourteen digits, so a
  UPC-A `036000291452`, its EAN-13 `0036000291452` and its GTIN-14 are one code; the unique key is `(company_id,
  match_key)`, the code itself is kept as typed. Anything that is not a valid 8/12/13/14-digit GTIN is its own key.
  The check digit now covers GTIN-14 too, since a pack row is typically an ITF-14. **(2) A code is found WHOLE.** The
  trigram index keeps the reference and the name; a code spelled exactly is found through the unique key and joins the
  words as `OR p.id = :code` (a BitmapOr of two indexes, where a joined LIKE would read every row), and a document's
  picker offers that product first. Part of a code finds nothing. **(3) Codes are written on their own**, `PUT
  …/products/{id}/barcodes` as one list, like the product's homes and defaults tabs; the product's own PUT leaves them
  alone, so its form can never wipe codes it does not show. A code another product holds answers 409 naming the row and
  that product. **(4) The import's `barcode` column is the UNIT code**: filled, it replaces the unit row and keeps the
  packs, supplier and internal codes the file has no column for; blank, it changes nothing. **(5) The migration** copies
  each `product.barcode` into a `unit` row with its key worked out inside the migration, and aborts naming both
  products when two hold two spellings of one GTIN — choosing which keeps it is not a migration's to decide.
- [2026-09-23 00:40] NOTED (decisions taken building the scan lookup, each the standard's or the codebase's own
  answer): **(1) a GS1 scan is read, never guessed.** `Gs1Scan` reads the three shapes a scanner or a person hands
  over (symbology prefix `]C1`/`]d2`/`]Q3`/`]e0`/`]J1` with FNC1 as ASCII 29, the separator alone, the bracketed
  form) and keeps `(01)`, `(10)`, `(17)` and `(21)`; other identifiers are skipped by their known length, and a string
  carrying one whose length is not known here stays a plain code, since a wrong guess would read a lot into the GTIN.
  `(17)`'s century follows GS1's sliding window and a day `00` is the month's last. **(2) One scan names one product
  or none**: `GET …/product-scan?code=` answers the product, the code's role, how many pieces the scan enters (a pack's
  count) and the lot, use-by and serial it carried, or 404. A retired product is still found, so a person holding one
  learns why it is refused. **(3) Scanning into a document line will use that lookup**, not a quantity added to the
  three pickers' rows: the pickers answer "which products match these words", the lookup answers "what did this scan
  mean", and a pack's count belongs only to the second. The search and the pickers read a GS1 scan by its GTIN too.
- [2026-09-23 01:10] TAKEN OVERNIGHT, to confirm (the recommended option, under the developer's 2026-09-22 instruction
  to keep going and record each choice; listed in `var/claude/overnight-questions.md`): **a scan on a page with no
  field focused opens a card of what it names.** The shell reads every keydown through `ScanWedge`: characters each
  within 30 ms of the one before (`SCAN_GAP_MS`, the threshold `PickField` already used), at least four, closed by an
  Enter as quick, are a scan. The card (`ProductScanCard`) shows the product, the code's role and the pieces one scan
  enters, the lot, use-by and serial a GS1 scan carried, and a retired product as retired; one key goes on — Enter
  the sheet, C its codes (`/products/{id}?tab=codes`), M its movements for somebody who may read the stock. A code
  nobody holds offers the catalogue searched for it; attaching it to a product from the card is left for later. In a
  field, or under an open dialog, the scan stays where it was typed. From the second character of a burst on, the
  keys are swallowed so a letter inside a code never runs a screen's shortcut; the FIRST cannot be told from a hand's
  and runs whatever the screen binds to it — accepted, since GTINs are digits and no screen binds a digit.
- [2026-09-23 01:35] NOTED (the fourth slice, and two defects of the picker it exposed): **a pack scanned into an
  invoice or delivery note line enters the pieces it holds.** `PickField` emits `scanned` with what the scanner read
  after the one match is taken; the line asks `ProductScans`, which asks the scan lookup and answers a count only for
  a pack of the product now on the line — a unit code, another product's code, no product or a failed lookup leave
  the quantity typed. The lot and use-by date a GS1 label carries are not written on the line yet: that waits for
  lots. **Defect 1, fixed:** a line hands its picker a new but equal pick object on every check, and the picker put
  that pick's words back over whatever was being typed, so a product already on a line could never be typed or
  scanned over; the picker now follows the pick by id, reference and name. **Defect 2, fixed:** the question the
  picker asks on focus could answer in the middle of a scan burst, putting its rows back with the first held ready,
  and the scanner's Enter took that row — measured in the browser, a carton scanned into a fresh line put another
  product on it (2 of 6 runs); a burst now drops any answer still out.
- [2026-09-23 02:05] TAKEN OVERNIGHT, to confirm (choices made tonight on the recommended option and first written as
  NOTED above; listed with the others in `var/claude/overnight-questions.md`): **(1)** a scan into a document line
  asks the scan lookup rather than every picker row carrying a count (00:40, point 3); **(2)** the lot and use-by
  date a GS1 label carries are not written on a document line until lots exist (01:35); **(3)** text typed over a
  line's product and abandoned stays in the box until the line changes — putting the product's words back on blur is
  the alternative (01:35). **Correcting the 01:10 exposure, stated plainly:** only unit and pack codes are GTINs, all
  digits. Supplier and internal codes are free text (the demo mints `F-<reference>`), so a scan of a code that starts
  with a letter, on a record page, ran that page's shortcut for the first letter — `s` saves, `e` issues an invoice
  without asking, `v` validates. Too much to accept: **a screen shortcut is now held for one scan gap (30 ms, below
  anything a person notices) and dropped when the next key proves a burst.** Also fixed: AltGr reports Ctrl and Alt together and types a character (`]` of a GS1
  prefix on AZERTY), and the wedge first read it as a command and dropped the scan; it now follows
  `isBareKeystroke`, the rule the shortcuts already used.
- [2026-09-23 02:40] TAKEN OVERNIGHT, to confirm (the recommended option at each point, building the 11:10 ruling;
  listed in `var/claude/overnight-questions.md`): **how lots enter the model.** (1) A product carries `tracking`,
  `none | lot | serial`, default `none`, set on its form; it can change only while the product has no stock movement,
  because a movement written without a lot cannot be given one afterwards — refused 422 on `tracking`, exactly as a
  unit or a kind change after stock already is, through the same `ProductStockHistory` port. A service tracks
  nothing. (2) A lot is
  its own row, `stock_lot`: the product, a code unique within the product (1 to 40 printable characters, no space: GS1
  `(10)` is at most 20, and a company's own codes run longer), an optional use-by date, and who released it and when
  if it was released after expiring. A serial number is a lot of the `serial` product: the same row, never more than
  one piece in stock across the company. (3) Every movement of a tracked product names its lot, a movement of an
  untracked one names none — held by the movement's factories in the domain, since a table constraint cannot see
  the product's tracking. (4) Stock becomes a number per location **and lot**; the levels
  list gains the lot and its date, and an untracked product reads exactly as before. (5) A receipt names the lot by its
  code and date — creating the lot the first time the code is seen, refusing a date that contradicts the lot's own —
  and a GS1 scan fills both. (6) Slices: the model and receipts, counts and moves (L1); delivery notes picking FEFO and
  the expired-lot block with its recorded release (L2); the web — the tracking field, receiving with the scan, the
  levels by lot (L3); the recall search (L4). Nothing here is written to existing movements: every product is `none`
  until someone sets it, so the history stays as recorded.
- [2026-09-23 04:10] TAKEN OVERNIGHT, to confirm (the recommended option at each point, building on 02:40; listed in
  `var/claude/overnight-questions.md` item 9): **how L1 treats lots at its edges.** (1) A lot code is 1 to 40 printable
  ASCII characters without space; an accent is refused, since a scanner reads labels as ASCII and a code nobody can
  scan back finds nothing. (2) A receipt or a count may open a lot, because either can be the first to meet its goods; a
  move names a lot that exists and never opens one. (3) A date given for a lot without one fills it; a different date
  for a lot that has one is refused on `lotExpiresOn`. (4) An expired lot is received like any other: goods do arrive
  past their date, and blocking them is the delivery's job (L2). (5) A serial number is in stock once across the
  company, checked under a lock on its code, which the movement takes before the stock's. (6) Until deliveries pick lots
  (L2), a delivery note leaves a tracked product's line out and says so, the way it leaves out a line in another unit,
  and the demo keeps its tracked articles off its delivery notes. (7) `uniq_stock_movement_source` keeps its columns:
  PostgreSQL counts NULLs as distinct, so adding the nullable lot would stop it holding every untracked delivery once;
  L2 changes it with `NULLS NOT DISTINCT`. (8) The opening-stock import refuses a tracked product's row
  (`lot_tracked`) rather than guessing a lot. Adding a lot column there is not a column alone: the file's identity
  (product and location) would have to take the lot, and an identity column left empty switches the duplicate check
  off, so it waits for its own step.
- [2026-09-23 05:20] TAKEN OVERNIGHT, to confirm (item 10 of `var/claude/overnight-questions.md`): **how deliveries take
  lots (L2).** (1) A validated delivery note takes a tracked product from its lots at the default location, the first
  to expire first, a lot without a date last, lots of one date in the order they arrived. (2) An expired lot — past the
  day it is used by, in the COMPANY's day — stays unless a person released it; a release is recorded on the lot (who,
  when), only for an expired lot, and only once: the first decision stands. (3) What no lot in date holds is not
  moved and is said, the way a line in another unit is, so the stock keepers are told; an untracked product still goes
  below zero silently, as before. Inventing a lot for the shortfall, or taking it from an expired one, would make the
  record say something nobody decided. (4) The source key takes the lot, `NULLS NOT DISTINCT` and partial on
  `source_id IS NOT NULL`: without the predicate, two receipts of one product at one location would collide as one
  row. (5) The demo puts a lot-tracked or serial article on three of its delivery notes.
- [2026-09-23 08:05] AGREED: `Unicode-3.0` joins the licences permitted for anything distributed, and the camera
  scanner uses `zxing-wasm` (developer ruling, answering the 01:50 stop). The decoder compiles in character tables
  generated from unicode.org mapping files; the Unicode License v3 is permissive and OSI-approved, asking only that its
  copyright and permission notice travel with the tables: the lock file names the package MIT only, so the camera
  slice teaches the notices generator to carry that notice too (the file stays generated). Chosen over
  `@zxing/library` (Apache-2.0, maintenance-only, weaker on 1D codes from a phone camera) because zxing-wasm reads
  angled and blurred codes better, GS1 included.
- [2026-09-23 09:30] AGREED: customer privacy has two layers — a server permission `product.cost.read` without which
  the API sends no cost, margin or supplier code, and a one-click "customer view" that hides on screen a
  company-configured list of sensitive fields; the price-check screen turns customer view on by itself.
- [2026-09-23 09:30] AGREED: a scan of a product already on a draft document's lines increments that line (by one, or
  by the pack's count for a pack code) when product, unit and lot are all the same, a hand-edited price included;
  otherwise it adds a line; "5×" then a scan sets the quantity to five.
- [2026-09-23 09:45] AGREED: a phone paired to a computer by QR code is a remote douchette with a remote control. It
  needs no sign-in: a single-use link gives it only the right to send scans to that session, which dies with the
  computer's tab or its sign-out. It keeps scanning. Each scan acts once, on the computer's screen and under the
  computer's session; the phone mirrors the product's name, its customer price and what happened, never more than
  customer view shows. It also shows the same choice buttons, and a tap sends the choice to the computer, which
  performs it.
- [2026-09-23 09:45] AGREED: the scanning goal runs autonomously and continuously in this order, each slice committed
  CI-green and its choices recorded for confirmation: (0) the red e2e fix; (1) a scan bus with per-screen handlers, a
  scan log with undo, and the cashier rule in invoice and delivery-note lines; (2) the generic actions card; (3) the
  device camera, continuous, on zxing-wasm; (4) phone pairing; (5) `product.cost.read` and customer view; (6) the
  price-check screen and a customer display; (7) lots on the web; (8) a stock count mode, location labels and printed
  product labels; (9) QR codes on our own documents; (10) the lot and serial recall search; (11) weighed-item barcodes
  and customer card scans. Purchase orders stay their own module.
- [2026-09-23 10:15] TAKEN OVERNIGHT (standing instruction, to confirm): slice 1's edges. Every scan, whatever read it,
  goes through one queue, one at a time in arrival order, to the screen on view when it acts on scans and otherwise to
  the product card. A draft invoice or delivery note acts on it; an issued document, somebody without `product.read`
  or a code no product holds leaves it to the card (not refused: the card is the useful answer there); a retired
  product is refused, said in a toast. A scan into a focused field stays the field's. A count is digits typed by hand
  then `*`, `x` or `×`, 1 to 9999, within two seconds; a chip shows it until the scan takes it, Escape or a click
  forgets it. A scan that changed a document says so in a toast with "Annuler" for eight seconds, and Ctrl Z outside a
  field takes back the latest scan still standing; the undo leaves a quantity somebody retyped since alone. The last
  twenty scans are kept in memory for the log panel, which comes later.
- [2026-09-23 10:42] TAKEN OVERNIGHT (standing instruction, to confirm): slice 2, the generic actions. The card of a
  known product still sold adds "Nouvelle facture" (I) and "Nouveau bon de livraison" (L), for somebody who may write
  them: the new document plays the scan itself (`?scan=`), so a pack enters its count by the till's rule. A code nobody
  holds offers, to somebody who may write the products, "Créer l'article" first (Enter; after saving, the product opens
  on its codes with the code listed, waiting to be saved) and "Ajouter à un article" (A: a picker of the products still
  sold, then the chosen product's codes with the code listed), then the catalogue search. No purchase order yet: that
  module does not exist. Adding the code is never saved without the person's own save, so the role (unit, pack) is
  theirs to set.
- [2026-09-23 11:08] TAKEN OVERNIGHT (standing instruction, to confirm): slice 3, the device camera. A camera button in the
  top bar (shown only where the browser can open a camera: a secure page) opens a small panel in the bottom corner,
  with no backdrop, so the screen behind stays usable. It reads a frame every 150 ms (at most 960 px wide) with
  `zxing-wasm/reader` 3.1.3, pinned exactly and self-hosted at `/vendor/zxing-reader/` beside the licence texts of the
  ten components it compiles in (listed in `web/src/third-party/zxing-reader/COMPONENTS.json`, which the licence gate
  checks against the lock's exact tarball). A code counts when it comes into view, once while it stays there, and
  again after it has been out of view 700 ms; each read beeps (1.8 kHz, 80 ms), vibrates 40 ms where the device can,
  and goes to the scan bus as a `camera` scan, so the screen on view acts on it exactly as on a handheld scanner's. A
  GS1 label is typed as a GS1 scanner types it (`]C1…`, `]d2…`). The screen is kept awake while the panel is open,
  the camera facing away is the default, and the camera picked last is remembered in this browser. The CSP gains
  `'wasm-unsafe-eval'`, which lets WebAssembly compile and still refuses `eval`. Formats read: EAN-13/8, UPC-A/E,
  Code 128, ITF, Code 39, GS1 DataBar, DataMatrix, QR.
- [2026-09-23 13:24] TAKEN OVERNIGHT (standing instruction, to confirm): slice 4, a phone lent as a scanner. A phone
  button in the top bar (with `product.read`, which every scan handler needs) opens a dialog with a QR code and the
  address `/pair#<link>`. The link is 256 random bits after `#`, so no server log or Referer carries it, and the phone
  page takes it off its address at once. It is single-use: the first phone to claim it within 5 minutes gets a key.
  Only the hashes of the link and the key are stored (`scan_pairing`, context `Scanning`, company-owned). With its key,
  the phone may do four things and nothing else, with no session: claim, send a scan, send a tapped choice, and take a
  realtime token that names only its own channel. That channel is in a new Centrifugo namespace, `scan`. A scan goes
  to the user's channel with the tab named (`X-Tab`), and only that tab acts on it, once per scan id, as a
  `phone` scan through the scan bus, under its own session. The tab then echoes to the phone: the toast's key and
  flat parameters, the product's name and a unit's customer price in the company's currency (a product scan now
  carries `unitPriceNet`, never the cost), and, for a code no screen claimed, the card's own choices. A tap sends
  the choice back, and only the latest echo's choices act. The API holds an echo to that shape. The tab renews the
  pairing every 30 s, and it lapses after 90 s without a renewal. Letting it go, leaving the signed-in shell and
  signing out (a logout listener) end it at once. A closed or crashed tab ends it through the lapse, because
  `sendBeacon` cannot carry the CSRF header and the app's HTTP client is not the fetch backend. One tab lends one
  phone; opening again ends the tab's earlier pairing. Claims are budgeted at 10 per 15 min per client, and a
  pairing's phone requests at 240 per minute. A caller without the key is refused for the key before its request is
  read. The phone keeps its key in its tab's session storage, so a reload resumes without a new link. The QR code
  reuses `lean-qr` through the shared `QrCode` component (moved from `auth/` to `shared/qr/`), so no new dependency.
- [2026-09-23 14:08] TAKEN OVERNIGHT (standing instruction, to confirm): slice 5, the server half of customer privacy.
  `product.cost.read`, declared by the products module, is the only way to the cost. The built-in admin holds it and
  the member does not; the owner holds it through the wildcard. A custom role in an existing database does not get it
  until someone grants it; the alternative was granting it to every role holding `product.write`. Without it:
  - the product's `costPrice` reads null in the item, the list and every write's answer;
  - a supplier's codes are left out of `barcodes`, and out of the codes resource's answer;
  - a create stores no cost, and a revision keeps the stored one, whatever the body says;
  - a codes PUT keeps the stored supplier codes and refuses a supplier row, 422 on its `role`;
  - the import guide has no `cost_price` column, so a file naming it is refused for the column.
  A scan still resolves a supplier's carton and names only the scanned code and its role. Null rather than an absent
  property keeps one generated type; the web reads the permission, not the null, to hide the field.
- [2026-09-23 14:08] AGREED (developer): a real phone reaches the dev stack through an HTTPS entry on the LAN. A small
  Caddy proxy with its own local certificate runs beside `make up`. The pairing link is built on the machine's LAN
  address, which `make` detects and hands over as a setting, so the computer stays on localhost. The phone accepts
  the certificate once, or installs its root. Chosen over opening the computer on the LAN address too, a public
  tunnel, and two-browser testing only.
- [2026-09-23 15:50] TAKEN OVERNIGHT (standing instruction, to confirm): slice 5, the screen half of customer privacy.
  - The product form asks the cost only of someone holding `product.cost.read`, outside customer view.
  - The codes editor names a supplier only with that permission.
  - Customer view is a toggle in the top bar, offered to whoever may read costs (and to whoever has it on, so it can
    be turned off). It is remembered per tab in session storage, so a counter stays in it through a reload and
    another tab is not affected. A banner above the page says it is on, with a button to leave it.
  - What it hides is the company's choice, two presentation settings at the company level alone:
    `presentation.customer-view.cost` and `presentation.customer-view.supplier-codes`, both true until changed on the
    company settings page. That is a list of switches rather than a free list, so every field it names exists.
  - It hides on screen only. A save in customer view sends the stored cost and the stored supplier codes, so hiding
    never erases them. What the API sends at all stays `product.cost.read`'s.
  - Alternatives: a keyboard shortcut and a palette command for the toggle; a free list setting; hiding stock levels
    too.
- [2026-09-23 16:30] TAKEN OVERNIGHT (standing instruction, to confirm): slice 6, the price check. A screen under
  Products (`/products/price-check`, a third tab beside the catalogue and the categories, `product.read`) answers a scan,
  or a code typed in its field, with the product's name and what a customer pays for one unit, TAXES INCLUDED, and for
  the pack a pack code enters, with its use-by date when the code carries one; never the cost. The API counts that price
  (`CustomerPrice`, `unitPriceGross` and `priceGross` on the scan answer) with the calculator every document uses: the
  quantity on one line at the net price, with the product's default line taxes, the company's currency scale and VAT
  rounding; no document tax (a stamp) and no customer's regime: the shelf price, for anyone. The screen turns customer
  view on while open and, on leaving, puts it back as it found it: a counter that was already on stays on. A code no
  product answers to is said on the screen and as a refusal. On the invoice and delivery-note pages the phone's echo still names the net unit price; no
  palette command was added (the palette's module commands are "create" commands only). The customer display (a second
  screen facing the customer during a sale) is NOT built yet: planned as a same-browser page fed by a BroadcastChannel
  from the invoice and delivery-note pages.
- [2026-09-23 18:55] TAKEN OVERNIGHT (standing instruction, to confirm): slice 6, the customer display.
  `/customer-display` is a window of the same signed-in browser, outside the shell (no menu, no scan card), turned
  towards the customer and opened from "Ouvrir l'affichage client" in the "⋮" of an editable invoice or delivery note.
  The counter's tab speaks to it over a BroadcastChannel named for the company (`shared/customer-display/`), so nothing
  leaves the browser and a second browser or device never receives it. It shows the line the last scan went onto: the
  product, how many the line holds, and one's price TAXES INCLUDED as the price check counts it (the shelf price: a
  customer discount or regime on the document is not in it); on an invoice it adds the total once the sale is read as
  saved, and any new scan takes that total away until the next save, because the API alone works a draft's figures out
  and there is no preview of unsaved lines. A tab says nothing until one of its scans lands on a sale's lines, so
  browsing documents never shows a customer another's total; undoing a scan, leaving the sale, and closing or reloading
  the tab (`pagehide`) empty the display. A display opened mid-sale asks and is answered at once. Lines added by hand are
  not shown. The price check does not feed it. The first save of a new sale keeps what the display shows, though it opens
  the sale at its own address; another document on the same screen empties it. One display per counter is assumed: a
  second counter tab of the same company that leaves its sale empties the display too.
- [2026-09-23 19:40] TAKEN OVERNIGHT (standing instruction, to confirm): slice 7a, lots on the web — the tracking
  field. The product form asks "Suivi du stock" (sans suivi, par lot, par numéro de série) beside its kind, a new
  product starting untracked; a service is always sent untracked, whatever the field says, since the API refuses a
  tracked service. Once stock of the product has moved, the API keeps the tracking it moved under, and the form says so
  in its own words (`tracking_kept`) rather than as a generic refusal. The field stays offered after the first
  movement: which product has moved is the API's to know, and a greyed field would say "you may not" without saying why.
- [2026-09-23 20:05] TAKEN OVERNIGHT (standing instruction, to confirm): slice 7b, the stock levels by lot. The stock
  list gains two columns after the location, "Lot" and "À utiliser avant" (the lot's use-by day, as ISO like the
  other dates of the lists), empty for an untracked product; a tracked product has one row per location and lot, as the
  API answers it. No colour marks an expired lot yet: the list does not know whether a person released it.
- [2026-09-23 20:45] TAKEN OVERNIGHT (standing instruction, to confirm): slice 7c, a movement names its lot. The stock
  picker says how each product is tracked (`tracking` on `stock-options/products`). Choosing a tracked product in a
  receipt, a count or a move adds "Lot" (or "Numéro de série") to the form, required, as a scanner can read it back:
  printable ASCII without a space, at most 40; a receipt or a count also offers "À utiliser avant", optional, since
  either may be the first to meet the lot, while a move names a lot that exists and never says its date again. The form
  is rebuilt for the product chosen and keeps what was already typed. An untracked product's form is as it was.
- [2026-09-23 19:47] TAKEN OVERNIGHT (standing instruction, to confirm): slice 7c, a scan fills a movement. On the stock
  screen with a receipt, count or move open, a scan fills the product and, from a GS1 label, its lot — or its serial
  number, which is the lot of a product kept by serial — and its use-by day, with the pieces the code enters; the same
  product and lot scanned again counts on, as a till does, and another lot starts again from its own pieces. Undo puts
  the form back as it was. The product is asked of the picker by id, exactly; a search answers only a window of the
  catalogue and could miss it, so whether stock is kept of the product is the API's to say when the movement is saved
  (a refusal the screen shows as it shows any). With no movement open, the card takes the scan as before; a person who
  may not read the products leaves it to the card too.
- [2026-09-23 20:07] TAKEN OVERNIGHT (standing instruction, to confirm): slice 8a, count mode. "Comptage" is a tab of the
  stock screens (`/stock/count`, the inventory module, `stock.write` to count). It counts at the company's default
  location until another is chosen or its label is scanned — the address a location's label carries
  (`…/stock/locations/<id>`, slice 8b) or its code exactly as the store numbers it; a location's code is looked for
  before a product's. Each product scanned is a line, a GS1 label's lot or serial number making a line of its own; the
  same product and lot counts on, a pack its pieces, and "5×" five times. A line is corrected by hand or removed; a
  tracked product whose label named no lot asks it before recording. Recording writes one count per line, setting the
  location's stock of it to what was counted; nothing is written of what was not scanned, so a product missing from
  the shelf is not set to zero by this screen. A line the API refuses stays on the sheet, the others go. The sheet
  lives in the page: leaving it forgets what was not recorded.
- [2026-09-23 20:26] TAKEN OVERNIGHT (standing instruction, to confirm): slice 8b, location labels. "Imprimer les
  étiquettes" on the locations screen opens a sheet in a new tab (`/print/location-labels`, outside the shell, the
  inventory module): one label per location of the company, each with its code in large type, its path from the site
  down, and a QR code of `<this app's origin>/stock/locations/<id>`, black on white whatever the screen's scheme. That
  address opens count mode with the location already chosen, so a phone's camera goes straight to counting there, and
  a scanner reading it in count mode switches location (slice 8a). The browser prints the sheet (the button is left
  off the paper); no label size or printer format is chosen by the app, and no selection of locations: the sheet is
  all of them.
- [2026-09-23 21:57] TAKEN OVERNIGHT (standing instruction, to confirm): slice 8c, product labels. "Étiquettes" is a
  rare action of a saved product, opening a sheet in a new tab (`/print/product-labels/<id>`, outside the shell, the
  products module): as many copies as asked, 1 to 100, of one label carrying the product's name, its reference, the
  price a scan of that code pays taxes included (the price check's own figure), and the code as a barcode with the code
  in clear under it. The code printed is the product's unit code, else an internal one, else a pack code, never a
  supplier's; `?code=` prints another of its codes. The barcode is drawn in-house as SVG from the published symbologies
  (ISO/IEC 15420: EAN-13 for a valid 12- or 13-digit GTIN, EAN-8 for an 8-digit one; ISO/IEC 15417: Code 128, set C
  for an even run of digits, set B otherwise) rather than taken from a library: a few tables and a checksum, no
  dependency to license and record, and the e2e proves it by reading the bars back with the decoder the camera uses.
  A product without a usable code says so instead of printing; black on white whatever the scheme; the browser
  prints, and no label size is chosen by the app. (The three stamps above, 7c, 8a and 8b, first read 21:10, 21:55
  and 22:30, two hours ahead of the clock; corrected 21:57 to the time of the commit each landed in.)
- [2026-09-24 10:28] AGREED: every choice TAKEN OVERNIGHT on 2026-09-22 → 23 (rows 1–25 of
  `var/claude/overnight-questions.md`, the entries from 01:10 to 21:57 above) is CONFIRMED as written, but one: rows
  1–4 one by one, then the rest on the developer's "accept all recommendations, I will test and tell you what to
  change" — so any of them may still be reopened after that test. Row 1 (the scan lookup rather than a count on every
  picker row) is confirmed "for now"; row 3 is superseded by 10:42's `A` key; row 7 was already ruled at 08:05.
  **Row 6 is REVERSED:** words typed over a pick and abandoned no longer stay in the box. Leaving a pick field without
  choosing puts back the words of the pick the record holds, so the box never reads one thing while the line holds
  another; the list is then asked afresh with no words. Where nothing is picked the typed words stay (not ruled: the
  narrower reading of row 6, and a scanned code that matched nothing is left there to deal with). While the list is
  open the leaving may be a click on a row, so the words wait for the list to close. This is `PickField`, so it applies to every picker, not only a line's product.

- [2026-09-24 11:20] AGREED: the scale company and the cleanup. The overnight audit's records were deleted from the
  development database after a dump (the stray owner invitation, the audit member, seven scanner pairings, the probe
  product and customers, a cancelled credit-note draft, the two AUDIT-1011 companies and their owners, six pending
  sessions). Kept on purpose: the scratch company AUDIT-EXT-2026-09-23, the measurement baseline for SCL-01/SCL-02
  until those are re-measured (the operator therefore holds a fourth membership locally), and Demo's issued
  FAC-2026-00006, FAC-2026-00007 and BL-2026-00009, whose deletion would leave gaps in Demo's numbering.
- [2026-09-24 11:40] AGREED: new data before the reports (audit K § 5d). **(1)** an invoice line carries the cost of its
  product copied at issue (`unit_cost`, nullable, read only with `product.cost.read`; row 87's weighted average replaces
  the source later), shipped before any report (RPT-01); **(2)** a reorder point per product per establishment,
  nullable, empty meaning no alert; **(3)** a company-wide payments list, built on IN-A-01's receipt aggregate and not
  before it; **(4)** cheques and traites as an instrument with a due date and a state (IN-B-14), its own row;
  **(5)** Tunisia's withholding on suppliers is first sourced in `docs/fiscal/TN.md`, then recorded on an expense's
  payment with the preset's rate, overridable (RPT-09); **(6)** each country's VAT basis and declaration calendar is
  researched and cited now (RPT-03), and until then the home's tile reads « TVA collectée » and the report « TVA par
  taux », never « à déclarer » or « déclaration »; **(7)** composite date indexes on invoice, payment and expense land
  with the first report.
- [2026-09-24 11:55] AGREED: the home page's figures (audit K § 5a), after row 57. **Margin this month is the headline**
  (the 2026-09-20 03:55 ruling stands), read from the costs frozen on lines and blank with a note until they exist;
  « Facturé ce mois » — net of tax and of credit notes, month to date against the same days last month — sits under
  it, smaller; collected stays its own figure. Kept, with upgrades: outstanding (delta against the same day last
  month), overdue (its « voir » opens the overdue list), collected (month to date against last month, and today by
  method), VAT invoiced (moves to Declarations with row 91), to chase (with each customer's total due), the
  six-month payments columns (each bar labelled, the current month outlined, the table behind it). Added: withholding
  suffered this month (Tunisia), expenses recorded (never called profit), products with no sale in 90 days and lots
  expiring within 30 days, and cheques and traites due this week once (4) above ships. Every figure reads maintained
  totals, and a functional test caps the home's query count on a seeded company, so a figure that walks the documents
  is red in CI (SCL-01).
- [2026-09-24 12:05] AGREED: the Reports area (audit K § 5b): ten reports on row 89's engine, in this order — takings
  by day and method, aged balance, sales by product and family (margin columns blank until line costs exist), sales
  by customer with those gone quiet, VAT by rate (a report, not a declaration), withholding suffered, expenses by
  category and supplier, credit notes and discounts, stock movements and dead stock, activity by member (owners and
  admins only). Each has period, comparison, establishment and export, and every figure opens its list. A report can
  be saved as a named view with its filters, columns and period, personal or shared; a user-built query builder stays
  refused **for now** (custom reports may be reconsidered). A factual « dû à 30 jours » — invoices due to us against
  bills due to suppliers, from the documents — is shown on the aged balance and expenses reports; a modelled cash
  forecast, a break-even and anything called profit stay refused. Charts are bars, columns, lines and tables, a part
  of a whole is a stacked bar, every figure carries its comparison or its rate; no pies, gauges or 3D (for now: the
  developer decides after testing). No consolidated figures across companies; the company switcher may show each of
  the member's own companies' headline figures, each computed inside its own tenant boundary; the operator sees
  operational figures only.
- [2026-09-24 12:10] AGREED: insights (audit K § 5c). The ten insights of the audit each ship when what they read
  exists, pushed once to whoever holds the subject's permission, every threshold a company setting declared through
  `DeclaresSettings`. And one live screen, « À surveiller », lists the conditions true now — each with its figure and a
  link to its list, recomputed and not stored, emptying as things are dealt with; the home shows its count.
- [2026-09-24 12:40] AGREED: the vision review of the overnight rulings (`var/claude/vision-review-2026-09-24.md`, one
  read-only reviewer: 12 kept, 11 amended, 2 reopened) is ruled row by row. **Row 5:** invoice and delivery-note lines
  carry an optional lot or serial, a GS1 scan fills it, and validation takes the named lot, first-to-expire only for a
  line naming none — built first, since every tracked delivery until then is attributed to a lot nobody handed over.
  **Row 8:** lot tracking becomes an articles-chain setting (company, category, product) seeded by the trade and
  materialised onto the product's column, whose "no change after the first movement" guard stays; the field is
  « Traçabilité » (par lot, par numéro de série). **Row 13:** scan sound and vibration on or off per user (on by
  default); the camera's re-count delay a company setting (700 ms by default, in a declared range); the frame rate
  fixed. **Row 14:** a lent phone's pairing ends when the lending tab closes (a keepalive request on `pagehide`) and the
  phone is told « terminé » on any end or lapse; the claim window stays 5 minutes, amending the 10 minutes of
  2026-09-17 (a QR is scanned in seconds). **Row 16 (reopened):** customer view holds for the whole browser on that
  device, leaving it needs step-up (the method ruled with the step-up list), and the company chooses from a list what
  it hides — cost, margin, supplier codes, other customers' names and totals; `product.cost.read` stays the server's
  guard. **Row 17:** the price check restores customer view from a marker it writes, so a reload cannot leave it on;
  a checked item goes to the customer display; « Le lot de N » becomes « Le colis de N ». **Row 18:** the customer
  display shows the line's own unit price with tax and discount once the draft is saved, and before that the shelf
  price labelled « prix affiché ». **Row 20 (reopened):** stock levels say whether a lot is expired and released, the
  list marks an expired unreleased lot, and its row offers « Libérer » behind a dialog naming the consequence.
  **Row 21:** a serial product's movement quantity is 1, read-only. **Row 22:** a re-scanned serial is refused
  (« déjà sur la ligne »), the movement form stays open after saving, and a location label names a move's destination.
  **Row 23:** a count's unrecorded lines are unsaved work under the usual leave guard; a member without `stock.write`
  is told they may count but not record; counting is « Comptage » everywhere. **Row 24 (a one-way door, printed):** a
  location label's QR is the platform's public address (the LAN address in development) plus a short stable path by
  the location's id, its code printed in clear so our scanner resolves it without the URL; sign-in returns to the
  location; a company label format shared with product labels; the sheet prints the locations chosen. **Row 25:** a
  barcode's height scales with its width at ISO/IEC 15420's magnification, quiet zones kept, the code in clear on one
  line, and the e2e asserts the proportions as well as the decode. The scanner's key gap becomes a per-browser setting
  (30 ms by default) with a « tester mon scanner » step that measures a scan and suggests the value.
- [2026-09-24 12:55] AGREED: **what faces a customer shows the price with tax the document will charge**, else the
  shelf price with tax labelled as such — Tunisia's Law 2015-36 art. 29 (« Le prix affiché est le prix au comptant
  toutes taxes comprises et en monnaie nationale », read in the law's text) and France's arrêté of 3 December 1987
  (per the ministry's summary; the text itself not yet read). A company setting « Afficher aussi le prix HT », off by
  default, shows the price without tax beside it, never instead. A phone or tablet's scan shows the name, that price,
  the photo, the stock here and at the other locations, the nearest use-by of a lot-tracked product (marked expired or
  expiring), the draft customer's own price after discount when the lending tab's draft names one, and a pack's
  contents on a pack scan; cost and margin only outside customer view and with `product.cost.read`.
- [2026-09-24 13:10] AGREED: a **Zakat** module (research: `var/claude/zakat-research-2026-09-24.md`, 26 sources,
  AAOIFI Shari'ah Standard 35 quoted directly). Off by default; `zakat.read` / `zakat.write`, built-in owner and admin
  only, every computation audited, an optional owners' share table. On the company's hawl date it builds a dated
  worksheet — stock, receivables and unpaid expenses from the app, cash, bank, loans and other items entered by hand
  — tests the nisab, computes the amount and freezes it, printing the method, the nisab source and date and the Hijri
  calendar beside the figure with a disclaimer that it is a calculation aid, not a religious ruling; « À surveiller »
  reminds before the hawl date. The 18 points where the schools differ are company settings, filled in one step by
  five named bundles — AAOIFI, Maliki, Hanafi, Shafi'i, Hanbali — each shipping only once all its points are sourced
  (Shafi'i and Hanbali need a second research pass); Maliki is the Tunisian preset's default and AAOIFI the default
  elsewhere, and a changed point reads « <bundle>, modifié : … ». Stock is valued by a parameter whose default is the
  selling price without tax on the hawl date, cost being offered as a minority fallback; an expired lot counts at zero
  or at an entered value. There is no price history, so the worksheet is computed on or near the hawl date and frozen
  then; the Hijri date stays editable, since two calendars disagree by two days on the same day.
- [2026-09-24 22:51] AGREED: the round-5 design canvas (https://claude.ai/artifact/DNsLYS28bWR3MyrXGnMeFB, version 9,
  199 boards: every screen of today and the future ones of `var/claude/design-direction-2026-09-24/screen-inventory.md`,
  each beside the real app where a screenshot exists) is the direction for the five areas Vendre, Catalogue et stock,
  Achats et caisse, Paramètres and Accès; they are built in the inventory's Part C priority order (rows 121–126). The
  developer comments on the canvas and a comment amends a board, not this entry.
- [2026-09-24 22:51] AGREED: **at 1024 px the rail keeps a short label under each icon (80 px)** and a record opens as a
  sheet over its list, so the list keeps its columns; the rail's Vendre group gains « Caisse » and « Travaux ».
  « Mon compte » is one page with four tabs, Sécurité, Préférences, Cet appareil and Notifications, which absorbs the
  device page.
- [2026-09-24 22:51] AGREED: **every behaviour has a default and is configurable**, keyboard shortcuts first: C opens
  the Créer menu, N a new document on its list, E the next step (Émettre, Encaisser), / or Ctrl K the search; each
  person changes or restores them in Mon compte › Préférences, and a single-letter key never fires inside a field.
- [2026-09-24 22:51] AGREED: **the paid stamp** is computed from the recorded payments, never set by hand, so deleting a
  payment removes it; it is printed only on the up-to-date copy (« COPIE — état au … »), never on the original as
  issued; three wordings, « Acquittée » (paid in money), « Réglée partiellement » (with the balance and its date) and
  « Soldée » (a credit note closed it); one template option, **off by default**, since a company may stamp digitally or
  by hand.
- [2026-09-24 22:51] AGREED: **a signature zone and a cachet image on invoices are postponed**, together with the
  electronic signature of PDF files, which is future work to explore (row 126). A picture of a stamp is not an
  electronic signature and nothing may claim it is.
- [2026-09-24 22:51] AGREED: the amount in words (« Arrêtée la présente facture à la somme de … ») and a « Comment
  payer » block (RIB and the reference to quote) are two template switches, on by default on invoices, available on
  quotes, off on delivery notes: the amount in words is custom, not a requirement found in a text, so it stays a
  switch.
- [2026-09-24 22:51] AGREED: **a credit note always states its reason and the number and date of the invoice it
  corrects** (EN 16931 BG-3); the reference comes from the invoice, the reason is required when the credit note is
  created.
- [2026-09-24 22:51] AGREED: **signature boxes on other documents now**: the delivery note's « Réception » box with
  réserves, on by default; the quote's « Bon pour accord », with « Marquer accepté » asking for the signed scan to
  attach, so the paper comes back into the app; the supplier order prints who approved it and when, plus a box.
  Capturing réserves or a signature in the app waits for the electronic-signature work.
- [2026-09-24 22:51] AGREED: a reprint after the first carries « DUPLICATA — réimprimé le jj/mm/aaaa » and the
  up-to-date copy « COPIE — état au … »; « ANNULÉE » on an issued invoice (a credit note corrects it, numbering stays
  continuous) and a PROFORMA document (the quote does its job) stay refused.
- [2026-09-25 02:47] PROVISIONAL — to confirm (overnight, audit MSG-03): **a format error states the rule its field's hint
  states**. Material hides a field's hint while its error shows, so « Cette valeur n’a pas le format attendu. » was all
  that remained; the error now reads the generic sentence followed by the field's own hint (« … Lettres, chiffres et . _
  / -, 32 caractères au plus. »). Chosen over the audit's per-validator message keys because it needs no new wording
  and covers every hinted field at once. The 19 patterned form fields that declare no hint (the preset's identifiers, IBAN,
  BIC, the numbering format among them) still show the generic sentence: each needs its rule written, which is wording
  for you to rule.
- [2026-09-25 03:13] PROVISIONAL — to confirm (overnight, row 63 slice 10): **the lot and serial recall search is a
  filter of the movements list, not a screen of its own.** « Mouvements de stock » gains a « Lot ou numéro de série »
  field and a Lot column; Entrée, or a GS1 label scanned on that page, opens `/stock/movements?lot=<code>`, every
  movement of that code across the company's products, matched whole and whatever its case, with its location, date and
  the kind of document that moved it (the Origine column). It does NOT yet name which delivery note or which customer:
  that is a read across the Inventory and delivery-notes modules, left for you to rule (a note number and customer
  column, or a link per row). Two limits, by design until row 108: a delivery note's lot is the
  one FEFO picked, not the one handed over, and an invoice with no delivery note moves no stock, so leaves no trace.
  Slice 9 (QR codes on our own documents) was skipped: printed output whose content, placement and documents are not
  ruled.
- [2026-09-25 03:54] PROVISIONAL — to confirm (overnight, row 121 first part): **a credit note's reason is asked in a
  dialog when it is drafted and kept as typed, trimmed, 500 characters at most; it does not change afterwards.** The
  ruling of 2026-09-24 22:51 says the reason is required when the credit note is created and says nothing about editing
  it, so a mistyped reason is fixed by cancelling the draft and drafting again. The credit note prints « Avoir sur la
  facture FAC-… du jj/mm/aaaa » (the number was printed before; the date is new) and, below it, « Motif : … » (English
  « Credit note for invoice … of … », « Reason: … »): the wording is mine. The invoice screen shows the same line under
  the link to the corrected invoice. A credit note drafted before this carries no reason and prints none. Skipped from
  row 121, each for a ruling it needs: the paid stamp and the COPIE and DUPLICATA marks (the issued PDF is stored and
  served byte for byte, so a mark needs a second output, and what counts as the first print is not ruled); « Comment
  payer » (the company has no bank account field yet); the amount in words (the number spelling and the TND millimes
  are printed money wording).
- [2026-09-25 04:10] PROVISIONAL — to confirm (overnight, row 122 first part): **the delivery note ends with a « Réception »
  block** in place of the dashed « Reçu par le client » strip: three cells 30 mm tall, « Date et heure », « Nom »,
  « Signature et cachet », and a « Réserves » line under them (design research R10), kept whole on one page. It is the
  setting « Imprimer le bloc de réception », on by default, in the parties chain like « Afficher les prix », so a
  customer group, a customer or one note may leave it out. Not done, for rulings or for work that does not exist yet:
  anchoring the block to the foot of the last page, « Préparé par », vehicle and driver; the quote's « Bon pour accord »
  and the supplier order's approver (neither document exists yet); capturing réserves in the app (waits for the
  electronic-signature work, row 126).
- [2026-09-25 04:32] PROVISIONAL — to confirm (overnight, row 109): **an issued line's `unit_cost` is its product's cost as it
  stood at issue, and only for a line in the product's own unit**; a line in another unit (a kilogram of a product sold
  by the piece) freezes nothing, since no conversion between the two is known, and neither does a line with no product
  or whose product has no cost. A credit note freezes its lines' costs when it is issued, as an invoice does: the
  product's cost THEN, not what its invoice froze, so a margin subtracting what was credited is exact only when the cost
  did not move in between. Copying the invoice line's frozen cost instead is the other choice, yours to rule. The API answers it on each line of a read invoice, a listed one and the answer to issuing, null for a
  caller without product.cost.read; no screen shows it yet (the margin reports and the home's headline will read it).
  Lines issued before this carry none, which the margin figures must read as unknown, never as zero.
- [2026-09-25 05:18] PROVISIONAL — to confirm (overnight, row 108 first half, the delivery note): **a delivery-note line
  of a product tracked by lot or serial may name the one handed over** (`lot_code`, up to 40 visible ASCII characters,
  stored as typed and trimmed), and a line of an untracked product, or with no product, is refused one. At validation a
  line naming its lot takes exactly that lot at the note's location, matched exactly first and then ignoring case; the
  lines naming none then take the first to expire from what is left, as before. A named lot that is expired and not
  released, or that is not at that location, moves NO stock for that line, and the note says so in its stock message
  rather than silently taking another lot: the paper says which lot left, so the stock must not contradict it. A GS1
  scan fills the line's lot (the serial for a serial-tracked product, the lot for a lot-tracked one, the other when the
  label carries only that one), and another lot of the same product starts its own line. Scanning the same serial twice
  still counts on to 2 here; refusing it is row 116's. The product pickers now answer each product's tracking. Not done
  yet: a lot scanned INTO a line's product field (only its piece count is read there), and invoice lines (second half).
- [2026-09-25 05:53] PROVISIONAL — to confirm (overnight, row 108 second half, the invoice): **an invoice line of a
  product tracked by lot or serial may name the one sold**, under the same rule and shape as a delivery note line
  (`Products\Domain\LotCode`, now the one rule stock lots, delivery notes and invoices share), a record only: an invoice
  moves no stock. An invoice drafted from delivery notes carries each note line's lot; a credit note carries its
  invoice's, since it corrects those very pieces; a DUPLICATED invoice names none, being a new sale of pieces not chosen
  yet. A copy whose product is no longer tracked drops the lot rather than refusing the copy. A GS1 scan fills the line
  as on a delivery note. Neither document PRINTS the lot yet: what a printed line shows is a one-way door, left for you
  to rule (the natural place is under the line's description, « Lot : … » / « N° de série : … »).
- [2026-09-25 06:34] PROVISIONAL — to confirm (overnight, row 110): **a product keeps a reorder point per
  establishment** (`product_reorder_point`: product, establishment, quantity DECIMAL(14,3), unique per pair), a quantity
  from 0 in the product's unit and never finer than it counts; zero is a real point, reordering once none is left; no
  row means no alert. Read with product.read and set with product.write, as a home is, and audited
  (`product_reorder_point.set` / `.cleared`, keyed on the product). The list answers EVERY establishment of the company,
  null where none is set, so the screen needs no read of the establishments a product editor may not hold. On screen it
  sits in the product's « Où il est rangé » tab, under the homes, one field per establishment. The product import gains
  a `reorder_point` column, offered with `home_location` (inventory on): the point is kept in the establishment of the
  row's home location, else the company's only establishment; a company with several and a row naming no home rejects
  the row (`ambiguous_establishment`) rather than guessing a building; a blank cell keeps what is there. The alert that
  reads it is row 115's; nothing reads it yet.
- [2026-09-25 07:18] PROVISIONAL — to confirm (overnight, row 111): **paying a supplier withholds tax at source, the
  rate proposed and overridable per payment** (`docs/fiscal/TN.md` § 5a, sources 15 and 16). An expense's payment
  keeps `withholdingRate` (DECIMAL(6,3), 0 to 100, three decimals) and `withholdingAmount` (DECIMAL(14,3), the gross
  including VAT times the rate, rounded to the currency's scale); what the supplier receives is `amountPaid`, the gross
  less that. Nothing withheld keeps both null, so an expense paid before this reads as it did. The proposal
  (`suggestedWithholdingRate`, read-only, on a recorded expense only) is the rate of the company's ONE active
  whole-amount withholding component (the TN preset's RS1, 1 %, the general rule since LF 2021 art. 14) when the
  payment's gross reaches that component's threshold (1 000 TND, VAT included, per payment); none, or several such
  components, proposes nothing rather than guessing. The pay dialog prefills it; sending no rate takes the proposal,
  "0" withholds nothing, any other rate is kept as said (the 0.5 % and 1.5 % cases depend on the supplier's own regime,
  which the company knows and the software does not). Not modelled: the natures excluded from withholding (utilities,
  insurance, leasing, price-controlled goods, agriculture) and the per-nature rates (fees, rents); the company overrides
  the rate on such a payment. The certificate handed to the supplier is printed output and stays with row 91.
- [2026-09-25 07:42] PROVISIONAL — to confirm (overnight, row 112): **each country's VAT basis and declaration
  calendar is sourced** in `docs/fiscal/TN.md` § 2a and `docs/fiscal/FR.md` § 2a (primary texts: Code de la TVA art. 5
  and 18, CGI art. 269 and 287, BOFiP, impots.gouv.fr). In both, goods are taxed on delivery; services on performance or
  earlier receipt in Tunisia, and on receipt (« encaissements ») in France unless the company opted for the débits.
  Tunisia declares monthly (by the 15th for a natural person, the 28th for a legal one); France files a monthly CA3
  (quarterly under 4,000 € a year, due the 15th to the 24th) or, under the simplifié, an annual CA12 with July and
  December instalments. What the home shows stays the VAT of the invoices issued in the month, so the tile now reads
  « TVA collectée · mois » (en « VAT collected · month », the wording that is provisional), and a translation test
  keeps « TVA à déclarer », « déclaration de TVA » and their English out of every message; the three « Déclarer » hits
  that remain are « Déclarer un règlement », a payment. The VAT due on receipts for French services is not computed:
  that belongs to the declaration work, row 91.
- [2026-09-25 08:31] PROVISIONAL — to confirm (overnight, row 115): **« À surveiller » ships as a live list, and pushing
  an insight once waits for its own row (127)**, because the repo has no scheduler and a push needs one (a Symfony
  Scheduler worker in compose and a table of what was already pushed). A module declares its conditions with
  `DeclaresWatch` (the `DeclaresImport` shape) in a new `Watch` context; `GET /companies/{c}/watch` answers `count`
  and `items` (kind, subject, figures) to any member holding `company.read`, each condition shown only while its module
  is on and to a role granting its subject's permission (`invoice.read`, `stock.read`), worked out on every read in one
  SQL statement per kind and never stored. Five of the audit's ten ship now: a customer with invoices late past
  `watch.late_after_days` (30, overdue as the invoices list means it, the link opening that list searched by the
  customer's name); goods not sold for `watch.unsold_after_days` (90, one count, products younger than that and
  services left out); a product at or under its reorder point in an establishment (row 110); one whose stock at the
  last 30 days' pace of delivery lasts under `watch.lead_days` (7, the pace read from the movements rather than a
  nightly projection until row 56's worker); and a dated lot on hand within `watch.lot_expiry_days` (30) of its date or
  past it, unless released. The thresholds are company settings (the invoices' under Clients et documents and
  Produits, the stock's under Produits). Left out, each for its reason: the cheques due (IN-B-14), the VAT to declare
  (row 91, and never worded « à déclarer » before it), the evening digest (row 90), the month against last month (no
  comparison rule, RPT-07), a credit limit passed (row 85) and the projection check (row 57). On screen the page is
  `/watch`, the home shows its count first, above the invoices' panel, and both read again on a live change.
- [2026-09-25 09:03] AGREED: **the order of work to the first version.** Bugs first, always: a reported defect jumps the queue.
  Then the approved design (`var/claude/design-direction-2026-09-24/`, `direction.md` and `approved/`), screen by
  screen: (1) tokens and the shell — the rail with « Créer » (C), search, the groups Vendre / Gérer, the company
  switcher at its top and the member at its bottom, a slimmer top bar, « Mon compte » (rows 123, 124); (2) the home as
  « Accueil »; (3) the invoice list as « Factures », status chips with counts and the record as a side sheet over the
  list; (4) the same pattern for delivery notes, expenses, customers, products and stock; (5) « À surveiller » as
  designed; (6) the phone screens; (7) the settings screens; (8) the rest of rows 121, 122 and 125. After the first
  version: rows 116–119, then 56, 57 and 127, reports (89, 114), declarations (91), quotes (78), the counter sale (82)
  and purchases (81). Each slice is built, pushed, the stack rebuilt, and the developer told what to test.
- [2026-09-25 09:03] AGREED: **an element a mockup shows with no data behind it yet is left out, never faked**, and named in the
  § 8 row that builds its data, which is done only once the element shows on its screen as designed.
- [2026-09-25 09:03] AGREED: **« Société à l'ouverture », a personal preference in Mon compte › Préférences**: off by default,
  meaning a sign-in reopens the company the person last worked in; on, it always opens the company pinned. A company
  the person no longer belongs to falls back to the last used, then to the only one; with one company nothing changes.
  A sign-in never lands on « aucune entreprise » while the person belongs to one.
- [2026-09-25 10:13] AGREED: **a scan on a product page offers the code to that product.** A code no product holds opens
  the scan card with « Ajouter à <référence> » first, which opens the product's Codes-barres tab with the code listed and
  nothing saved until « Enregistrer », so a carton's quantity can be set; a paired phone's card offers the same. A code
  another product holds is never moved: the card names its holder and offers to open it.
- [2026-09-25 11:17] AGREED: **the whole vision shows, marked, and can be hidden.** Every screen of the full vision (the
  screen inventory, beyond version 1 too) has its entry in the rail and the settings menu; one not built yet carries a
  « Bientôt » chip and opens one shared « En construction » page naming what it will do, its § 8 row and « Version 1 » or
  « Plus tard ». A personal preference in Mon compte › Préférences, « Montrer ce qui arrive », hides them all; it is on
  by default until release. Inside a built screen, a mockup card with no data yet shows as a greyed « En construction »
  tile where it will sit, named in the § 8 row that builds its data. This amends the 09:03 ruling, which left them out.
- [2026-09-25 11:23] AGREED: **every screen of the vision is mocked before more is built.** Building pauses (defects still
  first) while every screen and feature of the vision with no approved mockup or ruling is drawn on the design canvas,
  in batches by area, each board with the decisions it needs; the developer validates or comments each batch, and once
  all are validated the rest is built autonomously in the 09:03 order.
- [2026-09-25 11:54] AGREED: **the mockups are not complete yet: the ideas are walked first, then a batch 3 is drawn,
  then everything is validated.** Every screen of the inventory (B1–B90) has a board (round 5 or round 6), but these
  are not drawn yet: the flows (quote → order → delivery → invoice → payment; purchase order → receipt → supplier
  bill), the phone versions of the « Bientôt » pages and the café, Arabic beyond two screens, the new shell in dark
  mode and at 1024 px, café reservation tiers 1 and 3, and the opening hours. The idea pools the developer has never
  ruled on are walked with them first: the 280 harvested ideas (`var/claude/ideas.json`, graded by Claude only), the
  deep-pass lists (`var/claude/deep-{products,headers,lines,parties}.md`), and the rest of the hardcoded walkthrough
  (item 16 and the batch of ten). Batch 3 then draws the gaps and every idea ruled in, and flags Tâches (B73) and
  « Déclarations » under Gérer for a ruling.
- [2026-09-25 12:45] AGREED: **the rest of the hardcoded walkthrough (item 16 and the batch of ten), shown one by one with
  what each is and its risk.** Stay fixed in code: a document is issued with at least one line; recovery codes come
  in sets of ten; the realtime token's lifetime stays equal to Centrifugo's; the attachment types stay PDF, PNG, JPEG
  and WebP; a subscription runs at most 1200 billing periods and one declaration covers at most 60; each list keeps
  its default sort (a person's own sort is already remembered); rates print with no forced decimals (not a defect).
  Changed: **(2) a paid invoice can be credited**: a credit note takes up to what the invoice invoiced less its earlier
  credit notes, and the part beyond what is still due goes, at the user's choice, to a refund payment or to the
  customer's credit balance (the 2026-09-21 17:35 pattern; the cap stays for everything else, and nothing is ever
  credited with no trace of where the money went). **(3) partial invoicing of a delivery note**: each line keeps
  its quantity left to invoice, an invoice takes all or part of it, and the note turns invoiced only once nothing is
  left, so a unit is never billed twice. **(10) a date and number format of one's own**: a presentation setting
  (person, then company) that defaults to the language and country's own; a format chosen there overrides it
  everywhere, screens and printed documents alike. Item 16, a defect, is fixed: the subscription page wrote its
  dates with Angular's `mediumDate` in the browser's time zone (a subscription covered to the end of 18 August
  read "Aug 19, 2026") and its amounts as the API sent them; it now goes through the shared formatter, and the
  last day covered is read in the company's time zone. The 2026-09-21 17:35 credit balance and write-off had no
  § 8 row; row 128 carries them with (2).
- [2026-09-25 15:53] AGREED: **a café has several espaces, waiters are given zones, and a table QR stays account-free
  for now** (developer, after the round-6 board review). **(1) Espaces.** One café at one address can have a
  terrace, a non-smoking room, an events room: an **espace** is a named zone drawn on a floor (`venue_area` stays
  one plan per floor per establishment; an upper floor is a floor holding its own espaces), and each table belongs
  to exactly one. An espace carries **smoking or non-smoking**, can be **closed** (the terrace in winter: its tables
  leave the floor and their QR says so), and is what the price lists (2026-09-20 02:30) and the menu hours are
  keyed to; the room screen shows one tab per espace plus « Tout ». Booking a **whole espace** for an event joins
  the staff-side booking tier (2026-09-20 19:40, tier 2), built later with it. A second café at another address is
  a second **establishment**, not an espace. **(2) Waiter zones.** Per shift a waiter is given espaces and/or
  single tables; a table's own assignment wins over its espace's; zones may be shared. A QR order goes to the
  table's waiter; a table with none is offered to every waiter on shift, and the first to accept owns it for the
  service (2026-09-20 02:05's acceptance rule unchanged). The plan carries over from shift to shift until someone
  changes it; no weekday templates. **(3) « A client with an account »** at a table means a portal contact
  (2026-09-20 19:20): later, a signed-in contact may put the table's order on the company account, still accepted
  by the waiter. Guests stay device sessions; personal guest accounts are refused (a third kind of principal for
  no customer who asked). The review also found the café drawn one state board per module, never its flows: the
  guest's ordering path, the waiter's acceptance, moving and joining tabs, courses, the locked and suspended table,
  a station's rupture reaching every phone, the untouched-ticket alarm, the portal's standing order, station
  routing, the QR sheet, drawing the tables and the Guest settings are drawn in batch 4.
- [2026-09-25 16:51] AGREED: **the round-6 designs and batch 4 are validated, and the 237 open ideas are ruled** (developer:
  « I validate overall, let's implement — I would rather test when it's implemented than keep going over a design »).
  The canvas `DMiBgmXZ6QhFG2WkhWEeTo` version 7 is the design of record: rounds 1–3 plus the café flows of batch 4
  (17 boards, drawn against every café ruling of 2026-09-20 and 2026-09-25 15:53). The ideas page
  `BEKRVHDmLSY9bEEpKJhqYy` is settled as the developer chose at 16:10: its nine principles approved, every unmarked idea
  taking its recommendation, his marks and notes winning. The result, one line per idea, is
  `docs/spec/ideas-ruled-2026-09-25.md`: 115 yes, 92 yes with a change, 30 no. Three of his notes asked for a
  recommendation and were ruled on it. **Numbering (DOC-45):** a draft takes no number; the next number of a series is
  set freely until that series first issues, then locked; an issued number is never stepped back or reused, and a
  mistake is corrected by a credit note — no administrator reset, because a gap or a reuse is what an audit reads as
  fraud. **What a line keeps (DP-66):** an issued line keeps the product's name and reference for ever; a company
  setting « Un brouillon suit les changements de l'article », on by default, is the only choice, since what an issued
  document says is law, not preference. **Provenance (DP-49):** one typed « issu de » link between documents replaces
  the separate columns, migrated before the first customer while nothing is issued for real; the setting is which steps
  a company requires (« une facture doit venir d'un bon »), not the link itself, which is data. The five groups marked
  « avant le premier client » become rows 131–135 and are built before the first version ships; every other yes is
  built with the screen or row it touches.
- [2026-09-25 17:22] DECIDED (revisit): **the rail of step 1, as built where the boards say nothing** (developer: « keep
  implementing; make a recommended decision and note it »). (1) The **scanning controls** (camera, phone, customer view
  and the typed count) are on no round-6 board; from a tablet up they keep a slim bar above the page, shown only when
  one of them applies, rather than a « Scanner ▾ » menu, whose merge would rewrite three scanning e2e specs for no
  ruled gain — the direction's rule 10 (menu · company · search · Scanner · bell · avatar) is superseded by the boards
  for everything else. (2) **Language, scheme and density** move into the member's menu at every width, as on a phone
  (direction rule 10). (3) The settings area keeps its own fold preference but now **opens labelled**, as the round-6
  settings board draws it; `presentation.sidebar-settings` defaults to `expanded`. (4) The **phone's bottom bar** is
  Accueil · Factures · Créer · Clients · Plus, taking the sidebar's next destinations when a module is off, « Créer »
  always third. (5) **C** opens « Créer » and is reserved from every screen; the fold control moves from the top bar to
  the rail's foot, above the member. (6) The notification centre slides in from the side it was opened from: the
  rail's start from a tablet up, the end on a phone.
- [2026-09-25 19:01] DECIDED (revisit): **« Bientôt » and « En construction », as built** (step 1, slice 2a; same
  mandate). (1) Nine entries of the round-6 rail and settings boards are not built and show, each marked « Bientôt »,
  after the entry the board puts them behind: Caisse and Travaux in Vendre, Rapports and Déclarations in Gérer,
  Modèles de documents and Alertes in Société, Préréglage fiscal, Accès du support, Textes. They are `COMING_NAV` in
  `web/src/app/shell/nav-manifest.ts`; an entry leaves that list in the change that builds it. (2) **Versions**:
  Caisse (§ 8 row 82), Travaux (80), Rapports (89, 114), Déclarations (91), Modèles de documents (B45, no row yet) and
  Alertes (115) are « Version 1 »; Préréglage fiscal, Accès du support and Textes are « Plus tard », with no plan row
  yet. (3) One shared page, `/coming/<key>` or `/company/coming/<key>` beside the settings list, says what the entry
  will do, the version, the row and, when something does the job today, a link to it. (4) An entry not built yet
  joins only a **section the person already has**: somebody who sells nothing is not shown the till; a settings one
  carries `company.settings`. (5) They stay **off the phone's bottom bar and out of the Ctrl K palette**, which hold
  only what works. (6) « Montrer ce qui arrive » is `presentation.show-coming`, a boolean on the presentation chain at
  company, role and user level, on by default; the page's « Masquer ce qui arrive » sets it for the person, and Mon
  compte › Préférences will carry the switch. (7) The **brand mark** row 99 put in the sidebar is gone from the rail:
  the round-6 board opens the rail on the company, not on twes-in, and the board wins. (8) **Achats** (vendors and
  expenses as one entry) is deferred to the list-pattern step, where both lists are rebuilt anyway; the rail keeps
  Fournisseurs and Dépenses side by side until then.
- [2026-09-25 19:34] DECIDED (revisit): **« Mon compte », as built** (step 1, slice 2b; same mandate). `/account`, first item
  of the member's menu, with the round-6 board's four tabs, the tab in the address (`?tab=`). (1) **Sécurité** says
  whether the two-step check is on and leads to `/two-factor`, which it does not absorb: that page is also the
  sign-in's enrolment step, outside the shell. (2) **Préférences**: Langue, Thème and Densité as selects (the member's
  menu keeps its quick choices), « Montrer ce qui arrive », and « Société à l'ouverture » for somebody in several
  companies only — switching it on pins the company worked in now, a select then offers the others, switching it
  off opens the last one used; Raccourcis clavier is « Bientôt » (row 125). (3) **Cet appareil** and
  **Notifications** are « Bientôt » tabs, saying they arrive in a coming version.
- [2026-09-25 20:04] DECIDED (revisit): **the tokens of design direction § 1, first slice** (step 1, slice 3; same mandate).
  (1) The accent **as picked** gets its roles, computed in `accentTokens` (`shared/theme/accent-theme.ts`) and exposed
  to Tailwind as `accent`, `on-accent`, `accent-soft`, `accent-text`: it fills the primary button, marks the rail's
  selected row, and softly fills a selected settings row. `on-accent` is white when white reads, else the board's
  ink, else black — the direction's « #fff, else #0d0f14 » alone leaves mid-tone accents below 4.5:1 (#787878 gave
  4.34). `accent-text` starts from the direction's mix and moves toward black (light) or white (dark) until it reads
  at 4.5:1 on the scheme's surface; links still use Material's `primary` until each screen is rebuilt. (2) The
  **lifecycle map** `shared/theme/lifecycle-tones.ts`: invoices, delivery notes and expenses place their statuses on
  stages and their tones derive from it; a withdrawn status is grey and struck, so **a cancelled delivery note turns
  from red to grey**. (3) The **type scale, radii and s3** are declared (`text-title`, `rounded-card`, `shadow-s3`…),
  not yet applied: screens move onto them as each is rebuilt in the 09:03 order, and the gate refusing a raw
  `text-{size}` comes once they have. Tailwind's own `rounded-sm…xl` keep their sizes until then. The s3 shadow is
  derived from s2, as no approved value was found in the saved canvas. (4) The design-tokens gate reads the accent
  roles as colour tokens, so an undeclared one (`bg-accent-strong`) is refused.
- [2026-09-25 21:24] DECIDED (revisit): **the 80 px rail keeps a short label under each icon** (row 123, first half; same
  mandate), wherever the rail is folded — the medium window, or a wide one folded by choice — rather than only at
  1024 px, so a folded rail reads the same everywhere. A label too long for it declares a short one
  (`shortLabelKey`: « Livraisons » for « Bons de livraison »); an entry not built yet shows a hollow dot, its
  « Bientôt » kept for a screen reader. The foot (Notifications, Paramètres, the member) stays icons with tooltips.
  Measured in a real browser at 1024 and 1400 px, which found what the unit suite could not: the folded rail's
  scrollbar took 15 px of 80 (labels cut to « Ac… »), the `mat-sidenav` itself is what scrolls, a `@if` branch with
  two roots dropped the title into Material's default slot (rows 77–95 px tall), the « Bientôt » chip squeezed its
  label, and the search's « Ctrl K » wrapped because `font: … inherit` is invalid shorthand. The rail's row titles are
  now the direction's body size (14 px), and « Créer » takes the accent as picked.
- [2026-09-25 22:17] AGREED: **work runs as two builders and one integrator.** Measured the same evening: the machine sits at load
  24–31 on 8 cores with swap full before anything of ours runs; one spec file took 55 s alone at load 11, 149 s at load
  26, 213–238 s with two at once, and three at once timed out; one extra builder costs a worktree, `npm ci` (23 s),
  `composer install` (179 s), the generated API types copied in, and 577 MB. So at most **two builder agents** work at
  once, each on a slice whose files do not overlap the other's, in a git worktree on a **local branch that is never
  pushed** — the one exception to « master is the only branch », since nothing but master ever reaches the remote —
  running only the specs of what they touch. **Claude alone integrates**: merges into master, runs the whole suite and
  the gates, writes this log and § 8, and pushes. The e2e tests are split across parallel CI jobs, which costs this
  machine nothing.
- [2026-09-25 22:17] AGREED: **a practice company, and guides declared as data** (the guide of 2026-09-23, never drawn nor given a
  row until now). (1) Every consequential action states its **effect** before it runs and its **kind**: *annulable*
  (undone from its toast), *corrigeable* (not undone; corrected by a counter-document, such as a credit note) or
  *définitif*; the same words on its button, its confirmation and its toast, the irreversible ones kept apart
  (NAV-38). (2) The **dry run is a practice company** on the real engine, flagged `training`, watermarked on every
  screen and document, excluded from exports and declarations, reset in one click, seeded from the demo data —
  never a simulation in the browser, which would duplicate every rule and could teach effects the app does not have.
  France's BOFiP § 150 allows a recorded, marked training mode; Tunisia's décret 2019-1126 wants training operations
  typed « opération de formation ». (3) **Premiers pas** on the home page and contextual suggestions. (4) **Tours**,
  a help drawer per screen and a glossary, declared by each module as data beside its navigation, stepping on stable
  `data-tour` anchors of the shared components; a check turns CI red when a step's anchor is gone, so a screen that
  changes cannot silently break its tour, and the tour screenshots are regenerated by the e2e tests. Order: the
  effect model and the anchors before the Factures list; Premiers pas with the home page; the practice company with
  the fiscal journal (row 103); the tours once the version 1 screens settle.
- [2026-09-25 22:17] AGREED: **the till, France first and Tunisia started now** (`docs/research/till-hardware.md`,
  `till-certification.md`). France: the till runs in **Chrome or Edge**; a network Epson printer driven by ePOS-Print XML
  from the page (no vendor SDK), the cash drawer on the printer's port opened when cash is taken or change given, the
  customer display the existing second window; card payments later through a cloud provider's API called by the
  server; the **editor's attestation** (CGI 286 I 3° bis, restored 21 February 2026) once the fiscal journal (row 103)
  exists, its wording read by a lawyer; NF525 optional. Refused on licence: QZ Tray (LGPL-2.1) and Star's SDKs
  (proprietary). **Tunisia**: the register obligation covers only on-site consumption (cafés, restaurants, salons de
  thé), for every legal entity since 1 July 2026, through a Ministry-homologated register sold by an accredited
  supplier with a fiscal module transmitting permanently; **no till is sold to a Tunisian café until that is solved**,
  and the path starts now: a Tunisian lawyer or expert-comptable, the cahier des charges on homologation.nacef.tn read
  from Tunisia, and an accredited supplier as partner or twes-in accredited itself. Retail shops have no register
  obligation. This is the developer's task; Claude prepares the questions.
- [2026-09-25 22:17] AGREED: **tax data in two parallel tracks** (`docs/research/tax-data-france.md`, `tax-data-tunisia.md`).
  Track one, needing no partner: Tunisia's withholding certificates as the TEJ XML (every taxpayer since 1 January
  2026), France's four new invoice mentions, payments split by VAT rate, the CA3 and Tunisian monthly worksheets.
  Track two, the e-invoicing files and connectors: Factur-X and UBL (France), TEIF 1.8.8 signed (Tunisia), then a
  plateforme agréée with a public API live before 1 September 2027, and TTN with TunTrust or DigiGo certificates
  (row 102). Out of reach: becoming a plateforme agréée, EDI filing of VAT returns, a full FEC.
- [2026-09-25 22:34] DECIDED (revisit): **e2e runs in three CI shards** (row 136). Each shard is its own runner with
  its own compose stack, seed and database, so scenarios stay serial within a shard and never meet another shard's
  rows; the operator's sign-in setup runs in each. Three, not more: every shard rebuilds both images, and three
  already cut the 25-minute job to about ten. If a scenario turns out to depend on another file's rows, the fix is in
  the scenario, not in going back to one runner.
- [2026-09-25 22:34] DECIDED (revisit): **the TEJ schema is not vendored.** The official XSD zip (jibaya.tn, dated
  2026-09-15) is kept outside the tree (`var/claude/tej/`) until its licence is read: government-published, but no
  licence text ships with it. It is also malformed: `TEJISOPaysDevises.xsd` line 1580 closes an `xs:enumeration`
  twice, so nothing can load the schema as published; local validation uses a copy without that line. The operation
  code of a withholding (RS7_000001…) is chosen by the person when the payment is recorded, never inferred from the
  rate: the rate alone does not tell a supplier at the 15 % corporate rate from one exempt of withholding.
- [2026-09-25 23:13] DECIDED (revisit): **the kinds of the actions there are today** (row 137). Issuing an invoice
  and validating a delivery note are *corrigeable* (a credit note; cancelling the note); issuing a credit note,
  cancelling an invoice or a note, and deleting a customer group, a contact, a stock location or a product category
  are *définitif*; removing a member is *corrigeable* (invited again). Nothing is *annulable* yet: no bin exists in
  the API, and a scan's « Annuler » toast predates the model. The kind is REQUIRED on every confirmation, so a new one
  cannot be written without choosing; a toast after the action reads the kind from the screen's own declaration,
  never from a second literal. In the « ⋮ » menu what is final comes last under a « Définitif » heading; on a
  visible button the kind is not drawn — a word on the primary button of every draft read as noise — and is said
  by its confirmation instead.
- [2026-09-25 23:13] DECIDED (revisit): **tour anchors are named places on shared components**, never on one
  screen's markup: `web/src/app/shared/tour/tour-anchors.ts` lists fourteen (the shell's navigation, create, palette,
  account and settings; a list's search, views, columns and pages; a form; the record bar; the document actions,
  their final group and a confirmation's kind), and `scripts/gates/tour-anchors.sh` reds when a declared anchor is
  on no element or an element carries an undeclared one. Row 140's steps will point at these names.
- [2026-09-26 00:06] DECIDED (revisit): **« Premiers pas » is worked out, never ticked** (row 139). Each context declares
  its step (`DeclaresFirstStep`, collected like watches), `GET /companies/{id}/first-steps` answers them in the approved
  order with whether each is done, and the home shows them first until none remains. Done means: *profil et
  matricule* — a legal name, an address line, a city and every registration number the preset requires of a
  company, in its shape; *taxes* — a person created or revised one, read from the audit log, since the preset
  provisions them at creation and their existing proves nothing; *premier client*, *premier article* — one exists,
  created or imported; *couleur* — the company chose its own accent (the logo joins this step with the brand kit,
  row 36); *inviter un membre* — a second member, or an invitation still open. A step is shown to whoever may DO it
  (`customer.write`, not `customer.read`) while its module is on. No « Masquer »: the panel goes when the steps are
  done, and a member who may do none sees none; a dismissal waits for a real complaint. The contextual suggestions
  half of row 139 is not built.
- [2026-09-26 00:22] DECIDED (revisit): **the TEJ file, as the first builder built it** (row 144, commits `bac2ed0b`…`d554d62c`;
  the builder's full report is `var/claude/builders/tej.md`, local). An expense paid with a withholding carries its
  TEJ operation code (the 47 codes and labels carried verbatim from `TEJRSCodesOperations_v1.0.xsd`, typos included —
  the administration's text), given at payment or later through `POST …/expenses/{id}/withholding-operation`, refused
  outside the TN preset and never guessed from the rate. `GET /companies/{id}/withholding-declarations/tej/{YYYY}-{MM}`
  (read with `expense.read`) answers the month's `DeclarationsRS` file, named `[matricule]-[YYYY]-[MM]-0.xml`: one
  certificate per paid expense, its reference the expense's id so a later rectifying file can name it; every amount
  in millimes; `Resident=1`, `CNPC=0`, `P_Charge=0` always; `TauxTVA` and `MontantTVA` always written; the invoicing
  year is the expense's own date. The whole month is refused (422 `incomplete_expenses`, each payment with its
  problems) rather than written partly, since the initial filing happens once; an empty month is refused too. A
  vendor's address, email (to the XSD's pattern) and phone are required, as the XSD and the cahier require them;
  PM or PP is read from the matricule's category letter (M → PM; P and C → PP; any other is reported, never
  guessed). Certified: the XML validates against the official XSD with its one malformed line removed, locally only
  (CI has no copy, so those three cases skip there); the file was never uploaded to tej.finances.gov.tn, so the
  platform's content check is uncertified. Open: **whether the XSD may be vendored** (a licensing question, the
  developer's), whether the demo dataset should show a TEJ month, and a web screen for the code and the download.
- [2026-09-26 01:11] DECIDED (revisit), CI red on a419072b: **the shell is exactly as tall as the window, and the
  page scrolls inside its panel.** « Premiers pas » lengthened the home, and the sidenav container (`min-h-full`)
  grew with it, which pushed the rail's foot (the account menu and the fold toggle) 350 px below the fold.
  two-factor.spec timed out reaching « Se déconnecter » there. The container is now `h-full`. The rail's groups
  scroll in their own `.twes-rail-groups`, so the foot stays pinned however many modules are on. The panel is
  `calc(100% - 1rem)` inside its margins, and its old `min-h-screen` is gone. Sticky elements now stick to the panel,
  their nearest scroller, and nothing in `web/` read the window's scroll. shell.spec pins it at 1280×500: the foot and
  the panel end inside the window, and « Se déconnecter » is reachable. That menu is 641 px of content, so Material
  scrolls its panel. Certified by execution: that test was red for both causes and is now green; 8 spec files (shell,
  two-factor, global-changes, accessibility, data-list, stock-map, products, settings) pass locally. Stock-map and
  products timed out once at load average 18 and passed on rerun. Uncertified: the other 34 e2e files, which CI runs.
  Fixed with it: the five e2e checks that the page does not scroll sideways read only the document, which never
  overflows now. `e2e/overflow.ts` measures the document and the panel. Proven by a sabotage: with a 3000 px element
  in the page, the old check said the page fits, and the new one reports 2008 px at 1280 and 2626 px at 390.
- [2026-09-26 01:55] DECIDED (revisit), row 139's second half: **a contextual suggestion is the toast's one button**,
  offering the next step on what was just done, to a member who may take it, while its module is on. There is no
  panel, no counter and nothing to dismiss: it goes with the toast, which stays eight seconds instead of four when it
  carries one. There are three:
  - a customer just created offers « Facturer ce client ». It opens `/invoices/new?billTo=<id>`, which chooses that
    customer as if picked, then drops the parameter from the address.
  - an issued invoice with money owed offers « Enregistrer un paiement », which opens the payment dialog.
  - a validated delivery note offers « Facturer ce bon ».
  Each is decided on the document the API answered, by the same rule as the screen's own button (`owes`,
  `invoiceable`), so the two cannot disagree. `Feedback.effect` takes the action `success` already took. Left out on
  purpose: a product just created (its next step is not one thing), and anything on an edit. Revisit when a real
  first-run session shows where people stall.
- [2026-09-26 02:19] DECIDED (revisit), row 144 on the screen: **the TEJ operation is chosen by a person, at payment or
  afterwards.** A company whose options carry TEJ codes (Tunisia) sees an optional « Opération TEJ » on the payment
  form. A paid expense then shows the operation it was given, or « Opération TEJ non indiquée ». A member with
  `expense.write` can give or change the operation in a form of its own. The select lists the 47 codes, each labelled
  « code — the administration's text » verbatim. A searchable picker would suit 47 long labels better, and is the
  revisit. The code is sent only when chosen, since outside Tunisia the API refuses any code. Changing it is an
  ordinary save with a plain toast, not a confirmed action: it is corrigeable by nature, and it is the same endpoint
  again. Noticed, not fixed: the expense page's « Joindre un fichier » is a bare native file input, which shows the
  browser's own "Choose File / No file chosen" in its language.
- [2026-09-26 02:35] DECIDED (revisit), row 144 on the screen: **the month's TEJ file is downloaded from the expenses list**,
  through « Fichier TEJ », offered when the API lists TEJ operations. The web never decides by the company's country.
  A dialog offers the twelve months that are over, the latest first, and preselects the one just ended. The file is
  fetched, not linked, so a refusal can be shown in the page: each refusal code and each problem of each payment
  holding the month back is a sentence in the person's language, with a link to that payment. The API's `message` is
  never shown. The dialog says what the file is: an initial filing (acte 0), for the person to file on the platform
  themselves. It says nothing about whether the platform will accept it, since that check is uncertified.
  `shared/files/save-file.ts` is the first download a page fetches itself, behind `FileSaver`.
- [2026-09-26 03:00] DECIDED (revisit), CI red on faea4555, products.spec's axe scan: **a filled button's hover, focus and
  press layers are the opposite of its ink.** They darken a fill written in white, and lighten one written in ink.
  Material lays `on-primary` over the fill at 8 to 12 %. With the default accent #1f6feb, white reads 4.64:1 at rest
  but about 4.07:1 hovered, which fails AA. Axe saw that only when the pointer rested on the button after a click,
  which is why the same scan kept failing by turns (twice before in CI). `--twes-accent-state` is the layer's colour,
  derived beside `--twes-on-accent`, and the button overrides take it for the state layer and the ripple. A state now
  never lowers a filled control's contrast, whatever accent a company picks, and `accent-theme.spec` checks that for
  eleven accents in both schemes. The look changes: a hovered primary button darkens instead of lightening.
- [2026-09-26 03:54] DECIDED (revisit), row 145 part one: **an issued French invoice or credit note answers its
  Factur-X EN 16931 CII**, at `GET …/invoices/{id}/factur-x.xml`, and **its issued PDF with that XML embedded** at
  `…/factur-x.pdf`. The PDF uses Gotenberg's own Factur-X route, PDF/A-3b, so no library is added. The XML is
  written from what issuing stored and never recomputed. A draft answers 409; a document the standard cannot describe
  answers 422 naming every gap. Builder B's 22 choices are in `var/claude/builders/facturx.md`; the ones to review
  first:
  - a credit note is written with positive amounts under type 381;
  - VAT category S is derived from a VAT component's rate, and a 0 % component is refused rather than guessed;
  - the no-VAT categories (K, G, E and their VATEX codes) are preset data on the FR regimes; `exempt` declares none
    and is refused;
  - the seller is read from the company as it is now, since issuing takes no snapshot of it;
  - BT-115 equals BT-112, since later payments are not a document amount;
  - the seller's SIREN and full French addresses are required, which is stricter than EN 16931.
  Uncertified: the EN 16931 schematron, since no XSLT 2.0 processor is on the machine; PDF/A-3b conformance, since
  veraPDF is absent; and the PDF route end to end, since the functional tests stub Gotenberg. The schemas are kept
  out of the tree and of CI until their licences are ruled. The FNFE XSDs carry no licence text, and CEN's artefacts
  are EUPL-1.2. Open, to rule:
  - may the schemas, or Saxon-HE for the schematron, enter CI;
  - BT-32 for a franchise-en-base seller, who is refused today for lack of a VAT number;
  - which CGI article `exempt` names;
  - a seller snapshot at issue;
  - storing the XML at issue;
  - where the two downloads sit on the invoice screen;
  - whether issuing warns when the Factur-X would be refused.
- [2026-09-26 03:54] DECIDED (revisit), row 125 part one, the ruled keys before they are configurable: **N opens a new
  document on its own list** (the creation whose address is the list's followed by `/new`, nothing elsewhere); **E runs
  the state's next step**: Émettre then Enregistrer un paiement on an invoice, Valider, Livrer then Facturer on a
  delivery note, asking first exactly as its button does. **/ opens the search** as Ctrl K does. Each is held for one
  scan gap like every bare key, and taken only when it has something to do there.
  - `ScreenAction.next` marks the next step. It is declared rather than read from `primary`, which a record page's
    save carries. When two are offered at once, the first declared is the one E runs. The delivery note's three steps
    are included, although the ruling named only Émettre and Encaisser.
  - An invoice's Émettre no longer has a key of its own (it was E).
  - A screen may now declare only S, V, L or P (`SCREEN_KEYS`). That closed set is what a person will be free to
    avoid when choosing the shell's keys.
  - `DEFAULT_SHORTCUTS` is the one list the handler, the rail's « C » and the « ? » sheet read. The sheet shows the
    page's next step under E.
  - Part two is the per-person setting in Mon compte › Préférences.

## 8. Status

<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 0 | G0 reset + bootstrap + CI + notices generator + licence gates | L | done | 4001a34 | |
| 1 | G1a sessions + voters + audit log + seeded operator + hello page | L | done | 84ad791 | api/** web/** infra/** |
| 2 | G1b companies + memberships + switcher + invitations + mail | L | done | a021c93 | api/src/Tenancy/** api/templates/** web/src/app/company/** web/src/app/invitation/** |
| 3 | G1c MFA: TOTP, recovery codes, company requirement, passkeys | M | done | a89274c | api/src/Identity/** api/src/Tenancy/** api/migrations/** api/config/** web/src/app/auth/** web/src/app/company/** web/e2e/** compose.yaml |
| 4 | G1d signup + operator approval | L | done | 268a68c | api/src/Tenancy/** api/src/Settings/** api/migrations/** api/templates/email/** api/translations/** api/config/** web/src/app/signup/** web/src/app/platform/** web/src/app/auth/** web/src/app/hello/** web/e2e/** |
| 5 | G2a shell + design tokens + theme + notifications + nonce CSP | L | done | a7cddb1 | api/src/Inbox/** api/src/Shared/** web/src/app/shell/** web/src/app/notifications/** web/src/app/shared/** infra/web/** compose.yaml |
| 6 | G3a fiscal research + presets + tax components + tax regimes | L | done | 38866d0 | docs/fiscal/** api/config/fiscal/** api/src/Fiscal/** web/src/app/fiscal/** |
| 7 | G4 customers + contacts + customer groups | L | done | 5ab6c87 | api/src/Module/Customers/** api/src/CustomFields/** api/src/Settings/** web/src/app/customers/** web/src/app/company/** web/src/app/shared/** web/e2e/** |
| 8 | G5 products + categories + module registry | L | done | b97fd9b | api/src/ModuleRegistry/** api/src/Module/** api/src/Settings/** api/src/CustomFields/** api/migrations/** web/src/app/** web/e2e/** |
| 9 | G6 delivery notes + PDF | L | done | 468fe11 | api/src/Module/DeliveryNotes/** api/src/Tenancy/** api/src/Files/** api/src/Shared/** api/templates/pdf/** api/translations/** api/migrations/** web/src/app/delivery-notes/** web/e2e/** compose.yaml |
| 10 | G7 invoices + payments + credit notes: the screens, their e2e and WCAG walk (the FR columns moved to row 44) | L | done | b35ce69 | api/src/Module/Invoices/** api/src/Module/DeliveryNotes/** api/migrations/** web/src/app/delivery-notes/** web/public/i18n/** |
| 11 | G8 vendors | S | done | 694f0b4 | api/src/Module/Vendors/** api/migrations/** api/src/Tenancy/** api/tests/** web/src/app/vendors/** web/src/app/shell/** web/src/app/app.routes.ts web/public/i18n/** web/e2e/** docs/SPEC.md |
| 12 | G9 expenses + attachments | M | done | 5d7fa4e | api/src/Module/Expenses/** api/src/Files/** api/migrations/** api/config/** api/tests/** web/src/app/expenses/** web/src/app/shell/** web/src/app/app.routes.ts web/public/i18n/** web/e2e/** docs/SPEC.md |
| 13 | G10 inventory with stock locations | M | done | 64ef246 | api/src/Module/Inventory/** api/migrations/** api/src/Tenancy/** api/tests/** web/src/app/inventory/** web/src/app/shell/** web/public/i18n/** web/e2e/** docs/SPEC.md |
| 14 | G2b generic metadata list/form + presentation settings + design checkpoint | L | done | 900ac11 | web/src/app/shared/** web/src/app/design/** web/e2e/** |
| 15 | G3b settings engine chains + settings page + establishments + numbering series + company profile | L | done | 703e06f | api/src/Settings/** api/src/Tenancy/** api/config/fiscal/** web/src/app/settings/** web/src/app/company/** web/src/app/shared/settings/** web/e2e/** |
| 16 | Restyle basics: accent fidelity + locale formatting + status badges + shell states + settings area + notification panel (home page, list upgrades and notification preferences moved after the POC) | L | done | 15b332b | web/src/app/shared/** web/src/app/shell/** web/src/app/notifications/** web/public/i18n/** web/e2e/** |
| 17 | Review D1/D4/D11: documents locked before their status check, series formats that reset carry the period, database checks | M | done | 01d2200 | api/src/Module/Invoices/** api/src/Module/DeliveryNotes/** api/src/Tenancy/Domain/** api/migrations/** api/tests/** |
| 18 | Review S1/S2/S4/C3: existing accounts accept an invitation, role grants bounded by the actor's role, realtime token re-checks membership, forced logout and deactivation reachable | L | done | 9f5c817 | api/src/Tenancy/** api/src/Identity/** api/src/Inbox/** web/src/app/company/** web/src/app/invitation/** web/e2e/** |
| 19 | Review S3/C7: operators reach platform scope only, carry a second factor, and create companies and invite owners from /platform | M | done | 3196b8f | api/src/Tenancy/** api/src/Identity/** web/src/app/platform/** web/e2e/** |
| 20 | Review S5/C2/C12: Doctrine company filter (no API Platform extension, § 7 2026-09-16) + company_id on every business table + entity architecture test + unauthenticated sweep | L | done | 4a54b6a | api/src/** api/migrations/** api/config/** api/tests/** |
| 21 | Review D2: a credit note withholds as its invoice did | S | done | 17167fc | api/src/Fiscal/** api/src/Module/Invoices/** docs/fiscal/TN.md api/tests/** |
| 22 | Review S6/S10/S11: a cap on second-factor guesses, no boot on development keys, dev ports on loopback | S | done | 96cbac7 | api/src/Identity/** api/src/Kernel.php api/src/Shared/** api/config/** api/tests/** compose.yaml |
| 23 | Review C6/C8/C9/C10/C11: the screens no WCAG check had visited are walked in both schemes, § 4 and § 6 corrected, reviewer charters trimmed to this tree, CLAUDE.md map | M | done | b4a8179 | web/e2e/** web/src/app/notifications/** docs/SPEC.md CLAUDE.md .claude/agents/** |
| 24 | The expense screen proposes the company's today, not the browser's, for an expense's date and its payment | S | done | d0558a9 | web/src/app/expenses/** web/src/app/shared/** web/src/app/notifications/** |
| 25 | The credit notes of a withheld invoice add up to it exactly, the last one absorbing what the others rounded | S | done | 2343fa6 | api/src/Fiscal/** api/src/Module/Invoices/** api/tests/** |
| 26 | A second factor that cannot be read is not a wrong guess: after a key rotation the refusal stops counting toward the lock | S | done | 808fc25 | api/src/Identity/** api/tests/** |
| 27 | The second-factor limiter is covered again: the lock answers every wrong code first, so only a run of correct codes reaches it | S | done | 8e93574 | api/tests/** |
| 28 | The tab label's colour in the dark scheme: understood, then fixed or ruled correct — row 23 excluded it from the dark walk rather than guess | S | done | 2543645 | web/src/styles.scss web/src/app/shared/ui/** web/e2e/accessibility.spec.ts |
| 29 | A withholding prints one minus sign on a credit note, not two: the template prefixes a sign to an amount that is already negative, and the only test touching that template checks its margins | S | done | 369cf25 | api/templates/pdf/** api/tests/** |
| 30 | Restyle to the approved design, part 1: tokens (status tones, accent and its ink, surfaces, motion) + responsive shell (bottom bar, rail, labelled rail) + Ctrl K command palette with module-registered commands | L | done | b497568 | web/src/styles.scss web/src/app/shared/** web/src/app/shell/** web/src/app/*/*-nav.ts web/src/app/*/*-types.ts web/src/app/*/*.html web/public/i18n/** web/e2e/** |
| 31 | Enrolling an authenticator whose pending secret the current key cannot read answers a refusal, not a 500: `ConfirmTotpEnrolment` decrypts unguarded as `VerifySecondFactor` did before row 26 | S | done | 8e93574 | api/src/Identity/** api/tests/** |
| 32 | Mockups for the pages the approved design lacks: the six sign-in pages and the settings layout, light and dark, desktop and phone, variants where the layout is a real choice | M | done | 40889e0 | docs/SPEC.md |
| 33 | Restyle, part 2: the shared list and form components and every list and record page built on them, to the approved list and editor mockups | L | done | 1bc8f01 | web/src/styles.scss web/src/app/shared/** web/src/app/*/*-page.* web/e2e/** |
| 34 | Restyle, part 3: the sign-in pages, to the variant chosen in row 32 | M | done | 7a84e6b | web/src/app/auth/** web/src/app/signup/** web/src/app/invitation/** web/src/app/shared/brand/** web/public/fonts/** web/public/i18n/** web/src/styles.scss web/e2e/** THIRD-PARTY-NOTICES.md |
| 35 | Restyle, part 4: the settings pages and home, to the variant chosen in row 32 and the approved home mockup (home: invoice summary endpoint and manifest panels; settings frame: filter and phone list, § 7 2026-09-16) | L | done | 3dc70e5 | web/src/app/company/** web/src/app/settings/** web/src/app/fiscal/** web/src/app/hello/** web/src/app/shell/** web/src/app/invoices/** api/src/Module/Invoices/** api/tests/** web/src/styles.scss web/e2e/** |
| 36 | The installation brand kit: product name, tagline per language, logo light and dark, app icon, default accent and sign-in background as platform settings behind a brand port, the operator's Marque page, every place the name is read today reading it, and the sign-in scene built from one card per switched-on module | L | todo | - | api/src/Settings/** api/src/Platform/** api/src/Files/** api/config/** web/src/app/platform/** web/src/app/shared/** web/public/i18n/** web/e2e/** |
| 37 | The top bar and the scheme (§ 7 2026-09-16 review): Automatique · Clair · Sombre menu on every screen including sign-in, the language menu by name and code, the unread count, the centred search, settings from the sidebar, every control named with tooltips on icon-only ones under a test, and both schemes checked by eye against the chosen design | L | done | fe2a705 | web/src/app/shared/** web/src/app/shell/** web/src/app/notifications/** web/src/app/auth/** web/src/app/signup/** web/src/app/invitation/** web/src/app/app.config.ts web/src/styles.scss web/public/i18n/** web/e2e/** web/playwright.config.ts api/src/Settings/** api/tests/** scripts/gates/** .github/** Makefile |
| 38 | Per-company sign-in, signup and invitation pages, reached through a per-company address resolved from the host by a public endpoint, resolving the brand kit at the company level (brought forward 2026-09-16, with row 36) | L | todo | - | api/src/** web/src/app/auth/** web/src/app/signup/** web/src/app/invitation/** |
| 39 | The rebrand: name, then symbol, then tagline, from § 1's five jobs, drawn on the design canvas and shipped as the brand kit's defaults (after rows 10 and 35, before a public launch) | M | deferred | 8a40530 | docs/SPEC.md |
| 40 | Audit P1-1/P1-2/P2-3/P3-10: stock integrity — a unit or kind change refused while a product has stock movements, the delivery note stock handler fail-safe (catch, log, notify `stock.write`) with a repeatable reconcile command, quantities re-checked against the unit at validation, a stock count locked against a concurrent move | M | done | c44f650 | api/src/Module/Inventory/** api/src/Module/Products/** api/src/Module/DeliveryNotes/** api/src/Fiscal/** api/tests/** web/src/app/products/** web/public/i18n/** |
| 41 | Audit P1-4: every command use case commits its change and its audit row in one transaction; repositories persist, the transaction flushes | M | done | 33baadf | api/src/** api/tests/** |
| 42 | Audit P2-7/P2-8/P3-8: web boundary lint rules (HTTP and generated types only in `*-api.ts`, OnPush required, `shared/` imports no feature) and a `shared/session` port so `shared/` stops importing `auth` | S | done | 4d40573 | web/eslint.config.js web/src/app/** |
| 43 | Audit P2-1/P2-2/P2-4/P2-5/P2-6/P3-1..P3-7/P3-9: spec and code agreed on use-case grouping and cross-context references, the clock port in Identity, the Identity-Tenancy cross-calls, invoice drafting from notes owned by Invoices, settings levels registered, naming, infra duplication, `Invoice.php` split, architecture tests parsing imports and cycles, one document lines component shared by delivery notes and invoices (after the POC) | L | deferred | - | api/src/** api/tests/Architecture/** web/src/app/** infra/** .github/** docs/SPEC.md |
| 44 | The French invoice fields: `operation_category` (goods, services or both) and `vat_on_debits` (the seller's option), stored at issue and printed under the FR preset, wording UNCERTIFIED beyond the articles docs/fiscal/FR.md names (after rows 37, 45, 46, 38, 36 and 47) | M | todo | - | api/src/Module/Invoices/** api/src/Tenancy/** api/config/fiscal/** api/templates/pdf/** api/migrations/** web/src/app/invoices/** web/src/app/company/** |
| 45 | Per-screen actions: each list and editor declares its actions once, read by its toolbar, Ctrl K, keyboard shortcuts, tooltip hints and a "?" sheet; each action classed confirm (a dialog stating the consequence), undo (an Annuler toast over a server-side bin) or plain, and a guard before leaving unsaved changes (§ 7 2026-09-17) | L | done | a9bcfea | web/src/app/shell/** web/src/app/shared/** web/src/app/invoices/** web/src/app/customers/** web/src/app/products/** web/src/app/delivery-notes/** web/src/app/expenses/** web/src/app/vendors/** web/public/i18n/** web/e2e/** |
| 46 | Tax family and kind decoupled: the preset states both, validation refuses an incompatible pair, the form names the arithmetic generically; bounds only with a sourced tax | M | todo | - | api/src/Fiscal/** api/config/fiscal/** api/tests/** docs/fiscal/** web/src/app/fiscal/** web/public/i18n/** |
| 47 | Translation overrides: platform then company, per language, placeholder-checked, instant through realtime; one cached overrides request merged over the base file, a server-paged Textes editor over a catalogue exported into the API, screen texts first then mails and PDFs (§ 7 2026-09-17) | L | todo | - | api/src/** api/migrations/** api/tests/** web/src/app/shared/i18n/** web/src/app/settings/** web/src/app/platform/** web/src/app/notifications/** web/public/i18n/** web/e2e/** |
| 48 | Feedback: a lean loader while data loads, an unavailable state while the API is retried, a message for a slow request, success and failure toasts with clear wording (after row 37) | L | done | ce71fe1 | web/src/app/shared/** web/src/app/shell/** web/src/app/**/*-facade.ts web/public/i18n/** web/e2e/** |
| 49 | Live data: the API publishes what changed after commit to the company or user channel, one web bus reloads what a page shows, per-field merge in editors with the other version shown, announcements where I am, the company switch re-subscribing (§ 7 2026-09-17) | L | done | c453b0c | api/src/Shared/** api/src/**/Application/** api/tests/** web/src/app/shared/** web/src/app/notifications/** web/src/app/**/*-facade.ts web/public/i18n/** web/e2e/** |
| 50 | Account security: the two-factor deadline with its dialog and banner, change and forgot password, connected devices and signing out everywhere, session lengths within platform ceilings (§ 7 2026-09-17) | L | todo | - | api/src/Identity/** api/src/Tenancy/** api/migrations/** api/tests/** web/src/app/auth/** web/src/app/company/** web/src/app/settings/** web/src/app/platform/** web/public/i18n/** web/e2e/** |
| 51 | Legal pages: mentions légales, CGU with recorded acceptance, privacy, data processing agreement and sub-processors, AGPL source link, open-source notices, cookies and storage page, operator-edited per language and versioned; a gate refusing an unlisted cookie, storage key or third-party script (§ 7 2026-09-17) | L | todo | - | api/src/** api/migrations/** api/tests/** web/src/app/** scripts/gates/** web/public/i18n/** web/e2e/** |
| 52 | Fiscal presets editable in the app: the operator's country presets, versioned and sourced; the company's copy; the owner's "Zone sensible" for law-bound values with its seven hard limits; each document keeping the rules it was issued under (§ 7 2026-09-17) | L | todo | - | api/src/Fiscal/** api/src/Settings/** api/src/Module/Invoices/** api/migrations/** api/tests/** web/src/app/fiscal/** web/src/app/platform/** web/public/i18n/** web/e2e/** |
| 53 | Custom roles: the owner builds roles from permission templates, one role per member, the last owner never demoted (§ 7 2026-09-17) | M | todo | - | api/src/Tenancy/** api/migrations/** api/tests/** web/src/app/company/** web/public/i18n/** web/e2e/** |
| 54 | Logs by channel, as Symfony and Monolog recommend: JSON on STDERR in production with Docker rotation for every service, one file per channel with a shipped logrotate configuration when self-hosted, rotated files in development, errors only in tests (§ 7 2026-09-17); the ruling's "mail" channel is Symfony's `mailer`, tests already kept errors only | M | done | 54245f4 | api/config/packages/monolog.yaml api/config/** compose.yaml infra/** docs/** |
| 55 | Lists at scale: API Platform pagination by list type (page and total, cursor for histories, capped reference lists), `QueryParameter` filters and a `pg_trgm` search, server-side autocomplete in forms, the list state in the URL (§ 7 2026-09-17). DONE: the `Paging`/`ListOrder`/`SEARCH_TEXT` seam, seven paged and searched business lists each with its GIN index and an EXPLAIN test (`SearchIndexesTest`), JSON-LD confined to those, and the page, size, sort and filters in the URL for them. (a) DONE for the two document forms (0dfb9a4 invoices, f1102f6 delivery notes): a `pick-field` asks after a 300 ms pause and shows twenty, the customers and products left their `*-options` payload entirely, and a document now carries the words for what it names (its customer as it reads today, each line its product's reference and name) so a form opens without reading either book. One picker per consumer, four endpoints, each under the asking form's own permission and answering the shape that form needs — the delivery note's customer row is narrower than the invoice's on purpose. Given `ids`, a picker resolves exactly what a document names, deactivated or retired included. (a2) DONE for STOCK too: `StockOptions` is down to its establishments, and the level and movement rows carry their own `unitDecimals` rather than reading them out of a catalogue the page held. Its picker is the one that could not be a document form's, because what it offers is narrower than what the words find: stock is kept of GOODS whose `article.stock_tracking` resolves on, which is half a column and half a SETTING, so the kind is a WHERE clause and the setting is walked over a bounded window of 200 matching goods — the answer is "these, among the first few hundred that match", never "these are all there are", and the endpoint says so. (a3) DONE for EXPENSES (vendors) too, so every form now asks for the few it needs. (b) DONE for STOCK MOVEMENTS, the fastest-growing table: it was capped at the latest 200 for the browser to cut up, so a company past that silently stopped seeing its older history. The API now pages it, and — because a list the API pages shows the page it was sent — every filter the screen offers had to move with it or narrow that page alone and read as the whole history: the words, the product, the location, the kind, the source, and six sorts. The `KeepStock::MOVEMENTS_LISTED` cap and its two port methods are deleted rather than left for a caller to find. The words reuse the levels list's own expression, so a person typing the same thing into either box gets the same rows. LEFT, in the order it hurts: 'the recently used first' is still not served at all; (c) no cursor paging exists, so the deep histories (notifications, audit — which has no read endpoint yet) are unserved, and movements page by offset, which is right until the page numbers grow large; (d) stock levels is off the shared path: its own sort map, a total counted in PHP, and a search no index serves (see Known issues); (e) unpaged lists keep nothing in the URL; (f) the reference lists' 500 cap does not exist; (g) a handful of lists count per row (customer groups, product categories, stock locations) | L | doing | - | api/src/** api/migrations/** api/tests/** web/src/app/** web/e2e/** |
| 56 | A worker: Symfony Messenger and Scheduler in their own container, realtime, mail and PDF work off the request, scheduled purges of expired invitations and signups, read notifications and audit rows past their retention, an audit index by company (§ 7 2026-09-17); then import and export run on it, one transaction per file, and the import row cap rises (§ 7 2026-09-19) | L | todo | - | api/src/** api/config/** api/migrations/** api/tests/** compose.yaml infra/** |
| 57 | Running totals: the home figures and stock levels read maintained totals updated in the transaction of each change, not the whole history (§ 7 2026-09-17) | M | todo | - | api/src/Module/Invoices/** api/src/Module/Inventory/** api/migrations/** api/tests/** |
| 58 | Files and operations: file deletion with the legal retention (detached drafts after 30 days, issued PDFs and booked receipts kept ten years), a nightly PostgreSQL backup kept 14 days with a restore tested in CI, capped container logs and restart policies (§ 7 2026-09-17) | M | todo | - | api/src/Files/** api/tests/** compose.yaml infra/** .github/** docs/** |
| 59 | Import from CSV and .xlsx (§ 7 2026-09-17, urgent): a template per type, a server-side preview of new, updated and rejected rows with each line's reason, create-only or create-and-update, one transaction. Customers, products (2455942) and vendors (41984b2) done, each declared beside its own module. Opening stock done too (85c2f67 made the identity a LIST, then 7e634eb): a row is found again by its product AND the place it sits in, and it is a COUNT, so the same file twice leaves the same stock. The SCREEN is one page for every subject, driven entirely by the guide, reached from each list page; it previews before it imports, and will not import while a row is rejected | L | done | 64349f9 | api/src/** api/config/** api/migrations/** api/tests/** web/src/app/** web/e2e/** docs/** |
| 60 | Export every list as CSV and .xlsx with the filters, search and sort it shows (§ 7 2026-09-17) | M | todo | - | api/src/** api/tests/** web/src/app/** web/e2e/** |
| 68 | Operating guides (§ 7 2026-09-19): `docs/START.md` (bring-up, users, seed, clean start, checks) and `docs/UPDATE.md` (every pin, its copies, how to bump it), `make versions`, `make reset`, and the version-pins gate | M | done | 2d70b43 | docs/START.md docs/UPDATE.md scripts/versions.sh scripts/gates/version-pins.sh scripts/gates/tests/version-pins.test.sh Makefile |
| 69 | Demo fixtures (§ 7 2026-09-19): DoctrineFixturesBundle alone, every row written through the use cases, a fixed dataset of two companies (Carthage Conseil, TN; Atelier Mercier, FR) with five months of activity, loaded by `make fixtures` (append only) and described in `docs/START.md` § 4; `make gallery` shows Carthage Conseil | M | done | 9389371 | api/src/DataFixtures/** api/tests/Functional/DemoFixturesTest.php api/composer.json api/composer.lock api/symfony.lock api/config/bundles.php THIRD-PARTY-NOTICES.md Makefile docs/START.md |
| 61 | Product identity (§ 7 2026-09-17): an optional reference generated from a numbering series when left empty (previewed in the form, proposal to confirm), a barcode unique within the company when set, an EAN/UPC check digit verified | M | doing | - | api/src/** api/migrations/** api/tests/** web/src/app/** web/e2e/** |
| 62 | Substitution groups (§ 7 2026-09-17): the business groups products that replace each other; the product page and a document line short of stock show the in-stock substitutes and swap in one click | M | todo | - | api/src/** api/migrations/** api/tests/** web/src/app/** web/e2e/** |
| 63 | Scanner (§ 7 2026-09-17): a camera scan for the barcode field, a list's search and a document's lines; a phone paired to a laptop tab by a single-use, scan-only QR code, codes sent over the tab's realtime channel; iPhone Safari, HTTPS and the decoder's licence researched first | L | doing | - | api/src/** api/tests/** web/src/app/** web/e2e/** docs/** |
| 65 | Subscriptions, slice 1 (§ 7 2026-09-17): the `Licensing` context, a subscription per company with its trial, billing period, price and paid-through date, the standing computed from those dates, the operator's platform endpoints and panel, an unpaid company read-only or locked in both access checks, the notice above every page | L | done | 50d5494 | api/src/Licensing/** api/src/Tenancy/Infrastructure/** api/src/Identity/Infrastructure/ApiPlatform/** api/migrations/** api/tests/** web/src/app/** |
| 66 | Subscriptions, slice 2 (§ 7 2026-09-17): a company declares a cash payment with its method, date, reference and receipt; the operator is told in the app and by mail and confirms or rejects it from the platform page; a declaration holds the lock off for the configured days; the ledger is audited; the page a locked company reaches outside the shell is its only way back | L | done | 1daa4bb | api/src/Licensing/** api/migrations/** api/tests/** web/src/app/** web/e2e/** |
| 67 | Subscriptions, slice 3 (§ 7 2026-09-17): reminder mail before a period ends and while grace runs, on the row-56 scheduler worker; and operator hardening with owner-granted, time-boxed support access | M | todo | - | api/src/Licensing/** api/src/Identity/** api/tests/** web/src/app/** |
| 64 | Import past invoices and delivery notes as a read-only archive keeping their original numbers, outside the gapless series (§ 7 2026-09-17) | L | todo | - | api/src/** api/migrations/** api/tests/** web/src/app/** web/e2e/** |
| 70 | The shell after the design review (§ 7 2026-09-19 23:19-23:22, findings 5-8): the header search left-aligned and filling to the right-hand controls, capped near 800 px, its placeholder naming what it finds; the phone header showing the working company in the wordmark's place; the settings area as the app's 80 px rail plus the settings menu docked against it; the top bar's gear removed, settings from the sidebar only | M | done | - | web/src/app/shell/** web/src/app/shared/** web/public/i18n/** web/e2e/** |
| 71 | Lists after the design review (§ 7 2026-09-19, finding 1): the row a real link on its number or name, text selection and in-row controls never opening it; "Ouvrir" gone; a pinned right-edge column with each list's one or two frequent actions visible and the rare or destructive ones in "⋮"; phone rows as cards; the settings tables included (with row 45's declarations) | L | done | - | web/src/app/shared/list/** web/src/app/**/*-page.* web/public/i18n/** web/e2e/** |
| 72 | Documents after the design review (§ 7 2026-09-19, finding 3): a sticky action bar beside the title (the state's next step primary, PDF and Dupliquer visible, rare in "⋮"), locked invoices and delivery notes as a read view with empty fields left out, recording a payment in a dialog | L | done | - | web/src/app/invoices/** web/src/app/delivery-notes/** web/src/app/shared/** web/public/i18n/** web/e2e/** |
| 73 | Record pages after the design review (§ 7 2026-09-19, finding 4): the same title bar saving (Enregistrer active once changed, Annuler les modifications, the count of unsaved changes), long records in tabs with one save each (Fiche, Valeurs par défaut, plus Contacts on the customer page); then the balance pass (finding 10) re-measured on the gallery | M | done | - | web/src/app/customers/** web/src/app/products/** web/src/app/vendors/** web/src/app/expenses/** web/src/app/settings/** web/src/app/company/** web/src/app/shared/** web/e2e/** |
| 74 | Stock moves (§ 7 2026-09-19, 2026-09-21): a move inside an establishment, whole or partial, its out and in linked by one move id, offered on the stock screen and shown in the movements list | M | done | 9b24ee6 | api/src/Module/Inventory/** api/tests/** web/src/app/inventory/** web/public/i18n/** |
| 74 (b) | Stock losses (§ 7 2026-09-19): a write-off with a required reason, note and photo, a quarantine location kind, both in the movements list and reports; the VAT effect of a loss sourced in docs/fiscal first | L | todo | - | api/src/Module/Inventory/** api/migrations/** api/tests/** docs/fiscal/** web/src/app/inventory/** web/public/i18n/** web/e2e/** |
| 75 | Country pack (§ 7 2026-09-20): taxes and levies, mentions per situation AND per language, identifiers with their named check strategies, numbering constraints, formats, rounding, archive duration; the strategy registry; a conformance test loading every pack; rows 44 and 46 folded in | L | todo | - | api/config/fiscal/** api/src/Fiscal/** api/tests/** docs/fiscal/** |
| 76 | Catalogue (§ 7 2026-09-20): a family with axis values gathering variants that stay products; purchase, stock and sales units with conversion; one second-language name printed when the document is in that language | L | todo | - | api/src/Module/Products/** api/migrations/** api/tests/** web/src/app/products/** web/public/i18n/** |
| 77 | Price lists (§ 7 2026-09-20): per customer or group, quantity breaks, validity dates, keyed to area and channel; they decide a line's unit price before any discount | M | todo | - | api/src/Module/Products/** api/src/Module/Customers/** api/migrations/** api/tests/** web/src/app/** |
| 78 | Quote (§ 7 2026-09-20): issued, sent, accepted or refused, and acting as the order — what is delivered and what remains, partial delivery, no separate sales-order type | L | todo | - | api/src/Module/Quotes/** api/migrations/** api/tests/** web/src/app/quotes/** web/e2e/** |
| 79 | Deposits (§ 7 2026-09-20): a deposit invoice with its own number and VAT, subtracted from the final invoice by the engine and never typed | M | todo | - | api/src/Module/Invoices/** api/migrations/** api/tests/** web/src/app/invoices/** |
| 80 | Job (§ 7 2026-09-20): from an accepted quote, material consumed through the generic transformation, hours at a cost rate, output as a product or a one-off, scrap consumed by the job, closing into a delivery note and an invoice, quoted price against real cost | L | todo | - | api/src/Module/Jobs/** api/migrations/** api/tests/** web/src/app/jobs/** web/e2e/** |
| 81 | Purchase order and goods receipt (§ 7 2026-09-20): an order sent to a vendor with expected dates, receipts against it (partial allowed) moving stock into a location and recording unit cost | L | todo | - | api/src/Module/Purchasing/** api/migrations/** api/tests/** web/src/app/purchasing/** web/e2e/** |
| 82 | Register, the counter sale (§ 7 2026-09-20): one full screen on scanner and keyboard, receipt or invoice from the same sale, cash with change, card, on account and mixed payments, returns writing a credit note and restocking; sales idempotent and queued in shape so offline can be added later | L | todo | - | api/src/Module/Register/** api/migrations/** api/tests/** web/src/app/register/** web/e2e/** |
| 83 | Venue and the drawn map (§ 7 2026-09-19 23:40, brought forward 2026-09-20; surface ruled 2026-09-21): areas and spots with their plan rectangle, height and level; a 2D SVG plan per floor with rack front views, edited grid-snapped over an optional floor image, and a Three.js 3D view for looking; search or scan highlights every location holding a product, a delivery note highlights its lines'. DONE: the API half (the `Venue` context, `DrawStockMap`, the module's three endpoints); the SCREEN — floors with their levels, the SVG plan in metres with each rectangle turned about its own centre, drawing and erasing through a FORM beside the plan; and the GESTURES of the approved canvas so far — a rectangle moved by dragging it, resized by its eight handles, a new one traced on bare floor once the `Tracer` tool is armed, and the PALETTE of ready-made shapes, whose sizes are the company's own settings in the new `venue` chain and reach the screen through `stock-options` — every gesture writing into the form and never to the API. A bin is refused by the surface, not only by the picker. and REPEAT-DOWN-AN-AISLE, which creates the stock locations as well as the rectangles, in one transaction, listing their codes and refusing a copy off the floor before anything is written. The walls-and-doors structure layer shipped 2026-09-22 (`5b9918b`, `ba79e5c`). LEFT, in the ruled order: the usability pass of the 09:24 ruling (zoom/pan/fit, the Consulter/Aménager split, one drawing model, structure's gestures and keyboard path, the permission hole, erase confirmations), the data-driven palette and `form: rect|round` of the 09:30 ruling; after those the floor image with its two calibration points, the Three.js 3D view and the `presentation.stock-map-view` key that only makes sense with it, search-highlight, and the rack front view | L | doing | - | api/src/Venue/** api/src/Module/Inventory/** api/migrations/** api/tests/** web/src/app/inventory/** web/public/i18n/** api/src/Settings/** web/src/app/settings/** web/src/app/shared/settings/** scripts/gates/** |
| 84 | Supplier bill and the three-way match (§ 7 2026-09-20): a bill with lines, matched automatically against order and receipt within a tolerance the settings engine holds, anything outside it waiting on an approval before the bill is payable | M | todo | - | api/src/Module/Purchasing/** api/migrations/** api/tests/** web/src/app/purchasing/** |
| 85 | Statement of account and credit limit (§ 7 2026-09-20): a customer's documents and payments over a period, printed; a limit per customer or group warning when a delivery would pass what is already owed | M | todo | - | api/src/Module/Customers/** api/src/Module/Invoices/** api/tests/** web/src/app/customers/** |
| 86 | Recurring invoices (§ 7 2026-09-20, off "out with no date"): a schedule generating drafts that an issue confirms, on the row-56 worker | M | todo | - | api/src/Module/Invoices/** api/migrations/** api/tests/** web/src/app/invoices/** |
| 87 | Stock valuation (§ 7 2026-09-19 23:48): unit cost on receipts, weighted average unless the sourced fiscal research says otherwise, losses and transformations valued with it, a stock value report | M | todo | - | api/src/Module/Inventory/** api/migrations/** api/tests/** docs/fiscal/** web/src/app/inventory/** |
| 88 | Shift and drawer (§ 7 2026-09-20): a shift opened with a float and closed on a counted drawer by note and coin, a Z report by means of payment and VAT rate, tips, cash in and out, the waiter's purse counted at a handover, a float carried to tomorrow | M | todo | - | api/src/Module/Register/** api/migrations/** api/tests/** web/src/app/register/** |
| 89 | Reports (§ 7 2026-09-20): `DeclaresReport` beside the manifest, settings and import declarations; one engine giving every report the same period, comparison, filters, export and permission; drill-down on every figure; stored amounts never recomputed; the catalogue itself, module by module | L | todo | - | api/src/Reporting/** api/src/Module/** api/tests/** web/src/app/reports/** |
| 90 | Daily digest (§ 7 2026-09-20): yesterday's takings, what is overdue, what to reorder, what expires, by email or WhatsApp on the row-56 worker | M | todo | - | api/src/Reporting/** api/tests/** api/templates/** |
| 91 | Declarations (§ 7 2026-09-20): a dated area with its own calendar, not a reports menu — Tunisia's monthly form DC shape, the withholding certificate per beneficiary, and the recapitulation of withholding SUFFERED per customer and period; each rule sourced into docs/fiscal first | L | todo | - | api/src/Fiscal/** api/migrations/** api/tests/** docs/fiscal/** web/src/app/declarations/** |
| 92 | The accountant (§ 7 2026-09-20): sales, purchases, payments and VAT-summary exports as CSV and .xlsx; a read-only accountant role; a closed period refusing any new or back-dated document before its date | M | todo | - | api/src/** api/migrations/** api/tests/** web/src/app/** |
| 93 | E-invoicing readiness (§ 7 2026-09-20): every field TEIF and Factur-X need, room for a verification QR on the printed document, and one port for sending a document to a platform with no adapter behind it | M | todo | - | api/src/Fiscal/** api/src/Module/Invoices/** api/migrations/** api/tests/** api/templates/pdf/** |
| 94 | Profiles and module dependencies (§ 7 2026-09-20): the shop and workshop profiles as data (modules on, defaults, a starter catalogue); a manifest declaring what its module needs and a registry refusing an impossible combination | M | todo | - | api/src/ModuleRegistry/** api/config/** api/tests/** web/src/app/company/** |
| 95 | WhatsApp delivery (§ 7 2026-09-20): a signed expiring link to one document and a ready-written message, opened from the phone | S | todo | - | api/src/** api/tests/** web/src/app/** |
| 96 | Arabic and right-to-left (§ 7 2026-09-20): the interface in Arabic with RTL, the document language defaulting from the customer and overridable per document, bilingual printing, every pack's mentions in each language it can print | L | todo | - | api/translations/** api/templates/pdf/** api/tests/** web/public/i18n/** web/src/app/** |
| 97 | Alerts, not reports (§ 7 2026-09-20): low stock, a credit limit reached and a count difference raised through the notification centre and the realtime channel that already exist | S | todo | - | api/src/Inbox/** api/src/Module/** api/tests/** |
| 98 | A foreign note at the counter (§ 7 2026-09-20): a payment taken in another currency at a manual rate with change given in local money; the per-customer default currency waits for the foreign-currency milestone | S | todo | - | api/src/Module/Register/** api/tests/** web/src/app/register/** |
| 99 | The brand mark (§ 7 2026-09-20 09:30): the treatment chosen from the four on the Look canvas, shipped as the favicon, the sidebar mark and the app icon, drawn from vendored OFL type and recoloured by the installation's accent — ahead of row 36, which makes it configurable | S | todo | - | web/src/app/shell/** web/public/** web/src/index.html |
| 100 | Role accounts and an empty start (§ 7 2026-09-20 09:30): `make up` leaves an installation with one operator and no companies; `make fixtures` adds one owner, admin and member to each demo company, written through the invitation use cases | M | todo | - | api/src/DataFixtures/** api/tests/** docs/START.md |
| 101 | A product's home location (§ 7 2026-09-20 09:45, ruled 2026-09-21 09:30): one location per product per establishment, proposed when receiving and counting, with its own tab on the product screen and a `home_location` import column offered only to a company that holds stock; the map door ships with row 83 | M | done | - | api/src/Module/Products/** api/src/Module/Inventory/** api/migrations/** web/src/app/products/** web/src/app/inventory/** web/public/i18n/** api/tests/** |
| 102 | TTN connector (§ 7 2026-09-20 09:30): **unestimated — blocked on TTN's interface specification, signing and certificate requirements, and a test account.** Row 93's fields are built regardless, so no invoice waits on this | L | blocked | - | api/src/Module/** docs/fiscal/TN.md |
| 103 | Fiscal journal (§ 7 2026-09-20 11:30): a `FiscalJournal` context — append-only entries carrying the previous hash and a per-company sequence, signed with a per-company key encrypted at rest; a `training` flag on every entry; reprints marked duplicates; invoices and credit notes journalled. **Design ruled, nothing built.** The till joins the same journal with the register module, its Z-closure split from an ordinary count. TN's adapter blocked on JORT n° 125 | L | todo | - | api/src/Fiscal/** api/migrations/** api/tests/** docs/fiscal/** |
| 104 | Permission catalogue and the roles screen (§ 7 2026-09-20 11:30): each module declares its permission strings and labels beside its `DeclaresModule` declaration, the catalogue is collected not hand-written, and a company creates and edits its own roles in a matrix grouped by module; the members page offers the company's roles instead of three hardcoded names. `Role.company` is already nullable, so no migration for the roles themselves. The members page offers the company's roles, the API resolves a role name against the company acting, and an open invitation counts as holding its role | M | done | - | api/src/Tenancy/** api/src/ModuleRegistry/** api/src/Module/**/*Module*.php web/src/app/company/** api/tests/** web/src/app/**/*.spec.ts |
| 105 | Generated operator credentials (§ 7 2026-09-20 13:10): `app:seed --generate-operator-password` mints a password and a TOTP secret, prints both once and stores only the hash and the encrypted secret; seeding refuses the published development password and TOTP secret when the environment is production. The fixed literals stay for the local stack and CI so tests keep a deterministic sign-in | S | todo | - | api/src/Tenancy/** api/tests/Functional/SeedCommandTest.php docs/START.md |
| 106 | The five RecordBar pages (customer, product, expense, vendor, company profile — five, not the four the row named) and `RowAction` read the row-45 declaration: "s", Ctrl K and "?" work on a record page, and a row's own destructive control asks (§ 7 2026-09-20 22:10, 2026-09-21 07:05 and 07:50) | M | done | - | web/src/app/customers/** web/src/app/products/** web/src/app/expenses/** web/src/app/vendors/** web/src/app/shared/list/** |
| 107 | The server-side bin the `undo` action class needs: a deletion kept recoverable for a while, so "Annuler" on a toast can put it back. Row 45 shipped `plain` and `confirm` only and declared no field for `undo`, because a class nothing can produce is a promise (§ 7 2026-09-20 22:10) | L | todo | - | api/** web/src/app/shared/actions/** |
| 108 | Lot on document lines (§ 7 2026-09-24 12:40, row 5): an optional lot or serial on invoice and delivery-note lines, filled by a GS1 scan; validation takes the named lot, first-to-expire only for a line naming none. First of the amendments | M | done | 19b3b43 | |
| 109 | Cost on the line at issue (§ 7 2026-09-24 11:40, RPT-01): `invoice_line.unit_cost` copied from the product at issue, read only with `product.cost.read` | S | done | 82c4b08 | |
| 110 | Reorder point per product per establishment (§ 7 2026-09-24 11:40): column, product form field, import column; empty means no alert | S | done | 0088780 | |
| 111 | Supplier withholding (§ 7 2026-09-24 11:40, RPT-09): Tunisia's rules sourced in `docs/fiscal/TN.md`, then recorded on an expense's payment at the preset's rate, overridable | M | done | 4fa586e | |
| 112 | Declaration basis and calendar (§ 7 2026-09-24 11:40, RPT-03): TN and FR researched with citations; « TVA collectée » on the home meanwhile | S | done | 1a6ccf5 | |
| 113 | Home figures revised (§ 7 2026-09-24 11:55): margin headline, « Facturé ce mois » under it, the kept and added figures, the query-count test; after row 57 | M | todo | - | |
| 114 | The ten reports and saved report views (§ 7 2026-09-24 12:05) on row 89's engine, « dû à 30 jours », the company switcher's per-company figures | L | todo | - | |
| 115 | Insights and « À surveiller » (§ 7 2026-09-24 12:10): the ten insights as their data exists, thresholds as settings, the live screen and the home's count | M | done | - | |
| 116 | Lots amendments (§ 7 2026-09-24 12:40, rows 8, 20, 21, 22): lot tracking on the articles chain and « Traçabilité », expired marker and « Libérer », serial quantity 1, re-scanned serial refused, form kept open, destination label | M | todo | - | |
| 117 | Scanning and customer-facing amendments (§ 7 2026-09-24 12:40 and 12:55, rows 13, 14, 16, 17, 18): sound and re-count settings, key-gap setting and scanner test, pairing ends with its tab, customer view per device with step-up and a hide list, price-check restore and display, the display's line price, the customer-facing price rule and « Afficher aussi le prix HT », the phone's scan card | L | todo | - | |
| 118 | Count mode amendments (§ 7 2026-09-24 12:40, row 23): leave guard, the no-`stock.write` notice, « Comptage » everywhere | S | todo | - | |
| 119 | Labels (§ 7 2026-09-24 12:40, rows 24, 25): location QR on the public address and a stable path, return after sign-in, a company label format, chosen locations; barcodes at ISO/IEC 15420 proportions | M | todo | - | |
| 120 | Zakat (§ 7 2026-09-24 13:10): the second research pass (Shafi'i, Hanbali), then the module — settings, bundles, worksheet, reminder | L | todo | - | |
| 121 | Document mentions (§ 7 2026-09-24 22:51): the paid-stamp option (computed, copy only, off by default), amount in words and « Comment payer » switches, a credit note's required reason and invoice reference, COPIE and DUPLICATA marks | M | doing | - | |
| 122 | Signature boxes (§ 7 2026-09-24 22:51): delivery-note reception with réserves, quote « Bon pour accord » with « Marquer accepté » and the signed scan, the supplier order's printed approver | M | doing | - | |
| 123 | 1024 px layout (§ 7 2026-09-24 22:51): the labelled 80 px rail, a record as a sheet over its list | M | doing | - | |
| 124 | Navigation (§ 7 2026-09-24 22:51): « Caisse » and « Travaux » in the rail, « Mon compte » with four tabs absorbing the device page | S | done | d128c231 | |
| 125 | Configurable keyboard shortcuts (§ 7 2026-09-24 22:51): C, N, E, / and Ctrl K as defaults, changed and restored per person in Mon compte › Préférences | S | doing | - | |
| 126 | Signature, cachet and electronic PDF signature (§ 7 2026-09-24 22:51): research first, postponed | M | deferred | - | |
| 127 | Insights pushed once (§ 7 2026-09-24 12:10 and 2026-09-25 08:31): a scheduler (Symfony Scheduler worker in compose), a record of what was pushed per subject and bucket, and the pushes through the Inbox | L | todo | - | |
| 128 | Credit balance, write-off and crediting a paid invoice (§ 7 2026-09-21 17:35, 2026-09-25 12:45): an overpayment's excess moves to the customer's credit balance, applied to a later invoice and shown on the statement; a short-paid invoice closes on a credit note under a per-company tolerance; a credit note on a paid invoice sends what exceeds the due to a refund or to the credit balance | M | todo | - | |
| 129 | Partial invoicing of delivery notes (§ 7 2026-09-25 12:45): a quantity left to invoice per line, an invoice taking all or part, the note invoiced once nothing is left | M | todo | - | |
| 130 | A date and number format of one's own (§ 7 2026-09-25 12:45): a presentation setting, person then company, defaulting to the language and country, followed by every screen and printed document | S | todo | - | |
| 131 | What an issued document keeps (§ 7 2026-09-25 16:51, DP-05 / DP-49 / DP-60 / DP-66): name and reference frozen on the line at issue with the « Un brouillon suit les changements de l'article » setting, one typed « issu de » link replacing the separate columns with required steps as a setting, a fiscal code per product carried to the line, custom fields on documents and lines | L | todo | - | |
| 132 | Numbering (§ 7 2026-09-25 16:51, DOC-45 / MON-08 / NAV-47): drafts unnumbered, the next number editable until a series first issues then locked, never stepped back; the option « avoirs dans la suite des factures », chosen once; receipts and payments numbered where a gap is allowed; year, month and counter reset per type | M | todo | - | |
| 133 | Cancel or reverse (§ 7 2026-09-25 16:51, DOC-16): a draft is cancelled, an issued document is only reversed by a credit note; no soft delete, no restore | S | todo | - | |
| 134 | The customer's running account (§ 7 2026-09-25 16:51, MON-19 / MON-03): one account per customer that the statement, overdue and credit limit all read; a credit note always names the invoice it corrects, its excess going to the balance or a refund (row 128) | M | todo | - | |
| 135 | Reminders and late fees (§ 7 2026-09-25 16:51, DOC-20 / MON-15 / CLI-14): staged reminders; late fees off by default, per company, in tiers with no default amount until the law is sourced, charged on a separate debit document, never on the issued invoice | M | todo | - | |
| 136 | Two builders and one integrator (§ 7 2026-09-25 22:16): the builder protocol, e2e split across CI jobs | S | done | a3c59203 | .github/workflows/** |
| 137 | Effects and kinds of actions (§ 7 2026-09-25 22:16): every consequential action states its effect and whether it is annulable, corrigeable or définitif, on its button, confirmation and toast; the `data-tour` anchors on the shared components | M | done | 2118bab9 | web/src/app/shared/** |
| 138 | Practice company (§ 7 2026-09-25 22:16): a company flagged training on the real engine, watermarked, excluded from exports and declarations, reset in one click, seeded from the demo data; after row 103 | M | todo | - | api/src/** web/src/app/** |
| 139 | Premiers pas and suggestions (§ 7 2026-09-25 22:16): the first-run checklist on the home page and contextual next steps | M | done | 0e87b4a4 | web/src/app/** |
| 140 | Tours, help drawer, glossary (§ 7 2026-09-25 22:16): declared by modules as data on `data-tour` anchors, a CI check for a missing anchor, screenshots regenerated by e2e | L | todo | - | web/src/app/** web/e2e/** |
| 141 | Till hardware, France (§ 7 2026-09-25 22:16): a `ReceiptPrinter` port, ePOS-Print XML to a network printer, the drawer kick, Chrome/Edge only; a card terminal through a cloud provider later | M | todo | - | web/src/app/** api/src/** |
| 142 | The editor's attestation, France (§ 7 2026-09-25 22:16): the fiscal core versioned apart, the BOI-LETTRE-000242 attestation delivered in the product, its wording read by a lawyer; after row 103 | S | todo | - | docs/** |
| 143 | Tunisia till accreditation (§ 7 2026-09-25 22:16): lawyer or expert-comptable, the cahier des charges read, an accredited partner or our own accreditation — the developer's task | M | blocked | - | docs/fiscal/TN.md |
| 144 | Tax data without a partner (§ 7 2026-09-25 22:16): TEJ withholding certificates XML, the four French mentions, payments split by VAT rate, CA3 and Tunisian monthly worksheets | L | doing | - | api/src/** web/src/app/** |
| 145 | E-invoicing files (§ 7 2026-09-25 22:16): Factur-X and UBL (EN 16931), TEIF 1.8.8 signed; then a plateforme agréée before 2027-09-01 and TTN (row 102) | L | doing | - | api/src/** |
<!-- /progress-block -->

### Delivered
- **G0** (`4001a34`, CI green at `381d510`): orphan root; `api/` (Symfony 8.1, API Platform 4, Doctrine, `/api/health`); `web/` (Angular 22, Material, Tailwind, ngx-translate fr/en); `compose.yaml` (PostgreSQL 18, FrankenPHP, nginx, Gotenberg, Mailpit); CI with four jobs; licence, SPDX and executable-bit gates with their own tests; one Playwright smoke.
- **G1a**: three contexts, `Identity`, `Tenancy`, `Audit`, plus `Shared`, each with `Domain/`, `Application/`, `Infrastructure/` (§ 3 Architecture style; `tests/Architecture/LayerDependenciesTest` enforces the layers). Entities `user` (Identity), `company`, `role`, `membership` (Tenancy), `audit_log` (Audit) and the `sessions` table (migration `Version20260909000000`); `Email` value object through the `email` DBAL type; ports `UserRepository`, `CompanyRepository`, `RoleRepository`, `MembershipRepository`, `AuditTrail`, `PasswordHasher`, `CurrentCompany`, each with one Doctrine, Symfony or session adapter. Use cases: `RecordSuccessfulLogin`, `RecordFailedLogin` (lockout after 5, 15 min), `RecordLogout`, `ChooseWorkingCompany`, `DescribeWorkingContext`, `SeedPlatform`. Sessions in PostgreSQL through `PdoSessionHandler`; cookie `twes_session` HttpOnly, Secure, SameSite=Strict, session-scoped; id rotated at login; idle 30 min and absolute 12 h enforced per request (`app.session.*` parameters); `trusted_proxies: private_ranges`. Endpoints: `POST /api/auth/login` (json_login, argon2id 64 MiB / 3 passes, throttled, answers the `Me` shape), `POST /api/auth/logout` (204), `GET /api/auth/me` (API Platform resource built from the domain user and the working context: user, current company with role, permission strings), `GET /api/health` (in the OpenAPI document too). CSRF: Symfony stateless, `csrf-token` header on every request, origin enforced. Forced logout through a rotated `security_stamp` (`SecurityUser::isEqualTo`), deactivation ends sessions the same way. Auth events are `audit_log` rows (`auth.login`, `auth.login_failed`, `auth.logout`) with actor, address and company. `PermissionVoter` decides every permission string (`Permission` value object: `platform.*` for operators only; anything else through the membership role in the session's company, `*` wildcard); built-in roles owner / admin / member seeded by `app:seed` with the operator and the Demo company (idempotent). Migrations run in the API image's entrypoint. Web: features `auth` (`login-page`, `auth-facade` over signals, `auth-api` the only user of the generated types, `csrf-interceptor`, guards), `hello` (`hello-page`), `health` (facade + adapter for the G0 status line); TypeScript types generated from the exported OpenAPI document (`npm run api:types`, gitignored; the web image generates them from the document the api image exports at build, `compose.yaml` `additional_contexts`), fr/en parity test. nginx sends a CSP without nonces plus nosniff, Referrer-Policy and X-Frame-Options. Settings: none yet (the first company setting is G3). Playwright: unauthenticated redirect, login → hello → reload → logout, wrong password, security headers. Landed as `459a705`; `84ad791` made the web image generate its own TypeScript types from the OpenAPI document the api image exports at build, which is what a clean checkout needs, and CI run 34398270680 is green on all four jobs.
- **G1b**: companies, memberships, the switcher and invitations, in the `Tenancy` context. Entities: `company` gains its state transitions (`Company::pending()`, `activate()`, `suspend()`, all idempotent) and `invitation` is new (migration `Version20260909214300`): company, address, role, the SHA-256 of a 32-byte token, who invited, expiry, single use. Ports: `InvitationRepository`, `InvitationMailer`, `App\Identity\Application\BreachedPasswordCheck` and `App\Shared\Application\Notifications`, each with one adapter. Use cases: `CreateCompany`, `AddMember`, `RemoveMember`, `ListMembers`, `ListCompaniesOfUser`, `SwitchWorkingCompany`, `InviteToCompany`, `AcceptInvitation`, `DescribeInvitation`. Endpoints: `POST /api/companies` (platform operators only, the company starts `pending`), `GET`/`POST /api/companies/{companyId}/members` and `DELETE .../members/{userId}`, `GET /api/me/companies`, `POST /api/me/company`, and, reached logged out, `GET /api/invitations/{token}` and `POST /api/invitations/{token}/accept`. `CompanyGuard` turns every `{companyId}` in a path into a company the caller may act on and answers 404 for one that does not exist and one that is none of their business alike; a platform operator passes without a membership, because they open a company and add its first owner. Rules enforced in the domain: the first owner to join activates a pending company, a company always keeps at least one owner, an invitation is single use and expiring, inviting an address again replaces the open invitation, and an address that gained an account since being invited joins that account without its password being touched. The breached-password check refuses a breached password and, when the service cannot be reached, accepts it and writes `password.breach_check_skipped` to `audit_log`; it is off under `APP_ENV=test`. Mail is Symfony Mailer with a Twig template, translated through `api/translations/emails.{fr,en}.yaml`. Notifications go through the port to a logging adapter; the Centrifugo wire and the notification centre are G2. Web: features `company` (switcher, members page) and `invitation` (the logged-out accept page, bound to the route parameter by `withComponentInputBinding()`); the switcher renders whenever there is more than one company, labelled "choose a company" while none is chosen. Settings: none yet, the first is G3. Playwright: the switcher moving the session and surviving a reload, the member list, and the whole invitation from the mail Mailpit received to the new account signing in. A deadline a person reads is rendered in the company's timezone, not the UTC the row stores, so Acme in `Africa/Tunis` is told 23:37 where the invitation expires at 22:37 UTC. Landed as `e6c9fea` (companies, memberships, the switcher) and `c22866c` (invitations, mail, the breached-password check), with `a021c93` for the mail's timezone; CI run 34413552964 is green on all four jobs.
- **G1c**: two-step verification. TOTP: `spomky-labs/otphp`, the secret encrypted with `sodium_crypto_secretbox` under `APP_MFA_KEY` (§ 7, 2026-09-10), each code's timestep spent so none is replayed; `POST /api/auth/mfa/enrolment` returns a secret and an `otpauth://` URI (drawn as a QR code with `lean-qr`), refused with 409 while an authenticator is in force, and `POST .../enrolment/confirm` puts it in force against a code and issues ten recovery codes, stored as SHA-256 and shown once. Login: a password alone answers `mfaRequired` and leaves a pending marker, closed again at the start of any later login attempt; `POST /api/auth/mfa/verify` finishes it with a code or a recovery code (`auth.recovery_code_used`), on a budget of five attempts per account (`mfa_verify`). The recovery codes are replaced against an authenticator code (`POST /api/auth/mfa/recovery-codes`) or a passkey (`POST .../recovery-codes/passkey/options`, `POST .../recovery-codes/passkey`), never against a recovery code. Company requirement: `company.mfa_required` (migration `Version20260910074235`) at `GET`/`PUT /api/companies/{companyId}/security`, read with `company.read` and changed with `company.settings` (`company.mfa_required_changed`), evaluated over every membership of the user; an account a company requires to enrol is refused by the company endpoints until it has a factor. Passkeys: `web-auth/webauthn-lib` 5.3.8, table `passkey` (migration `Version20260915170000`); `GET`/`POST /api/auth/mfa/passkeys`, `POST .../passkeys/options`, `DELETE .../passkeys/{id}` (409 for the last factor a company requires), `POST /api/auth/mfa/passkey-login/options` and `POST .../passkey-login`; the first passkey issues the recovery codes, and `GET /api/auth/me` reports `mfa.enrolled`, `required`, `totp` and `passkeys`. Web: `/two-factor`, outside the shell so an account held back can reach it (set up an authenticator, replace the codes, list, add and remove passkeys, replace the codes with a passkey), the login's code step with "Use a passkey", and `/company/security`. Settings: `security.mfa_required` stays a company column (§ 7, 2026-09-10). Playwright: enrolment, code replacement, sign-in with a code and with a recovery code; a requiring company sending its owner to set up first; a passkey added through Chromium's virtual authenticator, signing in, replacing the codes and removed, with the WCAG check. Certified by execution: the WebAuthn validators' refusals through a PHP authenticator (challenge, origin, user verification, signature counter, another account's passkey), a sabotage of each guard the tests can tell apart, and CI. Not certified: real authenticator apps, hardware keys and platform passkeys, nor the account scope of the passkey lookup on its own, since the validators' own allowed-credential and user-handle checks refuse the same assertion if that scope goes. Landed as `59d5e1c`, `7668b53`, `0951591`, `0861fd5`, `d44d4d6`, `4f829f7` and `a89274c`; CI run 34977228038 is green.
- **G1d**: public signup and operator approval. A company that is not active is closed to its members in `CompanyGuard` and `PermissionVoter` alike (404, as for a stranger), while `GET /api/auth/me` still names it and its status; operators still reach it. Platform settings: a `platform` chain of one level holding `signup.enabled` (off) and `signup.approval_required` (on), `GET /api/platform/settings` and `PUT .../{key}` behind `platform.settings`, and a company's settings endpoint now refuses the platform level (422). Signup: table `signup` (migration `Version20260915180000`); `GET /api/signup` (open or not, and the countries with a preset), `POST /api/signup` (always 202; a single-use link valid 24 h, stored as SHA-256, or an "account exists" mail; 5 requests an hour per client, then 429, and one mail per address per 15 minutes, silently), `GET /api/signup/{token}` and `POST .../complete` (the account, the company through `CreateCompany` with its preset, the owner membership; active at once when approval is off). A link is refused once signup closes or its address gains an account. Approval: `PlatformCompanies`, `DecideCompanyApproval` and the endpoints of § 7 (2026-09-15), with the approval mail. Web: "Create an account" on the login page while signup is open, `/signup`, `/signup/{token}`, `/awaiting-approval` (where `authGuard` sends a member of a company that is not active) and `/platform` for operators (`operatorGuard`), linked from their home page. Playwright: an operator opens signup from `/platform`, somebody signs up from the login page through the Mailpit link, signs in to the waiting page, is approved and gets in; the operator closes signup again; the four new screens pass the WCAG check (an injected image without alt text reds it). Certified by execution: API functional tests (signup, company status, platform settings, approval), a sabotage of each guard the tests can tell apart (company status in both places, the platform level refusal, the approval's operator-only access, status check, idempotence, suspension and owner mail, and in the web the closed-company redirect, the signup link, the operator guard and the platform page's switches and list), web unit tests, that Playwright scenario against the local stack and CI. Not certified: the link's own used mark, since the account its first use creates refuses a second use first; the limiters' windows over real time; mail rendering in real clients; the web adapter's reading of a missing approval switch as on; and rejecting an active company, which no test drives. Landed as `c9c5dee` (company status), `8f6ed63` (platform settings), `63599e5` (signup API), `796b407` (signup pages), `c40004a` (approval API) and `268a68c` (platform page).
- **G2a**: the design system, the signed-in shell and the notification centre. Design tokens: Material 3 system colours generated at runtime from any accent hex (`@material/material-color-utilities` 0.3.0, `SchemeFidelity` — `SchemeTonalSpot` until 2026-09-14, which muted the accent), mapped into Tailwind through `@theme inline`; Inter vendored under `web/public/fonts/inter` with its OFL licence (the licence gate checks vendored fonts); Material Symbols. `ThemeFacade` (accent, light/dark, density) and `LanguageFacade` (fr/en, moves `<html lang>`). The shell: sidebar from the nav manifest filtered by permission, a drawer at phone width, skip link, company switcher, notification bell, account menu with language, theme and sign-out; every signed-in route is its child. CSP: a per-response nonce from nginx in `script-src` and `style-src`, written into `ngCspNonce`, the document served `no-store`, no `'unsafe-inline'`. Notifications: context `Inbox` (entity `inbox_item`, migration `Version20260913115212`), `InboxNotifications` implements the `Notifications` port (one row per recipient, a `company:` channel fanned out to its members, then a best-effort push through `RealtimePublisher` → `CentrifugoPublisher`), `NotificationCentre` (latest page with the unread count, mark one read, mark all read, always scoped to the recipient); endpoints `GET /api/me/notifications`, `POST /api/me/notifications/{id}/read`, `POST /api/me/notifications/read-all`, `GET /api/me/realtime-token` (HS256 connection token, `sub`, `exp`, `channels` user and working company, `app.realtime.*` parameters), documented by `InboxOpenApi`. Centrifugo v6.9.5 in compose with the `user` and `company` namespaces, browsers through nginx `/connection/websocket`, publishing from the api container only. Web: feature `notifications` (bell with unread badge and the centre in a dialog (`MatDialog`), reconnect on company switch, re-read on every publication). Settings: `app.inbox.page_size`, `app.realtime.token_lifetime`. Playwright: axe WCAG 2.1 AA in light and dark, phone width, language switch, no CSP violation, a fresh nonce per load, and a member joining reaching an already-open page with no reload. Landed as `d730652` (tokens, Inter), `acbe7ac` (shell, theme, language, nonce CSP; CI run 34755191132 green) and `a7cddb1` (the notification centre and Centrifugo).
- **G2b**: the generic list and form and the presentation settings. Settings: the `SettingsFacade` port (`web/src/app/shared/settings/`) with a registry of typed definitions, where an unregistered key can be neither read nor written and every stored value passes its parser; the `BrowserStorageSettings` adapter keeps `twes.settings.<user|anonymous>.<key>` in `localStorage` and remembers each write for the page when storage is refused. Keys: `presentation.accent`, `presentation.scheme`, `presentation.density`, `presentation.list.<id>` (hidden columns, order, widths, sort) and `presentation.list.<id>.views`. List (`shared/list/`): `ListDescriptor` with columns (sortable, filterable, hideable, hidden by default, width, alignment) and faceted filters, pure functions for visible columns, sort, text filter, filter options, pages, custom columns and saved views, and `DataList`: text and faceted filters, a column chooser (hide, reorder by dragging or with buttons, reset), resizing by pointer or keyboard, saved views (save under a name, apply, delete, the matching one marked), pages with translated labels, and empty and no-match states. Form (`shared/form/`): `FormDescriptor` (titled sections, text, email, tel, number, date, textarea and select fields with their rules), `buildFormGroup`, `fieldError`, custom fields, and `DescriptorForm`. The account menu toggles scheme and density. Members use `DataList` with a role filter. The dev-only `/design` route holds the checkpoint screens on fixture data (customers, customer form, invoice), approved on 2026-09-13. No endpoint. Playwright: a hidden column, a moved column, a saved view, dark scheme and compact density each survive a reload; the chooser is accessible at desktop and phone width with no sideways scroll; `playwright.design.config.ts` captures the 18 checkpoint screenshots with axe and overflow checks. Landed as `2d810ff`, `0481364` and `900ac11`.
- **G3a**: the fiscal groundwork for Tunisia and France. Research: `docs/fiscal/TN.md` and `docs/fiscal/FR.md`, each rule sourced, with its known gaps. Presets: `api/config/fiscal/{TN,FR}.yaml`, validated when read (`App\Fiscal\Infrastructure\Preset`), with regime labels and mentions in `api/translations/fiscal.{fr,en}.yaml`. Calculator: `api/src/Fiscal/Domain/Calculation` on `BcMath\Number` (bcmath required by `composer.json`, installed in the image and CI), held to `docs/spec/pricing-vectors.json` version 2. Entities: `tax_component` and `unit` (the company's, copied from its preset at creation) and `customer_tax_regime` (the operator's, keyed by preset and code, written by the seed), plus `company.fiscal_preset` (migration `Version20260913164031`). Ports: `TaxComponentRepository`, `UnitRepository`, `CustomerTaxRegimeRepository`, `CurrencyScales` (ISO 4217 decimals from symfony/intl, an unknown code refused). Use cases: `ProvisionCompany` (idempotent per table), `SyncCustomerTaxRegimes`, `ListCustomerTaxRegimes`, `ManageTaxComponents` and `ManageUnits` (create, revise, audited as `tax_component.*` and `unit.*`); `CreateCompany` refuses a country with no preset (422) and provisions in the same use case, and `SeedPlatform` syncs the regimes and provisions every company, so a company created before the presets gets its taxes and units on the next seed. Endpoints, behind `fiscal.read` / `fiscal.write` through `CompanyGuard`: `GET`/`POST /api/companies/{companyId}/tax-components`, `PUT .../tax-components/{componentId}`, `GET`/`POST .../units`, `PUT .../units/{unitId}`, `GET .../customer-tax-regimes` (labels in the reader's language); a duplicate code answers 409, a fiscal rule 422 naming the field. Roles: admin gains both permissions, member gains `fiscal.read`. Web: the form descriptor gains `checkbox` and `visibleWhen`; feature `fiscal` (`fiscal-api`, `fiscal-facade`, `fiscal-forms`, the taxes page with the regimes and the units page), two admin nav entries behind `fiscal.read`, add and edit behind `fiscal.write`. Settings: none; the rounding rules and numbering defaults reach a company through the settings engine at G3b. Playwright: the seeded Tunisian company lists its taxes and regimes, its owner renames the stamp and gets an amount field and no rate, and the units page lists the preset units. Landed as `08f3237` (research), `32c3921` (presets), `048feca` (calculator), `9c8859f` (tables, provisioning, endpoints), `38866d0` (screens) and `dcfc04d` (the seed provisions every company).
- **G3b**: the settings engine and the company's own setup. Settings: a `Settings` context; values in one `setting` table addressed by level, `level_id` and key (the platform level folds in § 4's `platform_setting`); definitions declared in code (`SettingDefinition`) and collected by `SettingCatalog` from every `DeclaresSettings` service; `ResolveSettings` walks a chain and skips a row its level or definition no longer accepts, `ChangeSettings` stores and forgets a value and audits company and role defaults as `setting.changed` / `setting.reset`, `ReadSetting` is how code reads a business value, and `RegisteredSettingsTest` refuses an undeclared key. Endpoints: `GET /api/companies/{companyId}/settings?chain=`, `PUT` and `DELETE .../settings/{key}`. Chains declared: presentation (accent, scheme, density, list layouts, saved views) and the first business defaults (`BusinessDefaultSettings`: payment terms, document language, printed notes, default unit, stock tracking). Web: `ApiSettings` replaces `BrowserStorageSettings` behind `SettingsFacade`, and one generic `/settings` page sets the company's defaults, rendered from the definitions (a new `colour` field kind). Company profile: columns on `company` (migration `Version20260913203000`), `GET`/`PUT /api/companies/{companyId}/profile` checked against the preset's identifiers and company VAT regimes and audited by field name, and `/company/profile`. Establishments and numbering series: tables `establishment` and `numbering_series` (migration `Version20260913213000`), a preset `establishment` node (Tunisia `000`, France `00001`), `NumberFormat` (`{YYYY}`, `{YY}`, `{MM}`, `{EST}`, one `{SEQ:n}`), `ProvisionCompany` adding the default establishment and a default series per preset document type (the seed does it for existing companies), `ManageEstablishments` and `ManageNumberingSeries`, endpoints `GET`/`POST /api/companies/{companyId}/establishments`, `PUT .../establishments/{establishmentId}`, `GET .../numbering-series` (with the next number in the company's time zone) and `PUT .../numbering-series/{seriesId}`, and the screens `/company/establishments` and `/company/numbering` (the next number shown while a format is typed). Every screen is in the admin section behind `company.settings`. Playwright: presentation choices survive a reload and a second browser context, the settings page sets and resets the payment terms, the owner fills the profile with the matricule fiscal, and renumbers delivery notes and puts them back. Not done here: the preset's rounding rules are not a setting (see § 7), and numbers are not yet allocated (G6). Landed as `d2be4a9` (engine and presentation chain), `d0f6de2` and `0cefa1f` (settings page and business defaults), `9a3ec3e` (company profile) and `703e06f` (establishments and numbering series).
- **G4**: customers, contacts, customer groups, their defaults and the company's custom fields. Customers: the first module under `src/Module/` (`Customers`); a customer has a number the company types, a kind, a tax regime from its company's preset, an optional group, the preset's registration numbers when it is a domestic business (Luhn, SIRET with La Poste's exception and the FR VAT key are preset data, applied to the company profile too), billing and shipping addresses, default taxes its regime charges, a default discount and notes; it is deactivated, never deleted. Contacts hang off a customer with one primary; a group refuses deletion while it holds customers. Tables `customer`, `contact`, `customer_group`; endpoints for groups, customers, contacts and the form's options behind `customer.read` / `customer.write`, audited by field name, never values; a schema-sync test holds the mapping to the migrations. Parties chain: the customer group and customer levels below the company (payment terms, document language, printed notes), a customer's own value winning over its group's, read and changed through the settings endpoint naming the subject, kept apart from the module by the `PartySubjects` port. Custom fields: a `CustomFields` context, table `custom_field_definition` and `customer.custom_fields` (migration `Version20260914120000`); a field is declared once per kind of record in a company (text, number, date, yes-or-no, choice), its key and type never change, it is retired, never deleted; `GET /api/companies/{companyId}/custom-fields?entity=` for every member, `POST` and `PUT .../custom-fields/{fieldId}` with `company.settings`, 409 on a taken key; `CustomFieldValues` refuses an undeclared key, an empty required field or a mistyped value as `customFields.<key>` (422), carries a retired field's value over and keeps a value resent exactly as stored. A writable list property refuses a JSON object (`Assert\Type('list')`). Web: `/customers`, `/customers/new`, `/customers/{id}` with contacts and a defaults panel, `/customers/groups`, and `/company/custom-fields`; the shared form gains a read-only mode, and `shared/custom-fields` joins the fields to the customer form (`withCustomFields`) and list (`withCustomColumns`). The shell keeps every part inside a named landmark. Playwright: a group with 45-day terms and a Tunisian business customer inheriting them, a contact, and a choice field declared in the UI, filled on a new customer and read back after a reload, each with the WCAG check. Landed as `3103534` (customers, contacts, groups), `806df9c` (group and customer defaults, shell landmarks) and `5ab6c87` (custom fields).
- **G5**: the module registry, products and product categories, and their defaults. Registry: a `ModuleRegistry` context; the catalogue is collected from every `DeclaresModule` service, a company's choices are rows in `module_state`, a dependency is enforced when a module is switched, and `DisabledModuleGuard` answers 404 for a switched-off module's resources while its data is kept. Customers became the first registered module, and the nav manifest now takes each module's entries from the registry. Products: the second module under `src/Module/` (`Products`), tables `product_category` and `product`; a category sits under another or at the top and is deleted only once empty; a product has a reference, a kind (goods or service), an optional category, a unit and default taxes from its company's fiscal set-up, a net price kept at four decimals and shown at the currency's scale, a description and custom fields (`product` joins the custom field kinds); it is deactivated, never deleted. Endpoints behind `product.read` / `product.write`: product categories, products and the form's options, a duplicate reference 409. Articles chain: the product category and product levels below the company (`article.default_unit`, `article.stock_tracking`), read and changed through the settings endpoint naming `productId` or `productCategoryId`, one subject per request whatever its chain (400), kept apart from the module by the `ArticleSubjects` port, 404 while the module is off; a category's values are forgotten with it. Web: `/modules`, `/products`, `/products/new`, `/products/{productId}` with a defaults panel, `/products/categories` with a category's defaults while it is edited, and the custom fields page switching between customers and products; a new product starts in the unit its company's `article.default_unit` resolves to. A form built in a `computed` no longer loses what was typed when its data is read again (the product and customer pages). Playwright: switching customers off and on, and a category given an hourly default unit, a service filed in it starting in hours, priced at three decimals, revised and listed, each with the WCAG check. Landed as `0dff9f7` (registry), `b3e9cc0` (products, categories) and `b97fd9b` (articles chain); CI runs 34791660418, 34795135504 and 34797116869 are green, the last on its second attempt (see Known issues).
- **G6**: numbering, delivery notes, the first domain events and the first PDF. Numbering: `AllocateNumber` takes the next number from an establishment's default series inside the transaction storing the document, the series row locked until it ends (a `Transactions` port), on the company's own day, restarting on a later year or month and refusing a day before the last number's month; a series that has numbered something keeps its next number and its establishment's code (read-only `numbered` and `codeLocked`, a per-field `readOnly` on the shared form). Delivery notes: the third module (`delivery_notes`), the first needing others (customers and products stay on while it is on); permissions `delivery_note.read`, `.write`, `.validate`; tables `delivery_note`, `delivery_note_line`, `delivery_note_line_tax`. A draft goes to an active customer from one of the company's establishments; a line delivers an active product or states what it is, with a quantity never finer than its unit counts, a net price at four decimals and the line taxes its customer's regime charges, each keeping the rate it had when written; the figures are the calculator's on every read, never stored. Validating numbers the draft in one transaction from its establishment's delivery note series, refuses a number another establishment already gave (409), takes each tax's rate again as it stands that day, keeps the customer's name and address (`customer_snapshot`) and fills an empty delivery address from the customer's shipping, else billing, address; delivering marks a validated note delivered on a day from its issue to today; cancelling withdraws a draft or a validated note, which keeps its number. Domain events: `DeliveryNoteValidated`, and `DeliveryNoteCancelled` for a validated note only (a cancelled draft records none), recorded by the aggregate and published through the `DomainEvents` port once the transaction has committed. PDF: a `Files` context (`file` table, a `FileStorage` port on Flysystem over a local volume, bytes checked against size and SHA-256 when read); a Twig layout in fr and en (`pdf` translation domain, a `decimal` filter) rendered by Gotenberg; a validated note's PDF is stored when it is validated, a draft or cancelled one renders on request under a watermark and is never stored; `GET …/delivery-notes/{id}/pdf` with `delivery_note.read`; `delivery_note.show_prices` on the parties chain; `DisabledModuleGuard` now also covers plain controllers. Web: `/delivery-notes`, `/delivery-notes/new` and `/delivery-notes/{deliveryNoteId}` with the header as a descriptor form, a lines editor where a product fills a line and the customer's regime filters the taxes, totals as saved, "validate and number" saving what is shown first, delivery on a day, a two-click cancellation and the PDF link. Playwright: a note drafted for a customer made for the run, its draft PDF, validated and numbered, read-only, its stored PDF (type, file name, `%PDF-`), delivered and listed, with the WCAG check twice. Landed as `e233739` (numbering), `4ffb95f` (drafts), `b46077e` (validate, deliver, cancel), `987ccc5` (PDF) and `468fe11` (screens), whose CI run 34843593546 is green.
- **G7**: invoices, payments, credit notes and the delivery note conversion, backend first; the invoice screens wait for the design canvas's review. Issuing numbers a draft gaplessly from its establishment's invoice series in the transaction that freezes its figures, stores the PDF it was issued with, and records `InvoiceIssued`. Payments (`POST`/`DELETE …/invoices/{id}/payments`) and credit notes (`POST …/invoices/{id}/credit-notes`, charged back as the invoice charged, never more than it still has due) lock the invoice's row while they write. `POST …/invoices/from-delivery-notes` drafts an invoice from validated or delivered notes of one customer and establishment, the two modules knowing each other only by id; issuing turns its notes `invoiced`, which the delivery note page shows as final. Decisions: the three 2026-09-15 entries in § 7, including building payments and credit notes before the conversion. Certified by execution: domain, application and functional tests with mutants on every guard (one gap found and closed), PHPStan, the web gate and CI. Not certified: the row locks under real concurrency (the `FOR UPDATE` paths run, single-threaded, never raced), PDF bytes beyond the fake renderer, and the listener's log-and-skip path against live commit ordering. Landed as `b6b5e5a` (issue), `5540c5b` and `d7febeb` (PDF), `d72a194` (payments), `ae9bbd4` (credit notes) and `92f1d6e` (conversion), whose CI run 34909239598 is green. The web screens and their Playwright scenario followed as § 8 row 10 (`b35ce69`): `web/src/app/invoices/` (list with status tabs and an on-screen overdue, one page for drafts, issued invoices and credit notes, payments), a *Facturer* action on a delivery note, the `/design` fixtures retired; certified by 830 unit tests with sabotages of the overdue rule, the refused-family document taxes and issue-after-revise, the invoice e2e against the real stack (issue, Gotenberg PDF, payment recorded and deleted, credit note leaving nothing due) and the WCAG walk in both schemes; not certified: phone layout beyond a screenshot, and the Tunisian printed wording of the credit note.
- **G10**: inventory with stock locations. The `inventory` module under `src/Module/` (`Inventory`, depending on products), tables `stock_location` and `stock_movement` (migration `Version20260915140000`). A location sits in a tree of kinds (site, building, floor, zone, rack, bin) under its establishment's default location, which is created the first time it is needed and never deleted; codes are unique per establishment, and a location goes only once it holds nothing. Stock is the sum of signed movements, never a stored figure. Use cases: `ManageStockLocations`, `KeepStock` (receipts, counts recording their difference, levels, movements) and `MoveStockForDeliveryNotes`, called by the listeners on `delivery_note.validated` (tracked goods leave the establishment's default location, once per product) and `delivery_note.cancelled` (exactly those return), both idempotent. Endpoints behind `stock.read` / `stock.write`: stock locations, stock levels, stock movements and the stock options, 404 while the module is off. Web: `/stock` (what is on hand where, with receipts and counts), `/stock/movements` (the latest, or one product's from its row) and `/stock/locations` (the tree by path), tabs under one sidebar entry. Playwright: a location filed under the default one, ten pieces received there, a delivery note of three validated through the API leaving seven and its cancellation bringing back ten, with the WCAG check. Landed as `64ef246`; CI run 34937919757 is green.
- **G8**: vendors. The `vendors` module under `src/Module/` (`Vendors`, depending on no other module), table `vendor` (migration `Version20260915150000`). A vendor has a number the company types, unique in it (409), a name and a legal name, contact details, one embedded postal address (`address_*`, in the company's country when none is given), an IBAN and BIC kept uppercase without spaces, payment terms from 0 to 365 days and notes; it is deactivated, never deleted. Its registration numbers are those the company's preset knows, checked for shape and check digits through `IdentifierRules` with no holder, so none is required. `ManageVendors` lists, reads, creates and revises, auditing `vendor.created` and `vendor.revised` with the names of the changed fields. Endpoints behind `vendor.read` / `vendor.write` (seeded for members and admins, and admins only): `vendors` (list, read, create, revise) and `vendor-options` (the company's country and the preset's identifiers), 404 while the module is off. The default expense category waits for G9 (§ 7, 2026-09-15). Web: `/vendors`, `/vendors/new` and `/vendors/{vendorId}` under one sidebar entry. Playwright: a vendor added without registration numbers with its IBAN and terms, revised and found in the list, with the WCAG check three times. Landed as `694f0b4`; CI run 34942226166 is green.
- **G9**: expenses and attachments. The `expenses` module under `src/Module/` (`Expenses`, depending on vendors), tables `expense_category`, `expense` and `attachment`, and `vendor.default_expense_category_id` (migration `Version20260915160000`). An expense moves draft → recorded → paid; recording needs a category; payment takes a method and a day between the expense's own and today; its tax is one percentage rate on the net, rounded to the currency's scale. Categories are a per-company tree with unique names. Attachments go through `Files`' `Attachments` (types read from the bytes, 10 MB, 10 per record); a draft loses one, any status gains one. Storage is local or, with `FILES_STORAGE=s3`, an S3-compatible bucket (`FilesystemFactory`, `ecbe514`). Endpoints behind `expense.read` / `expense.write`: `expenses` (list, read, create, revise, delete, `record`, `pay`), `expenses/{expenseId}/attachments` (list, upload, delete, `content`), `expense-categories` and `expense-options`, 404 while the module is off. Web: `/expenses`, `/expenses/new`, `/expenses/{expenseId}` and `/expenses/categories` under one sidebar entry with tabs. Playwright: a category added, an expense filed under it with VAT at 19 %, a PDF receipt uploaded and read back byte for byte, recorded and paid, with the WCAG check three times. Landed as `5d7fa4e`; CI run 34950720260 is green.
- **Audit rows 40–42, 27, 28, 31** (2026-09-16, before the invoice screens): stock integrity (`c44f650`: a product with stock movements keeps its unit and stays goods, `ProductStockHistory` port; a delivery note line re-checked against its unit's decimals at validation; the stock listener fail-safe, notifying `stock.write` holders, with `app:stock:replay-delivery-note`; advisory locks on counts and moves). One transaction per audited command (`33baadf`: `tests/Architecture/AuditedChangesTest` requires the `Transactions` port beside `AuditTrail`, five authentication events excepted, and `InMemoryAuditTrail` refuses a row written outside one). Web boundaries (`4d40573`: ESLint confines HTTP and the generated types to `*-api.ts`, keeps `shared/` free of features, refuses opting out of OnPush; `shared/session/Session` is the port `shared/` reads the session through, answered by `AuthFacade`). Theme changes hold transitions for one frame (`2543645`: the dark tab label was an axe reading mid-fade, the exclusion is gone). An unreadable pending authenticator refused as a wrong code, and the second-factor limiter reached again by runs of right codes (`8e93574`). Certified by execution: unit, functional and architecture tests with a sabotage per guarantee, the dark accessibility walk. Not certified: the advisory locks under two real connections, the lock order with unsorted product ids, and a notification written after a failed flush.
- **Row 35** (2026-09-16): the home page and the settings frame. Home (`a5440a6`): `GET /companies/{companyId}/invoice-summary` (`Module/Invoices/Application/SummarizeInvoices`, `invoice.read`, 404 with the module off) works out what is still to collect and how late, the aging buckets, the invoices to chase, six months of payments and the VAT invoiced this month on the company's day; the home page shows the day, the greeting and the panels modules declare in `shell/home-manifest.ts`, the invoices one laying out the summary. Settings (`3dc70e5`): a filter over the settings' and groups' names, and below the large window class the list alone at `/company`, which the gear opens on a phone, with the way back from each page. Certified by execution: the summary's rules by unit tests with four sabotages (due today not late, credit notes counted through their invoice, the company's day, VAT family only), the endpoint's access by functional tests, the panel's gating, the filter, the phone list and the gear by specs with five sabotages, and Playwright (the home page shows the API's own digits; the phone settings walk; the accessibility walk including `/company` in both schemes); CI run 35140915086. Not certified: the dark scheme by eye, the chase list and the chart rendered with data, and the settings pages other than the profile and members by eye.
- **Row 37** (2026-09-16): the top bar and the scheme (`fe2a705`, § 7 entries of 2026-09-16). `presentation.scheme` gains `auto` as its default and `presentation.language` is new (API declaration and web registry); `ThemeFacade` draws the device's scheme while the choice is Automatique; `LanguageFacade` remembers and applies the language. `SchemeMenu` and `LanguageMenu` sit in the top bar from 600 px and on every signed-out page, and fold into the account menu on a phone with the settings link, where the gear no longer shows. The bell's count is a number (9+), the search a field centred from 1440 px (a CI run caught the account button lying over the gear at 1280 px; `shell.spec.ts` now refuses any two top-bar controls overlapping at 900, 1200 and 1280 px), the sidebar ends with Paramètres, and the `appLabel` directive names every icon-only control and gives it a tooltip from the same string, enforced by `scripts/gates/icon-buttons-named.sh` (with its own test, in CI) and by the e2e walk. Certified by execution: unit tests including a Label spec that the tooltip message is the label, API settings tests, six sabotages (Automatique ignoring the device, the language not remembered, a small badge, the tooltip not set, the menus not folded on a phone, the API default back to light) each red and restored byte for byte, the gate's five cases, e2e for Automatique following an emulated device and a stored choice outliving a reload, the sign-in language surviving a reload, the dark and light walks, and screenshots of home, invoices, settings, sign-in and the menus in both schemes at 1440 px and the phone account menu at 390 px. Not certified: whether an English account sees French for an instant at sign-in before its chain answers, the sign-in page's corner menus at 390 px by eye, and the auth, invitation, company and platform e2e specs against the final tree (run locally before the last layout change; CI runs them on it).
- **Row 48** (2026-09-17): feedback (`ebf07de` and the sweep after it, § 7 entries of 2026-09-17). A request answered within 300 ms shows nothing; a longer one draws a 3 px bar under the window's top edge, and after 8 s a notice says it is taking longer than usual. No answer at all, or a gateway answering for the API, shows "Le service est momentanément injoignable. Nouvelle tentative dans N s." with Réessayer, checking health after 2, 4, 8, 16, then every 30 s, and "Connexion rétablie." when any answer arrives; offline has its own notice; a session that ends while a page is open lands on sign-in saying so. Successes are toasts (polite, 4 s), failures without a form are toasts that stay until closed, and every screen's inline outcome line became a toast. Certified by execution: unit specs for the activity service (timings, retry backoff, recovery, offline, expiry), the interceptor, the toast port and the bar; nine sabotages (no 300 ms delay, `unavailable` never cleared, a refused sign-in read as expiry, a polite toast made assertive, `SILENT` ignored, the expiry redirect removed, a revised vendor and an invited company saying nothing, the redirect not clearing its session end) each red and restored byte for byte; the gate's five cases, red on the tree before the sweep for its 24 lines; e2e for a slow request, an outage and its recovery toast, offline, and a session ending, run three times each; creation and deletion toasts asserted red before they were said, and a stale session end ignored by a new shell, red before the fix; the full e2e suite (57) locally and in CI; and screenshots of the toast at 390 px and the outage notice at 1280 px in both schemes. Not certified: the failure toast in a real screen (no screen reports a failure through the port yet: refusals stay beside their forms, and a list action's failure is row 45's), a screen reader hearing the toasts (the live region is asserted, no reader was run), the recovery path after a real API restart rather than a routed abort, and the bar's look on a slow real network.

### Blocked
### Needs input
- Should an opening-stock row also carry what the goods **cost**? A count records a quantity only, so the stock it opens has no value: a stock-valuation report would have nothing to sum. Adding a `unit_cost` column later is additive, so this is not urgent — but it is cheaper to decide before people have imported their opening balances.
- A location code is unique per **establishment**, so a company whose two sites both use "A-12" has a file that cannot say which. The import **refuses** it (`ambiguous_location`) rather than guessing. The alternative is an optional `establishment_code` column that disambiguates. Refusal was chosen because putting goods in the wrong building is worse than asking; say if a second column is preferred.
- Must the import screen make a person **preview** before importing, or may they import straight away? A preview is the same run rolled back, so it costs one extra upload and catches every rejection before anything is stored.
### Needs research
- **Tunisia's cash-register rules.** `docs/fiscal/TN.md` says nothing about cash registers, and JORT
  n° 125 has **not been read** — everything recorded about its scope, its 1 July 2026 date and its
  penalties comes from press, not from the text (§ 7, 2026-09-20 11:30). Row 103's Tunisian adapter
  is blocked on it, as is any claim about what a Tunisian till must record.
- VAT on a menu or kit whose components carry different rates, and on the same product sold on site or to go, in Tunisia and France: whether the price must be split across rates on the invoice, by which key, and whether the split is operator-owned preset data (§ 7, 2026-09-16, composite products). No rate is recorded here until it is sourced in `docs/fiscal/`.
### Fragile
### Known issues
- The stock levels list searches on `SEARCH_TEXT(p.reference, p.name, l.code, l.name)`, a four-argument expression spanning two joined tables, which `idx_product_search` (`search_text(reference, name, barcode)`) cannot serve — so every keystroke is a sequential scan. It is also the one paged list absent from `SearchIndexesTest`, which is exactly the drift that test exists to catch, and its total is counted in PHP over every product × location group. Row 55 (d).
- While the API cannot be reached, a list that failed to load still shows its empty state ("Aucun client pour l'instant.", filter counts at 0) under the outage notice and its own inline error, which reads as if the data were gone (seen 2026-09-17 on customers at 1280 px). A list needs to know its load failed and say that instead of empty; row 45 declares each screen's actions and is where the shared list gains that state. The outage notice also lies over the top bar's company switcher while it shows.
- In the dark scheme the cards barely separate from the page (seen 2026-09-16 on the home page, invoices and settings at 1440 px): the surface steps between ground, card and outline are too close. A design pass on depth, not a contrast failure (axe passes); it may be what made the design feel off to the developer.
- The interface language (`presentation.language`) and the account's `User.locale`, which the API uses for mails and option labels, are two values that can disagree: choosing English in the top bar still sends mails in French. Reconciled in row 43. Also measured-not-yet: whether a sign-in shows French for an instant before an English account's chain answers.
- `APP_SECRET` is empty in `api/.env` by design: `.env.dev` carries the Flex-generated development secret and production must set its own through the environment; the API image ships no secret.
- The PostgreSQL session handler is exercised by Playwright only (PHPUnit runs on `mock_file` storage, `framework.yaml` `when@test`); its own PDO connection sits outside dama's rollback, so a PHPUnit-level test of the handler would need its own cleanup. The idle and absolute timeouts are unit-tested against a mock session and not driven through a real clock end to end.
- With header-only CSRF the framework still emits a `Set-Cookie` clearing a `csrf-token_<value>` double-submit cookie the browser never had, on every unsafe API response (`SameOriginCsrfTokenManager` registers the name so a reverse proxy can be cleaned up). Harmless; noted so nobody hunts for the cookie's origin.
- A PUT whose body echoes the read representation with its `id` answers 400 "Cannot find object to populate, use JSON-LD or specify an IRI at path id" on every resource written with `read: false` (API Platform's item normalizer takes the `id` for an object to update). The SPA never sends one; a client that round-trips a row must drop `id`. Accepting and ignoring it would be friendlier, and is a change to every resource at once, not to one module.
- Domain events have four listeners carrying five subscriptions over three events: `delivery_note.validated` stores the issued PDF (`StoreIssuedDeliveryNotePdf`) and takes stock out (`MoveStockOnDeliveryNotes`), `delivery_note.cancelled` puts it back (the same listener, its second subscription), and `invoice.issued` stores the invoice PDF (`StoreIssuedInvoicePdf`) and marks its delivery notes invoiced (`MarkInvoicedDeliveryNotes`). `CompanyFilterLifter` is not among them: it listens on `kernel.terminate`. G1a's side effects stay direct port calls from the use cases.
- Stock under concurrency is not certified by execution. Since row 40 a count and a delivery note's moves take a PostgreSQL advisory lock per product and location inside their transaction (`StockMovementRepository::lockStockOf`, products locked in id order), which unit tests show is taken and no test shows holds under two real connections. Two first reads of an establishment's locations at the same moment can still both create its default location, the second failing on the partial unique index with a 500; rare in a single-company office, left until real use shows it.
- A delivery note whose stock move failed after validation keeps its validation (the move runs after commit): the listener logs the error and notifies every member holding `stock.write`, and the platform administrator replays it with `bin/console app:stock:replay-delivery-note <company> <delivery-note>`, which moves nothing twice (row 40). Nothing replays it automatically.
- Since row 41 an invitation and its audit row are committed before the invitation mail is sent, so a mail that fails leaves an open invitation nobody received; inviting the same address again replaces it.
- The invoice screens (row 10) keep the shared list's table at phone width, scrolled sideways, where the canvas draws cards grouped by week; phone cards are a list upgrade for every list, not an invoices one. The payment day field shows the browser's date format, as every date field does.
- A validated note whose PDF Gotenberg could not render at validation is logged and stored at its first download; nothing retries it in the background, so until someone downloads it no file exists for that note.
- Login throttling counters live in the app cache (filesystem inside the api container): two api containers would count separately. The account lockout is a column and does not have this limit.
- Expired rows in `sessions` are collected by PHP's session GC probability; no scheduled cleanup exists yet.
- CI first went green at `381d510` (run 2; run 1 failed on executables committed as 100644, fixed there). Only a dev image has been built; no production image exists yet.
- Breached-password check (spec § 3 Auth) shipped with G1b: `BreachedPasswordCheck` with an HIBP range adapter (`HibpBreachedPasswordCheck`), refusing a breached password wherever one is first set — invitation acceptance (`AcceptInvitation`) and signup completion (`CompleteSignup`). G1a's only passwords were seeded, which is why it waited.
- Centrifugo's HTTP API is reachable only inside the compose network, so a notification published by a host-side process (a console command run outside the api container) keeps its row and logs a failed push. By design (§ 7, 2026-09-13).
- CI run 34797116869's first attempt failed one e2e step, `products.spec.ts:79` (a just-created category's row not visible within 5 s), a step unchanged since `b3e9cc0` and green in that commit's CI; its second attempt passed, as did 32 of 32 locally and the scenario repeated three times. No trace survived: the failure upload pointed at `web/playwright-report`, which the dot reporter never writes, so it now uploads `web/test-results` too. It recurred in `987ccc5`'s run 34838768678, and its trace showed the category form with an empty name: the categories read that follows opening the form rebuilt it over what was typed. Fixed in `468fe11` (the form is a `linkedSignal` that keeps the typed values while the same category is edited). The establishments page has the same shape (its descriptor reads the establishments list for the code pattern), so a form opened before the page's first read of the list has finished is rebuilt over what was typed, the categories race again; no e2e has hit it yet.
- Locally, with other projects loading the machine, Playwright's 5-second waits failed a different test on two of three full runs (sign-in URL, then a translated heading after reload); each passed alone, the API answered in 0.1–0.26 s and the stack's containers were idle. CI is the arbiter.
- Passkeys are certified against Chromium's virtual authenticator and a PHP test authenticator only; no hardware key, phone or platform authenticator has been tried. A passkey belongs to its relying party id: a deployment on another domain sets `APP_WEBAUTHN_RP_ID` and `APP_WEBAUTHN_ORIGINS`, and a passkey registered under one id never works under another.
- The second-factor budget (`mfa_verify`, five attempts) is per account and shared by the login code, the passkey login and both replacements of the recovery codes, so failed replacements from a session also hold back that account's next login step until the window passes.
- Companies the company e2e scenario opened before `3b38493` never got an owner and stay pending in a long-lived development database (`Globex …`, no owners); every run since gives its companies an owner who accepts, which activates them. Signup e2e runs leave one account and one approved company each, and a sixth signup request from one client within an hour answers 429, which a local rerun loop can reach.
- A member of two companies whose working company stops being active is sent to `/awaiting-approval`, which sits outside the shell and so offers no company switcher, only a sign-out. Switching company from that page is not built.
- Operators list every company on `/platform` but decide only on pending ones there: reactivating a rejected (suspended) company still means approving it through the API.
- Notification fan-out includes the person the notification is about: accepting an invitation adds the membership and then publishes to the company channel, so the new member's own centre says they joined. Excluding them needs an actor on `Notification`, a port change deferred until a second event needs it.
- From the POC review (2026-09-15, `var/claude/poc-review/`), not scheduled: a partial credit note copies its invoice's whole document discount and fixed charges (D3); a stored `invoice_line.line_gross` mixes the net before the document discount with the tax after it, so the lines do not add up to `total_gross` (D5); a full credit note's per-line shares of a tied allocation are not the mirror of its invoice's (D6); a credit-note draft cannot be revised once its customer's regime excludes a tax it copied (D7); the stock listeners check idempotence outside their transaction and nothing replays a failed one (D8); an invoice drafted from delivery notes takes the issue day's rates, not the delivery's (D10).
- From the same review, not scheduled: after five wrong passwords the login answer tells an existing account (`account_locked`) from an unknown one (S7); while signup is open, completing it tells whether a company name is taken (S8); `trusted_proxies: private_ranges` lets any private hop set the client address that the limiters and the audit log read (S9); the settings page has no `file` field and no screen writes a document-level setting (C14); the product name is compiled into the web bundle (C15, RAISED 2026-09-15).
- The home page's six-month chart and its list of invoices to chase are certified by unit tests and the e2e digits check, and were looked at rendered only empty (the development company had nothing late and nothing collected this month); the dark scheme of the home page was not looked at.
- An invoice issued with nothing due stays `issued`, and no payment can move it (the POC review's D9): § 7 (2026-09-14) orders the status rule with nothing paid or credited first, so it is not `paid`. Changing it means changing that ruling, not the code.
- An open invitation cannot be withdrawn: inviting the same address again replaces it, and it otherwise lapses at its expiry. The members list shows it until then (review row 18, `4a9da33`).
- `membership.added` is no longer published (a member joins by accepting an invitation, `invitation.accepted`); the web keeps its type and translation only so stored notifications still read.
- Ending an account's sessions or deactivating it (C3, `9f5c817`) signs it out at its next API request; whether an already open realtime connection is cut at once is not certified, and no e2e asserts the bell for `invitation.received`.
- The seeded development operator's authenticator secret is fixed and public (`Makefile`, CI), like its password: a database seeded that way is a development one. A local Playwright rerun within five minutes of the last can meet the operator's `mfa_verify` budget (three code checks a run), and so can signing in by hand: `make operator-code` prints the current code rather than guessing one. Enrolling at first sign-in is certified for a company's owner (`two-factor.spec.ts`), not for an operator seeded without a secret.
- The company filter is turned on per request by `CompanyGuard` and lifted at `kernel.terminate`. The API runs PHP in classic mode; FrankenPHP worker mode, which `infra/api/Dockerfile` names as a later step, would need it lifted when the kernel resets too. What a request reads before it acts for a company (the module guard, the second-factor listener) is not filtered, and names its company itself.
