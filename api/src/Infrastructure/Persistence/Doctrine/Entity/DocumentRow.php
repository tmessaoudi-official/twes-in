<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A document ROW. Not the aggregate — see the class-level note on why those are two different things.
 *
 * **This is deliberately a dumb, mutable Doctrine entity and it must stay that way.** It holds no invariant, no
 * arithmetic and no state machine: those live in `Twes\Domain\Document\Invoice`, which is `final readonly` with a
 * private constructor and mutators that return new instances. Doctrine's identity map holds ONE mutable object
 * per row and diffs it against a snapshot to emit UPDATEs, so it cannot follow an aggregate shaped like that —
 * a `readonly` property is writable once and a new instance is something the unit of work has never seen.
 * Developer ruling, 2026-08-01: a separate persistence model with a repository translating between them.
 *
 * The consequence to hold on to: **any rule that appears here is a rule in two places.** Nothing in this class
 * may validate, derive or refuse anything. If a check belongs to the business it belongs to the aggregate; if it
 * belongs to storage it belongs to the schema as a constraint. That is what keeps the duplication to field
 * names, which the round-trip contract test can then pin.
 *
 * **The primary key is COMPOSITE — `(company_id, id)` — and that is a tenancy decision, not a modelling one.**
 * A single-column `id` PK would let a child row's foreign key reference a parent by `document_id` alone, so one
 * tenant could delete or re-parent another tenant's rows through a perfectly valid FK. Every child here
 * references `(company_id, document_id)` instead, which makes a cross-tenant reference unrepresentable rather
 * than merely forbidden. `build-waves.plan.md` § Wave 1 states that rule for every table in this wave.
 *
 * The enum-valued columns are `varchar` with a CHECK constraint in the migration, NOT native PostgreSQL enums.
 * That is forced by our own migration settings: `doctrine_migrations.transactional` is true, and PostgreSQL
 * refuses to add an enum value and use it in the same transaction. [Verified: `BEGIN; ALTER TYPE t ADD VALUE
 * 'c'; SELECT 'c'::t;` → `ERROR: unsafe use of new value "c" of enum type t`.] A CHECK constraint is dropped and
 * recreated inside a transaction freely, so it evolves with the migration rather than against it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document')]
// The gapless sequence's ONLY real cross-process guarantee. `DocumentNumberSequence` documents that it cannot
// promise uniqueness across processes; this constraint is what makes a broken adapter loud instead of silent.
#[ORM\UniqueConstraint(name: 'document_number_unique_per_tenant_and_type', columns: ['company_id', 'type', 'number'])]
class DocumentRow
{
    /**
     * The tenant, and the FIRST half of the primary key.
     *
     * Named `company_id` because `PostgresRowLevelSecurityIsolation::TENANT_COLUMN` says so, and that constant
     * is the anchor rather than a convention: round 14 found a policy certified as canonical while scoping an
     * arbitrary column, because the checker read the column name out of the policy it was checking.
     */
    #[ORM\Id]
    #[ORM\Column(name: 'company_id', type: 'uuid')]
    public Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    /** `DocumentType`'s BACKED VALUES, never its case names — a PHP rename must not become a data migration. */
    #[ORM\Column(type: 'string', length: 32)]
    public string $type;

    /** `DocumentState`'s backed values, same argument. */
    #[ORM\Column(type: 'string', length: 32)]
    public string $state;

    /**
     * The DOCUMENT's currency, fixed at `Invoice::draft()` and never inferred from the first line.
     *
     * One column for the row rather than one per amount: a `Money` is *(amount, currency)* and a bare `NUMERIC`
     * cannot reconstitute one — `12.3400` is EUR 12.34 or TND 12.340 with equal claim. The domain refuses to mix
     * currencies within a document, so a second column could only ever record an impossible state.
     */
    #[ORM\Column(type: 'string', length: 3, options: ['fixed' => true])]
    public string $currency;

    /**
     * The raw counter, NULL until issued — `Invoice::number()` returns null for a draft.
     *
     * `bigint`, not `integer`: `DocumentNumber` accepts any `int >= 1` up to `PHP_INT_MAX`, and PostgreSQL
     * `integer` stops at 2 147 483 647, so an `integer` column would reject a value the domain admits. `bigint`
     * matches PHP's own integer width exactly, which is why it is right rather than a wider `NUMERIC`.
     *
     * Stored as the INTEGER rather than the rendered string: `NumberPattern` renders, it does not identify, and
     * the pattern is per-tenant configuration that may change.
     */
    #[ORM\Column(type: 'bigint', nullable: true)]
    public ?int $number = null;

    /** `VatRoundingPoint`'s backed values. Persisted PER DOCUMENT: a company changing its setting must not restate a document a client already holds. */
    #[ORM\Column(name: 'vat_rounding_point', type: 'string', length: 32)]
    public string $vatRoundingPoint;

    /*
     * **NO COLLECTIONS, for the reason the child rows give for having no `ManyToOne`.** With the association
     * dropped on the owning side there is no inverse side to declare, and the repository assembles the aggregate
     * from three queries rather than from a lazy graph.
     *
     * That is a deliberate simplification, not a limitation worked around: the aggregate is rebuilt whole on
     * every read — it is `final readonly`, so there is no partial-hydration state to be in — and rewritten whole
     * on every save. A lazy collection would buy nothing an immutable aggregate can use, and would introduce the
     * one thing this model exists to avoid: Doctrine holding a mutable object the domain also holds.
     */

    /**
     * **A CONSTRUCTOR ON A DOCTRINE ENTITY, and it is the mapper's forget-a-column guard.**
     *
     * `CLAUDE.md` § Architecture accepts one cost for the separate persistence model: *"a mapper per aggregate
     * and a real duplication risk if one is careless — paid down by a round-trip contract test, not by care."*
     * Before this constructor existed, `InvoiceMapper::toRows()` built each row with seven bare assignments, and
     * omitting one was **silent at the call site**: the typed property simply stayed uninitialised and PHP threw
     * `must not be accessed before initialization` later, during `flush()`, from inside the ORM. Naming the
     * columns as required parameters moves that from a runtime error in Doctrine's stack to a `TypeError` — or,
     * with PHPStan running, to a static one — at the line that forgot.
     *
     * It costs nothing, because **Doctrine never calls it.** Hydration goes through
     * `Doctrine\Instantiator\Instantiator`, which materialises an instance without invoking the constructor and
     * then writes every mapped field by reflection. So the mapping is untouched and this parameter list binds
     * only OUR code — which is the only code that gets a column wrong.
     *
     * `$number` is absent on purpose: it is the one nullable column, its default is `null`, and a document is
     * created unnumbered. `Invoice::issue()` allocates the number afterwards, so requiring it here would force
     * every caller to pass `null` and would make the one genuinely optional column look mandatory.
     */
    public function __construct(
        Uuid $companyId,
        Uuid $id,
        string $type,
        string $state,
        string $currency,
        string $vatRoundingPoint,
    ) {
        $this->companyId = $companyId;
        $this->id = $id;
        $this->type = $type;
        $this->state = $state;
        $this->currency = $currency;
        $this->vatRoundingPoint = $vatRoundingPoint;
    }
}
