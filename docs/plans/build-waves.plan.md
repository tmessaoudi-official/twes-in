# Build waves Plan

The full build, sliced into waves, with a **certification review by the three lenses at every wave
boundary** (developer ruling, 2026-07-29). Written before Wave 1 starts so the shape is clear and
scope arrives deliberately rather than by accident.

**Nothing here is implemented.** Read `reimplementation-strategy.plan.md` first — it holds the
licensing invariants, the pinned stack and the tenancy design this plan assumes.

## Decisions Log

- [2026-07-29 13:40] AGREED: build in **waves**, each independently reviewable, each ending in a
  MAXIMAL certification round (three lenses, two consecutive clean rounds, cap 5 → ask). A wave is
  not "done" until its gate is green *and* the panel converges on it.
- [2026-07-29 13:40] AGREED: the wave list below is the **baseline**, not the final scope. The
  developer has feature changes and additions to fold in; those are gathered **before Wave 1** and
  this file is amended in the same change, so no wave is built against a stale scope.
- [2026-07-29 13:40] AGREED: **Wave 0 exists and is not optional.** The seams decided in it — money
  type, tenancy strategy, ID format, error shape, layer boundaries — are the ones that cannot be
  retrofitted without touching everything. Every later wave assumes them.
- [2026-07-29 13:45] AGREED: certification tier per wave is **MAXIMAL** for any wave touching money,
  tax, tenancy, migrations, payments or e-invoicing — which is most of them. Documentation-only
  changes between waves get a single pass. See `CLAUDE.md` § "Certification ladder".

---

## How a wave works

Every wave follows the same loop, and none of it is optional:

1. **State the slice** — what is in, what is explicitly out, and the acceptance criteria.
2. **Write the failing tests first.** Money, tax and state transitions get their test before their
   implementation, every time. This is where this product's expensive bugs live.
3. **Build** to the architecture rules in `CLAUDE.md` § "Architecture" — framework-free `Domain/`,
   Doctrine mapping in XML, dependencies inward only.
4. **Run the tier gate** — `CLAUDE.md` § "Quality gate". Green means all of it, not most of it.
5. **Certification round** — the three lenses (`domain-correctness-reviewer`,
   `tenancy-security-reviewer`, `completeness-reviewer`) against the **frozen** wave commit. Findings
   → fix → re-round. Two consecutive clean rounds, cap 5 → ask in plain text.
6. **Record** rulings in this file's Decisions Log, gotchas in `CLAUDE.md` § "Gotchas".

**Freeze before you certify.** A panel round run against a moving tree cannot count toward the
two-clean requirement — learned the hard way during the bundle integration, where four rounds were
spent partly because the tree changed under the reviewer.

---

## Wave 0 — Foundations (the seams that cannot be retrofitted)

**In:** repo skeleton (`api/`, `admin/`, `mobile/`, `infra/`) · Symfony 8.1.1 on PHP 8.5.8 · the
hexagonal layer layout · **`Money` value object** over `NUMERIC(19,4)` with explicit rounding on every
lossy operation · **`TenantContext` + `TenantIsolationStrategy`** seam with Mode A (shared DB,
default-on Doctrine filter) implemented · UUIDv7 IDs · RFC 9457 error shape · Doctrine Migrations ·
PHPUnit · **the architecture-fitness tooling that `CLAUDE.md` currently records as owed**: `deptrac`
for the inward-only rule, a PHPStan banned-function rule for ambient `time()`/`random_int()`/`getenv()`
in `Domain/`, and grep gates for `#[ORM\` under `Domain/` and for the SPDX header.

**Also in Wave 0, because the developer ruled them first-version and they are cross-cutting:** the
**i18n/l10n scaffolding** (locale catalogues with mechanically-checked key parity, locale-aware
formatting that honours each currency's own scale) and the **a11y harness** (`axe-core` in the admin
gate, semantics tests for Flutter). Both are cheap now and expensive to retrofit across every screen
and template later. `docs/spec/pricing-vectors.json` also lands here — the shared arithmetic fixture all
three tiers test against.

**Out:** any entity beyond what the money and tenancy tests need. No invoices yet.

**Acceptance:** `Money` arithmetic is exhaustively tested including repeating decimals and a
three-decimal currency; a cross-tenant read is proven impossible by a test that *fails* when the filter
is disabled; `deptrac` fails on a deliberately-introduced outward `use`; the SPDX gate fails on a file
missing its header. **Every one of those four is a test that must be watched failing first.**

**Why this is Wave 0 and not part of Wave 1:** the two P0 classes for this product — wrong money and
cross-tenant reads — are decided here. `CLAUDE.md` § Gotchas records both as day-zero rulings
precisely because they are unfixable later.

## Wave 1 — Client & the invoice core

**In:** Client (+ contacts) · Invoice with line items · the **calculation kernel** (line totals,
discounts, taxes, document totals) as **one parameterised implementation** — inclusive vs exclusive tax
is a *flag*, never a parallel class hierarchy · invoice state machine behind a **transition guard**, no
status written by assignment · numbering with per-tenant counters.

**Out:** quotes, credits, payments, PDF, e-invoicing.

**Acceptance:** the rounding order is deliberate, documented and tested (`round(sum(x))` vs
`sum(round(x))` on the same fixture); no illegal transition is reachable; a paid invoice cannot be
edited.

## Wave 2 — Quotes, credits & the shared document machinery

**In:** Quote · Credit · conversion (quote → invoice) · the shared document abstraction. **Full-set
coverage is the theme:** anything true of Invoice must be true of Quote and Credit, or explicitly not.

**Acceptance:** a calculation change applied to one document type is proven applied to all three — the
single most common finding class on the completeness lens.

## Wave 3 — Payments

**In:** Payment · partial payments · overpayment · applying a credit · refunds (full and partial) · one
payment split across several invoices · balance recomputed rather than incrementally adjusted.

**Out:** gateways. This wave is the arithmetic and the ledger only.

**Acceptance:** `sum(applied) <= payment.amount` and `balance = total - sum(applied)` hold after every
operation; no drift after a long sequence of partial payments and refunds.

## Wave 4 — PDF documents

**In:** the rendering pipeline · one template · a placeholder/variable contract · `live_preview` for an
**unsaved** entity (a real backend feature, not a client one).

**Acceptance:** the stored total, the number on the PDF and the number a validator recomputes all
agree. **Visual evidence delivered with `SendUserFile` in the same turn** — a PDF is a legal document
and no unit test looks at the pixels.

## Wave 5 — Tax & e-invoicing (France AND Tunisia, both in this wave)

**RULED 2026-07-29: both jurisdictions ship together.** This resolves the inconsistency flagged earlier
(the wave originally said "France first", written before TND and the Tunisian stamp duty were ruled).

Doing two jurisdictions at once is **more work than one but less than one-then-another**, and that is the
argument for it: two live jurisdictions from the start force the tax layer to be genuinely generic
instead of France-shaped with Tunisia bolted on. They differ in exactly the ways that expose a bad
abstraction — different VAT rates and bands, a fixed stamp duty in one and not the other, different
e-invoicing formats and transports, different currencies (EUR 2 decimals, TND 3), and different
languages. If the charge engine and the format layer survive both, they will survive the third.

**In, regardless of order:** the generic charge engine from `pricing-and-documents.plan.md` (per-line and
per-document VAT at differing rates, plus fixed absolute charges) · reverse charge · exempt vs
zero-rated, which are legally distinct and routinely conflated in code · one jurisdiction's rules and one
e-invoicing format, done properly end to end.

**Out:** every other jurisdiction and format. Deferred deliberately — and having done two, adding a
third should be configuration plus a format adapter, never a rewrite. If it is not, the abstraction is
wrong and that is the signal to stop and fix it.

**Acceptance:** generated XML passes a real schema/schematron validation; the PDF and the XML never
disagree on a figure.

## Wave 6 — Recurring billing

**In:** recurring invoices · the scheduler · timezone and DST handling · month-end dates (Jan 31 → Feb)
· **idempotency** — a scheduler that runs twice must not bill twice.

## Wave 7 — Auth, permissions & the API contract

**In:** authentication · API tokens (hashed at rest, constant-time comparison) · Symfony Voters over a
real `role_permissions` table · the OpenAPI spec as the contract SSOT · contract tests pinning the
shape.

**Note:** this is where the API contract becomes load-bearing. After the Flutter client ships, the
contract is frozen for anyone who has not updated.

## Wave 8 — Angular admin

**In:** the admin client over the Wave 7 contract · a generic entity-config service rather than
per-entity duplication · typed client generated from or checked against the OpenAPI spec.

**Acceptance:** visual evidence delivered, not just captured.

## Wave 9 — Payment gateways

**In:** Stripe · SEPA direct debit. Tokenization, webhooks with **signature verification and
idempotency**, refunds, SCA.

**Out:** the other ~19 gateways.

## Wave 10 — Client portal

**In:** view and pay, unauthenticated by link. **Treated as hostile surface:** unguessable expiring
tokens, rate limiting, and no field rendered that should not be.

## Wave 11 — Flutter client

**In:** written from scratch, 100% ours (licensing invariant 3). Mobile first; native desktop later.

## Wave 12 — Infra & CI

**In:** `infra/` written from scratch — Dockerfiles, compose, deployment. CI mirroring the quality
gate tier by tier, every job commented with why it exists and what breaks without it.

**Note:** the *topology* (php-fpm + nginx + db + redis + queue + scheduler + headless Chrome) is an
idea and free to reuse; upstream's **files** are GPL-2.0 and must never be copied.

---

## Explicitly out of scope for the first release

Recorded so it is a decision and not a silence: the other ~19 payment gateways · 7 of 8 e-invoicing
standards · 35 of 36 tax jurisdictions · bank feeds and transaction matching · QuickBooks sync · the
subscription/payment-link product · the visual template editor · e-signature · Elasticsearch · the
report builder · tasks/projects/expenses · purchase orders · vendors · 43 of 45 locales · database
sharding.

Upstream reached ~344k lines of backend PHP over twelve years; matching that is **25–40 person-years**.
The waves above are roughly **18–30 person-months**. Every item above is deferrable and none is
forgotten.

## Developer feature additions (2026-07-29)

Baseline behaviour is *"what Invoice Ninja does"* — legitimate, because behaviour and functionality are
not copyrightable; only expression is (licensing invariant 2). These four are **additions or
modifications on top of that baseline**, to be specced in detail when their wave starts.

**F1 — Merge several invoices of the same client into one.** Same client is a hard precondition.
Detailed spec at Wave 2 (shared document machinery). *Open decision: which invoice states may be
merged — see below.*

**F2 — Create an invoice from a quote, if one does not already exist.** One-to-one link, so converting
twice does not silently produce a second invoice; the second attempt returns the existing one.
Wave 2.

**F3 — Create a delivery note from an invoice while the invoice is still a draft.** Draft-only is a
deliberate constraint from the developer. *Open decision: separate document type or a rendering — see
below.* Wave 2, or Wave 4 if it turns out to be a rendering concern.

**F4 — Profit rate on the product, so the selling price is computed from cost + profit rate + VAT.**
This is the most arithmetic-sensitive addition in the list and has **no upstream behaviour to inherit**
— it is genuinely new, so every rule for it is ours to decide. Wave 1, alongside the calculation
kernel, because it feeds line pricing. Non-negotiable regardless of the open decisions: the **computed
selling price is snapshotted onto the invoice line** at issue time. A later change to a product's cost
or profit rate must never retroactively alter an issued document.

## Awaiting the developer

Five open decisions, listed in the session brief: the profit-rate formula (markup vs margin), which
invoice states may be merged, whether a delivery note is its own document type, whether multi-currency
is in the first release, and the VAT rounding point. **Wave 0 is unaffected by all five** — its seams
are scope-independent — so it can start before they are answered.
