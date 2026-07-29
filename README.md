# twes-in

An invoicing and billing platform — **Symfony** REST API, **Angular** admin web client, and a
**Flutter** client for mobile with native desktop support planned, over **PostgreSQL**.

> **Status: greenfield.** As of 2026-07-29 this repository contains planning documents, licensing, and
> the Claude Code tooling bundle. **There is no application code yet.** Everything below describes the
> target, not the present. Start with `docs/plans/reimplementation-strategy.plan.md`.

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
| Client | Flutter **3.44.8**, Dart **3.12.2** — mobile now, native desktop planned |
| Database | PostgreSQL **18.4** |

Versions are pinned exactly, not floated — a reproducible build is a precondition for trusting a money
calculation. See `docs/plans/reimplementation-strategy.plan.md` § "Pinned stack".

## Architecture

**TDD, DDD, hexagonal and clean architecture**, with a **framework-free domain layer**: no Symfony, no
Doctrine, no I/O and no ambient clock or randomness inside `Domain/`, and Doctrine mapping in XML rather
than attributes on entities. Money is a first-class value object in integer minor units with an explicit
rounding mode on every lossy operation — never a float. Multi-tenant scoping is a **default-on** Doctrine
filter, so it cannot be forgotten. See [CLAUDE.md § "Architecture"](CLAUDE.md).

## Repository map

| Path | What |
|---|---|
| `CLAUDE.md` | The rules for how code is delivered here — quality gates, licensing invariants, architecture. |
| `VISION.md` | Direction that is explicitly **not** a commitment. |
| `LICENSING.md` · `THIRD-PARTY-NOTICES.md` | The dual licence and every dependency's licence. |
| `docs/plans/build-waves.plan.md` | The wave-by-wave build plan and what is deliberately out of scope. |
| `docs/plans/pricing-and-documents.plan.md` | Profit-rate pricing, delivery notes, and the generic charge model. |
| `docs/plans/*.plan.md` | Plans, each with its own dated `## Decisions Log`. |
| `api/` · `admin/` · `mobile/` · `infra/` | The four tiers — **not yet created**. Symfony API, Angular admin, Flutter client, and deployment written from scratch. |
| `.claude/` | Repo-native Claude Code skills and reviewer agents, read in place. |
| `scripts/claude-bootstrap/` | The reasoning framework, installed into an ephemeral `~/.claude` at session start. |

## Contributing

There is no CLA yet, and **a CLA is required before any outside contribution can be accepted** — without
one, merging a patch would silently forfeit the commercial licence for the affected files
(`LICENSING.md`). Issues and discussion are welcome in the meantime.
