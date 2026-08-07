# twes-in

An invoicing and billing platform — **Symfony** REST API, **Angular** admin web client, and a
**Flutter** client for **all six targets** — Android, iOS, Linux, Windows, macOS and Web — over
**PostgreSQL**. Flutter Web means twes-in ships **two admin interfaces**, Flutter and Angular.

> **Status: early.** Wave 0 has landed but is **not yet certified** — the `Money` value object, the
> profit-rate arithmetic, the multi-tenant isolation seam, and the architecture/licensing gates, under `api/`.
> **Wave 1's pure domain has landed too**: the document calculation kernel (line nets, VAT grouped by rate,
> fixed charges, totals), the generic Draft→Issued→Cancelled lifecycle, per-type numbering, and the `Invoice`
> aggregate — all framework-free, which is why they could land first.
> **The Symfony application, Doctrine and the first migration have now landed too** (2026-08-01): `api/src/Kernel.php`,
> `api/bin/console`, `api/config/**`, the attribute-mapped persistence model, `api/migrations/`, and
> `scripts/gates/schema-tenancy.php`, which asserts every tenant-owned table in a real migrated schema is
> row-level-security-enabled, forced, canonically policed and out of the runtime role's reach. This paragraph said
> they were "blocked on GitHub egress" until then; that diagnosis was wrong — see `CLAUDE.md` § Gotchas.
> **`admin/` and `mobile/` are scaffolded**, each with its own official generator (`ng new`,
> `flutter create`), each green on its own toolchain, and each carrying the branding seam and — for Flutter —
> the font/same-origin controls its own invariants demanded, with tests. Neither holds **domain or transport**
> code yet: no invoicing, no models, no API client.
> **The API's HTTP surface, its Doctrine repository and the `infra/` tier have ALL landed since**, and this paragraph
> denied each of them for several commits while the paragraph three lines above announced Doctrine — two contradictory
> statements in one file, which the second certification round filed as a P0. `api/src/UI/` holds the invoice resource,
> its state provider and both write processors; `api/src/Infrastructure/Persistence/` holds `DoctrineInvoiceRepository`,
> `InvoiceMapper` and the gapless number counter; `infra/` holds three Dockerfiles, three compose files, a Caddyfile,
> an entrypoint and a database init script, and both stacks have been run end to end.
> **Wave 1 is NOT complete**, and its own scope line says why: Client, contacts, Product and the tenant settings table
> do not exist. Read `docs/plans/build-waves.plan.md` for exactly what is and is not built — and trust that file over
> this paragraph, which is a summary and will drift again.

## Licence — dual

twes-in is **dual-licensed**:

- **[AGPL-3.0-or-later](LICENSE)** for everyone. Use it, modify it, redistribute it. AGPL §13 means
  that if you run a modified version and let users interact with it over a network, you must offer
  those users the corresponding source.
- **A commercial licence**, available from the copyright holder, for anyone who needs to embed or host
  twes-in without AGPL's source-disclosure obligation.

Copyright © Takieddine MESSAOUDI. Read **[LICENSING.md](LICENSING.md)** before adding a dependency or
accepting a contribution — the dual licence imposes two rules that are easy to break by accident:
**dependencies must be permissive** — for anything distributed the permitted set is exactly
  MIT, Apache-2.0, BSD-2-Clause, BSD-3-Clause, ISC, 0BSD, MIT-0, CC0-1.0, BlueOak-1.0.0. A dev-only
  dependency may additionally carry CC-BY-4.0 or CC-BY-3.0, and only as build-time data that is never shipped;
  a vendored font asset may carry OFL-1.1 — *not* merely "AGPL-compatible", since
a copyleft dependency would destroy the commercial branch), and **no outside contribution can be merged
without a CLA**. Every dependency is recorded in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

## Relationship to Invoice Ninja

twes-in is a **clean-room reimplementation inspired by [Invoice Ninja](https://github.com/invoiceninja/invoiceninja)**
— never a fork, never a port, and **no upstream code is present in this repository in any form**.

That distinction is legal rather than stylistic. Invoice Ninja's backend and web UI are under the
**Elastic License 2.0**, which prohibits providing the software to third parties as a hosted service;
and a translation of copyrighted code into another framework is still a derivative work, so "rewriting
it in Symfony" from their source would carry the same encumbrance. twes-in is therefore built from
**the published API contract, the database schema shape, observable behaviour, and the public standards**
an invoicing product must implement (EN 16931, UBL, CII, Factur-X, Peppol BIS) — none of which is
copyrightable expression. The rules that keep it that way are in
[CLAUDE.md § "Licensing invariants"](CLAUDE.md).

## Planned stack

| Tier | Pinned |
|---|---|
| API | PHP **8.5.8**, Symfony **8.1.1** |
| Admin | Angular **22.0.8**, Node **26.5.0** |
| Client | Flutter **3.44.8**, Dart **3.12.2** — Android, iOS, Linux, Windows, macOS, Web |
| Database | PostgreSQL **18.4** |

Versions are pinned exactly, not floated — a reproducible build is a precondition for trusting a money
calculation. See `docs/plans/reimplementation-strategy.plan.md` § "Pinned stack".

## Architecture

**TDD, DDD, hexagonal and clean architecture**, with a **framework-free domain layer**: no Symfony, no
Doctrine, no I/O and no ambient clock or randomness inside `Domain/`, and persistence as a **separate model**
in `Infrastructure/` — ordinary mutable Doctrine entities mapped with attributes, plus a repository that
translates to and from the domain aggregate. (This read *"Doctrine mapping in XML rather than attributes on
entities"* until 2026-08-05; the 2026-08-01 ruling reversed it, because a `final readonly` aggregate is
something Doctrine's identity map cannot track whichever driver is used — the driver was the wrong argument,
the model boundary was the right one.) Money is a first-class value object carrying its currency's own
decimal scale, with an explicit rounding mode on every lossy operation — never a float, and never a
2-decimal assumption, because the default currency (TND) has **three**. The arithmetic is `bcmath`, so
`Domain/` has **zero Composer dependencies** — enforced by a gate, not by convention.

Multi-tenant scoping is **PostgreSQL row-level security**, applied by the server to every statement
whatever issued it — a Doctrine filter would be bypassed by a native query, a migration or a `psql`
session. An unbound connection sees **nothing**, not everything. See
[CLAUDE.md § "Architecture"](CLAUDE.md).

## Repository map

| Path | What |
|---|---|
| `CLAUDE.md` | The rules for how code is delivered here — quality gates, licensing invariants, architecture. |
| `VISION.md` | Direction that is explicitly **not** a commitment. |
| `LICENSING.md` · `THIRD-PARTY-NOTICES.md` | The dual licence and every dependency's licence. |
| `docs/plans/build-waves.plan.md` | The wave-by-wave build plan and what is deliberately out of scope. |
| `docs/plans/pricing-and-documents.plan.md` | Profit-rate pricing, delivery notes, and the generic charge model. |
| `docs/plans/*.plan.md` | Plans, each with its own dated `## Decisions Log`. |
| `api/` | The Symfony API. **Wave 0 landed** (`Domain/Money`, `Domain/Pricing`, `Infrastructure/` — tenancy, clock, ids) **plus most of Wave 1**: `Domain/Document` (the calculation kernel, lifecycle, numbering, the `Invoice` aggregate), the Symfony application, Doctrine and the first migrations, the persistence adapter, and the invoice HTTP surface — `GET /api/invoices/{id}`, `POST /api/invoices`, `POST /api/invoices/{id}/issue`. Four test suites. **Still owed in Wave 1: Client (+ contacts), Product, and the tenant settings table.** |
| `admin/` · `mobile/` | Angular admin (Wave 8), Flutter client (Wave 11). Scaffolded and green on their own toolchains; neither holds domain or transport code. Each README lists the tests and enforcers it owes as gate conditions. |
| `infra/` | Deployment, **written from scratch** — never copied from `invoiceninja/dockerfiles`, which is GPL-2.0 (licensing invariant 7). Three Dockerfiles, three compose files, a Caddyfile, an entrypoint and a database init script; both the development and the production stack have been run end to end. Wave 12 still owes CI. |
| `scripts/gates/` | The architecture, licensing and shell-syntax gates, plus their own test suite. `ls` it for the list — a count written in prose drifts. |
| `docs/spec/pricing-vectors.json` | The pricing arithmetic every tier tests against, so three implementations cannot drift. |
| `.claude/` | Repo-native Claude Code skills and reviewer agents, read in place. |
| `scripts/claude-bootstrap/` | The reasoning framework, installed into an ephemeral `~/.claude` at session start. |

## Contributing

There is no CLA yet, and **a CLA is required before any outside contribution can be accepted** — without
one, merging a patch would silently forfeit the commercial licence for the affected files
(`LICENSING.md`). Issues and discussion are welcome in the meantime.
