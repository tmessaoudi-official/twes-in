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
 * A fixed document charge ROW — a stamp duty and the like. See {@see DocumentRow} on the separate model.
 *
 * A fixed charge is NEVER in a VAT base. That rule lives in `DocumentCalculator`, which adds charges to the
 * total after the VAT groups are computed, and it is stated here only so nobody adds a `vat_rate` column to
 * this table by analogy with `document_line`. Taxing a stamp duty is a silent overcharge on every invoice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_charge')]
class DocumentChargeRow
{
    /** See {@see DocumentLineRow::$companyId} — a child table needs its own tenant column to be policed at all. */
    #[ORM\Id]
    #[ORM\Column(name: 'company_id', type: 'uuid')]
    public Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(name: 'document_id', type: 'uuid')]
    public Uuid $documentId;

    /** Ordered for the same reason lines are: `Invoice::withoutFixedCharge(int $position)` addresses by position. */
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
     * checked by the database, and by `schema-tenancy.php`.
     */

    /** Trimmed on the way in by `FixedCharge`, which also refuses an empty one. `text`, because a label is prose. */
    #[ORM\Column(type: 'text')]
    public string $label;

    /** `NUMERIC(19,4)`, in the DOCUMENT's currency. Never a float. */
    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    public string $amount;
}
