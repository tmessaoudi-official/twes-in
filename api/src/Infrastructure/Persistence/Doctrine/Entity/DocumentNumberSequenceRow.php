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
 * The gapless document-number counter, as a ROW — which is the whole point.
 *
 * **A PostgreSQL `SEQUENCE` is forbidden here and this table is what replaces it** (CLAUDE.md § Gotchas,
 * 2026-07-31). A sequence is deliberately non-transactional: it does not roll back, so every failed or
 * rolled-back issue burns its number and leaves a permanent hole. That is right for a surrogate key and
 * disqualifying for a legal document number — a missing invoice number is what a tax authority reads as a
 * suppressed sale, and France and Tunisia both audit for it. `SERIAL`, `IDENTITY` and any `CACHE n` fall to the
 * same objection.
 *
 * So the counter is an ordinary row, advanced inside the same transaction that persists the document — by one atomic
 * `INSERT … ON CONFLICT DO UPDATE … RETURNING`, which is what
 * {@see \Twes\Infrastructure\Persistence\Doctrine\PostgresDocumentNumberSequence} issues and where the reasoning
 * lives. (This sentence said `SELECT ... FOR UPDATE` until that adapter was written and measured; the lock in that
 * form closed a window no test could reach, so its presence was unprovable.) Accepted cost, ruled explicitly: issues
 * for one `(tenant, type)` SERIALISE. Two invoices sharing a number is worse than a queued request.
 *
 * **`PRIMARY KEY (company_id, type)` is what makes it one row per pair.** Two rows for one pair would let two
 * concurrent issues take the same number — the outcome the port's fifth guarantee (serialised) exists to
 * prevent — so the constraint is doing the work, not the code that reads it.
 *
 * **This table is TENANT-OWNED**, so it carries the same three RLS statements as every other tenant-owned table
 * and belongs in `schema-tenancy.php`'s subject set. Nothing said so before round 15 pointed it out: a counter
 * that leaks across tenants leaks one tenant's invoice volume to another, and an unpoliced counter is writable
 * by any tenant, which is a denial of service on somebody else's numbering.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_number_sequence')]
class DocumentNumberSequenceRow
{
    #[ORM\Id]
    #[ORM\Column(name: 'company_id', type: 'uuid')]
    public Uuid $companyId;

    /** `DocumentType`'s backed value: sequences are per type, so invoice 41 and delivery note 41 both exist. */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 32)]
    public string $type;

    /**
     * The next number to hand out. `bigint` for the same reason `DocumentRow::$number` is.
     *
     * `CHECK (next_value >= 1)` in the migration, matching the port's second guarantee that the first number is
     * 1 — a zero here would hand out a document number of 0, which `DocumentNumber` refuses on the way back in.
     * Enforced in BOTH places deliberately: the domain refusal gives a caller a usable error, and the constraint
     * stops a direct `UPDATE` from writing state the domain cannot then read.
     */
    #[ORM\Column(name: 'next_value', type: 'bigint')]
    public int $nextValue = 1;

    /**
     * {@see DocumentRow::__construct()} for why a Doctrine entity has one.
     *
     * `$nextValue` keeps its default and is NOT a parameter: 1 is the port's second guarantee, so a caller
     * creating a sequence row is creating one that has handed out nothing.
     */
    public function __construct(Uuid $companyId, string $type)
    {
        $this->companyId = $companyId;
        $this->type = $type;
    }
}
