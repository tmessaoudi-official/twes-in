---
name: twes-lenses
description: >
  MANDATORY companion to every global review skill run in twes-in. Load this BEFORE running
  /sweep, /sleuth, /inspect, /gaps, /forge, /cross-check, /converge, /pre-commit or
  /aggregate-findings here — it carries the twes-in review dimensions (money, tax, tenancy,
  e-invoicing, state machines), sleuth lens K (cross-surface divergence), and the repo
  conventions those global skills do not know about. Extracted 2026-08-18 from the deleted
  repo-local copies of those skills (global-is-reference ruling: a repo may not duplicate a
  global skill; what was repo-specific in them lives here instead).
---

# /twes-lenses — twes-in review dimensions & conventions

This skill adds no procedure of its own. It is the **domain payload** for the global review
skills: run the global skill for its machinery, with everything below folded into its scope.

## Repo conventions (apply to every review skill)

- **Reports live in the repo**: `var/claude/<skill>/` (gitignored). Never `~/.claude/projects/…`.
- **Non-blocking closes — no interrupts.** End with the findings and a plainly-stated offer
  (`N findings (P0:a P1:b P2:c) — say which to fix`), never a blocking question.
- **`/converge` runs the ladder** at the tier CLAUDE.md § "Certification ladder" mandates —
  the three lenses are the repo agents: `domain-correctness-reviewer`,
  `tenancy-security-reviewer`, `completeness-reviewer` (`.claude/agents/`).
- **Project scope only.** `~/.claude/` is the developer's own persistent install, out of this
  repo's audit scope — audit it from its own sessions, not from here.

## Review dimensions — MANDATORY additions to any sweep/review of this repo

Run these **in addition to** the global skill's own dimensions, on every review. Skip a dimension
only when the tree it applies to genuinely does not exist yet — and then **name the dimensions you
skipped and why**. A silently skipped dimension is a coverage lie.

- **Money arithmetic (P0).** Amounts are money, not numbers: a decimal string over bcmath end to end, via the Money value object (never integer minor units, never a decimal library — see CLAUDE.md § Architecture),
  never a binary float, and never a float that only *looks* right because the test picked friendly
  inputs. One rounding helper, one rounding mode, applied at one documented point. Any new total,
  discount, surcharge or tax path needs a unit test whose naive-float form is provably wrong (three
  lines of 9.99 at 20% is a good needle: 6.00 per line vs 5.99 per rate group).
- **Tax and e-invoicing correctness (P0).** VAT is computed per rate group, with exemption /
  reverse-charge reasons carried rather than inferred; multi-currency documents use the rate stored on
  the document, never "today's" rate. A change that claims EN16931 / Peppol / Factur-X validity must be
  **validated against a schema or business-rule set**, not asserted in prose — an unvalidated claim is
  the same untested-promise failure mode, and it rots the same way.
- **Invoice / quote / credit state machine (P0).** Every status change goes through the one transition
  guard. A handler that assigns status directly — a bulk action, a recurring-billing job, a gateway
  webhook, a fixture — is the bug even when the resulting status happens to be right. Issued documents
  are append-only in accounting terms: number, date and totals do not mutate; a credit note is how you
  disagree with a past invoice.
- **Multi-tenant isolation (P0 — this is a data breach, not a bug).** Every query, aggregate, export,
  file/PDF fetch, webhook payload and background job must carry the tenant filter its neighbours carry.
  A deliberately cross-tenant path (platform admin, billing reconciliation) must say in code *why* it is
  exempt. Treat any doubt as P0.
- **Payment and gateway safety (P0).** Webhook handlers are signature-verified and idempotent — a replayed
  event must not double-apply a payment. No card data, gateway secret or customer PII in logs, exception
  messages, error payloads or fixtures. Partial payment, over-payment and refund paths must leave
  `balance == total − sum(applied payments)` true by construction, not by a recompute job.
- **API contract stability.** The Symfony API has two independently-shipped consumers: the Angular admin
  and the Flutter app. A changed response shape, field type, enum value, error code, pagination contract
  or date/number serialisation is a **breaking change** until both consumers are updated or the change is
  shown to be purely additive. Say which of the two it is; do not leave it implied.
- **Migration safety.** A migration is reversible or explicitly declared one-way with the reason stated.
  A backfill over money or tax columns states the recompute rule and the rollback plan. Never edit a
  migration that has already been applied anywhere — add a new one.
- **Visual surface ⇒ delivered evidence.** twes-in's visual surfaces are the **Angular admin UI**, the
  **Flutter app UI**, and the **rendered PDF** of an invoice / quote / credit. A change to any of them
  needs (a) an automated guard in the stack that owns it — derive the runner from `package.json` or
  `pubspec.yaml` rather than assuming one — AND (b) a before/after capture.
  **CAPTURED IS NOT DELIVERED: send it with `SendUserFile` in the same turn you take it.** `var/` is
  gitignored, so a screenshot or a rendered PDF left on disk is evidence the developer never sees, and
  a claim resting on it is unevidenced.
- **Anti-bandaid gate.** For every `||` fallback, `2>/dev/null`, `|| true`, `try {} catch {}` that
  continues, error trap, retry loop, timeout bump or default-value assignment introduced: state the exact
  failure mode, the *physical* evidence that confirmed it (log, measurement, trace, test output), and
  whether the root cause is fixed. No evidence ⇒ **P0**, replace it with a root-cause fix.

## Sleuth lens K — MANDATORY additional agent for /sleuth

Beyond the global skill's agents A–J, always run **agent K** on this repo, and report its findings
as category **K** alongside A–J:

> **K — Cross-surface divergence.** twes-in computes the same accounting truth in more than one place —
> the Symfony API, the Angular admin, the Flutter app, the generated PDF, the e-invoicing document, and
> the database schema — and every one of those is a chance for two answers to disagree. Hunt for places
> they can:
> **(1) Money/tax recompute divergence** — totals, tax or balance computed in the API and *recomputed*
> anywhere else for display (Angular, Flutter, a PDF template, a report query). A second implementation
> of rounding is a divergence by construction; find which one is authoritative and whether anything says so.
> **(2) State-machine divergence** — a status transition allowed by the entity/service guard but reachable
> another way (a bulk endpoint, a recurring-billing job, a gateway webhook, a fixture), or forbidden in one
> path and silently permitted in another. Look for statuses assigned by direct write.
> **(3) Tenant-scoping divergence** — a query, aggregate, export, attachment fetch or queued job that omits
> the tenant filter its neighbours apply. This is an information leak, not a cosmetic bug: treat any doubt
> as the highest severity you have.
> **(4) Payment ↔ document divergence** — partial payment, over-payment, refund or a replayed gateway
> webhook leaving stored balance/status disagreeing with the sum of applied payments; a handler that is not
> idempotent under redelivery.
> **(5) E-invoicing divergence** — a Peppol / EN16931 / Factur-X document whose VAT breakdown, currency,
> rounding or totals disagree with the stored invoice, or whose validity is asserted but never validated
> against a schema or business-rule set.
> **(6) Migration ↔ model divergence** — a migration (or its down path) that disagrees with the mapping it
> is supposed to implement: a nullability, precision/scale, default, unique or FK constraint present in one
> and not the other. Money columns with the wrong scale are silent corruption.
> For each: file + line, which two surfaces diverge, the smallest invoice/payment fixture that would show
> it, and whether a test guards it in the stack that owns it — read `composer.json` / `package.json` /
> `pubspec.yaml` for the runner rather than assuming one. If that stack is not in the tree yet, say so;
> if it is and no test covers the divergence, that absence IS the finding.
> Research only, no writes.
