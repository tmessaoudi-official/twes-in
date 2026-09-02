# twes-in — the specification

> **This file is the single source of truth for WHAT twes-in is, what has been ruled, what exists
> today and what is still owed.** `CLAUDE.md` carries the rules for HOW work is delivered here and
> points at this file; where the two disagree about the product, this file wins.
>
> The five plan files that preceded it are archived VERBATIM under `docs/archive/plans/`, with a
> two-sided disposition of every dated ruling in `docs/archive/plans/RECONCILIATION.md`. Nothing in
> the archive is current unless this file carries it.

---

## 0. How to read — and how to amend — this spec

These are not style preferences. Each one is a defect this project actually shipped, and the
archive records the round that found it.

1. **Never write a count beside the thing it counts.** *"fourteen gates"*, *"nine roles"*,
   *"57 keys"* — every one of those was written here and every one went stale, usually in the same
   commit that changed the thing. Write the derivation instead: `ls scripts/gates/` minus `lib/`,
   `grep -c '^        CREATE ROLE' scripts/dev/provision-test-database.sh`. A prescribed derivation
   that returns a wrong answer is worse than a prose count, so check the derivation too.
2. **Correct in place; never append a correction below a false statement.** A reader who meets the
   original sentence first has no way to know a later paragraph reverses it. Edit the original.
3. **Grade every claim a reader might act on** — `[Verified: <what you ran, what it returned>]`,
   `[Inferred: <evidence>]`, `[Unverified: <why not>]`, `[Speculative]`. A bare `[Verified]` is
   theatre. A citation whose numbers move with the tree states the DIRECTION, not the total.
4. **A control asserted in prose is not a control.** Any sentence here claiming something is
   enforced must name the gate, test or migration that enforces it. If nothing does, say so.
5. **"Cannot be done" is banned.** Say *"not done — here is what it would take"*. Four impossibility
   claims in this project were refuted within minutes of somebody trying them, and each hid a real
   defect.
6. **This spec describes the code AS IT IS, defects included.** Where a shipped implementation is
   wrong, the wrongness is stated here and carries an entry in § 8. Never describe the intended
   state as though it were the current one.
7. **Do not restate the permitted-licence identifiers in this file.** `LICENSING.md` and
   `THIRD-PARTY-NOTICES.md` are where the list lives, `CLAUDE.md` § "Licensing invariants" is where
   Claude must read it, and `scripts/gates/test-gates.sh` asserts an exact inventory of the
   documents that state it. Adding the list here silently adds a seventh surface and turns that
   gate red. Point at those files.
8. **A multi-commit deliverable is recorded as PART-LANDED between its commits, never struck whole
   the moment any of it ships.** [AGREED 2026-08-22] This is the defect the archived record catches
   itself committing repeatedly: a "still owed" line struck at the first commit of three, or a
   heading claiming completion 45 lines above its own retraction. Strike an item in the change that
   delivers it, not beside it.
9. **§ 10 is the ONE live Decisions Log.** Per-plan logs are gone. A ruling made anywhere lands
   there in the same change, dated, one sentence.

---

## 1. What twes-in is

An invoicing / billing platform: a **Symfony REST API**, an **Angular admin web client**, and a
**Flutter** client for all six targets — Android, iOS, Linux, Windows, macOS and Web. PostgreSQL
underneath. The Flutter Web build is a **second admin interface** alongside `admin/`, deliberately.

It runs **both as the developer's own internal invoicing and as a product sold to others**
[RULED 2026-07-29], which is what makes the clean-room boundary load-bearing rather than tidy.

**It is a clean-room reimplementation inspired by Invoice Ninja — never a fork, never a port.** That
distinction is legal, not stylistic. The full set of licensing invariants lives in `CLAUDE.md`
§ "Licensing invariants" (where Claude must read them before writing code) and in `LICENSING.md`;
they are pointed at here, not restated, per § 0 rule 7. The three that shape the product itself:

- Build from the **contract and the behaviour**, never from the source. Legitimate inputs are the
  published OpenAPI specification, the schema shape, documented field names, the standards an
  invoicing product must implement (EN 16931, UBL, CII, Factur-X, Peppol BIS), and observable
  behaviour. [RULED 2026-07-29]
- **No exception for the Flutter client** — it is written from scratch too, so the Attribution
  Assurance License's every-launch attribution duty never attaches. [RULED 2026-07-29]
- **All branding is ours and configurable from day one.** Hostname, product name, logo and e-mail
  identity are configuration; a later public deployment is a config change, not a code change.
  [RULED 2026-07-29] Seams: `admin/src/app/branding.ts`, `mobile/lib/branding.dart`.

twes-in itself is **AGPL-3.0-or-later plus a commercial licence**, copyright wholly Takieddine
MESSAOUDI. [RULED 2026-07-29] Every dependency must be permissive and recorded in
`THIRD-PARTY-NOTICES.md` in the change that adds it; "AGPL-compatible" is the wrong test, because a
copyleft dependency satisfies the AGPL branch and kills the commercial one.

**Scope is the whole decision, not stack choice.** [AGREED 2026-07-29] Upstream is ~344k LOC of
hand-written backend PHP, 71 live tables, ~508 endpoints, 91 entities, ~21 payment gateways, 8
e-invoicing standards. A narrow, well-chosen scope is achievable; "recreate Invoice Ninja" is not.
**Symfony and Angular are both sound choices and neither is the risk** — recorded so the stack is not
re-litigated. The risks are scope, the API contract, and the two irreducible domain problems:
money/tax arithmetic, and e-invoicing.

**E-signature is not in scope.** [RULED 2026-07-29] It was only ever attractive as a pre-built React
package, and it is excluded on both grounds — no source to own, and no Angular path.

A future rewrite of the core in **phorj** is **vision, not a target** [CORRECTION 2026-07-29,
developer: *"the phorj is not for now! it's in the vision! i did not finish the language"*]. Nothing
here is designed for it; it may never be cited as a reason to defer a decision. See `VISION.md`.

---

## 2. Architecture invariants

**TDD, DDD, hexagonal, clean** [RULED 2026-07-29] — "really structured/scalable and flawless and
exemplary". A billing domain is the canonical case: the rules that matter (money arithmetic, tax,
state transitions) must be testable in isolation and must outlive every framework decision around
them.

### The domain layer is pure — the load-bearing rule

In `Domain/`:

- **No framework and no ambient I/O.** No Symfony, no Doctrine, no HTTP, no SQL, no filesystem — and
  no clock, no randomness, no environment. Time and randomness arrive through ports.
  **The detection differs by kind**, which is why two gates exist rather than one: a framework
  dependency arrives as a `use` statement, but `time()`, `random_int()`, `getenv()` and
  `file_get_contents()` are bare calls with no import at all. `layer-dependencies.php` sees the
  first; `no-ambient-calls-in-domain.php` sees the second. Merging them would blind the result.
- **Zero Composer dependencies.** Arithmetic is `bcmath`, a PHP extension, so it adds no package.
  Enforced by `layer-dependencies.php` with an empty allowlist. [RULED 2026-07-29]
- **No Doctrine mapping on a domain type. Persistence uses a SEPARATE MUTABLE MODEL in
  `Infrastructure/Persistence/Doctrine/Entity/`, mapped with ATTRIBUTES, and a repository
  translates.** [RULED 2026-08-01, reversing an earlier XML-mapping ruling] The reason is NOT that
  attributes couple a class to Doctrine at runtime — they do not [Verified: an attribute-mapped
  class instantiates and runs with no Doctrine autoloadable at all]. The real reason applies to
  attributes and XML equally: every domain type here is `final readonly` with a private constructor
  and mutators that `return new self(...)`, while Doctrine's ORM is an identity map holding one
  MUTABLE instance per row, diffed against a snapshot. Mapping the aggregate directly is
  insert-only and fights the ORM whichever driver you pick. Accepted cost: a mapper per aggregate,
  paid down by a round-trip contract test. `#[ORM\` under `Domain/` is a P0, enforced by
  `no-orm-attributes-in-domain.sh`.
- **Dependencies point inward.** `Domain` knows nothing; `Application` knows `Domain`;
  `Infrastructure` and `UI` know both. An outward `use` from `Domain/` is a P0.
- **Ports are domain interfaces; adapters implement them in `Infrastructure/`.** A repository
  interface belongs beside the aggregate it serves. Note a `Domain/` port gets **no auto-alias** —
  Symfony aliases an interface to its implementation only when the interface is in an autowired
  resource, and `Twes\Domain\` is deliberately excluded, so every port needs an explicit binding in
  `services.yaml`. [FOUND 2026-08-06]
- **Conservative, strongly-typed PHP.** `declare(strict_types=1)` everywhere, explicit types on
  every parameter, property and return. No magic, no dynamic properties, no arrays where a value
  object belongs.

### Repository layout — monorepo

Four tiers, so one commit can change the API and every consumer together. That is what makes the
API-contract rule in § 4 enforceable at all. [RULED 2026-07-29; `infra/` added at the developer's
catch]

```
api/          # Symfony REST API
admin/        # Angular admin web client
mobile/       # Flutter client -- all six targets; the Web build is a SECOND admin interface
infra/        # Dockerfiles, compose, deployment -- WRITTEN FROM SCRATCH (invoiceninja/dockerfiles is GPL-2.0)
docs/         # SPEC.md (this file), spec/pricing-vectors.json, archive/
```

```
api/src/
  Domain/          # entities, value objects, domain events, port interfaces. ZERO dependencies.
  Application/     # use cases / command + query handlers. Depends only on Domain.
  Infrastructure/  # Doctrine, HTTP clients, PDF, gateways, e-invoicing, the attribute-mapped
                   #   persistence model and its mappers, tenancy, clock, identifiers.
  UI/              # REST resources, state providers and processors, CLI commands, serializers.
```

### The Symfony ecosystem is the ONLY vocabulary

**Never transliterate a Laravel/Eloquent pattern.** [RULED 2026-07-31] Invariant 1 forbids upstream
*code*; this forbids upstream *shape*, and it binds even where copyright does not. We legitimately
learn behaviour from Invoice Ninja and that behaviour is expressed in Laravel idiom, so every time a
behaviour is understood the question is *"what is the Symfony-ecosystem way to do this?"* A
transliterated pattern is how a Symfony codebase ends up fighting its own framework — and how a
clean-room build starts to LOOK like a port even though no line was copied.

| Laravel / Eloquent | What we use instead | Why it is not a straight swap |
|---|---|---|
| Eloquent Active Record | **Doctrine ORM, data-mapper**: a framework-free aggregate plus an attribute-mapped persistence model | This is the whole reason `Domain/` can be pure |
| Global/local **scopes** for tenancy | **PostgreSQL row-level security** first, a Doctrine filter second | A scope is opt-in per query and a filter is bypassed by native SQL; RLS is applied by the server to every statement |
| **Laravel migrations** | **Doctrine Migrations** + `scripts/gates/schema-tenancy.php` | No migration framework enforces the RLS statements a tenant-owned table needs |
| **Eloquent pagination** | **API Platform's pagination extension** | Upstream's `per_page=999999` is rejected outright; a hand-rolled limit/offset per endpoint drifts |
| **Policies / Gates** | **Symfony Voters** over a real `role_permissions` table | Permissions are DATA — an administrator must change them without a deploy |
| **Laravel throttling** | **`symfony/rate-limiter`** | Wave 10 |
| **Form Requests** / `Validator` facade | **Symfony Validator** on DTOs at the boundary — and the domain still refuses invalid state itself | Validation at the edge is message quality; the invariant lives in the value object. Both, not either |
| **API Resources** / `toArray()` | **API Platform** resources + Symfony Serializer | The contract is declared, not assembled per endpoint |
| **Eloquent events / observers** | **Doctrine events** for persistence, **domain events** for business | Business meaning in a persistence hook is how tax logic fires on a flush |
| **Facades** (`DB::`, `Cache::`) | **Constructor-injected services**, always | Ambient access is forbidden in `Domain/` outright |
| **Jobs / Horizon** | **Symfony Messenger** | |
| **Blade** for documents | the PDF pipeline (Wave 4); Twig where a template engine is wanted | |
| **Artisan commands** | **Symfony Console** | |
| `Carbon` | our `ClockInterface` port; `DateTimeImmutable` behind it | Ambient time is a P0 in `Domain/` |
| **Sanctum / Passport** | Symfony Security (Wave 7) | |

A pattern met that has no row gets one in the same change.

---

## 3. Domain rulings in force

Each of these is **unfixable once data exists**, which is why they are rulings and not preferences.

### Money

- **Money is never a float.** [RULED 2026-07-29] Our own `Money` value object, immutable, carrying
  an amount plus its currency, with an explicit rounding mode on every lossy operation.
  `Money::of()` rejects a `float` by signature — but note the signature ACCEPTS `float` on purpose,
  so the body can reject it with a message; without it PHP's union coercion turns `19.99` into `19`.
  **Read the body, not the signature.**
- **Arithmetic is `bcmath`.** Scaled integers were rejected (`NUMERIC(19,4)` scaled to
  ten-thousandths reaches 10^19 and PHP integers become floats past 9.22×10^18); `brick/math` was
  rejected as unnecessary once bcmath does the exact arithmetic. bcmath truncates, so all rounding
  lives in `Domain/Shared/Decimal::applyRounding()`, tested across all eight modes including
  negative ties.
- **The default currency is TND, which has THREE decimal places.** [FOUND 2026-07-29 — "the most
  consequential fact in this spec"] 1 dinar = 1000 millimes, so a 2-decimal assumption is a bug for
  the DEFAULT currency, not an edge case. No `round($x, 2)`, no `× 100` to reach minor units, no
  "cents" in a name or comment. Tunisia's stamp duty of `0.100 TND` must represent exactly.
  [Verified: ISO 4217 — TND, BHD, JOD, KWD, OMR, LYD, IQD are the 3-decimal set.]
- **Column type is `NUMERIC(19,4)` plus a companion `currency` column on every persisted amount** —
  a `Money` is *(amount, currency)*, so a bare NUMERIC cannot reconstitute one.
- **The exchange rate is captured at document date and stored with the document**, never
  re-derived. [RULED 2026-07-29] An issued invoice's total must never change because a rate moved.

### Tax, discounts and rounding

- **Tax, discounts and rounding order are ONE implementation, never two.** Inclusive versus
  exclusive is a *parameter*, not a parallel class hierarchy.
- **A PER-LINE VAT figure is required** [RULED 2026-07-31, developer: *"i still need a per line
  vat"*], which forces an allocation rule: under `PerRateGroup` the group's VAT is rounded ONCE on
  the summed base, so the rounded per-line figures do not add up to it.
- **The allocation rule is LARGEST REMAINDER, TIES TO THE EARLIEST LINE**, with the invariant that
  the shares sum EXACTLY to the group VAT. "Put the difference on the last line" was rejected
  because it makes document ORDER significant to a tax figure.
- **Allocation applies under `PerRateGroup` ONLY.** Under `PerLine` the group VAT is by
  construction the sum of the per-line rounded figures, so allocating there moves tax onto a line
  that does not owe it.
- **The allocator floors with `Floor`, never `Down`** — truncation toward zero is not a floor for a
  negative amount, and Wave 2's credit note reaches that path.
- **A LINE discount reduces the VAT base.** [AGREED 2026-08-23]
- **A DOCUMENT-level discount is allocated PRO-RATA BY BASE across rate groups.** [AGREED 2026-08-23]
- **INCLUSIVE tax extracts PER RATE GROUP**, reusing `VatRoundingPoint` rather than adding a second
  axis. [AGREED 2026-08-23] On the half-grid tie the **NET is rounded half-up and the VAT absorbs
  the residue** (0.111 TND inclusive of 20% → net 0.093, VAT 0.018), mirroring the exclusive path
  where the net is the authored figure. [AGREED 2026-08-23 11:15] Unreachable at a Tunisian rate;
  binds from Wave 5.
- **A client may not choose `vatRoundingPoint`.** [AGREED 2026-08-07] The two settings produce
  numerically different tax, so a per-request choice would be a client choosing how much tax a
  document declares. It is a tenant setting, read server-side.
- **VAT applies per line AND per document, plus fixed charges.** [RULED 2026-07-29] Fixed
  absolute-amount charges (stamp duty) are document-scope charges in the same generic model —
  configuration, never a special case in code.

`docs/spec/pricing-vectors.json` is the cross-tier arithmetic fixture that stops Angular, Flutter
and PHP inventing three rules. **Every `document_totals` case DECLARES its `vat_rounding_point`**
(`per_rate_group` or `per_line`) and the expected figures belong to that point — closed 2026-09-02,
R5K-3. It did not, until then: every vector was computed under `per_rate_group` and none of them
said so, while the divergence column was named `..._which_is_WRONG`, so the cross-tier SSOT asserted
that a ruled setting produces a wrong answer. **Both points are correct for their own setting**, and
the fixture now carries a case under each. Consumers must read the declared point rather than
assuming one — the API tier's `DocumentTotalsTest::declaredPoint()` is the reference, and hard-coding
a point is pinned as a mutant.

### Pricing and products

- **A profit rate is pure arithmetic; a NEGATIVE SELLING PRICE is refused at the aggregate.**
  [RULED 2026-07-30] Three parts: `Rate` keeps no bound at −100% (a rate is a dimensionless number
  and `Rate` is the wrong place to encode what a *document* may contain); `ProductPricing` REFUSES
  a derived net price below zero; the negative-tie vector MOVES TO WAVE 2's Credit.
- **Profit rate is bidirectional, last-edited-wins** [RULED 2026-07-29], and **only the AUTHORED
  field is stored** — a product persists `authored_by` (`profit_rate` or `net_price`) and the typed
  field is **never recomputed**. A cost change preserves the RATE and moves the PRICE. `Rate`
  carries 12 fraction decimals. Enforced by a CHECK, not by discipline. [RULED 2026-07-29 17:40]
- **`Product` WRAPS `ProductPricing` and re-expresses none of that arithmetic.** [AGREED 2026-08-22]
  What it adds is identity, a name, an optional non-unique SKU, and the **VAT rate**, which pricing
  genuinely does not carry (a foodstuff and a service are taxed differently inside one company).
- **The SKU is optional and NOT unique.** [AGREED 2026-08-22] Uniqueness needs an answer for what a
  collision returns and whether it is scoped to non-deleted rows; inventing that as a side effect
  of shipping an aggregate is how a rule nobody ruled ends up in a CHECK constraint.

### Documents

- **A document number sequence is GAPLESS, so a PostgreSQL `SEQUENCE` / `nextval()` is FORBIDDEN.**
  [RULED 2026-07-31] A sequence is deliberately non-transactional, so every rolled-back issue burns
  a number and leaves a permanent hole — which a tax authority reads as a suppressed sale.
  `SERIAL`, `IDENTITY` and any `CACHE n` fall to the same objection. The shape is a
  per-`(tenant, type)` counter **row**, taken inside the transaction that persists the document.
  Accepted cost: issues for one `(tenant, type)` serialise.
- **The counter is ONE atomic statement, not `SELECT … FOR UPDATE`.** [AGREED 2026-08-07]
  `INSERT … ON CONFLICT (…) DO UPDATE SET next_value = <table>.next_value + 1 RETURNING
  next_value - 1`. The three-statement form was correct **only because of a lock nothing could
  observe** — deleting ` FOR UPDATE` left the whole suite green twice. There is no
  between-statements window to protect, so serialisation becomes a property of the statement.
- **A document number is WRITE-ONCE.** [AGREED 2026-08-07] Two concurrent issues of one draft each
  act on a stale read, both take a number, and the second save renumbers the first — leaving a
  number allocated to no document. Two mechanisms, and the ORDER of their importance is the
  finding: a row lock (`findForMutation()`, taken BEFORE the allocation, which also fixes ONE lock
  order for every writer — document then counter — making deadlock impossible); and, decisively,
  `save()`'s statement-level refusal. If the stored row already carries a number, `save()` issues a
  **state-only `UPDATE` whose every other column is a PREDICATE** and never touches the children.
  The predicate is about the NUMBER and deliberately not about the STATE, which is what lets
  `cancel()` still save.
- **The RENDERED number is persisted alongside the sequence**, so no later setting can restate a
  legal document a client already holds. [AGREED 2026-08-01] Implementing it REMOVED a dependency
  rather than adding a column: `NumberPattern::format()` left-zero-pads and grows rather than
  truncating, so for every *(width, sequence)* pair the stored string determines a pattern that
  re-renders it byte-identically [Verified: 48 of 48 combinations]. `InvoiceMapper` is therefore
  stateless. The derivation is CHECKED with one `!==`, not trusted. What is not recovered is the
  authored width for a sequence that outran its padding — and it does not matter, because every
  width from 1 to 5 renders `12345` identically. [AGREED 2026-08-06]
- **An "enum" column is `VARCHAR(32)` + `CHECK`, never a native PostgreSQL enum type.** [AGREED
  2026-08-01] `doctrine_migrations.transactional` is true, and PostgreSQL refuses to add an enum
  value and use it in the same transaction — so a native type would force every future
  `DocumentType` addition (Wave 2's Quote and Credit among them) into a two-migration dance.
- **State transitions go through a guard, never a direct assignment.** A status written by
  assignment is how illegal transitions enter a billing system.
- **`DocumentState` is a CLOSED set — Draft, Issued, Cancelled.** [RECORDED 2026-07-31] One of five
  Wave 1 domain decisions that existed only in a commit message until a certification round found
  none of them in any Decisions Log — which is the reason this spec has a single log at § 10, and
  the reason a ruling must land in the same change as the code. The others: discounts and
  inclusive-vs-exclusive tax were DEFERRED **to Wave 2** with a named destination, because no fixture
  specified them and inventing money numbers is the one thing this domain must not do.
- **`Invoice::issue()`'s two failure causes get distinct exception types.** [AGREED 2026-07-31]
- **A DRAFT may have no client; an ISSUED document may not.** [AGREED 2026-08-22] EN 16931 makes
  the buyer mandatory (BT-44), so the requirement attaches to the TRANSITION rather than to the
  type — the empty-lines guard's sibling. Encoded as CHECK `document_client_required_once_issued`,
  shipped `NOT VALID`: documents issued before the column existed carry no client, no correct value
  can be invented, and destroying an issued invoice is unthinkable, so they are grandfathered and
  every INSERT and UPDATE from now on is enforced.
- **`Invoice` holds a client ID and never a `Client` object.** [AGREED 2026-08-22] One aggregate
  references another by identity — deliberately UNLIKE the money rule, where an amount is copied BY
  VALUE because it must never move under an issued document. A client's address may legitimately be
  corrected, so the document names it and does not copy it.
- **The foreign key RESTRICTS and does not cascade.** [AGREED 2026-08-22] Deleting a client must
  never erase the invoices addressed to them.
- **Merging invoices: drafts only.** [RULED 2026-07-29] Anything carrying a real invoice number
  cannot be merged in either direction — an issued numbered invoice is undone by a credit note,
  never deleted. A merge touching a numbered invoice is a `domain-correctness-reviewer` P0.
- **Delivery notes are PERSISTENT, independently numbered documents**, not ephemeral children of an
  invoice. [RULED 2026-07-29] Draft (previewable, mutable, unnumbered) → Issued (numbered,
  immutable, PDF stored). **A re-download returns the STORED BYTES, never a re-render.**
- **`FixedCharge::MAX_LABEL_LENGTH = 64`, measured in CHARACTERS** — a DERIVED bound rather than a
  ruled one. [AGREED 2026-08-07] It was the last persisted value in the domain with no bound at
  either end, and an unbounded label is a persisted value nothing refuses.
- **The migrations' CHECK constraints must be asserted by a test.** [FOUND 2026-08-06] Five of them
  went unasserted from the first migration. Lower risk than the usual unenforced-control shape —
  PostgreSQL cannot quietly stop applying a CHECK — but an unasserted constraint is one nobody
  notices being dropped. **Partially addressed and NOT verified closed: see § 8.**
- **A contact is an ENTITY with its own id; a document line stays POSITIONAL.** [AGREED 2026-08-22]
  The difference is referenceability: nothing ever refers to a line.
- **The client field set is DERIVED from EN 16931 — BG-7, BG-8, BG-9 — and the country code is
  validated by SHAPE only.** [AGREED 2026-08-22] Every jurisdiction spells a tax identifier
  differently, so length is the only refusal there is.
- **Quantity representation is NOT stable across a save/reload; only its VALUE is.** [AGREED
  2026-08-01] `NUMERIC(21,6)` returns `2.000000` for a stored `2`. Assert the property, not the
  representation.

### Tenancy

- **Tenant isolation is PostgreSQL row-level security, not (only) a Doctrine filter.** [RULED
  2026-07-29, superseding the original filter prescription] The requirement was that forgetting must
  be *impossible*; a filter scopes queries the ORM builds and is bypassed by a native query, a
  migration, a reporting job or a `psql` session. RLS is applied by the server to every statement
  whatever issued it. Three things silently defeat it, and all three are checked rather than
  assumed: a **superuser or `BYPASSRLS`** role; the **table's owner**, unless the table also has
  `FORCE ROW LEVEL SECURITY`; and a **session-scoped `SET`** on a pooled connection — which is why
  binding uses transaction-local `set_config(..., true)`.
- **The policy compares against `current_setting('twes.tenant_id', true)`, which is NULL when
  unset, so an unbound session sees NOTHING rather than everything.** That fail-closed direction is
  the whole design.
- **Tenancy is AMBIENT CONTEXT, not a field.** [AGREED 2026-07-31, reversing round 13's
  prescription] Round 13 proposed moving `TenantId` into `Domain/` so `DocumentNumber` could carry
  it. That contradicted `TenantId`'s own invariant, would have ended the database-per-tenant mode
  the `TenantIsolationStrategy` seam exists to allow, and — decisively — **does not stop at
  `DocumentNumber`**: if a tenant must sit inside a value object for equality to be safe, it must
  sit inside `Invoice`, `DocumentLine` and `Money` too. A field every type needs is not a field.
  The remedy is a **boundary rule: no tenant-less path may hydrate a domain aggregate**, which is
  strictly stronger because it also stops the cross-tenant total, PDF and export.
- **The `InvoiceRepository` port takes NO tenant argument** [AGREED 2026-08-06], correcting a ruling
  that was **not buildable**: `TenantId` lives in `Infrastructure/`, so a `Domain/` port naming it
  is an outward dependency and a P0. The adapter is constructed with the request's `TenantContext`
  and refuses when none is bound — stronger than a parameter, because a parameter is satisfied by
  whatever tenant id the caller happens to hold, including the wrong one.
- **A tenant-less request CLEARS the context rather than leaving it alone.** [AGREED 2026-08-07]
  The most important line in the seam: `InMemoryTenantContext` is a service, so a stale binding
  would otherwise survive into the next request.
- **Both isolation modes are in scope** — one shared database with tenant scoping, AND
  database-per-tenant, selectable by configuration. [OPEN→ruled 2026-07-29] `TenantIsolationStrategy`
  is that seam.
- **`save()` REFUSES outside an active transaction rather than opening one.** [AGREED 2026-08-06] A
  document number is gapless, so allocating one and persisting the document must commit together or
  not at all.
- **`save()` writes with DBAL, not through the UnitOfWork** — a measurement, not a preference.
  [AGREED 2026-08-06] A whole-rewrite of child rows through the `EntityManager` raises
  `EntityIdentityCollisionException` from the identity map **before any SQL is emitted**. It is
  impossible, not merely slow.
- **The port declares no `delete()` and no query methods.** [AGREED 2026-08-06] An issued document
  is `cancel()`ed, never deleted.
- **The savepoint re-check is driven from the DBAL seam that emits the savepoint**, not from
  repository code. [AGREED 2026-08-06] Two findings shape it: `RELEASE SAVEPOINT` does **not**
  revert a transaction-local setting, so only the ROLLBACK half matters; and a FULL rollback must
  be EXCLUDED, because it discards the binding legitimately on every rolled-back request — a check
  there would throw on correct code and the first person to hit it would delete the middleware.
  PostgreSQL accepts **four** spellings and the `SAVEPOINT` keyword is optional, so the predicate is
  derived from the GRAMMAR rather than matching Doctrine's own spelling.
- **`TenantBindingMiddleware` is the call site**, on the DBAL driver's `beginTransaction()` — the
  one seam that fires exactly once per real transaction. Every other candidate was eliminated by
  the fact that `bind()` uses `SET LOCAL` semantics and refuses outside a transaction. **A READ
  needs a transaction too**: without one the query is issued unbound and a tenant's own document is
  invisible.
- **The acquire-time provisioning guards run ONCE PER (ROLE, DATABASE) PER TTL WINDOW**, not per
  acquisition [RULED 2026-08-06, developer, against a measurement of ~10.8 ms per connection], and
  **a failed verification is cached in NEITHER direction** — not as success (one bad start-up would
  disable the guard for the window), not as failure (the fix for a wrongly-provisioned database is
  to fix the database).
- **The release half of the connection lifecycle is `SessionStateReleaser`, a `ResetInterface`
  service — and "when a connection is RETURNED" had to be reinterpreted, because THERE IS NO POOL.**
  [AGREED 2026-08-06] PHP-FPM is shared-nothing: a connection is not handed back to a pool at the end
  of a request, the whole process context goes away. So the release obligation attaches to Symfony's
  own reset of stateful services between requests, which is the nearest real equivalent, rather than
  to a pool event that does not exist here. Same shared-nothing fact that makes an in-process cache
  amortise across nothing (§ 7's evidence table and `CLAUDE.md` § Gotchas).
- **The runtime role's table privileges come from an IDEMPOTENT GRANT STEP shipping with the
  migrations.** [AGREED 2026-08-23]
- **`assertStillBoundTo()` lives on the `TenantIsolationStrategy` PORT**, not only on the Postgres
  adapter. [AGREED 2026-08-06]

### Identifiers

- **A UUIDv7 identifier is an ORDERING ARTEFACT, NEVER A SECRET.** [RULED 2026-08-05] Recorded
  beside money-is-never-a-float because a security property assumed in the wrong place is unfixable
  once a public surface depends on it. Measured, not assumed: `symfony/uid` gives **64** random
  bits, not 74 (10 of the 12 `rand_a` bits carry sub-millisecond precision); same-millisecond
  siblings are **correlated within 2^24** because the random field is INCREMENTED rather than
  redrawn; and the seed is recoverable from the output — `rand[1]`/`rand[2]`, emitted verbatim in
  groups 3–5, **are the first 8 of the seed's 16 bytes**, so one identifier halves the search space
  with no deltas at all. `symfony/uid` is KEPT (ordering is what v7 is for, and the hand-written
  layout it replaced failed to ascend on about half of all consecutive same-millisecond pairs).
  What changes is what the identifier is allowed to MEAN. Two consequences, both mechanised:
  - the unauthenticated **client portal (Wave 10) gets its own `random_bytes(32)` token**, never the
    document primary key;
  - **FrankenPHP worker mode must not be enabled until that token exists** — one process per
    request is what confines the recoverable seed to a single tenant. Enforced by
    `worker-mode-blocked.sh` (every tracked file) and `compose-config.sh` (the rendered
    configuration). **Delete both in Wave 10 when the portal token lands, not before.**
- **`TenantId::fromString()` NORMALISES a non-canonical id and `DocumentIdentity` REFUSES one**, and
  the asymmetry is correct in both directions. [FOUND 2026-08-07] A document id is a key a client
  hands back.
- **An ill-formed id answers 404, not 400, deliberately.** [RECORDED 2026-08-07] Distinguishing
  "malformed" from "absent" tells a prober its guess had the right SHAPE.

### Privacy

- **`--no-web-resources-cdn` stays on the Flutter build in BOTH modes.** [AGREED 2026-08-04] It is a
  GDPR control — it stops the engine fetching fonts from `fonts.gstatic.com` — not an optimisation.
  Two tests assert the built bundle reaches no external origin. A dev bundle that phones home is
  still phoning home.
- **Vendoring Flutter's WHOLE Noto fallback set is REJECTED on evidence**, recorded so no future
  session re-explores it. [RECORDED 2026-07-30] The web engine compiles in **143 distinct Noto
  families**, the CJK ones at **100–124 subset shards each** (`notosanskr` 124, `notosansjp` 124,
  `notosanshk` 109, `notosanstc` 105, `notosanssc` 101), at version-hashed paths that break on every
  Flutter upgrade. Bundling Arabic — a first-class locale — and serving a substitute under the prefix
  for everything else is the shape that survives an upgrade; see § 8's `infra/` row.
- **Any GET under the Flutter web build's `fontFallbackBaseUrl` prefix is an INFRA rule, not app
  code.** [RULED 2026-07-30] Web-only: `fontFallbackBaseUrl` appears in the compiled web engine and
  nowhere else.

---

## 4. The API contract — ours to design, and load-bearing

Because all three clients are ours, **the contract is designed, not inherited.** None of Invoice
Ninja's shape is binding: no unix-second integers, no `double` money, no `_method=put` multipart, no
`per_page=999999`, no two mandatory version headers whose absence crashes the client.

It remains load-bearing for a different reason: **a shipped mobile client updates on app-store
timelines, not ours.** Once the Flutter app is in a store the contract is effectively frozen for
anyone who has not updated. So it is pinned by contract tests and versioned deliberately, and a
change to a field name, an enum value, an envelope shape, an error format or an auth header is a
**breaking change with a migration plan**, never an incidental edit. An API change that does not
reach every tier — API, OpenAPI document, Angular, Flutter — is a `completeness-reviewer` P0.

Rulings in force:

- **API Platform, in DTO + State Provider/Processor mode ONLY.** [AGREED 2026-07-29] `#[ApiResource]`
  on a domain entity is forbidden and already fails `layer-dependencies.php`.
- **Both JSON and JSON-LD, content-negotiated.** [AGREED 2026-07-29] Full contract tests on JSON —
  the shape both clients consume — plus a smoke test per resource on JSON-LD (200 + `@context`),
  because both come from the same normalizer.
- **GraphQL deferred, not refused.** [AGREED 2026-07-29] It solves *many clients you do not control*;
  twes-in has clients it does control. Its two real benefits are reachable otherwise (REST compound
  documents via `?include=`; typed clients generated from the OpenAPI document API Platform already
  emits). Its cost is not: GraphQL moves authorisation from per-endpoint to **per-field**, and a
  certification round returned three *proven* cross-tenant exploits in the isolation layer. Revisit
  after Wave 11 **with measurements**, and only with resolver-level tenancy enforcement and
  query-complexity limits shipping alongside.
- **Money crosses the HTTP boundary as a DECIMAL STRING, never a JSON number** — in both directions,
  and the same for quantities and rates. [AGREED 2026-08-07] JSON has one number type and it is a
  double. The input DTOs declare `string`; a JSON number is answered with a 422 naming
  `lines[0].unitNet`, which needs `COLLECT_DENORMALIZATION_ERRORS` on the operation (without it the
  answer is an opaque 400). Assert the ENCODED PAYLOAD, not the PHP type — the type declaration is
  the enforcement, so asserting it again is a dead assertion PHPStan refuses.
- **The edge constraint on a `clientId` is a HAND-WRITTEN REGEX, not `#[Assert\Uuid]`.** [AGREED
  2026-08-22] Symfony's constraint accepts the uppercase and braced spellings the domain refuses, so
  the validator would pass an id the aggregate then rejects — a 422 naming the wrong thing, or a 500.
  The edge must refuse exactly what the domain refuses.
- **The server computes totals and the clients display them.** [AGREED 2026-08-07] All three clients
  could compute from the lines; they must not.
- **A write response is the document READ BACK inside the write transaction**, never the aggregate
  just built. [AGREED 2026-08-07] `NUMERIC(21,6)` returns `2.000000` for a stored `2`, so returning
  the aggregate makes `POST` and a later `GET` disagree byte-for-byte on one document.
- **Two single-purpose operations rather than one flag**: `POST /api/invoices` creates a draft,
  `POST /api/invoices/{id}/issue` issues it. [AGREED 2026-08-07] An irreversible act is not reachable
  two ways.
- **Every omitted verb is ARGUED, never deferred by default.** [AGREED 2026-08-07 / 2026-08-22] The
  read resource declares no `Delete` (an issued document is `cancel()`ed), no `Put` (a full
  replacement must first answer whether a caller may supply ids), and no `GetCollection` (pagination
  is decided once, not per endpoint). The same discipline governs `Client` and `Product`.
- **A transport-agnostic gateway interface in BOTH clients, mandatory from the first screen.**
  [AGREED 2026-07-29] No component may call an HTTP client directly. The honest justification is
  **testability**; preserving the GraphQL option is a side benefit. Retrofitting after forty
  components call `HttpClient` directly is not a refactor anybody schedules. A Wave 8 and Wave 11
  gate condition.
- **There is no validation ruleset to port.** [FOUND 2026-07-29] Upstream's React app has zero
  client-side validation; every rule lives in Laravel FormRequests. Ours is written from the
  standards and the domain.

### Translation keys — which refusals get one

A domain exception gets its own key **only when a user can fix the thing it refuses.** Everything
else maps to `error.internal` and carries its detail in the log, not in a response. The test:
**would a competent user, reading only this message, know what to change?**

| Exception kind | Key? | Why |
|---|---|---|
| the user typed something invalid — a bad quantity, a negative price, a rate too precise | **yes** | they can retype it, and the message is the only instruction they get |
| the user acted on stale state — issuing twice, editing an issued document, removing a line that is gone | **yes** | plausible double-click or stale page, and a 409/422 the UI must explain |
| the CONTENT is not issuable — an empty invoice, or an issue with no client | **yes** | the draft is still there and the fix is obvious |
| the CLIENT NAMED does not exist for this company | **yes** | they can correct the id. Note the SAME key answers *"no such client"* and *"that client is somebody else's"*, deliberately — a distinct message for the second is an existence oracle over another tenant's ids |
| the document or client is FULL — a 1001st line, a 51st contact | **yes** | a real action, and the same prescription as an oversized total: remove something, or split |
| a CONFIGURATION value an administrator can fix — a number-pattern width | **yes** | admin-facing is still user-facing. **One refusal, one key**: a floor and a ceiling are two keys, because a message naming the wrong bound fails this section's own test |
| a PRODUCT carrying a NEGATIVE VAT rate | **yes** | `product.vat_rate_invalid`, added 2026-09-02 (R5K-6). The administrator typed it and can retype it. It is the sibling of `document.vat_rate_invalid` and shares its `{rate}` placeholder, but earns its OWN key rather than reusing that one: the consequence differs and the message says so — a product at a negative rate stores cleanly and then makes every line ever built from it refuse, so the defect surfaces at INVOICE time on a catalogue entry saved weeks earlier. Like every other key here, **nothing resolves it yet** |
| a PRODUCT priced by BOTH a profit rate and a net price, or by NEITHER | **yes** | **two keys, not one** — opposite mistakes with opposite fixes; one message covering both tells a user to do two contradictory things |
| our own fault — a number from the wrong sequence, a `\LogicException` of any kind | **no** | `error.internal`. Naming internals is noise at best and an information leak at worst |
| a currency mismatch INSIDE a document | **no** | the API fixes the currency per document, so a mismatch reaching `DocumentCalculator` means our own layer built the request wrong |
| a currency mismatch while PRICING A PRODUCT | **yes** | a user really can type a cost and a price in two currencies |
| an unsupported CURRENCY code | **yes** | the administrator picked it |
| a contact id that is malformed, duplicated, or names no contact | **no** | not reachable from the wire today — ids are minted server-side. The guards STAY (a future importer or `PUT` can trip them) and each earns a key in the change that makes it reachable |
| TRANSPORT-level refusals | n/a | `error.not_found`, `error.tenant_required`, `error.validation_failed`, `error.internal` belong to the HTTP layer |

**Two rules about the keys themselves.** One key per PART, never one carrying a `{what}` — the
domain's `$what` is English words (*first line*, *postcode*, *line*, *fixed charge*), and those are
gendered and pluralised differently in French and Arabic, so no single sentence can host them
grammatically. And **a placeholder carrying an ENUM takes a translated LABEL, never the backed
value** — `document.state.draft` / `.issued` / `.cancelled` exist for exactly that.

`scripts/gates/locale-key-parity.php` checks that every locale carries the same SET. Nothing checks
COVERAGE, and that stays deliberately unautomated: *"is this user-fixable"* is a judgement, not a
grep. **Nothing RESOLVES any of these keys today — see § 8.**

---

## 5. Current state — verified baseline

**Baseline commit: `5dfebf1`.** Everything below was checked against the tree, not recalled.

### Waves

| Wave | State |
|---|---|
| **Wave 0** — seams | **LANDED**, not separately certified. Money, pricing, tenancy strategy, clock, identifiers, the architecture gates |
| **Wave 1** — the document kernel | **SCOPE DELIVERED, ALL ROUND-5 FINDINGS CLOSED, NOT YET CERTIFIED.** Round 5 closed NOT CLEAN (7 findings, 0 P0) and the cap was reached; R5C-2 was fixed in `5dfebf1` and the other six on 2026-09-02 (`c170503`…`76f51ba`), each mutant-pinned. **What remains is the certification round, not code** — see § 9 |
| **Waves 2–12** | not started — § 9 |

### What exists in `api/`

- **`Domain/`** — `Money`, `Pricing`, `Document`, `Client`, `Product`, `Settings`, `Shared`.
  Framework-free, zero Composer dependencies.
- **`Application/`** — `Document`, `Client`, `Product`, `Shared`. Command handlers plus a
  `TransactionalScope` port whose adapter is the only thing that opens a transaction. **An
  application handler never mints a `DocumentNumber`** — it consumes one from
  `DocumentNumberAllocator`.
- **`Infrastructure/`** — `Persistence` (the attribute-mapped Doctrine model, its row entities and
  mappers, the repositories), `Tenancy` (the RLS isolation strategy, the resolver port, the binding
  middleware, the savepoint middleware, the connection-lifecycle guards), `Scheduler`, `Shared`
  (clock, UUIDv7 generator).
- **`UI/Http`** — API Platform resources, state providers and processors.
- **Migrations** — eight, in `api/migrations/` (`ls` for the list; the first is
  `Version20260801120000`).
- **Four PHPUnit suites** — `unit` (pure domain, no kernel, no database), `integration` (real
  PostgreSQL), `functional` (through the kernel), `e2e` (a really-booted server). All four hold
  code.
- **The Symfony application** — `Kernel.php`, `bin/console`, `config/**`, `public/`, dotenv cascade.
  `Kernel` and `bin/console` are hand-written rather than Flex-generated, because `symfony/flex` is
  a Composer plugin this container disables. Both require `vendor/autoload_runtime.php`, which
  **does exist** — so `APP_RUNTIME`, and therefore FrankenPHP worker mode, is technically
  **reachable and then deliberately REFUSED** by two gates. Reachable and permitted are different
  things.

`functional` holds **three kinds** of class, and the kinds are what to remember because membership
moves with every endpoint: `*ProcessorTest` / `*ProviderTest` drive providers and processors
DIRECTLY (their subject is the translation between wire and domain); `*SurfaceTest` needs the real
serializer, validator and router (its subject is the answer a caller gets); and `*WiringTest`
asserts what the CONTAINER did. The third kind is not optional — the primary tenancy control had no
call site for three commits and a behaviour test could not have seen it. **Both, or neither is
enough.**

### What exists in the client tiers

`admin/` and `mobile/` are **scaffolded with their own tier's official generator** (`ng new`,
`flutter create`), never by hand, and each is green on its own toolchain. Each also holds the
application code its own invariants already demanded — the branding seam, and for Flutter the
font-fallback and same-origin controls in `mobile/lib/main.dart` and `mobile/web/`, with executed
tests. **Neither holds any domain or transport code** — no invoicing, no models, no API client.

`infra/` has **landed**: three Dockerfiles, three compose files, an entrypoint, a Caddyfile, a
database init script and its own gate. Both the development and the production stack have been run
end to end.

**`phpstan/phpdoc-parser` and `phpdocumentor/type-resolver` are RUNTIME dependencies**, both
recorded in `THIRD-PARTY-NOTICES.md`. [AGREED 2026-08-07] PHP has no generics, so an input DTO's
`list<NewInvoiceLine>` is a docblock the serializer must actually read at request time — which makes
a documentation parser a runtime concern rather than a dev tool.

**The `+ 1` working-scale guard band** on decimal arithmetic is a ruled margin, not an accident:
intermediate products need more scale than either operand, and the band is what stops a correct
final figure being reached through a truncated intermediate. [RULED 2026-07-30]

### Pinned stack

PHP **8.5.9** · Symfony **8.1** · PostgreSQL **18.4** · Node **26.7.0** · Angular **22** ·
Flutter **3.44.9**. [AGREED 2026-07-29 — Node 26.7.0 was RULED at 13:40 over an earlier 24-LTS
recommendation, on the grounds that Angular 22 explicitly supports it and 26 is even-numbered.]

**Exact versions are pinned, not floated with `^`** — in `.nvmrc`, `composer.json`
`config.platform.php` and the Docker base images. **A reproducible build is a precondition for
trusting a money calculation.**

**No Angular patch version is written here, and that is a fix rather than an omission.** The ruling
named "Angular 22.1.3", which is the **CLI**; `admin/package.json` declares the RANGES `^22.0.0` and
`^22.0.9`; and the lock holds `@angular/core` and `@angular/cli` at two *different* patch versions.
An earlier draft of this line wrote the range floor as though it were the pin and was wrong. Derive
it: `python3 -c "import json; l=json.load(open('admin/package-lock.json'))['packages']; print({k:
l[k]['version'] for k in ('node_modules/@angular/core','node_modules/@angular/cli')})"`.

### Dev and production are different ARTEFACTS

[RULED 2026-08-04/05] Not one image switched by environment variables — a shared `base` stage with
`dev` and `runtime` targets. **The thing that forced it was Xdebug**: a debugger in a production
image is an RCE amplifier (`xdebug.mode` is settable from any `.ini` a compromised process can
write, and Xdebug can be told to connect OUT), so the only safe way not to have it there is not to
build it there.

| | dev (`make up`) | prod (`make up-prod`) |
|---|---|---|
| API image | `dev` target | `runtime` target |
| Xdebug | present, `xdebug.mode=off` until `make debug-on` | absent from the image entirely |
| Composer, `gcc` | present | absent |
| source | the whole `api/` tree bind-mounted read-write | baked in, root filesystem `read_only` |
| `vendor/` | on the HOST, installed BY THE CONTAINER | in the image |
| OPcache | `validate_timestamps=1`, no preload, JIT off | `validate_timestamps=0`, preload, JIT tracing |
| errors | `display_errors=On` | logged only; `zend.exception_ignore_args=On` |
| capabilities | Docker defaults | `cap_drop: ALL` on every service, adds established by test |

**Production drops ALL Linux capabilities on EVERY service**, adding back only what each provably
needs — established by dropping ALL and reading the failure, never by reputation. [AGREED 2026-08-05]
Asserted by `compose-config.sh` as a full-set check over the rendered configuration, prod-scoped,
with two mutants proving it fires. It is a FULL-SET check because the gap that prompted it was the
one-shot `migrate` container being missed while the six long-running services were hardened — and a
one-shot is short-lived, not low-privilege: `migrate` is the ONLY container holding the owner
credential. **Pid limits are expressed as `deploy.resources.limits.pids`, not the top-level
`pids_limit`** [AGREED 2026-08-05] — Compose normalises the latter into the former and then refuses
both as a duplicate, and the chosen spelling sits beside `memory`, which is how every other limit in
the file is written.

**The front-end tiers take a BUILD ARGUMENT for their configuration** (`NG_CONFIGURATION`,
`FLUTTER_BUILD_MODE`) rather than gaining a second Dockerfile. [AGREED 2026-08-04] Unlike the API
tier there is **no security delta** between a dev and a prod bundle — only optimisation and source
maps — so one artefact with a flag is correct here and a second Dockerfile would be duplication.

**`api/vendor` is BIND-MOUNTED from the host in dev, not a named volume.** [AGREED 2026-08-04] The
goal was an IDE that resolves vendor classes; a named volume cannot deliver it, because an IDE
indexes the project directory and a volume lives in root-owned `/var/lib/docker/volumes/`. The
standing objection ("the container would run whatever PHP resolved on the host") is about the
INSTALL, not the mount: `make install` runs Composer *in* the container. Dev keeps a named volume
for **`api/var` only** — cache and logs are the one thing that genuinely wants to be container-side.

**A bind mount does not translate ownership, so the dev container runs as the HOST's uid.**
`TWES_UID`/`TWES_GID` are passed from `id -u`/`id -g`, and **`USER` is NUMERIC** so any value works
including 0 — a named `USER` could not.

**`NET_BIND_SERVICE` is load-bearing for a reason its name does not suggest.** The Dockerfile applies
`setcap CAP_NET_BIND_SERVICE=+eip` to `frankenphp`, and the `e` (effective) bit makes **`execve`
itself fail with EPERM** when the capability is outside the bounding set — `exec
/usr/local/bin/frankenphp: operation not permitted`. It is required even where the daemon sets
`ip_unprivileged_port_start=0`. Every other `cap_add` was established the same way, by dropping ALL
and reading the failure; **gotenberg needs NOTHING**, which is worth knowing because Chromium is the
service people reflexively grant `SYS_ADMIN`.

**Makefile naming convention** [RULED 2026-08-05]: **a bare target acts on DEVELOPMENT, `-prod` acts
on PRODUCTION, and no other suffix means an environment.** That direction and not the reverse
because of blast radius — muscle memory types the short name, so the short name must be the harmless
one. A bare aggregate is the WHOLE thing; a suffix narrows it. Two deliberate asymmetries, named in
the gate's exemption list: there is **no `destroy-prod`**, and `install`/`composer`/`test`/`debug-*`
are dev-only because Composer, PHPUnit and Xdebug are absent from the production image by design.
The nine production twins are **not nine new recipes** — `DCX` is a target-scoped variable, so one
body serves both stanzas; writing one recipe per environment is exactly how `build-front` and
`build-front-dev` diverged.

**Messaging**: the Messenger queue is the **Doctrine transport** (PostgreSQL — backed up, and
transactional with the document that dispatches the message). Valkey keeps the **lock store**, where
losing state on restart is CORRECT rather than tolerable. [AGREED 2026-08-02] Valkey runs with
`--save '' --appendonly no`; pointing the failure transport at it would have silently dropped the
record of every message that exhausted its retries — in a billing product, an invoice nobody knows
was never sent. Note `symfony/redis-messenger` requires `ext-redis` and does **not** accept Predis.

**PDF rendering**: Gotenberg with the **allow-list alone** — `--chromium-deny-list=.*` combined with
an allow-list is a CONJUNCTION in which a deny match is absolute, so the pair configured the
renderer to render nothing. The security property was verified to survive its removal rather than
assumed: converting a URL returns `403`, and a local template embedding a remote image renders while
the remote server is **never contacted**. Gotenberg's own default deny-list (`file://` outside
`/tmp`) is left in force by not naming the flag. **A liveness endpoint answers "is the process
alive", never "does the thing it exists for work"** — `/health` reported `chromium: up` throughout.

**Served-surface headers**: a header the edge owns is set with Caddy's `>` deferred prefix, **on
every field, even though one suffices** [AGREED 2026-08-07] — a `header` block containing any
deferred operation is applied as a unit, so a correct response that depends on a neighbouring line
regresses when somebody deletes the other one. Two **disjoint** CSP matchers (`@apiDocs` /
`@apiData`) rather than an unmatched header plus an override, because exactly one applies to any
request and ordering stops mattering. Resource endpoints keep `default-src 'none'`; the docs page
gets `'self'`. That CSP is a **privacy control**, not defence in depth: ReDoc fetches
`cdn.redoc.ly/redoc/logo-mini.svg` and our own `img-src 'self' data:` blocks it.

### Environment facts about THIS machine

- **This machine has NO PostgreSQL server.** [Verified 2026-08-21] Only the client is installed —
  no `initdb`, no `pg_ctl`, no `/etc/postgresql`. The `pg_ctlcluster 16 main stop && pg_ctlcluster
  18 main start` recipe written throughout the archive describes the **dead cloud container** and
  its two-clusters-on-5432 trap; that account is still authoritative for a machine that HAS
  clusters, and it does not apply here. The database is a container — see § 6.
- **`composer gate` needs `COMPOSER_PROCESS_TIMEOUT=0`** here; two steps individually exceed
  Composer's 300 s default and are killed, which reads as a gate red and is not one.
- **`gate:licences` is currently ENVIRONMENT-BLOCKED** — the pub cache is missing locked packages,
  and FVM has 3.41.9 (Dart too old for `sdk: ^3.12.2`) and 3.47.1 (`--enforce-lockfile` reports
  "Would change 4 dependencies"); the lock was authored with ~3.44.9, which is not installed. The
  restore is in § 6, and the version decision is stated there rather than left open.

---

## 6. Environment and bootstrap

Nothing here is installed by default in a fresh container.

| Tier | How |
|---|---|
| PHP 8.5.9, PostgreSQL client 18.4 | sury.org and PGDG apt repositories |
| PHPUnit, php-cs-fixer, PHPStan | `bash scripts/dev/fetch-tools.sh` — official phars, **pinned SHA-256**. They need no vendor tree, which is what makes `gate:architecture` and `gate:style` independent of a successful `composer install` |
| Composer dependencies | `composer config -g use-github-api false && composer config -g github-protocols https`, then `cd api && composer install --prefer-source` |
| Node 26.7.0 | tarball from `nodejs.org/dist`, verified against the published `SHASUMS256.txt` |
| Angular CLI | `npm install -g @angular/cli@<the locked version>` — derive it from `admin/package-lock.json`, never from `package.json`'s range (§ 5) |
| Flutter 3.44.9 | see the `gate:licences` restore below |

**On `composer install`.** The plain form fails; `--prefer-source` clones instead of fetching
zipballs and succeeds. The 403 that used to be diagnosed as "restricted network egress" was
nothing of the kind — general egress is open, `git clone` over HTTPS works, and only GitHub's API
and archive hosts are authorization-scoped. **`--no-dev` is NOT required** and neither is the
`composer dump-autoload --dev` that compensated for it: both existed for `phpstan/phpstan`, which
left the lock with `deptrac` on 2026-08-02. Every locked package carries a `source` URL, in both
`packages` and `packages-dev`. **No totals are written here** — derive them with a script over
`api/composer.lock`; the property (`0 without source`) is what every version of this sentence was
actually asserting and it is the only part that never moved.

### The database — a container on this machine

```
docker run -d --name twes-pg -e POSTGRES_PASSWORD=postgres -p 127.0.0.1:5432:5432 postgres:18
cd /stack/projects/twes-in
PGHOST=127.0.0.1 PGPORT=5432 PGUSER=postgres PGPASSWORD=postgres \
  TWES_TEST_DB_SUPERUSER_PASSWORD=postgres bash scripts/dev/provision-test-database.sh
PGHOST=127.0.0.1 PGPORT=5432 PGUSER=postgres PGPASSWORD=postgres \
  bash scripts/dev/provision-dev-database.sh
cd api && php bin/console doctrine:migrations:migrate --no-interaction
```

**BOTH scripts, every time.** `twes_in_test` and `twes_in` are different databases; provisioning only
the second leaves the integration suite failing with `database "twes_in_test" does not exist`, which
is the fail-closed behaviour working rather than a regression. The container is EPHEMERAL:
`docker start twes-pg` after a reboot, and re-provision if it was removed.

**`TWES_TEST_DB_SUPERUSER_PASSWORD` is not optional and the script REFUSES without it.** It sets a
password on the cluster's pre-existing superuser, and `pg_authid` is a **shared** catalogue — that
one statement escapes `twes_in_test` and applies to every database on the cluster. Setting the
variable explicitly is the consent; a default would be indistinguishable from a choice. The script
also refuses when the target database already holds relations. A refused run creates nothing.

**MANY roles, not one** — read the script's own comment block, or `grep -c '^        CREATE ROLE'`
(anchored; the unanchored form counts comment lines). This replaced three `createuser` lines after a
round found a **P0**: a single role that owns the tenant-owned tables can `ALTER TABLE … DISABLE ROW
LEVEL SECURITY` or `TRUNCATE` them in one statement — `FORCE` stops an owner *skipping* policies,
not *removing* them — so every isolation assertion was being made against a connection that could
step around the thing being asserted, and the suite proved it by running both statements on the
connection it had just certified. Each role exists to make one refusal branch provable against a real
connection: the restricted runtime role; the owner (**never granted to the runtime role** — that
grant is the ordinary convenience wiring that reopens the whole bypass); a `BYPASSRLS` role; a
*member* of it, proving privileges reached by `SET ROLE` including from `session_user` while
`current_user` looks clean; a `REPLICATION` role (which reads the whole cluster through
`pg_basebackup` with row security never involved); a truncator granted **`WITH INHERIT FALSE`**, the
shape `has_table_privilege` cannot see; a probe owner granted **`WITH ADMIN OPTION`**; one granted
**`WITH INHERIT FALSE, SET FALSE`** — held but unreachable, the ONLY shape under which
`pg_has_role(…, 'MEMBER')` and `pg_has_role(…, 'SET')` disagree; and **one whose name is not
all-lowercase**, because `current_user::regrole` DOWNCASES and raised `role "twesmixedcase" does not
exist` — an outage, not a verdict.

> **The principle, learned the hard way: a fixture that cannot express a dangerous shape cannot
> detect it.** Every role after the first two was added because a certification round proved a real
> breach the previous topology made untestable.

**`provision-dev-database.sh` needs no environment at all** and its defaults match `api/.env`. Run
it **before the first migrate**: it is what makes `twes_in` owned by the owner role rather than by
the runtime role, and a database owned by the runtime role hands it `CREATE` on `public` through
`pg_database_owner` with no grant anywhere to find. It is re-runnable and it **CORRECTS rather than
only creates** — the database's owner, the runtime role's ATTRIBUTES and the owner of every RELATION
it holds (`REASSIGN OWNED BY`) are stated unconditionally, because repairing a wrong shape is this
script's whole value on an existing cluster. The one thing it never overwrites is an existing role's
PASSWORD. Two roles only — never the test script's set, because a `BYPASSRLS` fixture has no
business on a developer's own cluster.

**CI overrides** are named by `TWES_TEST_DB_*` environment variables. **Derive the list** —
`grep -o 'TWES_TEST_DB_[A-Z_]*' api/phpunit.xml | sort -u` — and note the derivation source has been
incomplete before: `provision-test-database.sh` honoured three role variables `phpunit.xml` did not
carry, so a CI provisioned *exactly as documented* still omitted three roles. **The durable check is
that the script and that file agree, and nothing yet enforces it** (§ 8).

**Nothing in the integration suite may skip.** A missing role name, a wrong superuser credential or
an unreachable database all **fail**. `api/tests/Integration/NoLegitimateSkipTest.php` scans every
tracked file in the suite and fails on a `markTestSkipped()` call — because `DatabaseRequirement`
asserted this invariant in capitals for eight rounds while it was false.

### The `gate:licences` restore — the version decision is STATED, not left open

```
fvm install 3.44.9
cd mobile && flutter pub get --enforce-lockfile
```

**Install 3.44.9 and verify the lock reproduces.** Upgrading the SDK instead (3.47.1 reports "Would
change 4 dependencies") is a **licensing-adjacent decision**, never an environment fix: four changed
dependencies means licence re-verification and `THIRD-PARTY-NOTICES.md` churn under the recording
obligation. Until this is done, `gate:licences` is a named `UNCERTIFIED-BY-EXECUTION`.

### Traps that cost time here

- **Never chain a verification step onto `git commit` through a pipe.** `phpunit … | tail && git
  commit` commits on red, because a pipeline's exit status is the LAST command's. Run each step and
  read its own status. **And never `&&`-chain the gate steps to each other** — the first failure
  swallows every verdict after it.
- **`git restore` is the wrong way to undo a mutant.** It restores to HEAD, so on a file also
  carrying the uncommitted fix you are testing it throws that away too, silently. Back the file up
  and restore from THAT; make the mutant script ASSERT its anchor before writing; print `git status
  --porcelain` in the same command as `git add`.
- **A denied compound command leaves its heredoc unwritten**, and `git commit -F` does not fail on a
  stale path — `/tmp` is shared across every project on this machine. Use session-scoped message
  filenames and `head -1` the file in the same command as the commit.
- **A drifted Bash cwd silently re-arms every project-scoped gate bypass under the wrong slug.**
  Prefer absolute paths and `git -C`.
- **Use `git grep`, not `grep -rn`, for completeness sweeps.**
- **A build-state change is invisible to `git status`.** A read-only reviewer once broke
  `composer gate` without touching a tracked file, by running `composer dump-autoload` through a
  `--shared` clone. A freeze protects what git tracks and protects nothing about `vendor/`,
  `node_modules/`, `.dart_tool/`, `build/` or a pub cache — so a panel prompt must say *copy or
  point `COMPOSER_VENDOR_DIR`/`PUB_CACHE` elsewhere*, and a green `git status --porcelain` at the
  end of a round is not evidence that the tree still builds.
- **This container's `LANG` makes Flutter Web render a BLANK PAGE under Playwright.** Headless
  Chromium reports `navigator.language` as `en-US@posix`, which Flutter's locale parser rejects
  with `RangeError` — no failing test anywhere, just an empty screenshot. Set a locale explicitly,
  and read a blank Flutter screenshot as "check `pageerror` first".

---

## 7. Quality gates and the certification protocol

### The gates

`ls scripts/gates/` minus `lib/` is the inventory — no count is written here. `lib/` holds
LIBRARIES rather than gates: no CLI, no exit code, no `--dump-rules`; `test-gates.sh` asserts
instead that every one of them is sourced, required or executed by a gate.

| Gate | Enforces |
|---|---|
| `layer-dependencies.php` | inward-only dependencies, **and** the domain's zero-Composer-dependency rule |
| `no-ambient-calls-in-domain.php` | no clock, randomness, environment or I/O in `Domain/` — the half a `use`-grep cannot see |
| `no-orm-attributes-in-domain.sh` | no `#[ORM\` or any Doctrine reference under `Domain/` |
| `spdx-headers.sh` | an SPDX identifier on every source file, **and that the search roots COVER every tracked source file** — the direction that was missing when a file sat unscanned |
| `dependency-licences.php` | every dependency permissive and recorded, over `api/composer.lock`, `admin/package-lock.json`, every locked pub package read out of the pub cache, and every **vendored font** under `mobile/assets/fonts/` — including that every font path the manifest declares was actually examined, because a forward walk says nothing about files it never reached |
| `locale-key-parity.php` | every locale carries the same key SET |
| `schema-tenancy.php` | every tenant-owned table in a **real migrated schema** is RLS-enabled, `FORCE`d, canonically policed on **both** halves, `NOT NULL` on its tenant column, not `TRUNCATE`-able by the runtime role, and **every key on it includes the tenant column** |
| `compose-config.sh` | every compose configuration RENDERS, and a set of security properties survives rendering — plus a **large-object cross-check** that sits ABOVE the `docker compose` probe and therefore never skips |
| `worker-mode-blocked.sh` | FrankenPHP worker mode refused across **every tracked file**. Needs nothing installed, so it can never skip |
| `no-forgeable-tenancy-in-production.sh` | `TWES_TRUST_TENANT_HEADER` is `0` in every tracked file |
| `no-owner-connection-in-application.php` | no code under `api/src/` names the **owner** DBAL connection |
| `no-orphaned-docblocks.php` | no doc comment that attaches to no declaration |
| `makefile-conventions.sh` | the bare/`-prod` convention of § 5, checked as NAME-VERSUS-BEHAVIOUR |
| `shell-syntax.sh` | every tracked shell script parses — **including the other gates** |
| `test-gates.sh` | **tests the gates.** A gate that cannot fail is a false assurance |

Four of these are worth understanding rather than listing.

**`schema-tenancy.php` is the only gate that needs a database, and the only thing that can see an
unpoliced tenant table at all.** The behavioural attack suite derives its subject set from tables
that already HAVE row security, so a table missing it is invisible to the attack *by construction*.
The gate **fails on a table it cannot classify** rather than ignoring it — a silent skip is how the
next tenant-owned table arrives unpoliced — and `customer_id`, `client_id` and `account_id` are
deliberately NOT treated as tenant columns, since each names a row's *subject* rather than its
owner. Its subject set is **every non-system schema** and includes relkinds `m` and `f` in order to
REFUSE them, since a materialized view or foreign table holding tenant data cannot carry a policy at
all. On **every** table, tenant-owned or not, it also checks that the runtime role does not **own**
it: a non-tenant table owned by the runtime role leaks nothing itself, but it proves migrations are
running as that role, so the next tenant-owned table they create is one statement from every
tenant's data.

**The KEY-SHAPE axis was DELETED and RESTORED, and the round trip is the most useful thing about
it.** It was removed on the claim that the behavioural suite covered it, and two lenses independently
reproduced a cross-tenant oracle without it. The reason is structural rather than a bug: **a probe's
reach is bounded by its fixture's value space**, so a soft-delete partial unique index is invisible
to it in BOTH directions, as are predicates over values the synthesiser never produces. So key
shapes are a **CATALOGUE property, not an attackable one.** The line that decides what lives where:
an attack suite MUTATES data, so it only ever proves things about the MIGRATION's output, whereas
every axis in the gate can also DRIFT on a live schema read-only.

**`BehaviouralIsolationTest` is a TEST, not a gate** — defence in depth ON TOP of that, never
instead of it. Two tenants with real rows, and eight attacker goals attempted as the restricted
runtime role against every relation discovery finds: read, write into another tenant, modify,
re-parent, delete, `TRUNCATE`, probe by uniqueness collision, reference another tenant's row. Plus
`FORCE` attacked as the owner, `SET ROLE` escalation into every reachable role, and the unbound-session
read that pins the `NULLIF` in the canonical policy. Its uniqueness probe runs in BOTH directions,
because a PARTIAL index's predicate is evaluated on exactly the columns the two tenants differ in.
Every goal is proven load-bearing by a mutant except GOAL 4, which **cannot be isolated**: with a
SELECT policy present, PostgreSQL requires an UPDATE's new row to satisfy that policy's `USING`
clause too, so re-parenting cannot be broken without also breaking GOAL 1. **GOAL 7 now CHECKS what
it previously ASSERTED** [AGREED 2026-08-21]: its message claimed *"some uniqueness mechanism does
not include `company_id`"* on the strength of an INFERENCE that held only while every tenant-owned
table had a surrogate key beside the tenant column. `company_settings` is `PRIMARY KEY (company_id)`
by design, so a correct schema was reported as an oracle. **Reshaping the schema to satisfy the probe
was rejected outright** — when a security probe and a design disagree, the question is which one is
wrong, and "make the test pass" is not an answer to it.

**`worker-mode-blocked.sh` inverted all three of its polarities**, because all three were defeated in
their enumerating form. Version 1 enumerated VALUES and was walked past by
`export APP_RUNTIME=`. Version 2 enumerated LOCATIONS, read a variable in the rendered environment
where nothing declares it, missed two Caddyfile seams, and SKIPPED without `docker compose`. Version
3 enumerated PATHS and was beaten six ways at once — the committed half of Symfony's own dotenv
cascade (invisible to a pattern anchored `\.env$`), a `.dev` Dockerfile, `composer.json`'s
`extra.runtime.class` (which `symfony/runtime` BAKES into the generated bootstrap, selecting a
runtime with no environment variable anywhere), a YAML block scalar carrying the directive on lines
the key never appears on, a fifth seam against a hand-written knob list, and the legacy
`ENV KEY value` form which contains no `=` at all. **Version 4 enumerates nothing forbidden**: scope
by EXCLUSION, seam set DERIVED from the Caddyfile's own `{$…}` placeholders, and every seam required
to be **EMPTY** rather than merely free of the word `worker`. That last inversion is what makes the
block scalar, the legacy form, a quoted YAML key and a `\`-split assignment all fail at once — the
gate stopped asking what a value MEANS. **The four seams are KEPT rather than deleted**: they are
FrankenPHP's own image's variable names, and deleting them would invent a project-specific spelling
of a conventional thing.

**And version 5 stopped scanning text where it could ask the server instead.** A `frankenphp adapt`
**ORACLE** (`scripts/gates/lib/worker-oracle.py`) runs against the rendered configuration and asserts
zero workers, with three distinct verdicts — unparseable config / non-JSON output / worker present —
because a single "no" cannot distinguish a clean answer from a broken probe. It replaces detection
for everything the server itself resolves: the CLI flag, directives, seams and block scalars at once.
The text rules STAY, because the oracle needs a daemon and they need nothing; the Caddy-config and
seam derivation lives ONCE in `scripts/gates/lib/caddy-configs.sh`, sourced by both, after a near-copy
in each led them to derive different sets. The value analysis is a real parser
(`lib/worker-mode-analyse.php`) rather than a normalised-line regex — *a transformation set is an
enumeration too*.

**Value-reading checks call the CONSUMER's own resolver, never a second parser beside it** [AGREED
2026-08-06]: DSNs come from DBAL's own `DsnParser`, JSON from `json_decode`, the superuser's name
relationally from `POSTGRES_USER` in the same rendered file. Each replaced a regex that had a real
divergence — the DSN parser rawurldecodes and merges the query over the userinfo; a JSON string
`"\u0072untime"` decodes to `runtime` and Composer honours it. **Failure to reach a resolver is a
VIOLATION, never a skip**: an unverifiable credential has not been cleared.

> **The generalisable lesson under all five: a gate must enumerate the SURFACE, not the spellings —
> and not the locations.** An inclusion list is fail-OPEN for every file nobody thought of. And where
> the real consumer can be asked, ask it rather than modelling it.

**PHPStan is CONFIGURED AND WIRED at level 6, and every finding was fixed rather than baselined.**
[AGREED 2026-08-05] `api/phpstan.neon.dist` covers `src/` and `tests/` with `checkUninitializedProperties`,
`treatPhpDocTypesAsCertain` and `reportUnmatchedIgnoredErrors` on — stricter than the level on the
three axes this project has already ruled on. Level 6 rather than max because levels 7–9 are
dominated by mixed-type findings. `gate:static` runs the **pinned phar** in `api/tools/bin/`, not
`vendor/bin/phpstan`, which can never exist here because PHPStan is not a Composer dependency. Only
two `ignoreErrors` entries exist and each carries its reason in the file.

### Running the gate

**`composer gate` chains** `gate:licences`, `gate:architecture`, `gate:schema`, `gate:static`,
`gate:style`, `gate:mapping`, `gate:test`. **`gate:e2e` is deliberately NOT in that chain** — it
FAILS rather than skipping when no server answers, so wiring it in would make every gate run require
a built image and a live stack. `gate:test` excludes the `e2e` suite EXPLICITLY
(`--exclude-testsuite e2e`) rather than by listing the other three, so a suite added later is
included by default rather than silently unrun.

Two gates FAIL rather than skip when they cannot look, and that coupling is the accepted cost of not
letting a check pass quietly on nothing: **`gate:licences`** needs a populated pub cache, and
**`gate:schema`** needs a migrated PostgreSQL database. Both print a `counts —` line unconditionally
so anti-vacuity probes still work. `compose-config.sh` is the one gate that SKIPS its rendered half
without `docker compose`; its large-object cross-check runs first and never skips.

```
cd /stack/projects/twes-in/api
for s in gate:architecture gate:schema gate:static gate:style gate:mapping gate:test; do
  COMPOSER_PROCESS_TIMEOUT=0 COMPOSER_ALLOW_SUPERUSER=1 \
    TWES_SCHEMA_DSN="pgsql:host=127.0.0.1;port=5432;dbname=twes_in;user=twes_owner;password=twes_owner" \
    TWES_SCHEMA_RUNTIME_ROLE=twes composer "$s"
  echo "$s EXIT=$?"
done
```

**Run the steps individually and read each exit**, never the chain — a Composer script chain stops
at the first failure, and with `gate:licences` environment-blocked here the chain dies at step 1
reaching nothing.

**`TWES_SCHEMA_USER` is the role the gate CONNECTS AS; `TWES_SCHEMA_RUNTIME_ROLE` is the role every
ownership and `TRUNCATE` assertion is made ABOUT.** Getting those backwards is the mistake the
parameter names invite. The DSN connects as the **owner** because reading `pg_policy`,
`pg_class.relowner` and `pg_authid` needs a role that can see them. `TWES_SCHEMA_USER` is omitted
above because the DSN already carries the credential and libpq prefers the connection string's own
`user=` over PDO's third argument.

### The certification protocol

**The panel of record** is three fresh-context, read-only, adversarial reviewer subagents in
`.claude/agents/` — one per lens: `domain-correctness-reviewer` (correctness + regression),
`tenancy-security-reviewer` (security + multi-tenant isolation), `completeness-reviewer`
(completeness + blast radius). Each **reads the actual diff, code and tests itself** and is
chartered to REFUTE, not approve. **Spawn them UNNAMED** — passing `name:` routes their return
through `SendMessage`, so they run, go idle, and their reports vanish, which is indistinguishable
from unavailability.

**Per-task gates run ONE `advisor()` call, not a panel** [developer ruling, 2026-08-19]. This
supersedes the older "MAXIMAL by default for every gate touching application code". The refuting
between gates comes from **executable evidence** rather than reviewers reading a diff: write the
failing test FIRST, confirm it fails **for the stated reason**, implement, then keep a
**sabotage/mutation check** proving the suite would NOTICE the guarantee breaking. **Sabotage the
INVARIANT, not the diff** — a mutation scoped to the code just written could not have seen the
primary tenancy control having no call site at all. Where a mutant already exists in the suite or in
`test-gates.sh`, re-running it IS the check; new sabotage work is owed only for an invariant that
has none.

**Wave boundaries get MAXIMAL**, unchanged: all three lenses, **two consecutive fully-clean rounds**,
any finding resets the counter, cap 5 → then ask via `AskUserQuestion`, never silently proceed.
Rationale: a wrong number is a wrong legal document and a cross-tenant read is a reportable breach,
and neither is caught by a green test suite. **Freeze first** — a round run on a moving tree cannot
count toward the two-clean requirement, and a mid-round commit once put a reviewer one step from
filing a fabricated finding against a file the frozen tree did not contain.

**`UNCERTIFIED-BY-EXECUTION` carries the weight the per-task panel used to.** When a change's
guarantee lands where an in-session check cannot reach, either produce the evidence or name the
dimension in those words in the completion report.

| Surface | Executable evidence available |
|---|---|
| `api/src/Domain/**`, `api/src/Application/**` | **STRONG** — the `unit` suite plus `gate:architecture`, which needs nothing installed |
| `api/src/Infrastructure/**` | **STRONG, GATED ON POSTGRES** — the `integration` suite and `gate:schema`; both FAIL rather than skip |
| `api/src/UI/**` | **STRONG THROUGH THE KERNEL** — the `functional` suite. CSP, site headers, the `/bundles/*` file server and a field sent twice need `gate:e2e` against a live stack |
| `infra/**` | **RENDER + TEXT ONLY** — only a real `docker compose up` proves it serves |
| `admin/**`, `mobile/**` | **TIER-LOCAL ONLY**. **No cross-tier contract check exists at all** |
| docs, `CLAUDE.md`, `.claude/**` | **N/A** — no runtime guarantee to break |

**Cross-tier contract completeness is the one lens knowingly left uncovered between gates.** Any
change touching the contract carries `UNCERTIFIED-BY-EXECUTION: cross-tier contract`.

> **A lens is a filter, and every filter has a blind spot.** In one round all three reviewers read a
> change, found two real defects, and **none asked "does the thing actually work?"** — it failed
> outright, and the developer found it by running it. The panel does not replace running the thing.

---

## 8. The OPEN register — the only one

Every owed item in this project lives here. The tier READMEs' owed tables were folded in; they now
point here. **Do not delete a row to make the table look green.**

### Work Package 0 — Wave 1's six open round-5 findings

**Standing ruling [AGREED 2026-08-29]: all seven of round 5's findings close (or are explicitly
ruled deferred) BEFORE Wave 1 re-freezes.** **ALL SEVEN ARE NOW CLOSED** — R5C-2 in `5dfebf1`, the
other six on 2026-09-02. Every row below is struck, each with a mutant, and **four of the six turned
up something the finding had not named**: the inverted bound was accompanied by `Unnecessary` never
having been in scope; the fixture's numbers proved already correct so only its framing was wrong; the
per-line column had no `per_line` vector at all; and the settings table turned out to be protected by
two independent mechanisms rather than the one its docblock claimed. **The wave may now re-freeze for
the re-certification round; the cap decision returns to the developer after it.** The full
filed text is in `var/claude/w1r5-findings.md` (gitignored). **Round 5's own record notes that the
correctness lens's filed text was LOST mid-round — R5C-1's description is a reconstruction from the
code, and the code is the authority.**

| # | Sev | Where | The defect, verified at `5dfebf1` |
|---|---|---|---|
| ~~**R5C-1**~~ | ~~P1~~ | `api/src/Domain/Document/Invoice.php` — `totallable()` | **CLOSED 2026-09-02.** The guard probed `PerRateGroup, Up` on an INVERTED bound (`ceil(a) + ceil(b) ≥ ceil(a+b)`), so it measured the smaller configuration and a document could pass it and then be impossible to total. Reproduced first, solved rather than guessed — the window is one quantum wide. Now probes `PerLine, Up`, verified maximal over 44 912 cases touching every accessor, with **zero under-refusals and zero over-refusals**. Pinned in both directions and mutant-checked |
| ~~**R5K-3**~~ | ~~P1~~ | `docs/spec/pricing-vectors.json` | **CLOSED 2026-09-02.** Every case now declares `vat_rounding_point`; the mislabelled column is `vat_if_per_line` with its reason; a companion `per_line` case was added so the SSOT covers both ruled settings; and the API tier reads the declared point instead of hard-coding one. **All ten pre-existing cases were independently recomputed from the ruled arithmetic before anything changed — zero mismatches**, so the numbers were right and only the framing was wrong. Three mutants pin it |
| ~~**R5K-6**~~ | ~~P2~~ | `api/src/Domain/Product/Product.php` | **CLOSED 2026-09-02.** `product.vat_rate_invalid` in all three locales, with its own § 4 row. Its own key rather than reusing the document one, because the consequence differs: a bad product rate stores cleanly and detonates at invoice time |
| ~~**R5T-1**~~ | ~~P2~~ | `api/src/Infrastructure/Persistence/Doctrine/DoctrineCompanySettingsRepository.php` | **CLOSED 2026-09-02.** `DoctrineCompanySettingsRepositoryTest` — four cases, two bound connections, a real migrated schema. It also corrected its own first docblock: dropping `FORCE` left every assertion GREEN, because the adapter's query carries its own tenant predicate, so **two independent mechanisms** deliver the guarantee. Pinned accordingly — a combined mutant for the adapter half, and a case reading the table with no predicate at all for the policy half |
| ~~**R5K-5**~~ | ~~P3~~ | `ConnectionProvisioningGuardMiddleware` | **CLOSED 2026-09-02.** The count is removed rather than corrected, per § 0 rule 1 — the enumeration is the count |
| ~~**R5T-2**~~ | ~~P3~~ | `api/tests/Functional/Http/ClientProcessorTest.php` | **CLOSED 2026-09-02.** The docblock described two things the loop does not do — "another tenant's", which the in-memory fixture cannot express, and "an id that is not a string at all", where the third case is an UPPERCASE spelling. Corrected to what it exercises, pointing at `DoctrineClientRepositoryTest::testAnotherTenantsClientIsNotFound`. **`ProductProcessorTest` needed no correction** — its docblock claims no enumeration, which the plan assumed it did |

**Remedies, and the two P1s reach money — follow them exactly.**

- ~~**R5C-1**~~ — **DONE 2026-09-02**, with two corrections the fix itself turned up. **`PerLine` does
  NOT dominate `PerRateGroup` in general** — under `HalfUp` and `HalfEven` it is routinely smaller, so
  replacing one algebraic claim with another would have repeated the defect; it is the COMBINATION
  that is maximal. And **`RoundingMode::Unnecessary` was never in scope**, independently of this bug:
  it raises the same exception type for a different reason — not that a figure is too large, but that
  it needs rounding at all and the caller forbade it — so no magnitude probe can ever satisfy it, and
  the old docblock's *"EVERY mode a caller may later choose"* was false for one of the eight. The
  R4C-6 direction case landed with it.
- ~~**R5K-3**~~ — **DONE 2026-09-02.** The expected values were **authored from the ruled arithmetic**
  and never dumped from the implementation — an independent exact-decimal recomputation of all ten
  existing cases agreed with every declared figure, which is what established that the numbers were
  already right and the defect was purely the missing declaration and the `_WRONG` label. The three
  remaining `_WRONG` columns are KEPT: they name genuine mistakes under the case's declared point (a
  subtotal rounded once, a per-line column recomputed instead of allocated, shares rounded to nearest
  instead of floored), not a rival ruled setting.
- **R5K-6** — add `product.vat_rate_invalid` in all three locales, plus its row in § 4.
- **R5T-1** — a two-tenant case against the **real** `DoctrineCompanySettingsRepository`, including
  the silent-miss direction.
- **R5K-5** — remove the count per § 0 rule 1.
- **R5T-2** — correct the docblock to what the fixture can express, pointing at
  `DoctrineClientRepositoryTest:271`; apply the same fix to `ProductProcessorTest`.

**Each code fix carries a mutant that proves it load-bearing.** Then ONE re-certification round
against a frozen commit; the cap decision returns to the developer after it, per the standing ruling.

### Systemic

| Item | Why it matters |
|---|---|
| **Typed exception per refusal** — the whole deliverable | Roughly sixty translation keys are shipped and **nothing resolves any of them.** A dozen keyed refusals raise a bare `\InvalidArgumentException` whose only payload is an English sentence, so nothing at the transport can tell `document.quantity_too_precise` from `document.total_too_large`. Deleting every key would leave the suite green, because `locale-key-parity.php` checks the SET and never that a key is USED. Today the input DTOs' validator catches the SHAPE errors and Symfony translates its own constraint messages, so a French or Arabic caller gets a translated message for the common case and an English one for the rest. `NoTenantBound` **carries** `error.tenant_required` as a constant and nothing reads it — carrying is not resolving. Covers `Domain/Document/`, `Domain/Client/` and `Domain/Product/` |
| ~~**The reconciliation table's anchors cover only part of it**~~ **CLOSED 2026-09-02** | Kept here rather than deleted, because what it cost is the point. All 178 `carried` rows now carry a literal `docs/SPEC.md` anchor and all 178 were asserted present — but the gap was real while it stood: **ten rulings had not in fact been carried**, and they were found only because something checked. A `carried` row without an anchor is a claim, not a fact; if this table is ever extended, extend the assertion with it |
| **NO CI at all** | There is no `.github/`. Every gate is local-only; nothing runs on push. The single-branch, no-PR-review flow means the local gate is the ONLY safety net before history |
| **No cross-tier contract check** | Named in § 7. Considered and declined; until it exists, every contract change carries `UNCERTIFIED-BY-EXECUTION: cross-tier contract` |
| **`deptrac` unwired** | Installable, and the only tool still owed. `qossmic/deptrac` is abandoned in favour of `deptrac/deptrac`, whose phar is not at the paths PHPStan's is, so re-adding it needs the release asset located first. It was REMOVED from `require-dev` on 2026-08-02 because it required `phpstan/phpstan`, which was dist-only, and so blocked every other dev dependency including the `symfony/browser-kit` the functional suite needs. `layer-dependencies.php` is the enforcement; deptrac would be defence in depth |
| **`provision-test-database.sh` and `api/phpunit.xml` can disagree** | Three role variables the script honoured were absent from `phpunit.xml`, so a CI provisioned *exactly as documented* omitted three roles. All are now present; **nothing enforces that they stay in step** |
| **`messenger_messages`** | A standing hazard for whichever wave wires Messenger: no policy, no tenant column, and the schema gate classifies by column, so it stays green while holding whatever a message payload holds |
| **`gate:licences` environment-blocked** | § 6 carries the restore. `UNCERTIFIED-BY-EXECUTION` until it lands |
| **Round 5 is itself UNCERTIFIED-BY-EXECUTION** | The correctness lens's filed text was permanently lost and the tenancy lens was half self-graded |

### Gate residue, named rather than implied

- **Rounds 23/24**: R24-3 (a reporting VIEW/MATVIEW that aggregates tenant data away carries no
  tenant column, so both the gate and the suite classify it "not tenant data" — the largest, and it
  needs a design decision about whether discovery should follow `pg_depend` to a relation's SOURCE),
  R24-6, R24-11, R24-13, R24-14, R24-19, R23-10, R23-12.
- **Round 31**: the three `caddy_config_paths` clause-(b) evasions (`--config=`, a flagged `COPY`,
  a build-time rewrite), and the `inspected == 0` anti-vacuity guard, which looks unreachable
  because the seam guard fires first — *a decision about which guard to keep, not a mechanical fix*.
- **Round 32**: the tracked Dockerfile `CMD`/`ENTRYPOINT` is checked by nothing while the gate's own
  message calls the image CMD *"the only place the server is invoked"*; a config **generated at
  build time** needs the oracle to adapt the BUILT image, which needs `docker compose build` inside
  a gate; the three `COPY` evasions and the oracle's block-scalar mutant are unpinned (the latter is
  killed through a shared message disjunction, so half the oracle's mutant coverage is vacuous); the
  library-reachability rule filters `\.(php|sh)$` and cannot see `worker-oracle.py`; nothing lints
  tracked Python; and the skip message states a false fact and prescribes an impossible remedy —
  with the image present and the daemon down it says the image *"is not present locally"* and tells
  you to `docker pull`.
- **`test-gates.sh`'s library-reachability check can produce a FALSE RED, and its own `2>/dev/null`
  is why.** Observed once on 2026-09-02: the case reported *"a library under scripts/gates/lib is
  reached by nothing ( caddy-configs.sh)"* and the suite exited 1. **It did not reproduce** — a
  re-run on the byte-identical tree gave `500 passed, 0 failed`, and reproducing the check's own
  loop by hand found **three** referencing files (`compose-config.sh`, `worker-mode-blocked.sh` and
  `test-gates.sh` itself). [Inferred, not Verified — the cause was not captured:] the check runs
  `sed -E … "$candidate" 2>/dev/null | grep -qE …`, so a transient read failure yields empty input,
  `grep` reports no match, and that is **indistinguishable from a genuine missing reference**. That
  suppression is the anti-bandaid shape: an error nobody diagnosed, silenced. **Owed: drop the
  `2>/dev/null` and fail loudly on an unreadable candidate**, the same call `schema-tenancy.php`
  already makes for a table it cannot classify.
  **And the same check carries a SECOND, deterministic defect found while investigating the first.**
  Its regex is `(source|require|include|php|bash)[^\n]*${lib}`, but in a POSIX ERE bracket expression
  `\` and `n` are LITERAL — so `[^\n]` means *"any character except a backslash or the letter n"*,
  not *"any character except a newline"*. Any reference whose text between the verb and the basename
  contains an `n` therefore fails to match. [Verified 2026-09-02: `echo 'source lib/n/caddy-configs.sh'
  | grep -qE '(source)[^\n]*caddy-configs\.sh'` → **no match**, while the same line with `x` for `n`
  matches.] It passes today only because none of the three real reference paths contains an `n`
  between the two. `[^[:space:]]` or `.` is the fix. This matters more than one flaky case — this
  repository prices a false finding as badly as a false clean, because *the next red gets
  dismissed*, and a red that cannot be told from a read error is exactly that.
- **R28-9** is closed as a CHECK and open as WIRING; the wiring half is a Wave 10 question.
- **R4-18**: the empty-string gap is deliberately left open.

### `admin/` owed — gate conditions for Wave 8

| Owed | Why |
|---|---|
| Test taxonomy: **unit · component · e2e** | Mirrors the API's four suites |
| **`axe-core` a11y check in the gate**, on every route | Accessibility asserted in prose and never measured is not accessibility |
| **Locale key parity** across `en`/`fr`/`ar` | `locale-key-parity.php` gains this directory |
| **RTL proven visually**, not asserted | Arabic is a shipped locale; a screenshot, delivered |
| **Consumes `docs/spec/pricing-vectors.json` — including its `document_totals` block, not only `cases`** | Named explicitly because a reader could implement `cases[]` in full and consider the row satisfied while the whole document kernel went unpinned. `document_totals` carries the rounding point, the per-line VAT column and the near-miss columns |
| Lint, strict TypeScript, production build | |
| **CSP `frame-ancestors` and `connect-src` scoped to the API origin**, once that origin exists, plus a test that fails if the built `index.html` carries no CSP | `security.autoCsp` is enabled and verified rendering clean; the scoping is the remaining half |
| **The bearer token must not reach `localStorage`** | This tier holds the session credential and renders every client's PII. In-memory token plus an httpOnly refresh cookie; assert it with a test that fails if a token-shaped value is written to web storage |
| **No `bypassSecurityTrust*` without a recorded reason** | `admin/.claude/CLAUDE.md` — generated by `ng new` and kept as upstream's own guidance — contains **no security rule at all**, so this row is the floor |

### `mobile/` owed — gate conditions for Wave 11

| Owed | Why |
|---|---|
| Test taxonomy: **unit · widget · integration** | |
| **Semantics / a11y tests in the gate** | Same reasoning as `axe-core` |
| **Golden tests or real screenshots**, at a DESKTOP window size as well as a phone one | `flutter test` asserts a widget tree, not a rendered result |
| **Locale key parity** across `en`/`fr`/`ar`, RTL included | |
| **Consumes `pricing-vectors.json`, `document_totals` included** | Third of three implementations of one formula |
| **The `fontFallbackBaseUrl` pin has nothing behind it for CJK, Hebrew and emoji** | Bundling Noto Sans Arabic removed the fallback path for Arabic only |
| **Release builds signed with OUR key, never the debug key** | `flutter create` scaffolds `signingConfigs.getByName("debug")` behind a TODO |
| **The API token belongs in the platform keystore, never in preferences** | |
| **A real reverse-DNS bundle identifier** | `com.twesin` is a placeholder for a domain nobody owns. Compile-time, so **not** covered by the branding config seam |
| **A build of all six targets** | Not cross-compilable — three CI runners (§ 9, Wave 12) |

**Gate order is load-bearing, not stylistic**: `flutter analyze` → `flutter build web --release
--no-web-resources-cdn` → `flutter test`. Two tests read `build/web` to prove the bundle reaches no
external origin, and with `test` before `build` they **skip** while the command still exits 0 with
"All tests passed!". A GDPR control that silently does not run is worse than one that is owed.

### `infra/` owed

**The operational catalogue — what a deployment must guarantee — lives in
[`infra/README.md`](../infra/README.md) § "What a deployment must guarantee", not here**, and is
deliberately not restated: it is what a deployer configures, and duplicating it is how the two
copies drift. Every row there is a requirement; the ones below are the ones still OWED.

| Owed | Why |
|---|---|
| **Serve 200, not 404, under `/assets/fonts/noto/`** | The Flutter engine resolves any script the bundled fonts do not cover through this prefix. With nothing behind it, a page rendering CJK, Hebrew or an emoji issues **3328 same-origin 404s in a 40-second load, uncapped**; serving the already-vendored Arabic face for any path under the prefix collapses that to **17 requests, 0 404s** [Verified]. Safe because a font either contains a codepoint or it does not — the substitute yields tofu, never a wrong glyph. In a billing product the trigger is tenant free text, so this lands before any user data is rendered |
| **Copy `dist/admin/3rdpartylicenses.txt` into the served web root** | `npm run build` writes it as a SIBLING of `dist/admin/browser/`, which is the actual document root, so the Angular tier's licence notices reach the artifact only if the deployment puts them there [Verified: `grep -rl "Permission is hereby granted" dist/admin/browser/` → nothing]. *"Beside the file is not shipped"* |
| **Assert `.wasm` is served as `application/wasm`** — the header, not just set it | `WebAssembly.instantiateStreaming` rejects any other MIME type **with no console error**, so the Flutter Web build sits at its bootstrap with a blank page. A wrong MIME type here is a blank application in production with a green CI. Found by screenshotting; no test caught it |
| **FrankenPHP worker mode — three preconditions** | (1) a Symfony 8 release of `runtime/frankenphp-symfony`; (2) proof that a tenant cannot stay bound to a connection a resident kernel reuses — the discard path exists and is tested, but never under a real resident worker; (3) the Wave 10 portal token (§ 3). Both gates are deleted then, not before |
| **`THIRD-PARTY-NOTICES.md` — the Angular notices item** | Paired with the `3rdpartylicenses.txt` row above |

### Wave 2 obligations already ruled

- **The negative-tie vector moves to Wave 2's Credit**, where a negative amount is the legitimate
  shape rather than an accident. Until then **no tier is pinned against `Math.round(-0.5) === -0` in
  JavaScript or Dart's half-away-from-zero** — a gap with a name and an owner rather than a silent
  one.

---

## 9. Roadmap

**Build in WAVES**, each independently reviewable, each ending in a certification round against a
frozen commit. [RULED 2026-07-29] The wave list is the **baseline, not the final scope** — a wave may
be re-cut, but a wave is not done until its gate is green and the panel converges.

**Wave 0 — Foundations.** LANDED. Money, pricing, the tenancy strategy seam, clock, identifiers, and
the architecture gates. Not separately certified. Wave 0 was never optional: the seams decided in it
are the ones that cannot be changed later.

**Wave 1 — Client & the invoice core.** **SCOPE DELIVERED, NOT CERTIFIED.** The document kernel,
lifecycle, numbering, the `Invoice` aggregate; the tenant-owned schema with RLS; the Doctrine
repositories; the savepoint guard, the boundary rule and the connection lifecycle; the invoice HTTP
surface (read and write); the `e2e` suite; the tenant settings table; Client (+ contacts), Product,
and the `document` → `client` link. **Nothing in the wave's SCOPE is owed.** What separates it from
COMPLETE is the certification tier: five boundary rounds have run and none reached two consecutive
clean rounds. **WP0 is closed — the outstanding balance is the ROUND, not the code.** All seven
round-5 findings were closed on 2026-09-02, each with a mutant; § 8 records what each one turned out
to be. **The wave is still NOT certified**: it re-freezes for a re-certification round, that round is
**round 6 — the cap the 2026-08-23 ruling extended to** — so even a clean round cannot satisfy the
two-consecutive-clean requirement inside the cap, and the cap decision returns to the developer after
it, exactly as ruled. Do not open Wave 2 on the strength of this paragraph.

**Wave 2 — Quotes, credits & the shared document machinery.** Quote · Credit · quote → invoice
conversion · the shared document abstraction · **discounts and inclusive-vs-exclusive tax**, which
arrived here from Wave 1 and are **unblocked** — all the worked numbers were supplied 2026-08-23 and
are ruled in § 3. **Wave 2 OWES the negative-tie vector.** *Full-set coverage is the theme*: anything
true of Invoice must be true of Quote and Credit, or explicitly not. **Acceptance:** a calculation
change applied to one document type is proven applied to all three — the single most common finding
class on the completeness lens.

**Wave 3 — Payments.** Payment · partial payments · overpayment · applying a credit · refunds (full
and partial) · one payment split across several invoices · balance **recomputed rather than
incrementally adjusted**. Gateways are OUT — this wave is the arithmetic and the ledger only.
**Acceptance:** `sum(applied) <= payment.amount` and `balance = total - sum(applied)` hold after every
operation, with no drift after a long sequence of partial payments and refunds.

**Wave 4 — PDF documents.** The rendering pipeline. A re-download returns the **stored bytes**, never
a re-render (§ 3). Live preview of an unsaved document is a real backend requirement, not a client
trick.

**Wave 5 — Tax & e-invoicing — France AND Tunisia together.** [RULED 2026-07-29] Doing two
jurisdictions at once is more work than one and **less than one-then-another**, and that is the
argument: two live jurisdictions force the tax layer to be genuinely generic instead of France-shaped
with Tunisia bolted on. They differ in exactly the ways that expose a bad abstraction — different VAT
rates and bands, a fixed stamp duty in one and not the other, different formats and transports,
different currencies (EUR 2 decimals, TND 3), different languages. **In:** the generic charge engine ·
reverse charge · exempt vs zero-rated, which are legally distinct and routinely conflated in code ·
one e-invoicing format done properly end to end. **Out:** every other jurisdiction and format —
adding a third should be configuration plus a format adapter; if it is not, the abstraction is wrong
and that is the signal to stop. **Acceptance:** generated XML passes a real schema/schematron
validation, and the PDF and the XML never disagree on a figure. The inclusive-tax half-grid tie is
ruled (§ 3) and binds from here.

**Wave 6 — Recurring billing.** Recurring invoices · the scheduler · timezone and DST · month-end
dates (Jan 31 → Feb) · **idempotency — a scheduler that runs twice must not bill twice.**

**Wave 7 — Auth, permissions & the API contract.** Authentication · API tokens (hashed at rest,
constant-time comparison) · Symfony Voters over a real `role_permissions` table · the OpenAPI document
as the contract SSOT · contract tests pinning the shape. **This is where the API contract becomes
load-bearing.** `HeaderTenantResolver`, `TWES_TRUST_TENANT_HEADER` and
`no-forgeable-tenancy-in-production.sh` are **DELETED in this wave** — not before, or an
authenticated resolver would ship beside a forgeable path to the same privilege.

**Wave 8 — Angular admin.** The admin client over the Wave 7 contract · a generic entity-config
service rather than per-entity duplication · a typed client generated from or checked against the
OpenAPI document. Gate conditions in § 8. **Acceptance: visual evidence delivered, not just
captured.**

**Wave 9 — Payment gateways.** Stripe · SEPA direct debit. Tokenization, webhooks with **signature
verification and idempotency**, refunds, SCA. The other gateways are out.

**Wave 10 — Client portal.** View and pay, unauthenticated by link. **Treated as hostile surface:**
unguessable expiring tokens, rate limiting, and no field rendered that should not be. **The portal
token is `random_bytes(32)`, never a document primary key** (§ 3). Both worker-mode gates are
**deleted in this wave, once that token lands** — and not before.

**Wave 11 — Flutter client, all six targets.** Gate conditions in § 8.

**Wave 12 — Infra & CI.** The six-target build matrix is **not cross-compilable** — iOS and macOS
need macOS, Windows needs Windows — so it is three CI runners. **CI does not exist at all today**
(§ 8); when it lands it goes in `.github/workflows/`, mirrors § 7's gates tier by tier, and every job
carries a comment explaining **why it exists and what breaks without it**. That comment style is
house convention, not decoration.

---

## 10. Decisions Log

The **one** live log. A ruling made anywhere lands here in the same change: one line, dated,
`AGREED` or `RULED`. Historical rulings live in §§ 1–4 and 7 as rulings-in-force with their dates,
and every one of them is dispositioned in `docs/archive/plans/RECONCILIATION.md`.

- [2026-09-02 00:00] AGREED: the five `docs/plans/*.plan.md` files are archived VERBATIM under
  `docs/archive/plans/` and this file becomes the single source of truth for the product; `CLAUDE.md`
  keeps the rules for HOW work is delivered and points here. `docs/plans/` ceases to exist.
- [2026-09-02 00:00] AGREED: every dated ruling in those five files and every `§ Gotchas` entry in
  `CLAUDE.md` was dispositioned two-sided before any file moved —
  `docs/archive/plans/RECONCILIATION.md` is that table, and nothing was dropped silently.
- [2026-09-02 00:00] AGREED: the permitted-licence identifiers are **pointed at, never restated
  here** — `LICENSING.md`, `THIRD-PARTY-NOTICES.md` and `CLAUDE.md` are the surfaces
  `scripts/gates/test-gates.sh` pins, and adding a seventh turns that gate red.
- [2026-09-02 00:00] AGREED: the 2026-08-19 certification regime — one `advisor()` per 3C/6C gate,
  the three-lens panel ONCE at a wave boundary — **lives in this file (§ 7) and in `CLAUDE.md`**,
  replacing "MAXIMAL by default" for per-task gates. The wave-boundary tier is unchanged. Provenance,
  because it matters: the regime was ruled 2026-08-19 and its placement was *"for now just in
  memory"*; moving it into the repo was proposed by the executing model on 2026-09-01, recorded as
  **pending** rather than as a ruling nobody had made, and **ratified by the developer on
  2026-09-02** on the reasoning that the memory note itself named memory-only placement as the thing
  that makes a session re-run per-task panels, while `CLAUDE.md` is the file that always loads. An
  unratified entry must never sit in this log wearing `AGREED` — see the archive on verifying
  `AGREED` provenance; this one waited for the word.
- [2026-09-02 00:00] AGREED: the tier READMEs' owed tables move into § 8 and the READMEs point here;
  the READMEs keep tier-local how-to content.
