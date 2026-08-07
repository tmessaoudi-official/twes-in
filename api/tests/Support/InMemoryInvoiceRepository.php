<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Support;

use Twes\Domain\Document\DocumentIdentity;
use Twes\Domain\Document\Invoice;
use Twes\Domain\Document\InvoiceRepository;
use Twes\Domain\Document\PersistedInvoice;

/**
 * An in-memory {@see InvoiceRepository}, for testing the use-case handlers without a database.
 *
 * **UNDER `tests/`, NEVER `src/`, and the reason is the same one that keeps `InMemoryDocumentNumberSequence` out of
 * production code**: an in-memory store loses every document on restart and would look like it works. It exists so
 * the handlers can be tested for the decisions they own — id generation, transaction boundaries, allocator use,
 * transition ordering — rather than for whether PostgreSQL persists a row, which
 * `DoctrineInvoiceRepositoryTest` and `InvoiceLifecycleTest` cover against the real schema.
 *
 * **IT DOES NOT SIMULATE NUMERIC RE-SCALING**, deliberately. A real `NUMERIC(21,6)` returns `'2.000000'` for a
 * stored `'2'`, and a fake that reproduced that would be re-implementing a database. The consequence is stated so it
 * is not mistaken for coverage: the handlers' re-read-after-write behaviour is *structurally* visible here (the
 * saved instance comes back) and its POINT — that `POST` and a later `GET` agree byte-for-byte — can only be proven
 * against real columns, which is what `InvoiceLifecycleTest` is for.
 *
 * **IT ENFORCES NEITHER THE TENANT BOUNDARY NOR THE TRANSACTION REFUSAL**, for the same reason: those are the
 * adapter's guarantees and they are asserted where they live. A fake that reproduced them would let a handler test
 * pass while the real adapter's version of the rule had been deleted.
 */
final class InMemoryInvoiceRepository implements InvoiceRepository
{
    /** @var array<string, PersistedInvoice> */
    private array $documents = [];

    /** How many times `save()` was called — the only way to see a write that a re-read would otherwise hide. */
    public int $saves = 0;

    public function save(DocumentIdentity $identity, Invoice $invoice): void
    {
        ++$this->saves;
        $this->documents[$identity->id] = new PersistedInvoice($identity, $invoice);
    }

    public function find(string $id): ?PersistedInvoice
    {
        return $this->documents[$id] ?? null;
    }
}
