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
 * A document line ROW. See {@see DocumentRow} for why the persistence model is separate from the aggregate.
 *
 * **`position` is LOAD-BEARING and had no column until it was specified** (round 15 filed that).
 * `Invoice::withoutLine(int $position)` addresses lines BY POSITION and `removeAt()` re-indexes to keep them
 * contiguous; `CurrencyMismatch::inContext('document line %d')` reports one to a client. Without a persisted
 * order, positions are not stable across a round-trip — so "remove line 2" issued against a rehydrated document
 * can remove a DIFFERENT line, which is precisely the stale-page hazard `removeAt()`'s own comment says it
 * exists to prevent.
 *
 * `smallint`, sized from `Invoice::MAX_LINES` (1000). That leaves a factor of about 32 against `smallint`'s
 * 32 767 — raising `MAX_LINES` past that needs a column change, which is why the two are noted together.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_line')]
class DocumentLineRow
{
    /**
     * The tenant, repeated on the child ROW rather than reached through the parent.
     *
     * That looks redundant and is not: it is what lets the foreign key be composite and, more importantly, what
     * lets row-level security police this table on its own. An RLS policy is evaluated per table, so a child
     * table without its own tenant column cannot be scoped at all — it would be readable through any join the
     * planner chooses.
     */
    #[ORM\Id]
    #[ORM\Column(name: 'company_id', type: 'uuid')]
    public Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(name: 'document_id', type: 'uuid')]
    public Uuid $documentId;

    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    public int $position;

    /*
     * **NO ASSOCIATION MAPPING, and the composite foreign key is declared in the MIGRATION instead.**
     *
     * The FK is `(company_id, document_id)` → `document (company_id, id)`, composite on purpose: a
     * single-column FK on `document_id` alone would let one tenant's row reference another tenant's document, so
     * a cross-tenant delete or re-parent would be a valid write. Composite makes it unrepresentable rather than
     * merely forbidden.
     *
     * Doctrine is not told about it, for two reasons. First it cannot be told cleanly: the FK columns here are
     * also the identifier columns, and ORM 3 has no `insertable`/`updatable` on `JoinColumn`, so expressing this
     * means derived identity — `#[ORM\Id] #[ORM\ManyToOne]` — which replaces the scalar fields with an object
     * graph the repository would immediately have to unpick again. Second it does not need to be: a foreign key
     * is a SCHEMA fact, the database enforces it whether or not the ORM knows, and the repository translates by
     * hand anyway. `doctrine:schema:validate --skip-sync` therefore checks the mapping only — the constraint is
     * enforced by the database, that it is COMPOSITE is asserted by `schema-tenancy.php`, and GOAL 8 of
     * `BehaviouralIsolationTest` attacks it as defence in depth. The gate now also reads `confkey` in ordinal order,
     * so a key composite in the WRONG pair — tenant mapped onto the parent's surrogate id — is refused too.
     */

    /**
     * `NUMERIC(21,6)`: `DocumentLine::MAX_INTEGER_DIGITS` (15) + `DocumentLine::MAX_SCALE` (6).
     *
     * Both constants exist because round 14 found this the ONE persisted decimal in the domain bounded at
     * neither end — it accepted 601 decimals and 40 integer digits, so no `NUMERIC` could have stored what the
     * domain admitted. A `decimal` column is read back as a STRING by Doctrine, which is exactly what the domain
     * wants: money and quantities here are decimal strings over bcmath, never floats.
     */
    #[ORM\Column(type: 'decimal', precision: 21, scale: 6)]
    public string $quantity;

    /** `NUMERIC(19,4)` — three decimals exact for TND plus a digit of headroom for unit prices. Never a float. */
    #[ORM\Column(name: 'unit_net', type: 'decimal', precision: 19, scale: 4)]
    public string $unitNet;

    /**
     * `NUMERIC(27,12)`: `Rate::FRACTION_SCALE` (12) + `Rate::MAX_INTEGER_DIGITS` (15).
     *
     * PER LINE, not per document: multiple VAT rates on one document is the normal Tunisian and French case, and
     * `DocumentCalculator` groups by rate precisely because of it.
     */
    #[ORM\Column(name: 'vat_rate', type: 'decimal', precision: 27, scale: 12)]
    public string $vatRate;
}
