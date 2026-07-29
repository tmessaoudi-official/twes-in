# twes-in

An invoicing and billing platform — **Symfony** REST API, **Angular** admin web client, and a
**Flutter** client for **all six targets** — Android, iOS, Linux, Windows, macOS and Web — over
**PostgreSQL**. Flutter Web means twes-in ships **two admin interfaces**, Flutter and Angular.

> **Status: early.** Wave 0 has landed — the `Money` value object, the profit-rate arithmetic, the
> multi-tenant isolation seam, and six architecture/licensing gates, under `api/`. The Symfony
> application itself, Doctrine and the two client tiers do **not** exist yet. Read
> `docs/plans/build-waves.plan.md` for exactly what is and is not built.

## Licence — dual

twes-in is **dual-licensed**:

- **[AGPL-3.0-or-later](LICENSE)** for everyone. Use it, modify it, redistribute it. AGPL §13 means
  that if you run a modified version and let users interact with it over a network, you must offer
  those users the corresponding source.
- **A commercial licence**, available from the copyright holder, for anyone who needs to embed or host
  twes-in without AGPL's source-disclosure obligation.

Copyright © Takieddine MESSAOUDI. Read **[LICENSING.md](LICENSING.md)** before adding a dependency or
accepting a contribution — the dual licence imposes two rules that are easy to break by accident:
**dependencies must be permissive** (MIT / Apache-2.0 / BSD-2-Clause / BSD-3-Clause / ISC — *not* merely "AGPL-compatible", since
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
Doctrine, no I/O and no ambient clock or randomness inside `Domain/`, and Doctrine mapping in XML rather
than attributes on entities. Money is a first-class value object carrying its currency's own
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
| `api/` | The Symfony API. **Wave 0 landed**: `Domain/` (money, pricing), `Infrastructure/` (tenancy, clock, ids), four test suites. No HTTP layer or Doctrine yet. |
| `admin/` · `mobile/` · `infra/` | Angular admin (Wave 8), Flutter client (Wave 11), deployment written from scratch (Wave 12). Each README lists the tests and enforcers it owes as gate conditions. |
| `scripts/gates/` | The six architecture and licensing gates, plus their own test suite. |
| `docs/spec/pricing-vectors.json` | The pricing arithmetic every tier tests against, so three implementations cannot drift. |
| `.claude/` | Repo-native Claude Code skills and reviewer agents, read in place. |
| `scripts/claude-bootstrap/` | The reasoning framework, installed into an ephemeral `~/.claude` at session start. |

## Contributing

There is no CLA yet, and **a CLA is required before any outside contribution can be accepted** — without
one, merging a patch would silently forfeit the commercial licence for the affected files
(`LICENSING.md`). Issues and discussion are welcome in the meantime.
